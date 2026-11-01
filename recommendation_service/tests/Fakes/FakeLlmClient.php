<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\LlmClient;
use App\DTO\Chat\ChatMessage;
use App\DTO\Chat\SearchFilters;
use App\Enums\EmbedTask;

final class FakeLlmClient implements LlmClient
{
    public string $lastChatAnswer = 'Here are some products you might like.';

    public SearchFilters $filters;

    public int $extractCalls = 0;

    public int $embedCalls = 0;

    public int $extractAndEmbedCalls = 0;

    public string $lastExtractInput = '';

    /** @var list<string> */
    public array $lastCatalogAttributeNames = [];

    /** @var list<ChatMessage> */
    public array $lastChatMessages = [];

    public ?string $lastChatSystemPrompt = null;

    /**
     * When set, replaces the default embedding vector. May throw.
     *
     * @var (\Closure(string, EmbedTask): list<float>)|null
     */
    public ?\Closure $embedHandler = null;

    public function __construct()
    {
        $this->filters = new SearchFilters(query: 'sneakers');
    }

    public function embed(string $text, EmbedTask $taskType = EmbedTask::RetrievalDocument): array
    {
        $this->embedCalls++;

        if ($this->embedHandler instanceof \Closure) {
            return ($this->embedHandler)($text, $taskType);
        }

        return array_fill(0, 768, 0.01);
    }

    public function chat(array $messages, ?string $systemPrompt = null): string
    {
        $this->lastChatMessages = $messages;
        $this->lastChatSystemPrompt = $systemPrompt;

        return $this->lastChatAnswer;
    }

    public function extractFilters(string $userMessage, array $catalogAttributeNames = []): SearchFilters
    {
        $this->extractCalls++;
        $this->lastExtractInput = $userMessage;
        $this->lastCatalogAttributeNames = $catalogAttributeNames;

        return $this->filters;
    }

    public function extractFiltersAndEmbed(
        string $userMessage,
        EmbedTask $embedTaskType = EmbedTask::RetrievalQuery,
        ?string $embedText = null,
        array $catalogAttributeNames = [],
    ): array {
        $this->extractAndEmbedCalls++;
        $this->lastExtractInput = $userMessage;
        $this->lastCatalogAttributeNames = $catalogAttributeNames;

        return [$this->filters, array_fill(0, 768, 0.01)];
    }
}
