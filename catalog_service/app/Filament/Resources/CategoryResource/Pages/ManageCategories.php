<?php

declare(strict_types=1);

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageCategories extends ManageRecords
{
    protected static string $resource = CategoryResource::class;
}
