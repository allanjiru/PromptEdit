<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FACTOR = 1000;

    private const COLUMNS = [
        'wallets' => [
            'available_units',
            'reserved_units',
        ],
        'generations' => [
            'estimated_charge_units',
            'provider_cost_units',
            'customer_charge_units',
        ],
        'wallet_reservations' => [
            'amount_units',
        ],
        'wallet_transactions' => [
            'available_delta_units',
            'reserved_delta_units',
            'available_after_units',
            'reserved_after_units',
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Legacy monetary metadata cannot be converted generically. Check
        // before touching any financial columns, even on databases whose
        // migration runner does not wrap this migration in a transaction.
        $hasMetadata = DB::table('wallet_transactions')
            ->whereNotNull('metadata')
            ->exists();

        if ($hasMetadata) {
            throw new RuntimeException(
                'Convert monetary transaction metadata before migration.'
            );
        }

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                // Fail rather than overflow a PostgreSQL BIGINT.
                DB::statement(sprintf(
                    'UPDATE "%s"
                     SET "%s" = ("%s"::numeric * %d)::bigint
                     WHERE "%s" IS NOT NULL',
                    $table,
                    $column,
                    $column,
                    self::FACTOR,
                    $column
                ));
            }
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Nanodollar values may not be divisible by 1000.
        // Refuse a potentially lossy rollback.
        throw new RuntimeException(
            'Nanodollar migration cannot be safely reversed.'
        );
    }
};
