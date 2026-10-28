<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ProductPublishStateChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Product $product,
        public readonly string $topic,
    ) {}
}
