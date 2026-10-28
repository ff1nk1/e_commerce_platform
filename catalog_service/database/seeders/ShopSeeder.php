<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Stock;
use App\Models\Store;
use App\Services\CatalogService;
use App\Services\ProductService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ShopSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Очищаем таблицы перед сидированием, чтобы избежать дублей
        // Отключаем проверку внешних ключей на время очистки
        Schema::disableForeignKeyConstraints();
        Stock::query()->truncate();
        ProductAttribute::query()->truncate();
        Product::query()->truncate();
        Category::query()->truncate();
        Store::query()->truncate();
        Schema::enableForeignKeyConstraints();

        // Truncate bypasses CatalogService/ProductService — drop stale Redis tags now.
        resolve(CatalogService::class)->clearCategoryCache();
        resolve(ProductService::class)->clearProductCache();

        // 2. Создаем магазины
        $storesData = ['Главный склад', 'Магазин на Ленина', 'ТЦ Галерея', 'Точка выдачи'];
        $stores = collect();

        foreach ($storesData as $storeName) {
            // Предполагается, что в модели Store есть поле name.
            // Если там другие поля - добавьте их сюда.
            $stores->push(Store::query()->create([
                'name' => $storeName,
            ]));
        }

        // 3. Создаем дерево категорий (вложенная структура)
        $categoriesTree = [
            [
                'name' => 'Электроника',
                'children' => [
                    [
                        'name' => 'Компьютеры',
                        'children' => [
                            ['name' => 'Ноутбуки'],
                            ['name' => 'Мониторы'],
                            ['name' => 'Комплектующие'],
                        ],
                    ],
                    [
                        'name' => 'Смартфоны и гаджеты',
                        'children' => [
                            ['name' => 'Смартфоны'],
                            ['name' => 'Умные часы'],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Одежда и обувь',
                'children' => [
                    [
                        'name' => 'Мужская',
                        'children' => [
                            ['name' => 'Куртки'],
                            ['name' => 'Футболки'],
                        ],
                    ],
                    [
                        'name' => 'Женская',
                        'children' => [
                            ['name' => 'Платья'],
                            ['name' => 'Джинсы'],
                        ],
                    ],
                ],
            ],
        ];

        // Рекурсивная функция для создания категорий и их детей
        $createCategories = function ($categories, $parentId = null) use (&$createCategories): void {
            foreach ($categories as $catData) {
                $category = Category::query()->create([
                    'name' => $catData['name'],
                    'slug' => Str::slug($catData['name']),
                    'parent_id' => $parentId,
                ]);

                // Если есть дочерние элементы - вызываем функцию снова
                if (isset($catData['children'])) {
                    $createCategories($catData['children'], $category->id);
                }
            }
        };

        $createCategories($categoriesTree);

        // 4. Генерируем товары для конечных категорий (у которых нет подкатегорий)
        // Товары логичнее привязывать к самым нижним веткам дерева (например, к "Ноутбуки", а не к "Электроника")
        $leafCategories = Category::query()->doesntHave('children')->get();

        foreach ($leafCategories as $category) {
            // Создаем по 5 товаров для каждой конечной категории
            for ($i = 1; $i <= 5; $i++) {
                Product::query()->create([
                    // Привязываем к случайному магазину из созданных ранее
                    'store_id' => $stores->random()->id,
                    'category_id' => $category->id,
                    'name' => "{$category->name} ".Str::random(5)." - Модель {$i}",
                    'description' => "Это подробное описание для потрясающего товара из категории {$category->name}. Отличное качество и гарантия.",
                    'price' => rand(1000, 150000) / 10, // Случайная цена от 100.0 до 15000.0
                    'image_url' => 'https://placehold.co/600x400/png?text='.urlencode($category->name),
                ]);
            }
        }

        // Drop anything warmed while this seeder wrote via Model::create.
        resolve(CatalogService::class)->clearCategoryCache();
        resolve(ProductService::class)->clearProductCache();
    }
}
