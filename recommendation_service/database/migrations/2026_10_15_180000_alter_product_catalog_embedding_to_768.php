<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE product_catalog DROP COLUMN IF EXISTS embedding');
        DB::statement('ALTER TABLE product_catalog ADD COLUMN embedding vector(768) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE product_catalog DROP COLUMN IF EXISTS embedding');
        DB::statement('ALTER TABLE product_catalog ADD COLUMN embedding vector(1536) NULL');
    }
};
