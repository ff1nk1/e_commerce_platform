<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Media\MediaStorage;

final class MediaPublicUrl
{
    public static function forPath(string $path): string
    {
        return MediaStorage::publicUrlForPath($path);
    }

    public static function absolute(?string $url): ?string
    {
        return MediaStorage::absoluteUrl($url);
    }
}
