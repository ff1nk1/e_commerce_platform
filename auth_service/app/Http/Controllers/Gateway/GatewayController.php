<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gateway;

use App\DTO\Auth\GatewayIdentity;
use App\DTO\Auth\InjectedGatewayUser;
use App\DTO\Gateway\ProxyOptions;
use App\Enums\ProxyMode;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\LogoutUserService;
use App\Services\Auth\TokenCookies;
use App\Services\Gateway\ForwardRequestService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class GatewayController extends Controller
{
    private const RECOMMENDATION_TIMEOUT = 120;

    public function __construct(
        private ForwardRequestService $forwarder,
        private LogoutUserService $logoutUser,
        private TokenCookies $tokenCookies,
    ) {}

    public function catalog(Request $request, string $path = ''): Response
    {
        return $this->forwardService($request, 'catalog', 'storefront', $path);
    }

    public function catalogAdmin(Request $request, string $path = ''): Response
    {
        return $this->forwardService($request, 'catalog', 'admin_api', $path, new ProxyOptions(
            identity: $this->gatewayIdentity($request),
        ));
    }

    public function catalogAdminPanel(Request $request, string $path = ''): Response
    {
        $response = $this->forwardService($request, 'catalog', 'admin_panel', $path, new ProxyOptions(
            mode: ProxyMode::Html,
            identity: $this->gatewayIdentity($request),
        ));

        // Filament POST /admin-catalog/logout — revoke JWT so the panel cannot be re-entered
        // with the same cookies after the catalog clears its session.
        if ($this->isPanelLogout($request, $path) && $response->getStatusCode() < 400) {
            $this->logoutUser->handle(
                $this->tokenCookies->accessTokenFrom($request),
                $this->tokenCookies->refreshTokenFrom($request),
            );

            return $this->tokenCookies->clear($response);
        }

        return $response;
    }

    public function uploadUrl(Request $request): Response
    {
        return $this->forwardService($request, 'catalog', 'upload_url', '', new ProxyOptions(
            identity: $this->gatewayIdentity($request),
        ));
    }

    public function notifications(Request $request, string $path = ''): Response
    {
        return $this->forwardService($request, 'notification', 'index', $path, new ProxyOptions(
            identity: $this->gatewayIdentity($request),
        ));
    }

    public function orders(Request $request, string $path = ''): Response
    {
        return $this->forwardService($request, 'order', 'customer', $path, new ProxyOptions(
            injectUser: InjectedGatewayUser::fromUser($this->authenticatedUser($request)),
        ));
    }

    public function ordersAdmin(Request $request, string $path = ''): Response
    {
        return $this->forwardService($request, 'order', 'admin', $path, new ProxyOptions(
            identity: $this->gatewayIdentity($request),
        ));
    }

    public function stripeWebhook(Request $request): Response
    {
        return $this->forwardService($request, 'order', 'stripe_webhook', '', new ProxyOptions(
            mode: ProxyMode::Raw,
        ));
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
        return $this->forwardService($request, 'recommendation', 'health', '', new ProxyOptions(
            timeout: self::RECOMMENDATION_TIMEOUT,
        ));
    }

    public function recommendations(Request $request, string $path = ''): Response
    {
        return $this->forwardService($request, 'recommendation', 'api', $path, new ProxyOptions(
            injectUser: InjectedGatewayUser::fromUser($this->authenticatedUser($request)),
            timeout: self::RECOMMENDATION_TIMEOUT,
        ));
    }

    public function recommendationsReindex(Request $request): Response
    {
        return $this->forwardService($request, 'recommendation', 'reindex', '', new ProxyOptions(
            timeout: self::RECOMMENDATION_TIMEOUT,
        ));
    }

    private function forwardService(
        Request $request,
        string $service,
        string $pathKey,
        string $path = '',
        ProxyOptions $options = new ProxyOptions,
    ): Response {
        $baseUrl = (string) config("services.{$service}.url");
        $prefix = (string) config("services.{$service}.paths.{$pathKey}");

        return $this->forwarder->forward(
            $request,
            $baseUrl,
            $this->forwarder->joinPath($prefix, $path),
            $options,
        );
    }

    private function isPanelLogout(Request $request, string $path): bool
    {
        if (strtoupper($request->method()) !== 'POST') {
            return false;
        }

        $normalizedPath = trim($path, '/');

        return $request->is('admin-catalog/logout')
            || $normalizedPath === 'logout'
            || str_ends_with($normalizedPath, '/logout');
    }

    private function gatewayIdentity(Request $request): GatewayIdentity
    {
        return GatewayIdentity::fromUser($this->authenticatedUser($request));
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
