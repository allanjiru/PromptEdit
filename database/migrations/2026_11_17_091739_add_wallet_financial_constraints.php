<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE wallets
            ADD CONSTRAINT wallets_available_nonnegative
            CHECK (available_units >= 0)'
        );

        DB::statement(
            'ALTER TABLE wallets
            ADD CONSTRAINT wallets_reserved_nonnegative
            CHECK (reserved_units >= 0)'
        );

        DB::statement(
            'ALTER TABLE wallet_reservations
            ADD CONSTRAINT reservations_amount_positive
            CHECK (amount_units > 0)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement(
            'ALTER TABLE wallet_reservations
            DROP CONSTRAINT IF EXISTS reservations_amount_positive'
        );

        DB::statement(
            'ALTER TABLE wallets
            DROP CONSTRAINT IF EXISTS wallets_reserved_nonnegative'
        );

        DB::statement(
            'ALTER TABLE wallets
            DROP CONSTRAINT IF EXISTS wallets_available_nonnegative'
        );
    }
};
