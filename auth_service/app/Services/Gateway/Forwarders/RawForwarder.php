<?php

declare(strict_types=1);

namespace App\Services\Gateway\Forwarders;

use App\DTO\Gateway\ProxyOptions;
use App\Services\Gateway\UpstreamHeaders;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

/**
 * Forwards the raw request body (e.g. Stripe webhooks) without re-encoding JSON,
 * so upstream signature checks see the exact bytes.
 */
final class RawForwarder implements Forwarder
{
    /** @var list<string> */
    private const FORWARD_HEADERS = ['Content-Type', 'Stripe-Signature'];

    public function __construct(
        private UpstreamHeaders $headers,
    ) {}

    public function forward(Request $request, string $url, ProxyOptions $options): Response
    {
        $headers = array_merge(
            ['Accept' => 'application/json'],
            $this->headers->forwarded($request),
            $this->headers->copyWhitelisted($request, self::FORWARD_HEADERS),
        );

        /** @var HttpResponse $upstream */
        $upstream = Http::withHeaders(array_filter($headers))
            ->withBody($request->getContent(), (string) ($request->header('Content-Type') ?: 'application/json'))
            ->timeout($options->timeout)
            ->send($request->method(), $url);

        return $this->headers->jsonResponse($upstream);
    }
}
