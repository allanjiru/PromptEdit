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
            $table->string('submission_status', 30)
                ->default('pending');

            $table->unsignedInteger('submission_attempts')
                ->default(0);

            $table->timestamp('submission_last_attempted_at')
                ->nullable();

            $table->timestamp('submission_reconciliation_due_at')
                ->nullable();

            $table->text('submission_last_error')
                ->nullable();

            $table->index(
                [
                    'submission_status',
                    'submission_reconciliation_due_at',
                ],
                'generations_submission_reconciliation_idx'
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
                'generations_submission_reconciliation_idx'
            );

            $table->dropColumn([
                'submission_status',
                'submission_attempts',
                'submission_last_attempted_at',
                'submission_reconciliation_due_at',
                'submission_last_error',
            ]);
        });
    }
};
