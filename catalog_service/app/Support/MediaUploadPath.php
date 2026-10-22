<?php

namespace App\Support;

use Illuminate\Support\Str;

final class MediaUploadPath
{
    public static function new(string $extension): string
    {
        $extension = ltrim($extension, '.');

        return 'uploads/'.Str::uuid().'.'.$extension;
    }
}
