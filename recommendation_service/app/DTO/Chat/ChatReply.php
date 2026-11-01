<?php

declare(strict_types=1);

namespace App\DTO\Chat;

final readonly class ChatReply
{
    /**
     * @param  array<string, mixed>  $extractedFilters
     * @param  list<array<string, mixed>>  $products
     */
    public function __construct(
        public string $conversationId,
        public string $answer,
        public array $extractedFilters,
        public array $products,
    ) {}

    /**
     * @return array{
     *     conversation_id: string,
     *     answer: string,
     *     extracted_filters: array<string, mixed>,
     *     products: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'answer' => $this->answer,
            'extracted_filters' => $this->extractedFilters,
            'products' => $this->products,
        ];
    }
}
