<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Contracts\LlmClient;
use App\Contracts\ProductFreshnessChecker;
use App\Contracts\RelevanceGate;
use App\DTO\Catalog\AttributeConstraint;
use App\DTO\Catalog\AttributeConstraintBuckets;
use App\DTO\Catalog\ProductCard;
use App\DTO\Catalog\RelaxationStep;
use App\DTO\Catalog\SearchHit;
use App\DTO\Chat\ChatExtractionResult;
use App\DTO\Chat\ChatMessage;
use App\DTO\Chat\ChatReply;
use App\DTO\Chat\ChatTurn;
use App\DTO\Chat\ExtractedFilters;
use App\DTO\Chat\SearchFilters;
use App\Enums\EmbedTask;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Catalog\AttributeConstraintDetector;
use App\Services\Catalog\AttributeResolver;
use App\Services\Catalog\AttributeVocabulary;
use App\Services\Catalog\ProductAttributeMatcher;
use App\Services\Catalog\ProductTypeHint;
use App\Services\Catalog\SearchCatalogProductsDetailedService;
use App\Services\Catalog\SearchFiltersNormalizer;
use App\Services\Catalog\SearchFiltersRelaxer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SendChatMessageService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Ты помощник по подбору товаров в интернет-магазине.
Отвечай кратко по-русски и только по текущему сообщению и списку Candidates ниже.
Не упоминай предыдущие запросы, товары или отказы из истории диалога — их нет в этом запросе.
Упоминай название товара, цену и магазин, если они есть.
Не пиши пути вроде /products/123, path:, markdown-код и ссылки — карточки товаров уже показываются в интерфейсе под ответом.
Если Candidates: (none) — скажи, что подходящих товаров не нашлось, и предложи упростить запрос (убрать цвет, магазин или бюджет).
Если Candidates — нумерованный список (не (none)) — обязательно рекомендуй эти товары; запрещено говорить «не найдено», «точного совпадения нет» или что поиск не дал результатов.
Если указаны «Ослабленные условия» и Candidates не пуст — одна короткая оговорка (например, точный цвет не попал), затем рекомендуй товары из списка.
PROMPT;

    private const CHITCHAT_SYSTEM_PROMPT = <<<'PROMPT'
Ты помощник по подбору товаров в интернет-магазине.
Отвечай кратко по-русски. Пользователь не ищет товар прямо сейчас — поздоровайся или ответь на вопрос
и предложи описать, что нужно найти (тип товара, цвет, размер, бюджет, магазин).
Не выдумывай конкретные товары и цены.
PROMPT;

    private const HISTORY_LIMIT = 6;

    private const MAX_RELAXATION_STEPS = 3;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly SearchCatalogProductsDetailedService $search,
        private readonly SearchFiltersNormalizer $filtersNormalizer,
        private readonly RelevanceGate $relevanceGate,
        private readonly ProductFreshnessChecker $freshnessChecker,
        private readonly AttributeConstraintDetector $attributeConstraints,
        private readonly OwnedConversationFinder $conversations,
        private readonly ProductAttributeMatcher $productAttributes = new ProductAttributeMatcher,
        private readonly ProductTypeHint $productTypeHint = new ProductTypeHint,
        private readonly AttributeVocabulary $attributeVocabulary = new AttributeVocabulary,
        private readonly AttributeResolver $attributeResolver = new AttributeResolver,
        private readonly SearchFiltersRelaxer $filtersRelaxer = new SearchFiltersRelaxer,
    ) {}

    public function handle(ChatTurn $turn): ChatReply
    {
        $userId = $turn->userId;
        $messageText = $turn->message;
        $conversation = $this->conversations->resolve($userId, $turn->conversationId);

        /** @var \Illuminate\Database\Eloquent\Collection<int, Message> $history */
        $history = $conversation->messages()->latest()
            ->limit(self::HISTORY_LIMIT)
            ->get(['role', 'content'])
            ->reverse()
            ->values();

        $this->llm->startBudget((int) config('llm.gemini.timeout', 25));

        $relaxedLabels = [];
        $productCards = [];
        $filters = new SearchFilters;

        try {
            $previousFilters = is_array($conversation->active_filters) ? $conversation->active_filters : null;
            $clientCategory = $turn->categoryName;
            $typeHint = $this->productTypeHint->resolve($messageText, $previousFilters, $clientCategory);
            $catalogAttrNames = $this->attributeVocabulary->namesForScope($typeHint);

            $extraction = $this->resolveFiltersAndVector(
                $messageText,
                $history,
                $previousFilters,
                $catalogAttrNames,
            );
            $merged = $this->mergeFilters($extraction->filters, $turn, $history, $catalogAttrNames);
            $filters = $this->filtersNormalizer->normalize($merged);
            $queryVector = $extraction->embedding;
            $resultLimit = max(1, min($turn->limit ?? 5, 20));

            if (! $filters->hasSearchIntent()) {
                $chatMessages = [];
                foreach ($history as $row) {
                    $chatMessages[] = $row->role === MessageRole::Assistant->value
                        ? ChatMessage::assistant((string) $row->content)
                        : ChatMessage::user((string) $row->content);
                }
                $chatMessages[] = ChatMessage::user($messageText);
                $answer = $this->llm->chat($chatMessages, self::CHITCHAT_SYSTEM_PROMPT);
                Log::info('Recommendation chat skipped catalog search (no product intent)', [
                    'conversation_id' => (string) $conversation->id,
                ]);
            } else {
                $searchResult = $this->search->handle($filters, $resultLimit, $queryVector);
                $preferredSoftPath = $searchResult->preferredRelaxed;
                $preferredForLabel = $filters->preferredAttributes;

                // Relevance gate runs inside the search loop: an irrelevant (below
                // threshold) result set must count as empty before we decide to relax.
                $preGateHits = $searchResult->hits;
                $hits = $this->relevanceGate->accept($preGateHits);

                // Progressive relaxation while the gated result is still empty.
                $steps = 0;
                while ($hits === [] && $steps < self::MAX_RELAXATION_STEPS) {
                    $step = $this->filtersRelaxer->relaxOnce($filters);
                    if (! $step instanceof RelaxationStep) {
                        break;
                    }
                    $filters = $this->filtersNormalizer->normalize($step->filters);
                    $relaxedLabels[] = $step->relaxed;
                    $searchResult = $this->search->handle($filters, $resultLimit, $queryVector);
                    $preGateHits = $searchResult->hits;
                    $hits = $this->relevanceGate->accept($preGateHits);
                    $steps++;
                }

                // Log once if the final gate pass dropped the whole pre-gate set.
                if ($preGateHits !== [] && $hits === []) {
                    $this->logGateDroppedAll($preGateHits);
                }

                $hits = $this->freshnessChecker->keepFresh($hits);

                // Label preferred soft-fallback only when final hits miss that attr (not merely because soft path ran).
                if ($preferredSoftPath && $preferredForLabel !== []) {
                    foreach ($preferredForLabel as $attr) {
                        if (! $this->anyHitSatisfiesAttribute($hits, $attr)) {
                            $preferredAttrName = $attr->name;
                            $preferredAttrValue = $attr->value;
                            $preferredSoftLabel = $preferredAttrName.'='.$preferredAttrValue
                                .' (точный '.$preferredAttrName.' не обязателен)';
                            $relaxedLabels[] = $preferredSoftLabel;
                        }
                    }
                }

                $productCards = array_map(
                    static fn (SearchHit $hit): ProductCard => ProductCard::fromModel($hit->product),
                    $hits,
                );

                $this->logSearchTurn($conversation, $filters, $relaxedLabels, count($preGateHits), count($hits));

                $candidatesBlock = CandidatePromptFormatter::lines($productCards);
                $userContent = $messageText."\n\nCandidates:\n".$candidatesBlock;
                if ($relaxedLabels !== []) {
                    $userContent .= "\n\nОслабленные условия: ".implode('; ', $relaxedLabels);
                }
                // Answer chat is isolated from prior turns so past "не нашлось" does not leak into this reply.
                $answer = $this->llm->chat(
                    [ChatMessage::user($userContent)],
                    self::SYSTEM_PROMPT,
                );
            }
        } finally {
            $this->llm->clearBudget();
        }

        $extractedFilters = new ExtractedFilters($filters, $relaxedLabels);
        $filtersPayload = $extractedFilters->toArray();

        DB::transaction(function () use ($conversation, $messageText, $answer, $productCards, $filtersPayload): void {
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'role' => MessageRole::User->value,
                'content' => $messageText,
            ]);

            Message::query()->create([
                'conversation_id' => $conversation->id,
                'role' => MessageRole::Assistant->value,
                'content' => $answer,
                'products' => array_map(static fn (ProductCard $card): array => $card->toArray(), $productCards),
            ]);

            $conversation->active_filters = $filtersPayload;
            $conversation->touchActivity();
        });

        return new ChatReply(
            conversationId: (string) $conversation->id,
            answer: $answer,
            extractedFilters: $extractedFilters,
            products: $productCards,
        );
    }

    /**
     * @param  Collection<int, object{role: string, content: string}>|\Illuminate\Database\Eloquent\Collection<int, Message>  $history
     * @param  array<string, mixed>|null  $previousFilters
     * @param  list<string>  $catalogAttributeNames
     */
    private function resolveFiltersAndVector(
        string $messageText,
        $history,
        ?array $previousFilters,
        array $catalogAttributeNames = [],
    ): ChatExtractionResult {
        $extractInput = FilterExtractPrompt::build($messageText, $history, $previousFilters);

        return $this->llm->extractFiltersAndEmbed(
            $extractInput,
            EmbedTask::RetrievalQuery,
            // Fallback embed text when extract.query is null (never the full extract prompt).
            $messageText,
            $catalogAttributeNames,
        );
    }

    /**
     * @param  Collection<int, object{role: string, content: string}>|\Illuminate\Database\Eloquent\Collection<int, Message>  $history
     * @param  list<string>  $catalogAttributeNames
     */
    private function mergeFilters(
        SearchFilters $extracted,
        ChatTurn $turn,
        $history,
        array $catalogAttributeNames = [],
    ): SearchFilters {
        $exclusiveFromHistory = $this->attributeConstraints->historyImpliesExclusive($history);
        $split = $this->attributeConstraints->split(
            $turn->message,
            $extracted->attributes,
            $exclusiveFromHistory,
        );

        $resolved = $this->resolveAttributeBuckets($split, $catalogAttributeNames);
        // Client store_id wins; drop conflicting extracted store_name (and vice versa).
        $storeId = $turn->storeId ?? $extracted->storeId;
        $storeName = $turn->storeName ?? $extracted->storeName;
        if ($turn->storeId !== null) {
            $storeName = null;
        } elseif ($turn->storeName !== null) {
            $storeId = null;
        }

        $resolvedCategoryName = $turn->categoryName ?? $extracted->categoryName;
        $resolvedInStock = $turn->inStock !== null
            ? $turn->inStock
            : $extracted->inStock;

        return new SearchFilters(
            storeId: $storeId,
            storeName: $storeName,
            categoryName: $resolvedCategoryName,
            inStock: $resolvedInStock,
            priceMin: $turn->priceMin !== null ? $turn->priceMin : $extracted->priceMin,
            priceMax: $turn->priceMax !== null ? $turn->priceMax : $extracted->priceMax,
            // Keep extracted query as-is (null for chitchat); do not force raw message into query.
            query: $extracted->query,
            attributes: $resolved->soft,
            requiredAttributes: $resolved->required,
            preferredAttributes: $resolved->preferred,
        );
    }

    /**
     * @param  list<string>  $vocabulary
     */
    private function resolveAttributeBuckets(AttributeConstraintBuckets $split, array $vocabulary): AttributeConstraintBuckets
    {
        $required = [];
        $preferred = [];
        $soft = [];

        /** @var array<string, list<AttributeConstraint>> $buckets */
        $buckets = [
            'required' => $split->required,
            'preferred' => $split->preferred,
            'soft' => $split->soft,
        ];

        foreach ($buckets as $bucket => $attrs) {
            foreach ($this->attributeResolver->resolveList($attrs, $vocabulary) as $attr) {
                // Unresolved names stay soft so bare numbers do not hard-filter wrongly.
                if ($attr->valueOnly && $bucket !== 'soft') {
                    $soft[] = $attr;

                    continue;
                }
                if ($bucket === 'required') {
                    $required[] = $attr;
                } elseif ($bucket === 'preferred') {
                    $preferred[] = $attr;
                } else {
                    $soft[] = $attr;
                }
            }
        }

        return new AttributeConstraintBuckets(
            required: $required,
            preferred: $preferred,
            soft: $soft,
        );
    }

    /**
     * @param  list<SearchHit>  $hits
     */
    private function logGateDroppedAll(array $hits): void
    {
        Log::info('RelevanceGate dropped all search hits', [
            'max_cosine_distance' => (float) config('recommendations.max_cosine_distance', 0.75),
            'hits' => array_map(
                static fn (SearchHit $hit): array => [
                    'product_id' => (int) $hit->product->product_id,
                    'distance' => $hit->distance,
                ],
                $hits,
            ),
        ]);
    }

    /**
     * @param  list<string>  $relaxedLabels
     */
    private function logSearchTurn(
        Conversation $conversation,
        SearchFilters $filters,
        array $relaxedLabels,
        int $preGateCount,
        int $postFreshCount,
    ): void {
        $payload = $filters->toArray();
        Log::info('Recommendation chat search turn', [
            'conversation_id' => (string) $conversation->id,
            'query' => $filters->query,
            'soft_attributes' => $payload['attributes'],
            'required_attributes' => $payload['required_attributes'],
            'preferred_attributes' => $payload['preferred_attributes'],
            'store_id' => $filters->storeId,
            'store_name' => $filters->storeName,
            'price_min' => $filters->priceMin,
            'price_max' => $filters->priceMax,
            'relaxed' => $relaxedLabels,
            'hits_pre_gate' => $preGateCount,
            'hits_post_freshness' => $postFreshCount,
        ]);
    }

    /**
     * @param  list<SearchHit>  $hits
     */
    private function anyHitSatisfiesAttribute(array $hits, AttributeConstraint $attr): bool
    {
        foreach ($hits as $hit) {
            if ($this->productAttributes->satisfies($hit->product, $attr->name, $attr->value, $attr->valueOnly)) {
                return true;
            }
        }

        return false;
    }
}
