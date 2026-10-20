<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Category extends Model
{
    use HasFactory;

    /**
     * Атрибуты, доступные для массового заполнения.
     *
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'name',
        'slug',
    ];

    /**
     * Получить родительскую категорию.
     *
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * Получить дочерние категории (только первый уровень).
     *
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * Получить все дочерние категории рекурсивно (всё дерево вниз).
     *
     * @return HasMany<Category, $this>
     */
    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        // У одной категории может быть много товаров
        return $this->hasMany(Product::class);
    }

    /**
     * Получить ID текущей категории и всех её подкатегорий (рекурсивно)
     */
    public function getTreeIds(): array
    {
        // Кэшируем массив ID для каждой отдельной категории
        return Cache::tags(['categories'])->remember("category_tree_ids_{$this->id}", 7200, function () {

            // Начинаем с ID самой категории (например, "Компьютеры")
            $ids = [$this->id];

            // Подгружаем всё дерево подкатегорий из БД
            $this->load('allChildren');

            // Запускаем рекурсивную функцию для обхода дерева
            $collectIds = function ($categories) use (&$collectIds, &$ids) {
                foreach ($categories as $category) {
                    $ids[] = $category->id; // Добавляем ID ребенка

                    if ($category->allChildren->isNotEmpty()) {
                        $collectIds($category->allChildren); // Идем глубже
                    }
                }
            };

            $collectIds($this->allChildren);

            return $ids;
        });
    }
}
