<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Category;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CategoryDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Category $category,
    ) {}

    public function topic(): string
    {
        return 'category.deleted';
    }
}
