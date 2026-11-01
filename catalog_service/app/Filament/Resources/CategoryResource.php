<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\DTO\Catalog\CategoryData;
use App\Filament\Resources\CategoryResource\Pages\ManageCategories;
use App\Models\Category;
use App\Services\Categories\CreateCategoryService;
use App\Services\Categories\DeleteCategoryService;
use App\Services\Categories\UpdateCategoryService;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Categories';

    protected static ?string $modelLabel = 'Category';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('slug')->required()->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data): Category => resolve(CreateCategoryService::class)->handle(CategoryData::fromArray($data))),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (Category $record, array $data): Category => resolve(UpdateCategoryService::class)->handle($record, CategoryData::fromArray($data))),
                DeleteAction::make()
                    ->using(function (Category $record): bool {
                        return resolve(DeleteCategoryService::class)->handle($record);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCategories::route('/'),
        ];
    }
}
