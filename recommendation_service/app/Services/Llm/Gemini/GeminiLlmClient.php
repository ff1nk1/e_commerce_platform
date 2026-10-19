<?php

namespace App\Services\Llm\Gemini;

use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\ChatMessage;
use App\Services\Llm\DTOs\SearchFilters;
use App\Services\Llm\Exceptions\LlmException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GeminiLlmClient implements LlmClient
{
    private const FILTER_SYSTEM_PROMPT = <<<'PROMPT'
You extract e-commerce search filters from a user message (and optional prior conversation turns).
When prior turns are provided, carry forward sticky filters (product type, store, budget, attributes)
unless the current message clearly replaces or clears them (e.g. a new product type).
Return ONLY a JSON object with these keys (use null when unknown):
- store_id: integer store id ONLY if the user explicitly gave a numeric store id; otherwise null (do not invent)
- store_name: store name string as mentioned by the user (e.g. "ТЦ Галерея"), or null
- category_name: category name string as mentioned by the user, or null (never invent IDs)
- in_stock: boolean or null
- price_min: number or null
- price_max: number or null
- query: short product-type text for semantic retrieval (e.g. "джинсы", "ноутбук"), without colors/sizes/materials, or null
- attributes: array of {"name": string, "value": string} using Russian catalog names when possible
  (Цвет, Размер, Материал, Бренд, …), or []
Put color/size/material/brand into attributes, not into query.
For exact size requests use numeric value only (e.g. "28", not "28 размер").
Words like только/исключительно/именно do not change the JSON shape — still put constraints into attributes.
Example: "найди черные джинсы" → query="джинсы", attributes=[{"name":"Цвет","value":"чёрный"}]
Example: "синие джинсы исключительно 28 размера" → query="джинсы", attributes=[{"name":"Цвет","value":"синий"},{"name":"Размер","value":"28"}]
Example: "товары из ТЦ Галерея" → store_name="ТЦ Галерея", query=null
Example follow-up after jeans: "а синие?" → query="джинсы", attributes=[{"name":"Цвет","value":"синий"}]
Only extract values clearly implied by the user or prior turns.
PROMPT;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $chatModel,
        private readonly string $embeddingModel,
        private readonly float $temperature,
        private readonly int $embeddingDimensions,
    ) {}

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
            $this->http()->post($this->endpoint($this->embeddingModel, 'embedContent'), $payload)
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

        $response = $this->http()
            ->post($this->endpoint($this->chatModel, 'generateContent'), $payload);

        if (! $response->successful()) {
            throw new LlmException(sprintf(
                'Gemini chat failed (%s): %s',
                $response->status(),
                $response->body()
            ));
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            throw new LlmException('Gemini chat response missing candidate text');
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
            ],
        ];

        return $this->parseExtractFiltersResponse(
            $this->http()->post($this->endpoint($this->chatModel, 'generateContent'), $payload)
        );
    }

    public function extractFiltersAndEmbed(string $userMessage, string $embedTaskType = 'RETRIEVAL_QUERY'): array
    {
        // Embed after extract so the vector uses cleaned query text, not the raw utterance.
        $filters = $this->extractFilters($userMessage);
        $embedText = $filters->query ?: $userMessage;

        return [$filters, $this->embed($embedText, $embedTaskType)];
    }

    private function http(): PendingRequest
    {
        $this->ensureApiKey();

        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'x-goog-api-key' => $this->apiKey,
            ])
            ->timeout(60);
    }

    private function ensureApiKey(): void
    {
        if ($this->apiKey === '') {
            throw new LlmException('GEMINI_API_KEY is not configured');
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
            throw new LlmException(sprintf(
                'Gemini embed failed (%s): %s',
                $response->status(),
                $response->body()
            ));
        }

        $values = $response->json('embedding.values');
        if (! is_array($values) || $values === []) {
            throw new LlmException('Gemini embed response missing embedding.values');
        }

        return array_map(static fn (mixed $v): float => (float) $v, array_values($values));
    }

    private function parseExtractFiltersResponse(Response $response): SearchFilters
    {
        if (! $response->successful()) {
            throw new LlmException(sprintf(
                'Gemini extractFilters failed (%s): %s',
                $response->status(),
                $response->body()
            ));
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            throw new LlmException('Gemini extractFilters response missing JSON text');
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new LlmException('Gemini extractFilters returned invalid JSON');
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
            if (! $message instanceof ChatMessage) {
                throw new LlmException('chat() expects a list of ChatMessage');
            }

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
            throw new LlmException('chat() requires at least one user/assistant message');
        }

        return [$contents, implode("\n\n", $systemBits)];
    }
}
