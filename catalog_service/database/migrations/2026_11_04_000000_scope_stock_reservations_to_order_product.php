<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            // SQLite cannot drop an inline unique constraint (auto-index); the
            // service-level duplicate check still enforces the invariant in tests.
            return;
        }

        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->dropUnique(['order_id', 'product_id', 'store_id']);
            $table->unique(['order_id', 'product_id']);
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->dropUnique(['order_id', 'product_id']);
            $table->unique(['order_id', 'product_id', 'store_id']);
        });
    }
};
