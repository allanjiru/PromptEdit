<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DeploymentMigrationPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_applied_nanodollar_migration_passes_preflight(): void
    {
        $this->artisan('deployment:migration-preflight')
            ->expectsOutput(
                'Nanodollar conversion is already recorded as applied.'
            )
            ->assertSuccessful();
    }

    public function test_legacy_transaction_metadata_blocks_preflight(): void
    {
        $user = User::factory()->create();

        $walletId = DB::table('wallets')->insertGetId([
            'user_id' => $user->id,
            'available_units' => 1,
            'reserved_units' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('wallet_transactions')->insert([
            'wallet_id' => $walletId,
            'generation_id' => null,
            'type' => 'legacy_credit',
            'idempotency_key' => 'legacy-preflight-test',
            'available_delta_units' => 1,
            'reserved_delta_units' => 0,
            'available_after_units' => 1,
            'reserved_after_units' => 0,
            'description' => 'Legacy preflight fixture',
            'metadata' => json_encode([
                'amount' => 1,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('migrations')
            ->where(
                'migration',
                '2026_11_17_113406_convert_financial_amounts_to_nanodollars'
            )
            ->delete();

        $this->artisan('deployment:migration-preflight')
            ->expectsOutput(
                '1 wallet transaction row(s) contain legacy metadata.'
            )
            ->expectsOutput(
                'Migration preflight failed; no records were modified.'
            )
            ->assertFailed();
    }

    public function test_nanodollar_migration_checks_metadata_before_updating_balances(): void
    {
        $user = User::factory()->create();

        $walletId = DB::table('wallets')->insertGetId([
            'user_id' => $user->id,
            'available_units' => 123,
            'reserved_units' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('wallet_transactions')->insert([
            'wallet_id' => $walletId,
            'generation_id' => null,
            'type' => 'legacy_credit',
            'idempotency_key' => 'legacy-migration-order-test',
            'available_delta_units' => 123,
            'reserved_delta_units' => 0,
            'available_after_units' => 123,
            'reserved_after_units' => 0,
            'description' => 'Legacy migration ordering fixture',
            'metadata' => json_encode([
                'amount' => 123,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path(
            'migrations/2026_11_17_113406_convert_financial_amounts_to_nanodollars.php'
        );

        try {
            $migration->up();
            $this->fail('Expected incompatible metadata to stop migration.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Convert monetary transaction metadata before migration.',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            123,
            DB::table('wallets')->where('id', $walletId)->value(
                'available_units'
            )
        );
    }
}
