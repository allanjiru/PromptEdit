<?php

namespace Tests\Feature;

use App\Jobs\PersistGenerationImages;
use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\PixelforgeClient;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ReconcilePixelforgeResultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_reconciliation_settles_and_dispatches_once(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        app(WalletService::class)->addDemoCredits(
            $user,
            10_000_000_000,
            (string) Str::uuid()
        );

        $generation = app(GenerationService::class)->create(
            $user,
            (string) Str::uuid(),
            'Reconcile result',
            '1024x1024',
            'medium',
            [],
            100_000_000
        );
        app(GenerationService::class)->attachProviderJob(
            $generation,
            'gen_reconcile123'
        );
        $generation->submitted_at = now()->subMinute();
        $generation->save();

        $client = Mockery::mock(PixelforgeClient::class);
        $client->shouldReceive('retrieveGeneration')
            ->once()
            ->with('gen_reconcile123')
            ->andReturn([
                'id' => 'gen_reconcile123',
                'status' => 'succeeded',
                'usage' => [
                    'input_text_tokens' => 1000,
                    'input_image_tokens' => 0,
                    'output_tokens' => 2000,
                    'reasoning_tokens' => 0,
                    'total_tokens' => 3000,
                ],
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
                'image_url' => 'https://pixelforge.promptedit.dev/images/reconcile.png',
            ]);
        $this->app->instance(PixelforgeClient::class, $client);

        $this->assertSame(0, Artisan::call('pixelforge:reconcile-results'));
        $walletAfterFirst = $user->wallet->fresh();

        $this->assertSame(0, Artisan::call('pixelforge:reconcile-results'));
        $walletAfterSecond = $user->wallet->fresh();

        $fresh = $generation->refresh();
        $this->assertSame(Generation::STATUS_COMPLETED, $fresh->status);
        $this->assertSame(106_250_000, $fresh->customer_charge_units);
        $this->assertSame(
            $walletAfterFirst->available_units,
            $walletAfterSecond->available_units
        );
        $this->assertSame(0, $walletAfterSecond->reserved_units);
        Queue::assertPushed(PersistGenerationImages::class, 1);
        Queue::assertPushedOn('images', PersistGenerationImages::class);
    }
}
