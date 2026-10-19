<?php

namespace App\Services\Llm\Contracts;

use App\Services\Llm\DTOs\ChatMessage;
use App\Services\Llm\DTOs\SearchFilters;

interface LlmClient
{
    /**
     * @return list<float>
     */
    public function embed(string $text, string $taskType = 'RETRIEVAL_DOCUMENT'): array;

    /**
     * @param  list<ChatMessage>  $messages
     */
    public function chat(array $messages, ?string $systemPrompt = null): string;

    public function extractFilters(string $userMessage): SearchFilters;

    /**
     * Extract filters then embed the cleaned query (or raw message when query is null).
     *
     * @return array{0: SearchFilters, 1: list<float>}
     */
    public function extractFiltersAndEmbed(string $userMessage, string $embedTaskType = 'RETRIEVAL_QUERY'): array;
}
