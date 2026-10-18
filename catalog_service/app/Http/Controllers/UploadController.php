<?php

namespace App\Http\Controllers;

use App\Http\Requests\GetUploadUrlRequest;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    public function getUploadUrl(GetUploadUrlRequest $request)
    {
        $validated = $request->validated();
        $contentType = $validated['content_type'];
        $extension = GetUploadUrlRequest::CONTENT_TYPE_EXTENSIONS[$contentType];
        $fileName = Str::uuid().'.'.$extension;
        $path = 'uploads/'.$fileName;

        /** @var AwsS3V3Adapter $s3Disk */
        $s3Disk = Storage::disk('s3');
        $internalUrl = $s3Disk->temporaryUploadUrl(
            $path,
            now()->addMinutes(5),
            ['ContentType' => $contentType]
        );

        if (is_array($internalUrl)) {
            $internalUrl = $internalUrl['url'] ?? $internalUrl[0] ?? (string) json_encode($internalUrl);
        }
        $internalUrl = (string) $internalUrl;

        $bucket = (string) config('filesystems.disks.s3.bucket', 'media');
        $publicBase = rtrim((string) config('filesystems.media_public_base', '/storage'), '/');

        return response()->json([
            // Internal MinIO URL for server-side PUT (admin Docker network)
            'upload_url' => $internalUrl,
            'file_path' => $path,
            'public_url' => "{$publicBase}/{$bucket}/{$path}",
        ]);
    }
}
