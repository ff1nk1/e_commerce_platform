<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\DTO\Gateway\ProxyOptions;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forwards an incoming request to an internal microservice using the
 * strategy selected by {@see ProxyOptions::$mode}.
 */
final class ForwardRequestService
{
    public function __construct(
        private Container $container,
    ) {}

    public function forward(
        Request $request,
        string $baseUrl,
        string $path,
        ProxyOptions $options = new ProxyOptions,
    ): Response {
        $url = rtrim($baseUrl, '/').'/'.ltrim($path, '/');

        return $this->container
            ->make($options->mode->forwarder())
            ->forward($request, $url, $options);
    }

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
}
