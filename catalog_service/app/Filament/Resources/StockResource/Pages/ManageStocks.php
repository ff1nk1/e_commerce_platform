<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockResource\Pages;

use App\Filament\Resources\StockResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageStocks extends ManageRecords
{
    protected static string $resource = StockResource::class;
}
