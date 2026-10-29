<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AuthenticateFromGatewayHeaders;
use App\Http\Responses\FilamentLogoutResponse;
use App\Messaging\CatalogInboundEventFactory;
use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Illuminate\Auth\RequestGuard;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Features\SupportFileUploads\FilePreviewController;
use Livewire\Features\SupportFileUploads\FileUploadController;
use Livewire\Livewire;
use Shop\Messaging\InboundEventFactory;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LogoutResponseContract::class, FilamentLogoutResponse::class);
        $this->app->singleton(InboundEventFactory::class, CatalogInboundEventFactory::class);
    }

    public function boot(): void
    {
        // Filament calls Guard::logout(); RequestGuard (viaRequest) does not define it.
        // Setting user=null alone is not enough: user() treats null as "not resolved yet"
        // and re-runs the gateway-headers callback against the same X-User-* headers.
        RequestGuard::macro('logout', function (): void {
            /** @var RequestGuard $guard */
            $guard = $this;
            foreach (['X-User-Id', 'X-User-Role', 'X-User-Name', 'X-User-Email'] as $header) {
                $guard->request->headers->remove($header);
            }
            $guard->user = null;
        });

        Auth::viaRequest('gateway-headers', function (Request $request): ?User {
            $userId = $request->header('X-User-Id');
            $role = $request->header('X-User-Role');

            if (! is_numeric($userId) || (int) $userId < 1 || $role !== 'admin') {
                return null;
            }

            return User::fromGatewayHeaders(
                (int) $userId,
                $role,
                $request->header('X-User-Name'),
                $request->header('X-User-Email'),
            );
        });

        // MinIO AWS_ENDPOINT is Docker-internal HTTP — browser S3 direct uploads cause
        // Mixed Content / unreachable host. Keep Livewire temps on local disk; permanent
        // product images still go to S3 in ProductResource::uploadImage().
        config([
            'livewire.temporary_file_upload.disk' => config('filesystems.livewire_temporary_disk', 'local'),
        ]);

        // Livewire signed upload URLs are built during proxied requests. Without forcing
        // APP_URL, UrlGenerator may emit http://app.localhost/...; Traefik then 301s to
        // HTTPS and the browser retries as GET → MethodNotAllowed on upload-file.
        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl !== '') {
            URL::forceRootUrl($appUrl);
            $scheme = parse_url($appUrl, PHP_URL_SCHEME);
            if (is_string($scheme) && $scheme !== '') {
                URL::forceScheme($scheme);
            }
        }

        // Keep Livewire JS on the same /admin-catalog prefix so Traefik → auth → catalog,
        // instead of a broad PathPrefix(/livewire*) catch-all.
        Livewire::setScriptRoute(function ($handle) {
            $path = config('app.debug')
                ? '/admin-catalog/livewire/livewire.js'
                : '/admin-catalog/livewire/livewire.min.js';

            return Route::get($path, $handle);
        });

        Livewire::setUpdateRoute(function ($handle) {
            return Route::post('/admin-catalog/livewire/update', $handle)
                ->middleware(['web', AuthenticateFromGatewayHeaders::class]);
        });

        $this->app->booted(function (): void {
            Route::post('/admin-catalog/livewire/upload-file', [FileUploadController::class, 'handle'])
                ->middleware(['web', AuthenticateFromGatewayHeaders::class])
                ->name('livewire.upload-file');

            Route::get('/admin-catalog/livewire/preview-file/{filename}', [FilePreviewController::class, 'handle'])
                ->middleware(['web', AuthenticateFromGatewayHeaders::class])
                ->name('livewire.preview-file');

            $this->app->booted(function (): void {
                $routes = Route::getRoutes();
                if (! $routes instanceof RouteCollection) {
                    return;
                }

                $upload = collect($routes->getRoutes())
                    ->first(fn ($route): bool => $route->uri() === 'admin-catalog/livewire/upload-file');
                $preview = collect($routes->getRoutes())
                    ->first(fn ($route): bool => $route->uri() === 'admin-catalog/livewire/preview-file/{filename}');

                (function () use ($upload, $preview): void {
                    /** @var RouteCollection $this */
                    if ($upload) {
                        $this->nameList['livewire.upload-file'] = $upload;
                    }
                    if ($preview) {
                        $this->nameList['livewire.preview-file'] = $preview;
                    }
                })->call($routes);
            });
        });
    }
}
