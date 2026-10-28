<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\StockResource\Pages\ManageStocks;
use App\Models\Stock;
use App\Models\Store;
use App\Services\StockService;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class StockResource extends Resource
{
    protected static ?string $model = Stock::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'Stocks';

    protected static ?string $modelLabel = 'Stock';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('product_id')->label('Product ID')->numeric()->required(),
                Select::make('store_id')
                    ->label('Store')
                    ->options(fn (): array => Store::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                TextInput::make('quantity')->numeric()->required()->minValue(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->placeholder(fn (Stock $record): string => 'Product #'.$record->product_id),
                TextColumn::make('product_id')->label('Product ID'),
                TextColumn::make('store.name')
                    ->label('Store')
                    ->placeholder(fn (Stock $record): string => 'Store #'.$record->store_id),
                TextColumn::make('quantity'),
                TextColumn::make('reserved'),
                TextColumn::make('available'),
            ])
            ->filters([
                SelectFilter::make('store_id')
                    ->label('Store')
                    ->options(fn (): array => Store::query()->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('product_id')
                    ->schema([
                        TextInput::make('product_id')
                            ->label('Product ID')
                            ->numeric(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            filled($data['product_id'] ?? null),
                            fn (Builder $q): Builder => $q->where('product_id', (int) $data['product_id'])
                        );
                    })
                    ->indicateUsing(fn (array $state): ?string => filled($state['product_id'] ?? null)
                        ? 'Product ID: '.$state['product_id']
                        : null),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data): Stock => resolve(StockService::class)->createStock([
                        'product_id' => (int) $data['product_id'],
                        'store_id' => (int) $data['store_id'],
                        'quantity' => (int) $data['quantity'],
                    ])),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Edit quantity')
                    ->schema([
                        TextInput::make('quantity')->numeric()->required()->minValue(0),
                    ])
                    ->using(fn (Stock $record, array $data): Stock => resolve(StockService::class)->updateQuantity(
                        $record,
                        (int) $data['quantity']
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStocks::route('/'),
        ];
    }
}
