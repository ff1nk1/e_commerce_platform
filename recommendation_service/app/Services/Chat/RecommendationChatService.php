<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Catalog\AttributeConstraintDetector;
use App\Services\Catalog\Contracts\ProductFreshnessChecker;
use App\Services\Catalog\Contracts\RelevanceGate;
use App\Services\Catalog\DTOs\SearchHit;
use App\Services\Catalog\ProductSearchService;
use App\Services\Catalog\SearchFiltersNormalizer;
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
Отвечай кратко по-русски и только по списку Candidates ниже.
Упоминай название товара, цену и магазин, если они есть.
Не пиши пути вроде /products/123, path:, markdown-код и ссылки — карточки товаров уже показываются в интерфейсе под ответом.
Если подходящих кандидатов нет (Candidates: (none)), честно скажи, что точного совпадения не найдено, и предложи изменить или упростить запрос (например, убрать фильтр по цвету, магазину или бюджету).
PROMPT;

    private const HISTORY_LIMIT = 6;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly ProductSearchService $search,
        private readonly SearchFiltersNormalizer $filtersNormalizer,
        private readonly RelevanceGate $relevanceGate,
        private readonly ProductFreshnessChecker $freshnessChecker,
        private readonly AttributeConstraintDetector $attributeConstraints,
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
            $this->llm->startBudget((int) config('llm.gemini.timeout', 12));
        }

        try {
            [$extracted, $queryVector] = $this->resolveFiltersAndVector($messageText, $input, $history);
            $merged = $this->mergeFilters($extracted, $input);
            $filters = $this->filtersNormalizer->normalize($merged);
            $limit = max(1, min((int) ($input['limit'] ?? 5), 20));

            $hits = $this->search->search($filters, $limit, $queryVector);

            $preGateHits = $hits;
            $hits = $this->relevanceGate->accept($hits);
            if ($preGateHits !== [] && $hits === []) {
                $this->logGateDroppedAll($preGateHits);
            }

            $hits = $this->freshnessChecker->keepFresh($hits);

            $productCards = array_map(
                static fn (SearchHit $hit): array => $hit->product->toCardArray(),
                $hits,
            );

            $chatMessages = [];
            foreach ($history as $row) {
                $chatMessages[] = $row->role === Message::ROLE_ASSISTANT
                    ? ChatMessage::assistant((string) $row->content)
                    : ChatMessage::user((string) $row->content);
            }
            $chatMessages[] = ChatMessage::user($messageText."\n\nCandidates:\n".$this->formatCandidates($productCards));

            $answer = $this->llm->chat($chatMessages, self::SYSTEM_PROMPT);
        } finally {
            if ($this->llm instanceof GeminiLlmClient) {
                $this->llm->clearBudget();
            }
        }

        DB::transaction(function () use ($conversation, $messageText, $answer, $productCards): void {
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

            $conversation->touchActivity();
        });

        return [
            'conversation_id' => (string) $conversation->id,
            'answer' => $answer,
            'extracted_filters' => $filters->toArray(),
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
     * @param  array<string, mixed>  $input
     * @param  Collection<int, object{role: string, content: string}>|\Illuminate\Database\Eloquent\Collection<int, Message>  $history
     * @return array{0: SearchFilters, 1: list<float>}
     */
    private function resolveFiltersAndVector(string $messageText, array $input, $history): array
    {
        // Always extract so attributes (color/size/…) are available even when the
        // client sends structured filters; client fields win in mergeFilters().
        $extracted = $this->llm->extractFilters(
            $this->buildExtractInput($messageText, $history)
        );
        $embedText = $extracted->query ?: $messageText;

        return [$extracted, $this->llm->embed($embedText, 'RETRIEVAL_QUERY')];
    }

    /**
     * @param  Collection<int, object{role: string, content: string}>|\Illuminate\Database\Eloquent\Collection<int, Message>  $history
     */
    private function buildExtractInput(string $messageText, $history): string
    {
        if ($history->isEmpty()) {
            return $messageText;
        }

        $lines = [];
        foreach ($history as $row) {
            $role = $row->role === Message::ROLE_ASSISTANT ? 'assistant' : 'user';
            $lines[] = $role.': '.(string) $row->content;
        }

        return "Conversation so far:\n".implode("\n", $lines)."\n\nCurrent user message:\n".$messageText;
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
     */
    private function mergeFilters(SearchFilters $extracted, array $input): SearchFilters
    {
        $messageText = trim((string) ($input['message'] ?? ''));
        $split = $this->attributeConstraints->split($messageText, $extracted->attributes);

        return new SearchFilters(
            storeId: isset($input['store_id']) ? (int) $input['store_id'] : $extracted->storeId,
            storeName: isset($input['store_name']) && trim((string) $input['store_name']) !== ''
                ? trim((string) $input['store_name'])
                : $extracted->storeName,
            categoryName: isset($input['category_name']) && trim((string) $input['category_name']) !== ''
                ? trim((string) $input['category_name'])
                : $extracted->categoryName,
            inStock: array_key_exists('in_stock', $input) && $input['in_stock'] !== null
                ? (bool) $input['in_stock']
                : $extracted->inStock,
            priceMin: isset($input['price_min']) ? (float) $input['price_min'] : $extracted->priceMin,
            priceMax: isset($input['price_max']) ? (float) $input['price_max'] : $extracted->priceMax,
            query: $extracted->query ?: $messageText,
            // Soft re-rank uses non-required attrs; size / exclusive go to requiredAttributes.
            attributes: $split['soft'],
            requiredAttributes: $split['required'],
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
     * @param  list<array<string, mixed>>  $products
     */
    private function formatCandidates(array $products): string
    {
        if ($products === []) {
            return '(none)';
        }

        $lines = [];
        foreach ($products as $i => $product) {
            $n = $i + 1;
            $price = $product['price'] ?? null;
            $priceLabel = $price !== null && $price !== ''
                ? rtrim(rtrim(number_format((float) $price, 2, '.', ''), '0'), '.').' ₽'
                : 'цена не указана';
            $store = trim((string) ($product['store_name'] ?? ''));
            if ($store === '') {
                $store = isset($product['store_id']) ? 'id '.$product['store_id'] : 'не указан';
            }
            $attrs = $this->formatCandidateAttributes(
                is_array($product['attributes'] ?? null) ? $product['attributes'] : []
            );
            $line = sprintf(
                '%d. id:%s | %s | %s | магазин: %s',
                $n,
                (string) ($product['product_id'] ?? ''),
                $product['name'] ?? '',
                $priceLabel,
                $store,
            );
            if ($attrs !== '') {
                $line .= ' | '.$attrs;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{name?: string, value?: string}|mixed>  $attributes
     */
    private function formatCandidateAttributes(array $attributes): string
    {
        $parts = [];
        foreach ($attributes as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }
            $parts[] = $name.': '.$value;
        }

        return $parts === [] ? '' : implode('; ', $parts);
    }
}
