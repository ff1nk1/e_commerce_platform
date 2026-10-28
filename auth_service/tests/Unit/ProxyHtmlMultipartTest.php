<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Gateway\ProxyService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ProxyHtmlMultipartTest extends TestCase
{
    public function test_forward_html_rebuilds_multipart_when_files_present(): void
    {
        Http::fake([
            '*' => Http::response('ok', 200, ['Content-Type' => 'text/plain']),
        ]);

        $file = UploadedFile::fake()->create('product.jpg', 20, 'image/jpeg');

        $request = Request::create(
            '/admin-catalog/livewire/upload-file',
            'POST',
            ['component' => 'test'],
            [],
            ['files' => [$file]],
            [
                'HTTP_HOST' => 'app.localhost',
                'HTTP_ACCEPT' => '*/*',
            ],
        );

        $response = resolve(ProxyService::class)->forwardHtml(
            $request,
            'http://catalog.test',
            '/admin-catalog/livewire/upload-file',
            [
                'id' => 7,
                'role' => 'admin',
                'name' => 'Yan',
                'email' => 'yan@example.com',
            ],
        );

        $this->assertSame(200, $response->getStatusCode());

        Http::assertSent(function ($httpRequest): bool {
            if (! str_contains($httpRequest->url(), '/admin-catalog/livewire/upload-file')) {
                return false;
            }

            if (! $httpRequest->isMultipart()) {
                return false;
            }

            $body = $httpRequest->body();

            return str_contains($body, 'product.jpg')
                && $httpRequest->header('X-User-Id') === ['7']
                && $httpRequest->header('X-User-Role') === ['admin'];
        });
    }

    public function test_forward_html_still_forwards_raw_json_body(): void
    {
        Http::fake([
            '*' => Http::response('{"ok":true}', 200, ['Content-Type' => 'application/json']),
        ]);

        $payload = json_encode(['components' => []], JSON_THROW_ON_ERROR);

        $request = Request::create(
            '/admin-catalog/livewire/update',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_HOST' => 'app.localhost',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $payload,
        );

        $response = resolve(ProxyService::class)->forwardHtml(
            $request,
            'http://catalog.test',
            '/admin-catalog/livewire/update',
            [
                'id' => 7,
                'role' => 'admin',
            ],
        );

        $this->assertSame(200, $response->getStatusCode());

        Http::assertSent(function ($httpRequest) use ($payload): bool {
            return str_contains($httpRequest->url(), '/admin-catalog/livewire/update')
                && $httpRequest->body() === $payload;
        });
    }

    public function test_forward_html_strips_jwt_cookies_from_upstream(): void
    {
        Http::fake([
            '*' => Http::response('ok', 200, ['Content-Type' => 'text/html']),
        ]);

        $request = Request::create(
            '/admin-catalog',
            'GET',
            [],
            [
                'access_token' => 'jwt-access',
                'refresh_token' => 'jwt-refresh',
                'catalogservice-session' => 'filament-session',
            ],
            [],
            [
                'HTTP_HOST' => 'app.localhost',
                'HTTP_ACCEPT' => 'text/html',
                'HTTP_COOKIE' => 'access_token=jwt-access; refresh_token=jwt-refresh; catalogservice-session=filament-session',
            ],
        );

        resolve(ProxyService::class)->forwardHtml(
            $request,
            'http://catalog.test',
            '/admin-catalog',
            ['id' => 1, 'role' => 'admin'],
        );

        Http::assertSent(function ($httpRequest): bool {
            $cookie = $httpRequest->header('Cookie')[0] ?? '';

            return str_contains($httpRequest->url(), '/admin-catalog')
                && str_contains($cookie, 'catalogservice-session=filament-session')
                && ! str_contains($cookie, 'access_token=')
                && ! str_contains($cookie, 'refresh_token=');
        });
    }
}
