<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Auth\AuthService;
use App\Services\Auth\TokenCookieService;
use App\Services\Gateway\ProxyService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class GatewayController extends Controller
{
    private const RECOMMENDATION_TIMEOUT = 120;

    private const DEFAULT_TIMEOUT = 30;

    public function __construct(
        private ProxyService $proxy,
        private AuthService $authService,
        private TokenCookieService $tokenCookies,
    ) {}

    public function catalog(Request $request, string $path = ''): Response
    {
        return $this->forwardService($request, 'catalog', 'storefront', $path);
    }

    public function catalogAdmin(Request $request, string $path = ''): Response
    {
        return $this->forwardService($request, 'catalog', 'admin_api', $path, [
            'identity' => $this->gatewayIdentity($request),
        ]);
    }

    public function catalogAdminPanel(Request $request, string $path = ''): Response
    {
        $user = $request->user();

        $response = $this->forwardService($request, 'catalog', 'admin_panel', $path, [
            'mode' => 'html',
            'identity' => [
                'id' => (int) $user->id,
                'role' => (string) ($user->role->slug ?? ''),
                'name' => (string) ($user->name ?? ''),
                'email' => (string) ($user->email ?? ''),
            ],
        ]);

        // Filament POST /admin-catalog/logout — revoke JWT so the panel cannot be re-entered
        // with the same cookies after the catalog clears its session.
        $normalizedPath = rtrim(ltrim($path, '/'), '/');
        $isLogoutPath = strtoupper($request->method()) === 'POST'
            && (
                $request->is('admin-catalog/logout')
                || $normalizedPath === 'logout'
                || str_ends_with($normalizedPath, '/logout')
            );
        $upstreamSucceeded = $response->getStatusCode() < 400;

        if ($isLogoutPath && $upstreamSucceeded) {
            $accessToken = $request->bearerToken() ?: $request->cookie('access_token');
            $refreshToken = $request->cookie('refresh_token');

            $this->authService->logout(
                is_string($accessToken) && $accessToken !== '' ? $accessToken : null,
                is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            );

            return $this->tokenCookies->clear($response);
        }

        return $response;
    }

    public function uploadUrl(Request $request): Response
    {
        return $this->forwardService($request, 'catalog', 'upload_url', '', [
            'identity' => $this->gatewayIdentity($request),
        ]);
    }

    public function notifications(Request $request, string $path = ''): Response
    {
        return $this->forwardService($request, 'notification', 'index', $path);
    }

    public function orders(Request $request, string $path = ''): Response
    {
        $user = $request->user();

        return $this->forwardService($request, 'order', 'customer', $path, [
            'injectUser' => [
                'id' => (int) $user->id,
                'email' => $user->email,
                'name' => $user->name,
            ],
        ]);
    }

    public function ordersAdmin(Request $request, string $path = ''): Response
    {
        return $this->forwardService($request, 'order', 'admin', $path);
    }

    public function stripeWebhook(Request $request): Response
    {
        return $this->forwardService($request, 'order', 'stripe_webhook', '', [
            'mode' => 'raw',
        ]);
    }

    public function analytics(Request $request, string $path = ''): Response
    {
        return $this->forwardService($request, 'analytic', 'index', $path);
    }

    public function search(Request $request): Response
    {
        return $this->forwardService($request, 'search', 'search');
    }

    public function searchAutocomplete(Request $request): Response
    {
        return $this->forwardService($request, 'search', 'autocomplete');
    }

    public function recommendationsHealth(Request $request): Response
    {
        return $this->forwardService($request, 'recommendation', 'health', '', [
            'timeout' => self::RECOMMENDATION_TIMEOUT,
        ]);
    }

    public function recommendations(Request $request, string $path = ''): Response
    {
        $user = $request->user();

        return $this->forwardService($request, 'recommendation', 'api', $path, [
            'injectUser' => [
                'id' => (int) $user->id,
                'email' => $user->email,
                'name' => $user->name,
            ],
            'timeout' => self::RECOMMENDATION_TIMEOUT,
        ]);
    }

    public function recommendationsReindex(Request $request): Response
    {
        return $this->forwardService($request, 'recommendation', 'reindex', '', [
            'timeout' => self::RECOMMENDATION_TIMEOUT,
        ]);
    }

    /**
     * @param  array{
     *     mode?: 'json'|'html'|'raw',
     *     injectUser?: array{id: int, email?: string|null, name?: string|null},
     *     identity?: array{id: int, role: string, name?: string, email?: string},
     *     timeout?: int
     * }  $options
     */
    private function forwardService(
        Request $request,
        string $service,
        string $pathKey,
        string $path = '',
        array $options = [],
    ): Response {
        $baseUrl = (string) config("services.{$service}.url");
        $prefix = (string) config("services.{$service}.paths.{$pathKey}");
        $mode = $options['mode'] ?? 'json';

        if ($mode === 'raw') {
            return $this->proxy->forwardRaw($request, $baseUrl, $prefix);
        }

        $fullPath = $this->proxy->joinPath($prefix, $path);

        if ($mode === 'html') {
            return $this->proxy->forwardHtml(
                $request,
                $baseUrl,
                $fullPath,
                $options['identity'] ?? ['id' => 0, 'role' => ''],
            );
        }

        return $this->proxy->forward(
            $request,
            $baseUrl,
            $fullPath,
            $options['injectUser'] ?? null,
            (int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT),
            $options['identity'] ?? null,
        );
    }

    /**
     * @return array{id: int, role: string, name: string, email: string}
     */
    private function gatewayIdentity(Request $request): array
    {
        $user = $request->user();
        $user->loadMissing('role');

        return [
            'id' => (int) $user->id,
            'role' => (string) ($user->role->slug ?? ''),
            'name' => (string) ($user->name ?? ''),
            'email' => (string) ($user->email ?? ''),
        ];
    }
}
