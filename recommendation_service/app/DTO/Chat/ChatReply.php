<?php

declare(strict_types=1);

namespace App\DTO\Chat;

use App\DTO\Catalog\ProductCard;

final readonly class ChatReply
{
    /**
     * @param  list<ProductCard>  $products
     */
    public function __construct(
        public string $conversationId,
        public string $answer,
        public ExtractedFilters $extractedFilters,
        public array $products,
    ) {}
}
