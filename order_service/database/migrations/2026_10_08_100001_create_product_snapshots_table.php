<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_snapshots', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->decimal('price', 12, 2);
            $table->unsignedBigInteger('store_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_snapshots');
    }
};
