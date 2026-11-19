<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->uuid('recovery_dispatch_token')->nullable();
            $table->timestampTz('recovery_dispatch_until')->nullable();

            $table->index(
                'recovery_dispatch_until',
                'generations_recovery_dispatch_until_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropIndex(
                'generations_recovery_dispatch_until_idx'
            );

            $table->dropColumn([
                'recovery_dispatch_token',
                'recovery_dispatch_until',
            ]);
        });
    }
};
