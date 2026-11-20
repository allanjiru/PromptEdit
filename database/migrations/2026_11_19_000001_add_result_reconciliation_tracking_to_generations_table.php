<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->unsignedSmallInteger('result_reconciliation_attempts')
                ->default(0);
            $table->timestamp('result_reconciliation_due_at')->nullable();
            $table->uuid('result_reconciliation_claim_token')->nullable();
            $table->timestamp('result_reconciliation_claimed_until')->nullable();
            $table->timestamp('result_reconciliation_exhausted_at')->nullable();

            $table->index(
                ['status', 'result_reconciliation_due_at'],
                'generations_result_reconciliation_due_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropIndex('generations_result_reconciliation_due_idx');
            $table->dropColumn([
                'result_reconciliation_attempts',
                'result_reconciliation_due_at',
                'result_reconciliation_claim_token',
                'result_reconciliation_claimed_until',
                'result_reconciliation_exhausted_at',
            ]);
        });
    }
};
