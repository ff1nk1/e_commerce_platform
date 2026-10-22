<?php

namespace App\Http\Controllers;

use App\Http\Requests\GetUploadUrlRequest;
use App\Support\MediaPublicUrl;
use App\Support\MediaUploadPath;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    public function getUploadUrl(GetUploadUrlRequest $request)
    {
        $validated = $request->validated();
        $contentType = $validated['content_type'];
        $extension = GetUploadUrlRequest::CONTENT_TYPE_EXTENSIONS[$contentType];
        $path = MediaUploadPath::new($extension);

        /** @var AwsS3V3Adapter $s3Disk */
        $s3Disk = Storage::disk('s3');
        $internalUrl = $s3Disk->temporaryUploadUrl(
            $path,
            now()->addMinutes(5),
            ['ContentType' => $contentType]
        );

        $internalUrl = (string) ($internalUrl['url'] ?? $internalUrl[0] ?? json_encode($internalUrl));

        return response()->json([
            // Internal MinIO URL for server-side PUT (admin Docker network)
            'upload_url' => $internalUrl,
            'file_path' => $path,
            'public_url' => MediaPublicUrl::forPath($path),
        ]);
    }
}
