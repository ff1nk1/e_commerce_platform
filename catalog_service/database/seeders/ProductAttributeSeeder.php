<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\ProductService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds product attributes and republishes products to Kafka for OpenSearch
 * and recommendation_service (product_catalog + embeddings).
 *
 * If OpenSearch still has a non-nested `attributes` mapping (Dashboards shows "!"),
 * recreate the index first in search_service:
 *   php artisan opensearch:setup --recreate --force
 * then re-run this seeder (or RepublishProductsSeeder).
 */
class ProductAttributeSeeder extends Seeder
{
    /**
     * Атрибуты по имени категории (конечные ветки из ShopSeeder).
     *
     * @var array<string, list<array{name: string, values: list<string>}>>
     */
    private array $templates = [
        'Ноутбуки' => [
            ['name' => 'Процессор', 'values' => ['Intel Core i5', 'Intel Core i7', 'AMD Ryzen 5', 'AMD Ryzen 7']],
            ['name' => 'ОЗУ', 'values' => ['8 ГБ', '16 ГБ', '32 ГБ']],
            ['name' => 'Накопитель', 'values' => ['256 ГБ SSD', '512 ГБ SSD', '1 ТБ SSD']],
            ['name' => 'Диагональ', 'values' => ['13.3"', '14"', '15.6"', '16"']],
            ['name' => 'Вес', 'values' => ['1.2 кг', '1.5 кг', '1.8 кг', '2.1 кг']],
        ],
        'Мониторы' => [
            ['name' => 'Диагональ', 'values' => ['24"', '27"', '32"', '34"']],
            ['name' => 'Разрешение', 'values' => ['1920x1080', '2560x1440', '3840x2160']],
            ['name' => 'Частота', 'values' => ['60 Гц', '75 Гц', '144 Гц', '165 Гц']],
            ['name' => 'Тип матрицы', 'values' => ['IPS', 'VA', 'OLED']],
        ],
        'Смартфоны' => [
            ['name' => 'Экран', 'values' => ['6.1"', '6.4"', '6.7"']],
            ['name' => 'Память', 'values' => ['128 ГБ', '256 ГБ', '512 ГБ']],
            ['name' => 'Камера', 'values' => ['12 Мп', '48 Мп', '50 Мп + 12 Мп']],
            ['name' => 'Аккумулятор', 'values' => ['4000 мА·ч', '4500 мА·ч', '5000 мА·ч']],
            ['name' => 'ОС', 'values' => ['Android 14', 'Android 15', 'iOS 18']],
        ],
        'Куртки' => [
            ['name' => 'Размер', 'values' => ['S', 'M', 'L', 'XL']],
            ['name' => 'Цвет', 'values' => ['Чёрный', 'Синий', 'Хаки', 'Серый']],
            ['name' => 'Материал', 'values' => ['Полиэстер', 'Нейлон', 'Хлопок']],
            ['name' => 'Бренд', 'values' => ['NorthPeak', 'UrbanWear', 'TrailCo']],
        ],
        'Футболки' => [
            ['name' => 'Размер', 'values' => ['S', 'M', 'L', 'XL']],
            ['name' => 'Цвет', 'values' => ['Белый', 'Чёрный', 'Серый', 'Синий']],
            ['name' => 'Материал', 'values' => ['Хлопок 100%', 'Хлопок/эластан', 'Органический хлопок']],
            ['name' => 'Бренд', 'values' => ['BasicLine', 'UrbanWear', 'DailyFit']],
        ],
        'Платья' => [
            ['name' => 'Размер', 'values' => ['XS', 'S', 'M', 'L']],
            ['name' => 'Цвет', 'values' => ['Чёрный', 'Красный', 'Бежевый', 'Зелёный']],
            ['name' => 'Материал', 'values' => ['Вискоза', 'Шёлк', 'Хлопок']],
            ['name' => 'Бренд', 'values' => ['ElleMood', 'SoftLine', 'Atelier']],
        ],
        'Джинсы' => [
            ['name' => 'Размер', 'values' => ['28', '30', '32', '34']],
            ['name' => 'Цвет', 'values' => ['Синий', 'Чёрный', 'Светло-синий']],
            ['name' => 'Материал', 'values' => ['Деним', 'Стрейч-деним']],
            ['name' => 'Бренд', 'values' => ['DenimCo', 'UrbanWear', 'RawFit']],
        ],
    ];

    /**
     * @var list<array{name: string, values: list<string>}>
     */
    private array $defaultTemplate = [
        ['name' => 'Бренд', 'values' => ['Generic', 'ProLine', 'ValueTech']],
        ['name' => 'Цвет', 'values' => ['Чёрный', 'Белый', 'Серый', 'Серебристый']],
        ['name' => 'Страна производства', 'values' => ['Китай', 'Вьетнам', 'Тайвань', 'Германия']],
        ['name' => 'Гарантия', 'values' => ['12 мес.', '24 мес.', '36 мес.']],
    ];

    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        ProductAttribute::truncate();
        Schema::enableForeignKeyConstraints();

        $products = Product::with('category')->get();

        foreach ($products as $product) {
            $categoryName = $product->category?->name;
            $template = $this->templates[$categoryName] ?? $this->defaultTemplate;

            $rows = [];
            foreach ($template as $attr) {
                $rows[] = [
                    'product_id' => $product->id,
                    'name' => $attr['name'],
                    'value' => $attr['values'][array_rand($attr['values'])],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            ProductAttribute::insert($rows);
        }

        $service = app(ProductService::class);
        $service->clearProductCache();

        // Publish product.updated so search_service and recommendation_service reindex.
        Product::query()
            ->with(['store', 'category', 'attributes'])
            ->orderBy('id')
            ->each(fn (Product $product) => $service->updateProduct($product, [
                'name' => $product->name,
            ]));

        $this->command->info(sprintf(
            'Seeded attributes and republished %d products to Kafka.',
            Product::query()->count()
        ));
    }
}
