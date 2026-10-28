<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index('store_id');
            $table->index('category_id');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['store_id']);
            $table->dropIndex(['category_id']);
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropIndex(['parent_id']);
        });
    }
};
