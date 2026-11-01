<?php

declare(strict_types=1);

namespace App\Services\Gateway\Forwarders;

use App\DTO\Auth\InjectedGatewayUser;
use App\DTO\Gateway\ProxyOptions;
use App\Services\Gateway\UpstreamHeaders;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

final class JsonForwarder implements Forwarder
{
    /** @var list<string> */
    private const FORWARD_HEADERS = ['Content-Type', 'Idempotency-Key'];

    /** @var list<string> */
    private const QUERY_ONLY_METHODS = ['GET', 'DELETE', 'HEAD'];

    public function __construct(
        private UpstreamHeaders $headers,
    ) {}

    public function forward(Request $request, string $url, ProxyOptions $options): Response
    {
        // Guzzle 8 sends the method verbatim. Nginx rejects lowercase methods with 400.
        $method = strtoupper($request->method());
        $query = $request->query();
        $body = $request->all();

        if ($options->injectUser instanceof InjectedGatewayUser) {
            [$query, $body] = $this->mergeInjectedUser($query, $body, $options->injectUser);
        }

        $headers = array_merge(
            ['Accept' => 'application/json'],
            $this->headers->forwarded($request),
            $this->headers->copyWhitelisted($request, self::FORWARD_HEADERS),
            $options->identity?->toHeaders() ?? [],
        );

        $pending = Http::withHeaders(array_filter($headers))
            ->acceptJson()
            ->asJson()
            ->timeout($options->timeout);

        $payload = in_array($method, self::QUERY_ONLY_METHODS, true)
            ? ['query' => $query]
            : ['json' => $body];

        /** @var HttpResponse $upstream */
        $upstream = $pending->send($method, $url, $payload);

        return $this->headers->jsonResponse($upstream);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function mergeInjectedUser(array $query, array $body, InjectedGatewayUser $injectUser): array
    {
        $query['user_id'] = $injectUser->id;
        $body['user_id'] = $injectUser->id;

        foreach (['email' => $injectUser->email, 'name' => $injectUser->name] as $field => $value) {
            if (is_string($value) && $value !== '') {
                $query[$field] = $value;
                $body[$field] = $value;
            }
        }

        return [$query, $body];
    }
}
