<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Catalog\Contracts\ProductFreshnessChecker;
use App\Services\Catalog\Contracts\RelevanceGate;
use App\Services\Catalog\DTOs\SearchHit;
use App\Services\Catalog\ProductSearchService;
use App\Services\Catalog\SearchFiltersNormalizer;
use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\ChatMessage;
use App\Services\Llm\DTOs\SearchFilters;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
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

        $history = $conversation->messages()
            ->orderByDesc('created_at')
            ->limit(self::HISTORY_LIMIT)
            ->get(['role', 'content'])
            ->reverse()
            ->values();

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => Message::ROLE_USER,
            'content' => $messageText,
        ]);

        [$extracted, $queryVector] = $this->resolveFiltersAndVector($messageText, $input);
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

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => Message::ROLE_ASSISTANT,
            'content' => $answer,
            'products' => $productCards,
        ]);

        $conversation->touchActivity();

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
     * @return array{0: SearchFilters, 1: list<float>}
     */
    private function resolveFiltersAndVector(string $messageText, array $input): array
    {
        if ($this->hasClientFilters($input)) {
            $filters = new SearchFilters(
                storeId: isset($input['store_id']) ? (int) $input['store_id'] : null,
                storeName: isset($input['store_name']) ? trim((string) $input['store_name']) ?: null : null,
                categoryName: isset($input['category_name']) ? trim((string) $input['category_name']) ?: null : null,
                inStock: array_key_exists('in_stock', $input) && $input['in_stock'] !== null
                    ? (bool) $input['in_stock']
                    : null,
                priceMin: isset($input['price_min']) ? (float) $input['price_min'] : null,
                priceMax: isset($input['price_max']) ? (float) $input['price_max'] : null,
                query: $messageText,
            );

            return [$filters, $this->llm->embed($messageText, 'RETRIEVAL_QUERY')];
        }

        $extracted = $this->llm->extractFilters($messageText);
        $embedText = $extracted->query ?: $messageText;

        return [$extracted, $this->llm->embed($embedText, 'RETRIEVAL_QUERY')];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function hasClientFilters(array $input): bool
    {
        // Store scope from UI still allows LLM extract; skip extract only for
        // explicit structured filters that replace natural-language parsing.
        foreach (['category_name', 'in_stock', 'price_min', 'price_max'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '') {
                return true;
            }
        }

        return false;
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
            query: $extracted->query ?: (string) ($input['message'] ?? ''),
            attributes: $extracted->attributes,
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
            $lines[] = sprintf(
                '%d. %s | %s | магазин: %s',
                $n,
                $product['name'] ?? '',
                $priceLabel,
                $store,
            );
        }

        return implode("\n", $lines);
    }
}
