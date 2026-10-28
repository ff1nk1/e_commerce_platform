<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_catalog', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->unique();
            $table->unsignedBigInteger('store_id')->index();
            $table->jsonb('attributes')->default(DB::raw("'[]'::jsonb"));
            $table->boolean('is_published')->default(true)->index();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE product_catalog ADD COLUMN embedding vector(1536) NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_catalog');
    }
};
