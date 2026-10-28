<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Resources\ProductResource;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Mockery;
use Tests\TestCase;

final class PrepareProductPayloadTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_prepare_product_payload_extracts_uuid_keyed_temporary_upload(): void
    {
        Storage::fake('s3');

        $tempPath = tempnam(sys_get_temp_dir(), 'prodimg');
        file_put_contents($tempPath, 'fake-image-bytes');

        $file = Mockery::mock(TemporaryUploadedFile::class);
        $file->shouldReceive('getClientOriginalExtension')->andReturn('jpg');
        $file->shouldReceive('getRealPath')->andReturn($tempPath);
        $file->shouldReceive('getMimeType')->andReturn('image/jpeg');

        $payload = ProductResource::prepareProductPayload([
            'name' => 'Camera',
            'price' => 100,
            'store_id' => 1,
            'category_id' => 2,
            'description' => 'desc',
            'image' => ['a1b2c3d4-e5f6-7890-abcd-ef1234567890' => $file],
        ]);

        $this->assertArrayNotHasKey('image', $payload);
        $this->assertArrayHasKey('image_url', $payload);
        $this->assertIsString($payload['image_url']);
        $this->assertStringContainsString('/uploads/', $payload['image_url']);
        $this->assertStringEndsWith('.jpg', $payload['image_url']);

        File::delete($tempPath);
    }

    public function test_prepare_product_payload_keeps_existing_image_when_no_upload(): void
    {
        $payload = ProductResource::prepareProductPayload([
            'name' => 'Camera',
            'price' => 100,
            'store_id' => 1,
            'category_id' => 2,
            'image' => null,
        ], '/storage/media/uploads/old.jpg');

        $this->assertSame('/storage/media/uploads/old.jpg', $payload['image_url']);
    }
}
