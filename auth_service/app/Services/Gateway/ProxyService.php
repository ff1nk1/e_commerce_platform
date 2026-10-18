<?php

namespace App\Services\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class ProxyService
{
    /** @var list<string> */
    private const JSON_FORWARD_HEADERS = ['Content-Type', 'Idempotency-Key'];

    /** @var list<string> */
    private const RAW_FORWARD_HEADERS = ['Content-Type', 'Stripe-Signature'];

    /** @var list<string> */
    private const HTML_PASSTHROUGH_HEADERS = [
        'Accept',
        'Accept-Language',
        'Content-Type',
        'X-Livewire',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
        'Referer',
        'Origin',
        'Cookie',
    ];

    /** @var list<string> */
    private const HTML_RESPONSE_HEADERS = [
        'Content-Type',
        'Content-Disposition',
        'Cache-Control',
        'Pragma',
        'Expires',
        'Location',
    ];

    /** @var list<string> */
    private const QUERY_ONLY_METHODS = ['get', 'delete', 'head'];

    /**
     * Join a configured path prefix with an optional route suffix.
     * joinPath('/api/catalog', 'products/1') => '/api/catalog/products/1'
     * joinPath('/api/catalog', '') => '/api/catalog'
     */
    public function joinPath(string $prefix, string $suffix = ''): string
    {
        $prefix = '/'.trim($prefix, '/');
        $suffix = trim($suffix, '/');

        return $suffix === '' ? $prefix : $prefix.'/'.$suffix;
    }

    /**
     * Build absolute upstream URL from service base URL and path.
     */
    public function buildUrl(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    /**
     * Forward the incoming request to an internal microservice and return its response.
     *
     * @param  array{id: int, email?: string|null, name?: string|null}|null  $injectUser
     *                                                                                    When set, merges user_id (and email/name when present) into query and JSON body.
     */
    public function forward(Request $request, string $baseUrl, string $path, ?array $injectUser = null, int $timeout = 30): Response
    {
        $url = $this->buildUrl($baseUrl, $path);
        $method = strtolower($request->method());
        $query = $request->query();
        $body = $request->all();

        if ($injectUser !== null) {
            [$query, $body] = $this->mergeInjectedUser($query, $body, $injectUser);
        }

        $pending = Http::withHeaders($this->jsonForwardHeaders($request))
            ->acceptJson()
            ->asJson()
            ->timeout($timeout);

        $options = in_array($method, self::QUERY_ONLY_METHODS, true)
            ? ['query' => $query]
            : ['json' => $body];

        /** @var HttpResponse $upstream */
        $upstream = $pending->send($method, $url, $options);

        return response($upstream->body(), $upstream->status())
            ->withHeaders($this->jsonResponseHeaders($upstream));
    }

    /**
     * Forward raw HTML / Livewire / asset requests (Filament panel) with identity headers.
     *
     * @param  array{id: int, role: string, name?: string, email?: string}  $identity
     */
    public function forwardHtml(Request $request, string $baseUrl, string $path, array $identity): Response
    {
        $url = $this->buildUrl($baseUrl, $path);

        if ($query = $request->getQueryString()) {
            $url .= '?'.$query;
        }

        $headers = array_merge(
            $this->htmlIdentityHeaders($request, $identity),
            $this->htmlPassthroughHeaders($request),
        );

        $upstream = $this->sendHtml($request, $url, $headers);

        return $this->htmlResponse($upstream);
    }

    /**
     * Forward raw request body (e.g. Stripe webhooks) without re-encoding JSON.
     */
    public function forwardRaw(Request $request, string $baseUrl, string $path): Response
    {
        $url = $this->buildUrl($baseUrl, $path);

        $headers = [
            'Accept' => 'application/json',
            'X-Forwarded-For' => $request->ip() ?? '',
            'X-Forwarded-Proto' => $request->getScheme(),
        ];

        foreach (self::RAW_FORWARD_HEADERS as $name) {
            if ($request->hasHeader($name)) {
                $headers[$name] = (string) $request->header($name);
            }
        }

        /** @var HttpResponse $upstream */
        $upstream = Http::withHeaders(array_filter($headers))
            ->withBody($request->getContent(), (string) ($request->header('Content-Type') ?: 'application/json'))
            ->timeout(30)
            ->send($request->method(), $url);

        return response($upstream->body(), $upstream->status())
            ->withHeaders($this->jsonResponseHeaders($upstream));
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @param  array{id: int, email?: string|null, name?: string|null}  $injectUser
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function mergeInjectedUser(array $query, array $body, array $injectUser): array
    {
        $query['user_id'] = $injectUser['id'];
        $body['user_id'] = $injectUser['id'];

        foreach (['email', 'name'] as $field) {
            $value = $injectUser[$field] ?? null;
            if (is_string($value) && $value !== '') {
                $query[$field] = $value;
                $body[$field] = $value;
            }
        }

        return [$query, $body];
    }

    /**
     * @return array<string, string>
     */
    private function jsonForwardHeaders(Request $request): array
    {
        $headers = [
            'Accept' => 'application/json',
            'X-Forwarded-For' => $request->ip() ?? '',
            'X-Forwarded-Proto' => $request->getScheme(),
        ];

        foreach (self::JSON_FORWARD_HEADERS as $name) {
            if ($request->hasHeader($name)) {
                $headers[$name] = (string) $request->header($name);
            }
        }

        return array_filter($headers);
    }

    /**
     * @return array<string, string>
     */
    private function jsonResponseHeaders(HttpResponse $upstream): array
    {
        $contentType = $upstream->header('Content-Type');

        return $contentType ? ['Content-Type' => $contentType] : [];
    }

    /**
     * @param  array{id: int, role: string, name?: string, email?: string}  $identity
     * @return array<string, string>
     */
    private function htmlIdentityHeaders(Request $request, array $identity): array
    {
        $publicHost = $request->getHost();
        $publicPort = $request->getPort();
        $hostHeader = $publicHost;
        if ($publicPort && ! in_array((int) $publicPort, [80, 443], true)) {
            $hostHeader .= ':'.$publicPort;
        }

        $headers = [
            'Host' => $hostHeader,
            'X-User-Id' => (string) $identity['id'],
            'X-User-Role' => (string) $identity['role'],
            'X-Forwarded-For' => $request->ip() ?? '',
            'X-Forwarded-Proto' => $request->getScheme(),
            'X-Forwarded-Host' => $publicHost,
            'X-Forwarded-Port' => (string) $publicPort,
        ];

        if (! empty($identity['name'])) {
            $headers['X-User-Name'] = (string) $identity['name'];
        }

        if (! empty($identity['email'])) {
            $headers['X-User-Email'] = (string) $identity['email'];
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function htmlPassthroughHeaders(Request $request): array
    {
        $headers = [];

        foreach (self::HTML_PASSTHROUGH_HEADERS as $name) {
            if (! $request->headers->has($name)) {
                continue;
            }

            if ($name === 'Cookie') {
                $stripped = $this->stripAuthCookiesFromHeader((string) $request->header('Cookie'));
                if ($stripped !== '') {
                    $headers[$name] = $stripped;
                }

                continue;
            }

            $headers[$name] = (string) $request->header($name);
        }

        return $headers;
    }

    /**
     * Do not forward JWT cookies upstream — catalog only needs its own session/XSRF cookies.
     */
    private function stripAuthCookiesFromHeader(string $cookieHeader): string
    {
        $parts = array_filter(array_map('trim', explode(';', $cookieHeader)));
        $kept = [];

        foreach ($parts as $part) {
            $name = strstr($part, '=', true);
            if ($name === false) {
                $name = $part;
            }

            if (in_array($name, ['access_token', 'refresh_token'], true)) {
                continue;
            }

            $kept[] = $part;
        }

        return implode('; ', $kept);
    }

    /**
     * @param  array<string, string|null>  $headers
     */
    private function sendHtml(Request $request, string $url, array $headers): HttpResponse
    {
        $method = strtoupper($request->method());
        $files = $request->allFiles();

        // multipart/form-data leaves php://input empty after PHP parses $_FILES —
        // rebuild the body from uploaded files instead of getContent().
        if ($files !== [] && ! in_array($method, ['GET', 'HEAD'], true)) {
            return $this->sendMultipart($request, $url, $headers, $files);
        }

        $pending = Http::withHeaders($this->nonEmptyHeaders($headers))
            ->withOptions(['allow_redirects' => false])
            ->timeout(60);

        $content = $request->getContent();
        $contentType = (string) ($request->header('Content-Type') ?: 'application/octet-stream');

        if ($content !== '' && ! in_array($method, ['GET', 'HEAD'], true)) {
            return $pending->withBody($content, $contentType)->send($method, $url);
        }

        return $pending->send($method, $url);
    }

    /**
     * @param  array<string, string|null>  $headers
     * @param  array<string, mixed>  $files
     */
    private function sendMultipart(Request $request, string $url, array $headers, array $files): HttpResponse
    {
        unset($headers['Content-Type']);

        $pending = Http::withHeaders($this->nonEmptyHeaders($headers))
            ->withOptions(['allow_redirects' => false])
            ->timeout(60);

        $this->attachFilesRecursive($pending, $files);

        // attach() sets bodyFormat to multipart; form fields merge with pending files in send().
        return $pending->send(strtoupper($request->method()), $url, [
            'multipart' => $request->request->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $files
     */
    private function attachFilesRecursive(PendingRequest $pending, array $files, string $prefix = ''): void
    {
        foreach ($files as $key => $file) {
            $name = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";

            if (is_array($file)) {
                $this->attachFilesRecursive($pending, $file, $name);

                continue;
            }

            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $pending->attach(
                $name,
                file_get_contents($file->getRealPath()) ?: '',
                $file->getClientOriginalName(),
                ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream'],
            );
        }
    }

    /**
     * @param  array<string, string|null>  $headers
     * @return array<string, string>
     */
    private function nonEmptyHeaders(array $headers): array
    {
        return array_filter($headers, fn ($value) => $value !== null && $value !== '');
    }

    private function htmlResponse(HttpResponse $upstream): Response
    {
        $response = response($upstream->body(), $upstream->status());
        $psr = $upstream->toPsrResponse();

        foreach (self::HTML_RESPONSE_HEADERS as $name) {
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
