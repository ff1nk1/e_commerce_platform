<?php

namespace Tests\Fakes;

use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\SearchFilters;

class FakeLlmClient implements LlmClient
{
    public string $lastChatAnswer = 'Here are some products you might like.';

    public SearchFilters $filters;

    public int $extractCalls = 0;

    public int $embedCalls = 0;

    public int $extractAndEmbedCalls = 0;

    public function __construct()
    {
        $this->filters = new SearchFilters(query: 'sneakers');
    }

    public function embed(string $text, string $taskType = 'RETRIEVAL_DOCUMENT'): array
    {
        $this->embedCalls++;

        return array_fill(0, 768, 0.01);
    }

    public function chat(array $messages, ?string $systemPrompt = null): string
    {
        return $this->lastChatAnswer;
    }

    public function extractFilters(string $userMessage): SearchFilters
    {
        $this->extractCalls++;

        return $this->filters;
    }

    public function extractFiltersAndEmbed(string $userMessage, string $embedTaskType = 'RETRIEVAL_QUERY'): array
    {
        $this->extractAndEmbedCalls++;

        return [$this->filters, array_fill(0, 768, 0.01)];
    }
}
