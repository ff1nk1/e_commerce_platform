<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages\ManageProducts;
use App\Models\Product;
use App\Models\Store;
use App\Services\ProductService;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = 'Products';

    protected static ?string $modelLabel = 'Product';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('price')->numeric()->required(),
                Select::make('store_id')
                    ->label('Store')
                    ->options(fn (): array => Store::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                TextInput::make('category_id')->numeric()->required()->label('Category ID'),
                Textarea::make('description'),
                FileUpload::make('image')
                    ->label('Photo')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                    ->maxSize(5120)
                    ->storeFiles(false)
                    ->nullable(),
                Repeater::make('attributes')
                    ->label('Attributes')
                    ->schema([
                        TextInput::make('name')->label('Name')->required()->maxLength(255),
                        TextInput::make('value')->label('Value')->required()->maxLength(255),
                    ])
                    ->columns(2)
                    ->default([])
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => ($state['name'] ?? null)
                        ? (($state['name'] ?? '').': '.($state['value'] ?? ''))
                        : null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                ImageColumn::make('image_url')
                    ->label('Image')
                    ->getStateUsing(fn (Product $record): ?string => static::publicImageUrl($record->image_url))
                    ->height(48),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('price')->sortable(),
                TextColumn::make('store.name')->label('Store')->placeholder('—'),
                TextColumn::make('category_id')->label('Category'),
                ToggleColumn::make('is_published')
                    ->label('AI')
                    ->tooltip(fn (Product $record): string => $record->is_published
                        ? 'Visible for AI — click to unpublish'
                        : 'Hidden from AI — click to publish')
                    ->updateStateUsing(function (Product $record): bool {
                        $updated = app(ProductService::class)->togglePublished($record);

                        Notification::make()
                            ->title($updated->is_published
                                ? 'Product published for AI'
                                : 'Product unpublished from AI')
                            ->success()
                            ->send();

                        return (bool) $updated->is_published;
                    }),
            ])
            ->filters([
                SelectFilter::make('store_id')
                    ->label('Store')
                    ->options(fn (): array => Store::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data): Product => app(ProductService::class)->createProduct(
                        static::prepareProductPayload($data)
                    )),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data, Product $record): array {
                        $data['attributes'] = $record->attributes()
                            ->get(['name', 'value'])
                            ->map(fn ($attr): array => [
                                'name' => (string) $attr->name,
                                'value' => (string) $attr->value,
                            ])
                            ->values()
                            ->all();

                        return $data;
                    })
                    ->using(fn (Product $record, array $data): Product => app(ProductService::class)->updateProduct(
                        $record,
                        static::prepareProductPayload($data, $record->image_url)
                    )),
                DeleteAction::make()
                    ->using(function (Product $record): bool {
                        app(ProductService::class)->deleteProduct($record);

                        return true;
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProducts::route('/'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareProductPayload(array $data, ?string $existingImageUrl = null): array
    {
        $image = $data['image'] ?? null;
        unset($data['image']);

        $file = static::resolveUploadedFile($image);

        if ($file instanceof TemporaryUploadedFile) {
            $data['image_url'] = static::uploadImage($file);
        } elseif (is_string($existingImageUrl) && $existingImageUrl !== '') {
            $data['image_url'] = $existingImageUrl;
        }

        return $data;
    }

    private static function resolveUploadedFile(mixed $image): ?TemporaryUploadedFile
    {
        if ($image instanceof TemporaryUploadedFile) {
            return $image;
        }

        if (is_string($image) && $image !== '') {
            try {
                return TemporaryUploadedFile::createFromLivewire($image);
            } catch (\Throwable) {
                return null;
            }
        }

        if (is_array($image)) {
            foreach (Arr::flatten($image) as $item) {
                $resolved = static::resolveUploadedFile($item);
                if ($resolved instanceof TemporaryUploadedFile) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    private static function uploadImage(TemporaryUploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $path = 'uploads/'.Str::uuid().'.'.$extension;

        Storage::disk('s3')->put($path, file_get_contents($file->getRealPath()) ?: '', [
            'ContentType' => $file->getMimeType() ?: 'application/octet-stream',
        ]);

        $bucket = (string) config('filesystems.disks.s3.bucket', 'media');
        $publicBase = rtrim((string) config('filesystems.media_public_base', '/storage'), '/');

        return "{$publicBase}/{$bucket}/{$path}";
    }

    public static function publicImageUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $base = rtrim((string) config('app.url'), '/');
        $path = str_starts_with($url, '/') ? $url : '/'.$url;

        return $base.$path;
    }
}
