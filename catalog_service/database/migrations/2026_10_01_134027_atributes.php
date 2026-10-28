<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_attributes', function (Blueprint $table): void {
            $table->id();

            // Связь с товаром
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->string('name');  // Название (например: "Процессор", "Цвет", "Вес")
            $table->string('value'); // Значение (например: "Intel i7", "Синий", "1.5 кг")

            $table->timestamps();

            // Индекс для быстрого поиска всех атрибутов конкретного товара
            $table->index(['product_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_attributes');
    }
};
