<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Media\MediaStorage;

final class MediaUploadPath
{
    public static function new(string $extension): string
    {
        return MediaStorage::newUploadPath($extension);
    }
}
