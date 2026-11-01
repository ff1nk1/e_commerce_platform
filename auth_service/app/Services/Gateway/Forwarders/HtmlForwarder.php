<?php

declare(strict_types=1);

namespace App\Services\Gateway\Forwarders;

use App\DTO\Auth\GatewayIdentity;
use App\DTO\Gateway\ProxyOptions;
use App\Services\Auth\TokenCookies;
use App\Services\Gateway\UpstreamHeaders;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Forwards HTML / Livewire / asset requests (Filament panel) with identity headers.
 */
final class HtmlForwarder implements Forwarder
{
    private const TIMEOUT = 60;

    /** @var list<string> */
    private const PASSTHROUGH_HEADERS = [
        'Accept',
        'Accept-Language',
        'Content-Type',
        'X-Livewire',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
        'Referer',
        'Origin',
    ];

    /** @var list<string> */
    private const RESPONSE_HEADERS = [
        'Content-Type',
        'Content-Disposition',
        'Cache-Control',
        'Pragma',
        'Expires',
        'Location',
    ];

    /** @var list<string> */
    private const BODYLESS_METHODS = ['GET', 'HEAD'];

    /** @var list<string> */
    private const AUTH_COOKIES = [TokenCookies::ACCESS_COOKIE, TokenCookies::REFRESH_COOKIE];

    private const DEFAULT_HTTP_PORTS = [80, 443];

    public function __construct(
        private UpstreamHeaders $headers,
    ) {}

    public function forward(Request $request, string $url, ProxyOptions $options): Response
    {
        if ($query = $request->getQueryString()) {
            $url .= '?'.$query;
        }

        $headers = array_merge(
            $this->identityHeaders($request, $options->identity ?? new GatewayIdentity(id: 0, role: '')),
            $this->passthroughHeaders($request),
        );

        return $this->response($this->send($request, $url, $headers));
    }

    /**
     * @return array<string, string>
     */
    private function identityHeaders(Request $request, GatewayIdentity $identity): array
    {
        $publicHost = $request->getHost();
        $publicPort = $request->getPort();
        $hostHeader = $publicHost;
        if ($publicPort && ! in_array((int) $publicPort, self::DEFAULT_HTTP_PORTS, true)) {
            $hostHeader .= ':'.$publicPort;
        }

        return array_merge(
            [
                'Host' => $hostHeader,
                'X-Forwarded-Host' => $publicHost,
                'X-Forwarded-Port' => (string) $publicPort,
            ],
            $this->headers->forwarded($request),
            $identity->toHeaders(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function passthroughHeaders(Request $request): array
    {
        $headers = $this->headers->copyWhitelisted($request, self::PASSTHROUGH_HEADERS);

        if ($request->headers->has('Cookie')) {
            $stripped = $this->stripAuthCookies((string) $request->header('Cookie'));
            if ($stripped !== '') {
                $headers['Cookie'] = $stripped;
            }
        }

        return $headers;
    }

    /**
     * Do not forward JWT cookies upstream — catalog only needs its own session/XSRF cookies.
     */
    private function stripAuthCookies(string $cookieHeader): string
    {
        $parts = array_filter(array_map('trim', explode(';', $cookieHeader)));
        $kept = [];

        foreach ($parts as $part) {
            $name = strstr($part, '=', true);
            if ($name === false) {
                $name = $part;
            }

            if (in_array($name, self::AUTH_COOKIES, true)) {
                continue;
            }

            $kept[] = $part;
        }

        return implode('; ', $kept);
    }

    /**
     * @param  array<string, string|null>  $headers
     */
    private function send(Request $request, string $url, array $headers): HttpResponse
    {
        $method = strtoupper($request->method());
        $files = $request->allFiles();
        $hasBody = ! in_array($method, self::BODYLESS_METHODS, true);

        // multipart/form-data leaves php://input empty after PHP parses $_FILES —
        // rebuild the body from uploaded files instead of getContent().
        if ($files !== [] && $hasBody) {
            return $this->sendMultipart($request, $method, $url, $headers, $files);
        }

        $pending = $this->pending($headers);

        $content = $request->getContent();
        $contentType = (string) ($request->header('Content-Type') ?: 'application/octet-stream');

        if ($content !== '' && $hasBody) {
            return $pending->withBody($content, $contentType)->send($method, $url);
        }

        return $pending->send($method, $url);
    }

    /**
     * @param  array<string, string|null>  $headers
     * @param  array<string, mixed>  $files
     */
    private function sendMultipart(Request $request, string $method, string $url, array $headers, array $files): HttpResponse
    {
        unset($headers['Content-Type']);

        $pending = $this->pending($headers);

        $this->attachFilesRecursive($pending, $files);

        // attach() sets bodyFormat to multipart; form fields merge with pending files in send().
        return $pending->send($method, $url, [
            'multipart' => $request->request->all(),
        ]);
    }

    /**
     * @param  array<string, string|null>  $headers
     */
    private function pending(array $headers): PendingRequest
    {
        return Http::withHeaders($this->headers->nonEmpty($headers))
            ->withOptions(['allow_redirects' => false])
            ->timeout(self::TIMEOUT);
    }

    /**
     * @param  array<string, mixed>  $files
     */
    private function attachFilesRecursive(PendingRequest $pending, array $files, string $prefix = ''): void
    {
        foreach ($files as $key => $file) {
            $multipartFieldName = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";

            if (is_array($file)) {
                $this->attachFilesRecursive($pending, $file, $multipartFieldName);

                continue;
            }

            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $pending->attach(
                $multipartFieldName,
                file_get_contents($file->getRealPath()) ?: '',
                $file->getClientOriginalName(),
                ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream'],
            );
        }
    }

    private function response(HttpResponse $upstream): Response
    {
        $response = $this->headers->response($upstream);
        $psr = $upstream->toPsrResponse();

        foreach (self::RESPONSE_HEADERS as $name) {
            foreach ($psr->getHeader($name) as $value) {
                $response->headers->set($name, $value, false);
            }
        }

        foreach ($psr->getHeader('Set-Cookie') as $cookie) {
            $response->headers->set('Set-Cookie', $cookie, false);
        }

        return $response;
    }
}
