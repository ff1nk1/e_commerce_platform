<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Support\Str;

final class MediaStorage
{
    public static function newUploadPath(string $extension): string
    {
        $extension = ltrim($extension, '.');

        return 'uploads/'.Str::uuid().'.'.$extension;
    }

    public static function publicUrlForPath(string $path): string
    {
        $bucket = (string) config('filesystems.disks.s3.bucket', 'media');
        $publicBase = rtrim((string) config('filesystems.media_public_base', '/storage'), '/');
        $path = ltrim($path, '/');

        return "{$publicBase}/{$bucket}/{$path}";
    }

    public static function absoluteUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $base = rtrim((string) config('app.url'), '/');
        $path = str_starts_with($url, '/') ? $url : '/'.$url;

        return $base.$path;
    }
}
