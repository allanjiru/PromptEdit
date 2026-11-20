<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DeploymentMigrationPreflight extends Command
{
    private const NANODOLLAR_MIGRATION =
        '2026_11_17_113406_convert_financial_amounts_to_nanodollars';

    private const MAX_SAFE_LEGACY_UNITS = 9_223_372_036_854_775;

    /** @var array<string, list<string>> */
    private const FINANCIAL_COLUMNS = [
        'wallets' => ['available_units', 'reserved_units'],
        'generations' => [
            'estimated_charge_units',
            'provider_cost_units',
            'customer_charge_units',
        ],
        'wallet_reservations' => ['amount_units'],
        'wallet_transactions' => [
            'available_delta_units',
            'reserved_delta_units',
            'available_after_units',
            'reserved_after_units',
        ],
    ];

    protected $signature = 'deployment:migration-preflight';

    protected $description =
        'Read-only compatibility checks for PromptEdit financial migrations.';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->error('PromptEdit deployment migrations require PostgreSQL.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('migrations')) {
            $this->info(
                'No migration repository exists; this appears to be a fresh database.'
            );

            return self::SUCCESS;
        }

        $applied = DB::table('migrations')
            ->where('migration', self::NANODOLLAR_MIGRATION)
            ->exists();

        if ($applied) {
            $this->info('Nanodollar conversion is already recorded as applied.');

            return self::SUCCESS;
        }

        if (! Schema::hasTable('wallet_transactions')) {
            $this->info(
                'Financial tables do not exist yet; the normal fresh-database sequence is compatible.'
            );

            return self::SUCCESS;
        }

        $blocked = false;
        $metadataRows = DB::table('wallet_transactions')
            ->whereNotNull('metadata')
            ->count();

        if ($metadataRows > 0) {
            $this->error(
                "{$metadataRows} wallet transaction row(s) contain legacy metadata."
            );
            $this->line(
                'A reviewed, application-specific metadata reconciliation is required before migration.'
            );
            $blocked = true;
        }

        foreach (self::FINANCIAL_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $unsafe = DB::table($table)
                    ->whereNotNull($column)
                    ->where(function ($query) use ($column) {
                        $query->where(
                            $column,
                            '>',
                            self::MAX_SAFE_LEGACY_UNITS
                        )->orWhere(
                            $column,
                            '<',
                            -self::MAX_SAFE_LEGACY_UNITS
                        );
                    })
                    ->count();

                if ($unsafe === 0) {
                    continue;
                }

                $this->error(
                    "{$table}.{$column} has {$unsafe} row(s) that would overflow BIGINT."
                );
                $blocked = true;
            }
        }

        if ($blocked) {
            $this->newLine();
            $this->error('Migration preflight failed; no records were modified.');

            return self::FAILURE;
        }

        $this->info(
            'Migration preflight passed; no incompatible legacy financial records were found.'
        );

        return self::SUCCESS;
    }
}
