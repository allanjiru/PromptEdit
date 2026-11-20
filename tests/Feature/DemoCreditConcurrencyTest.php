<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DemoCreditConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    public function test_concurrent_duplicate_requests_credit_the_wallet_once(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('promptedit_test', DB::connection()->getDatabaseName());

        $user = User::factory()->create();
        $wallet = app(WalletService::class)->ensureWallet($user);
        $requestKey = (string) Str::uuid();
        $root = base_path();
        $command = [
            PHP_BINARY,
            $root.'/tests/Support/purchase-demo-credits.php',
            (string) $user->id,
            'starter',
            $requestKey,
        ];
        $environment = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'promptedit_test',
            'DB_USERNAME' => 'allannjiru',
            'DB_PASSWORD' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ];
        $first = new Process($command, $root, $environment);
        $second = new Process($command, $root, $environment);
        $first->setTimeout(10);
        $second->setTimeout(10);

        DB::beginTransaction();
        try {
            DB::table('wallets')->where('id', $wallet->id)->lockForUpdate()->first();
            $first->start();
            $second->start();
            usleep(200_000);
            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        $first->wait();
        $second->wait();

        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
        $this->assertSame($first->getOutput(), $second->getOutput());
        $this->assertSame(
            5_000_000_000,
            $wallet->fresh()->available_units
        );
        $this->assertSame(
            1,
            WalletTransaction::query()
                ->where('type', WalletTransaction::TYPE_DEMO_CREDIT)
                ->count()
        );

        $user->delete();
    }
}
