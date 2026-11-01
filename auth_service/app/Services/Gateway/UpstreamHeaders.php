<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as LaravelResponse;

/**
 * Header helpers shared by all gateway forwarders.
 */
final class UpstreamHeaders
{
    /**
     * @return array<string, string>
     */
    public function forwarded(Request $request): array
    {
        return [
            'X-Forwarded-For' => $request->ip() ?? '',
            'X-Forwarded-Proto' => $request->getScheme(),
        ];
    }

    /**
     * Cookie is never copied here — forwarders that need it filter it explicitly.
     *
     * @param  array<int|string, string>  $allow
     * @return array<string, string>
     */
    public function copyWhitelisted(Request $request, array $allow): array
    {
        $headers = [];

        foreach ($allow as $name) {
            if ($name === 'Cookie' || ! $request->hasHeader($name)) {
                continue;
            }

            $headers[$name] = (string) $request->header($name);
        }

        return $headers;
    }

    /**
     * @param  array<string, string|null>  $headers
     * @return array<string, string>
     */
    public function nonEmpty(array $headers): array
    {
        return array_filter($headers, fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * Upstream body + status, with Content-Type copied when present.
     */
    public function jsonResponse(HttpResponse $upstream): LaravelResponse
    {
        $response = $this->response($upstream);
        $contentType = $upstream->header('Content-Type');

        return $contentType ? $response->withHeaders(['Content-Type' => $contentType]) : $response;
    }

    public function response(HttpResponse $upstream): LaravelResponse
    {
        return response($upstream->body(), $upstream->status());
    }
}
