<?php

declare(strict_types=1);

namespace App\Enums;

enum LlmErrorKind: string
{
    case Http = 'http';
    case Parse = 'parse';
    case Configuration = 'configuration';
    case Connection = 'connection';
}
