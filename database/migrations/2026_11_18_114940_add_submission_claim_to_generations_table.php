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
            $table->uuid('submission_claim_token')->nullable();

            $table->timestamp('submission_claimed_until')
                ->nullable();

            $table->index(
                ['submission_status', 'submission_claimed_until'],
                'generations_submission_claim_idx'
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
                'generations_submission_claim_idx'
            );

            $table->dropColumn([
                'submission_claim_token',
                'submission_claimed_until',
            ]);
        });
    }
};
