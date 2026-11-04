<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the acting identity from the trusted gateway headers.
 *
 * Identity arrives only as `X-User-*` headers (the gateway no longer mirrors it
 * into the body/query). A request without a header (internal callers, tests) is
 * left untouched so the scoped FormRequests can still validate explicit values.
 *
 * Header values win over the body: a client cannot forge the buyer contact.
 */
final class ResolveGatewayIdentity
{
    private const IDENTITY_HEADERS = [
        'X-User-Id' => 'user_id',
        'X-User-Email' => 'email',
        'X-User-Name' => 'name',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $identity = [];

        foreach (self::IDENTITY_HEADERS as $header => $field) {
            $value = $request->header($header);
            if (is_string($value) && trim($value) !== '') {
                $identity[$field] = trim($value);
            }
        }

        if ($identity !== []) {
            $request->merge($identity);
        }

        return $next($request);
    }
}
