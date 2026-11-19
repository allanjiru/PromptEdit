<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE generations
             ALTER COLUMN recovery_dispatch_until
             TYPE timestamp(0) without time zone
             USING recovery_dispatch_until AT TIME ZONE 'UTC'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement(
            "ALTER TABLE generations
             ALTER COLUMN recovery_dispatch_until
             TYPE timestamp(0) with time zone
             USING recovery_dispatch_until AT TIME ZONE 'UTC'"
        );
    }
};
