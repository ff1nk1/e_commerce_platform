<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\Chat\ChatExtractionResult;
use App\DTO\Chat\ChatMessage;
use App\DTO\Chat\SearchFilters;
use App\Enums\EmbedTask;

interface LlmClient
{
    /**
     * Cap total HTTP wait for one chat turn (extract + embed + chat).
     * Implementations without a shared budget may treat this as a no-op.
     */
    public function startBudget(?int $seconds = null): void;

    public function clearBudget(): void;

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
     */
    public function extractFiltersAndEmbed(
        string $userMessage,
        EmbedTask $embedTaskType = EmbedTask::RetrievalQuery,
        ?string $embedText = null,
        array $catalogAttributeNames = [],
    ): ChatExtractionResult;
}
