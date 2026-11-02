<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Stripe\HttpClient\ClientInterface;

/**
 * Recording Stripe transport double: stores the last request and returns a
 * canned v1 JSON body so the SDK's object conversion path runs unchanged.
 */
final class StripeRecordingHttpClient implements ClientInterface
{
    /** @var array{method: string, url: string, headers: list<string>}|null */
    public ?array $lastRequest = null;

    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(private readonly array $response) {}

    /**
     * @param  'delete'|'get'|'post'  $method
     * @param  string  $absUrl
     * @param  list<string>  $headers
     * @param  array<string, mixed>  $params
     * @param  bool  $hasFile
     * @param  'v1'|'v2'  $apiMode
     * @param  int|null  $maxNetworkRetries
     * @return array{0: string, 1: int, 2: array<string, mixed>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $this->lastRequest = [
            'method' => $method,
            'url' => $absUrl,
            'headers' => $headers,
        ];

        $body = json_encode($this->response + [
            'object' => 'checkout.session',
            'api_version' => '2024-06-20',
        ], JSON_THROW_ON_ERROR);

        return [$body, 200, ['request-id' => 'req_fake', 'idempotency-key' => 'ik_fake']];
    }
}
