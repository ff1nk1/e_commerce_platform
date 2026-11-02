<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            // SQLite (tests) has no ALTER TABLE ... ADD CONSTRAINT CHECK.
            return;
        }

        DB::statement('ALTER TABLE stocks ADD CONSTRAINT stocks_quantity_non_negative CHECK (quantity >= 0)');
        DB::statement('ALTER TABLE stocks ADD CONSTRAINT stocks_reserved_non_negative CHECK (reserved >= 0)');
        DB::statement('ALTER TABLE stocks ADD CONSTRAINT stocks_reserved_within_quantity CHECK (reserved <= quantity)');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE stocks DROP CONSTRAINT IF EXISTS stocks_reserved_within_quantity');
        DB::statement('ALTER TABLE stocks DROP CONSTRAINT IF EXISTS stocks_reserved_non_negative');
        DB::statement('ALTER TABLE stocks DROP CONSTRAINT IF EXISTS stocks_quantity_non_negative');
    }
};
