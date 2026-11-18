<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\PixelforgeResultProcessor;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class PixelforgeResultProcessorTest extends TestCase
{
    use RefreshDatabase;

    private PixelforgeResultProcessor $processor;

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processor = app(
            PixelforgeResultProcessor::class
        );

        $user = User::factory()->create();

        $wallets = app(WalletService::class);

        // $10 in nanodollars.
        $wallets->addDemoCredits(
            $user,
            10_000_000_000,
            (string) Str::uuid()
        );

        $this->generation = app(
            GenerationService::class
        )->create(
            $user,
            (string) Str::uuid(),
            'Generate a mountain landscape',
            '1024x1024',
            'medium',
            [],
            100_000_000
        );

        app(GenerationService::class)
            ->attachProviderJob(
                $this->generation,
                'gen_test123'
            );
    }

    private function successfulPayload(): array
    {
        return [
            'id' => 'gen_test123',
            'status' => 'succeeded',
            'usage' => [
                'input_tokens' => 1000,
                'output_tokens' => 2000,
            ],
            'rates' => [
                'input_tokens' => '5.00',
                'output_tokens' => '40.00',
            ],
            'images' => [
                [
                    'url' =>
                        'https://example.com/result.png',
                ],
            ],
        ];
    }

    private function failedPayload(): array
    {
        return [
            'id' => 'gen_test123',
            'status' => 'failed',
            'error' => [
                'message' => 'Provider generation failed.',
            ],
        ];
    }

    private function process(array $payload): Generation
    {
        return $this->processor->process(
            'gen_test123',
            $payload
        );
    }

    public function test_queued_result_preserves_state(): void
    {
        $result = $this->process([
            'id' => 'gen_test123',
            'status' => 'queued',
        ]);

        $this->assertSame(
            Generation::STATUS_QUEUED,
            $result->status
        );
    }

    public function test_processing_result_updates_state(): void
    {
        $result = $this->process([
            'id' => 'gen_test123',
            'status' => 'processing',
        ]);

        $this->assertSame(
            Generation::STATUS_PROCESSING,
            $result->status
        );
    }

    public function test_success_settles_wallet_and_stores_images(): void
    {
        $result = $this->process(
            $this->successfulPayload()
        );

        $this->assertTrue($result->isCompleted());

        // $0.005 input + $0.080 output.
        $this->assertSame(
            85_000_000,
            $result->provider_cost_units
        );

        // 25% markup, rounded upward.
        $this->assertSame(
            106_250_000,
            $result->customer_charge_units
        );

        $this->assertSame(
            ['https://example.com/result.png'],
            $result->result_image_urls
        );

        $this->assertNotNull($result->completed_at);

        $wallet = $result->user->wallet->fresh();

        $this->assertSame(
            9_893_750_000,
            $wallet->available_units
        );

        $this->assertSame(
            0,
            $wallet->reserved_units
        );
    }

    public function test_duplicate_success_does_not_charge_twice(): void
    {
        $payload = $this->successfulPayload();

        $first = $this->process($payload);

        $walletBefore = $first->user->wallet->fresh();

        $second = $this->process($payload);

        $walletAfter = $second->user->wallet->fresh();

        $this->assertTrue($second->isCompleted());

        $this->assertSame(
            $walletBefore->available_units,
            $walletAfter->available_units
        );

        $this->assertSame(
            $walletBefore->reserved_units,
            $walletAfter->reserved_units
        );
    }

    public function test_failure_releases_reservation(): void
    {
        $result = $this->process(
            $this->failedPayload()
        );

        $this->assertTrue($result->isFailed());

        $this->assertSame(
            'Provider generation failed.',
            $result->error_message
        );

        $wallet = $result->user->wallet->fresh();

        $this->assertSame(
            10_000_000_000,
            $wallet->available_units
        );

        $this->assertSame(
            0,
            $wallet->reserved_units
        );
    }

    public function test_duplicate_failure_does_not_refund_twice(): void
    {
        $payload = $this->failedPayload();

        $this->process($payload);

        $before = $this->generation
            ->user->wallet->fresh();

        $this->process($payload);

        $after = $this->generation
            ->user->wallet->fresh();

        $this->assertSame(
            $before->available_units,
            $after->available_units
        );
    }

    public function test_late_processing_does_not_override_success(): void
    {
        $this->process(
            $this->successfulPayload()
        );

        $result = $this->process([
            'id' => 'gen_test123',
            'status' => 'processing',
        ]);

        $this->assertTrue($result->isCompleted());
    }

    public function test_late_processing_does_not_override_failure(): void
    {
        $this->process(
            $this->failedPayload()
        );

        $result = $this->process([
            'id' => 'gen_test123',
            'status' => 'processing',
        ]);

        $this->assertTrue($result->isFailed());
    }

    public function test_failure_cannot_override_success(): void
    {
        $this->process(
            $this->successfulPayload()
        );

        $this->expectException(LogicException::class);

        $this->process(
            $this->failedPayload()
        );
    }

    public function test_success_cannot_override_failure(): void
    {
        $this->process(
            $this->failedPayload()
        );

        $this->expectException(LogicException::class);

        $this->process(
            $this->successfulPayload()
        );
    }

    public function test_mismatched_provider_id_is_rejected(): void
    {
        $payload = $this->successfulPayload();

        $payload['id'] = 'gen_different';

        $this->expectException(LogicException::class);

        $this->process($payload);
    }

    public function test_missing_usage_is_rejected(): void
    {
        $payload = $this->successfulPayload();

        unset($payload['usage']);

        $this->expectException(LogicException::class);

        $this->process($payload);
    }

    public function test_missing_rates_is_rejected(): void
    {
        $payload = $this->successfulPayload();

        unset($payload['rates']);

        $this->expectException(LogicException::class);

        $this->process($payload);
    }

    public function test_missing_images_is_rejected(): void
    {
        $payload = $this->successfulPayload();

        unset($payload['images']);

        $this->expectException(LogicException::class);

        $this->process($payload);
    }

    public function test_invalid_image_url_is_rejected(): void
    {
        $payload = $this->successfulPayload();

        $payload['images'][0]['url'] = 'not-a-url';

        $this->expectException(LogicException::class);

        $this->process($payload);
    }

    public function test_unknown_status_is_rejected(): void
    {
        $this->expectException(LogicException::class);

        $this->process([
            'id' => 'gen_test123',
            'status' => 'cancelled',
        ]);
    }

    
    public function test_webhook_before_provider_id_attachment_cannot_resolve(): void
    {
        // Simulate a webhook arriving before the provider
        // job ID has been persisted.
        \Illuminate\Support\Facades\DB::table('generations')
            ->where('id', $this->generation->id)
            ->update([
                'provider_job_id' => null,
            ]);

        $this->assertDatabaseHas('generations', [
            'id' => $this->generation->id,
            'provider_job_id' => null,
        ]);

        $this->expectException(
            \Illuminate\Database\Eloquent\ModelNotFoundException::class
        );

        $this->process(
            $this->successfulPayload()
        );
    }

}
