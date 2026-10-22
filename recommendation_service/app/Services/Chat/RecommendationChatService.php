<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Catalog\AttributeConstraintDetector;
use App\Services\Catalog\AttributeResolver;
use App\Services\Catalog\AttributeVocabulary;
use App\Services\Catalog\Contracts\ProductFreshnessChecker;
use App\Services\Catalog\Contracts\RelevanceGate;
use App\Services\Catalog\DTOs\SearchHit;
use App\Services\Catalog\ProductSearchService;
use App\Services\Catalog\ProductTypeHint;
use App\Services\Catalog\SearchFiltersNormalizer;
use App\Services\Catalog\SearchFiltersRelaxer;
use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\ChatMessage;
use App\Services\Llm\DTOs\SearchFilters;
use App\Services\Llm\Gemini\GeminiLlmClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecommendationChatService
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
        private readonly ProductSearchService $search,
        private readonly SearchFiltersNormalizer $filtersNormalizer,
        private readonly RelevanceGate $relevanceGate,
        private readonly ProductFreshnessChecker $freshnessChecker,
        private readonly AttributeConstraintDetector $attributeConstraints,
        private readonly ProductTypeHint $productTypeHint = new ProductTypeHint,
        private readonly AttributeVocabulary $attributeVocabulary = new AttributeVocabulary,
        private readonly AttributeResolver $attributeResolver = new AttributeResolver,
        private readonly SearchFiltersRelaxer $filtersRelaxer = new SearchFiltersRelaxer,
    ) {}

    /**
     * @param  array{
     *     user_id: string|int,
     *     message: string,
     *     conversation_id?: string|null,
     *     store_id?: int|null,
     *     store_name?: string|null,
     *     category_name?: string|null,
     *     in_stock?: bool|null,
     *     price_min?: float|null,
     *     price_max?: float|null,
     *     limit?: int|null
     * }  $input
     * @return array{
     *     conversation_id: string,
     *     answer: string,
     *     extracted_filters: array<string, mixed>,
     *     products: list<array<string, mixed>>
     * }
     */
    public function chat(array $input): array
    {
        $userId = (string) $input['user_id'];
        $messageText = trim((string) $input['message']);
        $conversation = $this->resolveConversation(
            $userId,
            isset($input['conversation_id']) ? (string) $input['conversation_id'] : null,
        );

        /** @var \Illuminate\Database\Eloquent\Collection<int, Message> $history */
        $history = $conversation->messages()
            ->orderByDesc('created_at')
            ->limit(self::HISTORY_LIMIT)
            ->get(['role', 'content'])
            ->reverse()
            ->values();

        if ($this->llm instanceof GeminiLlmClient) {
            $this->llm->startBudget((int) config('llm.gemini.timeout', 25));
        }

        $relaxedLabels = [];
        $productCards = [];
        $filters = new SearchFilters;

        try {
            $previousFilters = is_array($conversation->active_filters) ? $conversation->active_filters : null;
            $clientCategory = isset($input['category_name']) && trim((string) $input['category_name']) !== ''
                ? trim((string) $input['category_name'])
                : null;
            $typeHint = $this->productTypeHint->resolve($messageText, $previousFilters, $clientCategory);
            $catalogAttrNames = $this->attributeVocabulary->namesForScope($typeHint);

            [$extracted, $queryVector] = $this->resolveFiltersAndVector(
                $messageText,
                $history,
                $previousFilters,
                $catalogAttrNames,
            );
            $merged = $this->mergeFilters($extracted, $input, $history, $catalogAttrNames);
            $filters = $this->filtersNormalizer->normalize($merged);
            $limit = max(1, min((int) ($input['limit'] ?? 5), 20));

            if (! $filters->hasSearchIntent()) {
                $chatMessages = [];
                foreach ($history as $row) {
                    $chatMessages[] = $row->role === Message::ROLE_ASSISTANT
                        ? ChatMessage::assistant((string) $row->content)
                        : ChatMessage::user((string) $row->content);
                }
                $chatMessages[] = ChatMessage::user($messageText);
                $answer = $this->llm->chat($chatMessages, self::CHITCHAT_SYSTEM_PROMPT);
                Log::info('Recommendation chat skipped catalog search (no product intent)', [
                    'conversation_id' => (string) $conversation->id,
                ]);
            } else {
                $searchResult = $this->search->searchDetailed($filters, $limit, $queryVector);
                $hits = $searchResult->hits;
                $preferredSoftPath = $searchResult->preferredRelaxed;
                $preferredForLabel = $filters->preferredAttributes;

                // Progressive relaxation when still empty.
                $steps = 0;
                while ($hits === [] && $steps < self::MAX_RELAXATION_STEPS) {
                    $step = $this->filtersRelaxer->relaxOnce($filters);
                    if ($step === null) {
                        break;
                    }
                    $filters = $this->filtersNormalizer->normalize($step['filters']);
                    $relaxedLabels[] = $step['relaxed'];
                    $searchResult = $this->search->searchDetailed($filters, $limit, $queryVector);
                    $hits = $searchResult->hits;
                    $steps++;
                }

                $preGateHits = $hits;
                $hits = $this->relevanceGate->accept($hits);
                if ($preGateHits !== [] && $hits === []) {
                    $this->logGateDroppedAll($preGateHits);
                }

                $hits = $this->freshnessChecker->keepFresh($hits);

                // Label preferred soft-fallback only when final hits miss that attr (not merely because soft path ran).
                if ($preferredSoftPath && $preferredForLabel !== []) {
                    foreach ($preferredForLabel as $attr) {
                        if (! $this->anyHitSatisfiesAttribute($hits, $attr)) {
                            $relaxedLabels[] = $attr['name'].'='.$attr['value']
                                .' (точный '.$attr['name'].' не обязателен)';
                        }
                    }
                }

                $productCards = array_map(
                    static fn (SearchHit $hit): array => $hit->product->toCardArray(),
                    $hits,
                );

                $this->logSearchTurn($conversation, $filters, $relaxedLabels, count($preGateHits), count($hits));

                $userContent = $messageText."\n\nCandidates:\n".CandidatePromptFormatter::lines($productCards);
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
            if ($this->llm instanceof GeminiLlmClient) {
                $this->llm->clearBudget();
            }
        }

        $filtersPayload = $filters->toArray();
        if ($relaxedLabels !== []) {
            $filtersPayload['relaxed'] = $relaxedLabels;
        }

        DB::transaction(function () use ($conversation, $messageText, $answer, $productCards, $filtersPayload): void {
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'role' => Message::ROLE_USER,
                'content' => $messageText,
            ]);

            Message::query()->create([
                'conversation_id' => $conversation->id,
                'role' => Message::ROLE_ASSISTANT,
                'content' => $answer,
                'products' => $productCards,
            ]);

            $conversation->active_filters = $filtersPayload;
            $conversation->touchActivity();
        });

        return [
            'conversation_id' => (string) $conversation->id,
            'answer' => $answer,
            'extracted_filters' => $filtersPayload,
            'products' => $productCards,
        ];
    }

    public function getConversation(string $userId, string $conversationId): Conversation
    {
        return $this->findOwnedActive($userId, $conversationId);
    }

    public function deleteConversation(string $userId, string $conversationId): void
    {
        $this->findOwnedActive($userId, $conversationId)->delete();
    }

    /**
     * @param  Collection<int, object{role: string, content: string}>|\Illuminate\Database\Eloquent\Collection<int, Message>  $history
     * @param  array<string, mixed>|null  $previousFilters
     * @param  list<string>  $catalogAttributeNames
     * @return array{0: SearchFilters, 1: list<float>}
     */
    private function resolveFiltersAndVector(
        string $messageText,
        $history,
        ?array $previousFilters,
        array $catalogAttributeNames = [],
    ): array {
        $extractInput = FilterExtractPrompt::build($messageText, $history, $previousFilters);

        return $this->llm->extractFiltersAndEmbed(
            $extractInput,
            'RETRIEVAL_QUERY',
            // Fallback embed text when extract.query is null (never the full extract prompt).
            $messageText,
            $catalogAttributeNames,
        );
    }

    private function resolveConversation(string $userId, ?string $conversationId): Conversation
    {
        if ($conversationId === null || $conversationId === '') {
            $now = Carbon::now();

            return Conversation::query()->create([
                'user_id' => $userId,
                'last_activity_at' => $now,
                'expires_at' => Conversation::computeExpiresAt($now, $now),
            ]);
        }

        return $this->findOwnedActive($userId, $conversationId);
    }

    private function findOwnedActive(string $userId, string $conversationId): Conversation
    {
        $conversation = Conversation::query()
            ->where('id', $conversationId)
            ->where('user_id', $userId)
            ->where('expires_at', '>', now())
            ->first();

        if ($conversation === null) {
            throw (new ModelNotFoundException)->setModel(Conversation::class, [$conversationId]);
        }

        return $conversation;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  Collection<int, object{role: string, content: string}>|\Illuminate\Database\Eloquent\Collection<int, Message>  $history
     * @param  list<string>  $catalogAttributeNames
     */
    private function mergeFilters(
        SearchFilters $extracted,
        array $input,
        $history,
        array $catalogAttributeNames = [],
    ): SearchFilters {
        $messageText = trim((string) ($input['message'] ?? ''));
        $exclusiveFromHistory = $this->attributeConstraints->historyImpliesExclusive($history);
        $split = $this->attributeConstraints->split(
            $messageText,
            $extracted->attributes,
            $exclusiveFromHistory,
        );

        $resolved = $this->resolveAttributeBuckets($split, $catalogAttributeNames);

        $clientStoreId = isset($input['store_id']) ? (int) $input['store_id'] : null;
        $clientStoreName = isset($input['store_name']) && trim((string) $input['store_name']) !== ''
            ? trim((string) $input['store_name'])
            : null;

        // Client store_id wins; drop conflicting extracted store_name (and vice versa).
        $storeId = $clientStoreId ?? $extracted->storeId;
        $storeName = $clientStoreName ?? $extracted->storeName;
        if ($clientStoreId !== null) {
            $storeName = null;
        } elseif ($clientStoreName !== null) {
            $storeId = null;
        }

        return new SearchFilters(
            storeId: $storeId,
            storeName: $storeName,
            categoryName: isset($input['category_name']) && trim((string) $input['category_name']) !== ''
                ? trim((string) $input['category_name'])
                : $extracted->categoryName,
            inStock: array_key_exists('in_stock', $input) && $input['in_stock'] !== null
                ? (bool) $input['in_stock']
                : $extracted->inStock,
            priceMin: isset($input['price_min']) ? (float) $input['price_min'] : $extracted->priceMin,
            priceMax: isset($input['price_max']) ? (float) $input['price_max'] : $extracted->priceMax,
            // Keep extracted query as-is (null for chitchat); do not force raw message into query.
            query: $extracted->query,
            attributes: $resolved['soft'],
            requiredAttributes: $resolved['required'],
            preferredAttributes: $resolved['preferred'],
        );
    }

    /**
     * @param  array{
     *     required: list<array{name: string, value: string}>,
     *     preferred: list<array{name: string, value: string}>,
     *     soft: list<array{name: string, value: string}>
     * }  $split
     * @param  list<string>  $vocabulary
     * @return array{
     *     required: list<array{name: string, value: string, value_only?: bool}>,
     *     preferred: list<array{name: string, value: string, value_only?: bool}>,
     *     soft: list<array{name: string, value: string, value_only?: bool}>
     * }
     */
    private function resolveAttributeBuckets(array $split, array $vocabulary): array
    {
        $required = [];
        $preferred = [];
        $soft = [];

        foreach (['required' => $split['required'], 'preferred' => $split['preferred'], 'soft' => $split['soft']] as $bucket => $attrs) {
            foreach ($this->attributeResolver->resolveList($attrs, $vocabulary) as $attr) {
                $valueOnly = (bool) ($attr['value_only'] ?? false);
                // Unresolved names stay soft so bare numbers do not hard-filter wrongly.
                if ($valueOnly && $bucket !== 'soft') {
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

        return [
            'required' => $required,
            'preferred' => $preferred,
            'soft' => $soft,
        ];
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
        Log::info('Recommendation chat search turn', [
            'conversation_id' => (string) $conversation->id,
            'query' => $filters->query,
            'soft_attributes' => $filters->attributes,
            'required_attributes' => $filters->requiredAttributes,
            'preferred_attributes' => $filters->preferredAttributes,
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
     * @param  array{name: string, value: string, value_only?: bool}  $attr
     */
    private function anyHitSatisfiesAttribute(array $hits, array $attr): bool
    {
        $name = (string) $attr['name'];
        $value = (string) $attr['value'];
        $valueOnly = (bool) ($attr['value_only'] ?? false);
        foreach ($hits as $hit) {
            if ($this->search->productSatisfiesAttribute($hit->product, $name, $value, $valueOnly)) {
                return true;
            }
        }

        return false;
    }
}
