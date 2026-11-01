<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\CatalogEventType;
use App\Models\Category;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CategoryUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Category $category,
    ) {}

    public function topic(): CatalogEventType
    {
        return CatalogEventType::CategoryUpdated;
    }
}
