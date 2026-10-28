<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Tests\TestCase;

final class AdminCatalogGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_catalog_rejects_request_without_gateway_headers(): void
    {
        $this->get('/admin-catalog')->assertForbidden();
    }

    public function test_admin_catalog_rejects_non_admin_role_header(): void
    {
        $this->withHeaders([
            'X-User-Id' => '1',
            'X-User-Role' => 'customer',
        ])->get('/admin-catalog')->assertForbidden();
    }

    public function test_admin_catalog_allows_admin_gateway_headers(): void
    {
        $response = $this->withHeaders([
            'X-User-Id' => '42',
            'X-User-Role' => 'admin',
            'X-User-Name' => 'Yan Admin',
            'X-User-Email' => 'yan@example.com',
        ])->get('/admin-catalog');

        $this->assertNotSame(403, $response->status());
        $this->assertNotSame(401, $response->status());
        $this->assertTrue(in_array($response->status(), [200, 302], true));
    }

    public function test_livewire_script_is_served_under_admin_catalog_prefix(): void
    {
        $this->get('/admin-catalog/livewire/livewire.js')
            ->assertOk();
    }

    public function test_livewire_temporary_uploads_use_local_disk_not_s3(): void
    {
        $this->assertSame('local', config('livewire.temporary_file_upload.disk'));
        $this->assertFalse(FileUploadConfiguration::isUsingS3());

        $uploadUrl = resolve(GenerateSignedUploadUrl::class)->forLocal();

        $this->assertStringStartsWith('https://', $uploadUrl);
        $this->assertStringContainsString('/admin-catalog/livewire/upload-file', $uploadUrl);
        $this->assertStringNotContainsString('catalog-minio', $uploadUrl);
        $this->assertStringNotContainsString(':9000', $uploadUrl);
        $this->assertStringNotContainsString('http://app.localhost', $uploadUrl);
    }
}
