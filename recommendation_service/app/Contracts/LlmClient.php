<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\Chat\ChatMessage;
use App\DTO\Chat\SearchFilters;
use App\Enums\EmbedTask;

interface LlmClient
{
    /**
     * @return list<float>
     */
    public function embed(string $text, EmbedTask $taskType = EmbedTask::RetrievalDocument): array;

    /**
     * @param  list<ChatMessage>  $messages
     */
    public function chat(array $messages, ?string $systemPrompt = null): string;

    /**
     * @param  list<string>  $catalogAttributeNames  Prefer these catalog names in extract JSON
     */
    public function extractFilters(string $userMessage, array $catalogAttributeNames = []): SearchFilters;

    /**
     * Extract filters then embed cleaned query text.
     * When $embedText is null, embeds filters.query or $userMessage.
     *
     * @param  list<string>  $catalogAttributeNames
     * @return array{0: SearchFilters, 1: list<float>}
     */
    public function extractFiltersAndEmbed(
        string $userMessage,
        EmbedTask $embedTaskType = EmbedTask::RetrievalQuery,
        ?string $embedText = null,
        array $catalogAttributeNames = [],
    ): array;
}
