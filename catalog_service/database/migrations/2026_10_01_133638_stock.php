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
        Schema::create('stocks', function (Blueprint $table): void {
            $table->id();

            // Привязка к товару
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            // Привязка к магазину/складу
            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            // Общий физический остаток на складе
            $table->unsignedInteger('quantity')->default(0);

            // Зарезервированный остаток (под оформляемые заказы)
            $table->unsignedInteger('reserved')->default(0);

            $table->timestamps();

            // Уникальный индекс: у одного магазина может быть только одна запись остатков для конкретного товара
            $table->unique(['product_id', 'store_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
