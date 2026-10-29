<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('outbox_events');
    }

    public function down(): void
    {
        // The historical create migration owns this table. Recreating it here
        // would fork the schema from that migration.
    }
};
