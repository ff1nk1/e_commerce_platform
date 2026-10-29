<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messaging_inbox_dedup')) {
            Schema::create('messaging_inbox_dedup', function (Blueprint $table): void {
                $table->string('message_id')->primary();
                $table->timestamp('created_at')->nullable();
            });
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_inbox_dedup');
    }
};
