<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index('user_id');
            $table->index(['user_id', 'id']);
            $table->index('status');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['user_id', 'id']);
            $table->dropIndex(['status']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['order_id', 'status']);
        });
    }
};
