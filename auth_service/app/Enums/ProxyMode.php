<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Gateway\Forwarders\Forwarder;
use App\Services\Gateway\Forwarders\HtmlForwarder;
use App\Services\Gateway\Forwarders\JsonForwarder;
use App\Services\Gateway\Forwarders\RawForwarder;

enum ProxyMode: string
{
    /** Re-encode query/body as JSON (API services). */
    case Json = 'json';

    /** Pass HTML / Livewire / multipart through with identity headers (Filament panel). */
    case Html = 'html';

    /** Forward the raw body untouched (signed webhooks). */
    case Raw = 'raw';

    /**
     * @return class-string<Forwarder>
     */
    public function forwarder(): string
    {
        return match ($this) {
            self::Json => JsonForwarder::class,
            self::Html => HtmlForwarder::class,
            self::Raw => RawForwarder::class,
        };
    }
}
