<?php

declare(strict_types=1);

namespace App\DTO\Chat;

final readonly class ChatTurn
{
    public function __construct(
        public string $userId,
        public string $message,
        public ?string $conversationId = null,
        public ?int $storeId = null,
        public ?string $storeName = null,
        public ?string $categoryName = null,
        public ?bool $inStock = null,
        public ?float $priceMin = null,
        public ?float $priceMax = null,
        public ?int $limit = null,
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
     */
    public static function fromArray(array $input): self
    {
        $storeName = isset($input['store_name']) ? trim((string) $input['store_name']) : '';
        $categoryName = isset($input['category_name']) ? trim((string) $input['category_name']) : '';

        return new self(
            userId: (string) $input['user_id'],
            message: trim((string) $input['message']),
            conversationId: isset($input['conversation_id']) ? (string) $input['conversation_id'] : null,
            storeId: isset($input['store_id']) ? (int) $input['store_id'] : null,
            storeName: $storeName !== '' ? $storeName : null,
            categoryName: $categoryName !== '' ? $categoryName : null,
            inStock: array_key_exists('in_stock', $input) && $input['in_stock'] !== null
                ? (bool) $input['in_stock']
                : null,
            priceMin: isset($input['price_min']) ? (float) $input['price_min'] : null,
            priceMax: isset($input['price_max']) ? (float) $input['price_max'] : null,
            limit: isset($input['limit']) ? (int) $input['limit'] : null,
        );
    }
}
