<?php

namespace App\Services\Llm\Gemini;

use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\ChatMessage;
use App\Services\Llm\DTOs\SearchFilters;
use App\Services\Llm\Exceptions\LlmException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GeminiLlmClient implements LlmClient
{
    private const FILTER_SYSTEM_PROMPT = <<<'PROMPT'
You extract e-commerce search filters from a user message (and optional prior conversation / structured filters).
Merge rules:
- KEEP sticky filters (product type/query, store, budget, attributes) from "Previous structured filters" and prior user turns
  unless the current message clearly replaces or clears them (new product type, "без фильтра", "любой цвет", etc.).
- REPLACE a field when the user gives a new value for it (e.g. "а подешевле" → lower price_max; "а синие" → update Цвет).
- CLEAR a field only when the user explicitly drops it.
Return ONLY a JSON object with these keys (use null when unknown):
- store_id: integer store id ONLY if the user explicitly gave a numeric store id; otherwise null (do not invent)
- store_name: store name string as mentioned by the user (e.g. "ТЦ Галерея"), or null
- category_name: category name string as mentioned by the user, or null (never invent IDs)
- in_stock: boolean or null
- price_min: number or null
- price_max: number or null
- query: short product-type text for semantic retrieval (e.g. "джинсы", "ноутбук"), without colors/sizes/materials, or null
- attributes: array of {"name": string, "value": string} using Russian catalog names when possible
  (Цвет, Размер, Материал, Бренд), or []
For greetings / off-topic with no product intent, set query=null and leave other fields null/empty.
Put color/size/material/brand into attributes, not into query.
For exact size requests use numeric value only (e.g. "28", not "28 размер").
Words like только/исключительно/именно do not change the JSON shape — still put constraints into attributes.
Example: "найди черные джинсы" → query="джинсы", attributes=[{"name":"Цвет","value":"чёрный"}]
Example: "синие джинсы исключительно 28 размера" → query="джинсы", attributes=[{"name":"Цвет","value":"синий"},{"name":"Размер","value":"28"}]
Example: "товары из ТЦ Галерея" → store_name="ТЦ Галерея", query=null
Example follow-up after jeans: "а синие?" → query="джинсы", attributes=[{"name":"Цвет","value":"синий"}]
Example: "привет" → query=null, attributes=[]
Only extract values clearly implied by the user or prior turns / previous structured filters.
PROMPT;

    /** @var array<string, mixed> */
    private const FILTER_RESPONSE_SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'store_id' => ['type' => 'INTEGER', 'nullable' => true],
            'store_name' => ['type' => 'STRING', 'nullable' => true],
            'category_name' => ['type' => 'STRING', 'nullable' => true],
            'in_stock' => ['type' => 'BOOLEAN', 'nullable' => true],
            'price_min' => ['type' => 'NUMBER', 'nullable' => true],
            'price_max' => ['type' => 'NUMBER', 'nullable' => true],
            'query' => ['type' => 'STRING', 'nullable' => true],
            'attributes' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING'],
                        'value' => ['type' => 'STRING'],
                    ],
                    'required' => ['name', 'value'],
                ],
            ],
        ],
        'required' => [
            'store_id',
            'store_name',
            'category_name',
            'in_stock',
            'price_min',
            'price_max',
            'query',
            'attributes',
        ],
    ];

    /** Remaining seconds for a chat turn (extract+embed+chat); null = no shared budget. */
    private ?float $budgetRemaining = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $chatModel,
        private readonly string $embeddingModel,
        private readonly float $temperature,
        private readonly int $embeddingDimensions,
        private readonly int $timeout = 12,
    ) {}

    /**
     * Cap total Gemini HTTP wait for one chat turn (does not include DB/search time).
     */
    public function startBudget(?int $seconds = null): void
    {
        $this->budgetRemaining = (float) max(1, $seconds ?? $this->timeout);
    }

    public function clearBudget(): void
    {
        $this->budgetRemaining = null;
    }

    public function embed(string $text, string $taskType = 'RETRIEVAL_DOCUMENT'): array
    {
        $payload = [
            'model' => 'models/'.$this->embeddingModel,
            'content' => [
                'parts' => [
                    ['text' => $text],
                ],
            ],
            'taskType' => $taskType,
        ];

        if ($this->embeddingDimensions > 0) {
            $payload['outputDimensionality'] = $this->embeddingDimensions;
        }

        return $this->parseEmbedResponse(
            $this->post($this->endpoint($this->embeddingModel, 'embedContent'), $payload)
        );
    }

    public function chat(array $messages, ?string $systemPrompt = null): string
    {
        [$contents, $inlineSystem] = $this->toGeminiContents($messages);
        $system = trim(implode("\n\n", array_filter([
            $systemPrompt,
            $inlineSystem !== '' ? $inlineSystem : null,
        ], static fn (?string $part): bool => $part !== null && trim($part) !== '')));

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $this->temperature,
            ],
        ];

        if ($system !== '') {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $system],
                ],
            ];
        }

        $response = $this->post($this->endpoint($this->chatModel, 'generateContent'), $payload);

        if (! $response->successful()) {
            throw LlmException::http(
                sprintf('Gemini chat failed (%s): %s', $response->status(), $response->body()),
                $response->status(),
            );
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            throw LlmException::parse('Gemini chat response missing candidate text');
        }

        return $text;
    }

    public function extractFilters(string $userMessage): SearchFilters
    {
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userMessage],
                    ],
                ],
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => self::FILTER_SYSTEM_PROMPT],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => self::FILTER_RESPONSE_SCHEMA,
            ],
        ];

        return $this->parseExtractFiltersResponse(
            $this->post($this->endpoint($this->chatModel, 'generateContent'), $payload)
        );
    }

    public function extractFiltersAndEmbed(
        string $userMessage,
        string $embedTaskType = 'RETRIEVAL_QUERY',
        ?string $embedText = null,
    ): array {
        // Embed after extract so the vector uses cleaned query text, not the raw utterance.
        $filters = $this->extractFilters($userMessage);
        $text = $filters->query ?: ($embedText ?? $userMessage);

        return [$filters, $this->embed($text, $embedTaskType)];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $endpoint, array $payload): Response
    {
        $timeout = $this->effectiveTimeout();
        $started = microtime(true);

        try {
            return $this->http($timeout)->post($endpoint, $payload);
        } catch (ConnectionException $e) {
            $isTimeout = str_contains(strtolower($e->getMessage()), 'timed out')
                || str_contains(strtolower($e->getMessage()), 'timeout');

            throw LlmException::connection(
                sprintf('Gemini request failed: %s', $e->getMessage()),
                $isTimeout ? 504 : null,
                $e,
            );
        } finally {
            if ($this->budgetRemaining !== null) {
                $this->budgetRemaining -= microtime(true) - $started;
            }
        }
    }

    private function effectiveTimeout(): int
    {
        if ($this->budgetRemaining === null) {
            return $this->timeout;
        }

        if ($this->budgetRemaining <= 0.05) {
            throw LlmException::connection('Gemini request budget exceeded', 504);
        }

        return max(1, (int) ceil($this->budgetRemaining));
    }

    private function http(int $timeout): PendingRequest
    {
        $this->ensureApiKey();

        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'x-goog-api-key' => $this->apiKey,
            ])
            ->connectTimeout(min(5, $timeout))
            ->timeout($timeout);
    }

    private function ensureApiKey(): void
    {
        if ($this->apiKey === '') {
            throw LlmException::configuration('GEMINI_API_KEY is not configured');
        }
    }

    private function endpoint(string $model, string $method): string
    {
        return '/models/'.ltrim($model, '/').':'.$method;
    }

    /**
     * @return list<float>
     */
    private function parseEmbedResponse(Response $response): array
    {
        if (! $response->successful()) {
            throw LlmException::http(
                sprintf('Gemini embed failed (%s): %s', $response->status(), $response->body()),
                $response->status(),
            );
        }

        $values = $response->json('embedding.values');
        if (! is_array($values) || $values === []) {
            throw LlmException::parse('Gemini embed response missing embedding.values');
        }

        return array_map(static fn (mixed $v): float => (float) $v, array_values($values));
    }

    private function parseExtractFiltersResponse(Response $response): SearchFilters
    {
        if (! $response->successful()) {
            throw LlmException::http(
                sprintf('Gemini extractFilters failed (%s): %s', $response->status(), $response->body()),
                $response->status(),
            );
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            throw LlmException::parse('Gemini extractFilters response missing JSON text');
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw LlmException::parse('Gemini extractFilters returned invalid JSON');
        }

        return SearchFilters::fromArray($decoded);
    }

    /**
     * @param  list<ChatMessage>  $messages
     * @return array{0: list<array{role: string, parts: list<array{text: string}>}>, 1: string}
     */
    private function toGeminiContents(array $messages): array
    {
        $contents = [];
        $systemBits = [];

        foreach ($messages as $message) {
            if ($message->role === ChatMessage::ROLE_SYSTEM) {
                $systemBits[] = $message->content;

                continue;
            }

            $role = $message->role === ChatMessage::ROLE_ASSISTANT ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [
                    ['text' => $message->content],
                ],
            ];
        }

        if ($contents === []) {
            throw LlmException::parse('chat() requires at least one user/assistant message');
        }

        return [$contents, implode("\n\n", $systemBits)];
    }
}
