<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_catalog', function (Blueprint $table): void {
            $table->string('name')->nullable()->after('store_id');
            $table->text('description')->nullable()->after('name');
            $table->unsignedBigInteger('category_id')->nullable()->index()->after('description');
            $table->string('category_name')->nullable()->after('category_id');
            $table->string('image_url')->nullable()->after('category_name');
            $table->decimal('price', 10, 2)->unsigned()->default(0)->after('image_url');
            $table->boolean('in_stock')->default(true)->index()->after('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('product_catalog', function (Blueprint $table): void {
            $table->dropColumn([
                'name',
                'description',
                'category_id',
                'category_name',
                'image_url',
                'price',
                'in_stock',
            ]);
        });
    }
};
