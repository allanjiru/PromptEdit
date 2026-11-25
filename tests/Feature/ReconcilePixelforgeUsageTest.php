<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GenerationService;
use App\Services\PixelforgeClient;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class ReconcilePixelforgeUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_usage_matches_provider_daily_report(): void
    {
        $usage = [
            'input_text_tokens' => 12,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 500,
            'total_tokens' => 1612,
        ];
        $rates = [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];

        $user = User::factory()->create();
        app(WalletService::class)->addDemoCredits(
            $user,
            10_000_000_000,
            'usage-reconciliation:credit'
        );

        $generations = app(GenerationService::class);
        $generation = $generations->create(
            $user,
            'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'A reconciled image',
            '1024x1024',
            'medium',
            [],
            100_000_000
        );
        $generations->attachProviderJob($generation, 'gen_usage123');
        $generations->succeed(
            $generation,
            50_310_000,
            ['https://pixelforge.promptedit.dev/images/test.png'],
            $usage,
            $rates
        );

        $date = now('UTC')->format('Y-m-d');
        $client = Mockery::mock(PixelforgeClient::class);
        $client->shouldReceive('usage')
            ->once()
            ->with($date, $date)
            ->andReturn([
                'object' => 'usage',
                'data' => [[
                    'date' => $date,
                    'generations' => 1,
                    'input_text_tokens' => 12,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 500,
                    'cost_usd' => '0.050310000',
                ]],
            ]);
        $this->app->instance(PixelforgeClient::class, $client);

        $this->artisan('pixelforge:reconcile-usage', [
            '--start-date' => $date,
            '--end-date' => $date,
        ])->assertSuccessful();
    }
}
