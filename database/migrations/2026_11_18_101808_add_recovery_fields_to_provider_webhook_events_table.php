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
        Schema::table('provider_webhook_events', function (Blueprint $table) {
            $table->unsignedInteger('recovery_attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('recovery_claimed_until')->nullable();

            $table->index(
                ['status', 'next_retry_at'],
                'webhook_recovery_due_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('provider_webhook_events', function (Blueprint $table) {
            $table->dropIndex('webhook_recovery_due_idx');

            $table->dropColumn([
                'recovery_attempts',
                'last_attempted_at',
                'next_retry_at',
                'recovery_claimed_until',
            ]);
        });
    }
};
