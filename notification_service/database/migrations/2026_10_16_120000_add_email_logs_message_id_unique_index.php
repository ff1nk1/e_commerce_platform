<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mongodb')->table('email_logs', function ($collection) {
            // index($columns, $name, …) — 2nd arg is index name, not options.
            $collection->sparse_and_unique('message_id');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->table('email_logs', function ($collection) {
            $collection->dropIndexIfExists('message_id_1');
        });
    }
};
