<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessPixelforgeWebhook;
use App\Jobs\SubmitPixelforgeGeneration;
use App\Models\Generation;
use App\Models\ProviderWebhookEvent;
use App\Models\User;
use App\Services\GenerationImagePersistenceDispatcher;
use App\Services\GenerationService;
use App\Services\GenerationSubmissionClaimService;
use App\Services\GenerationSubmissionRecoveryService;
use App\Services\PixelforgeClient;
use App\Services\PixelforgeResultProcessor;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class PixelforgeGenerationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'lifecycle-test-secret';

    private const PROVIDER_ID = 'gen_lifecycle123';

    private const IMAGE_URL =
        'https://example.com/generated/lifecycle.png';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'services.pixelforge.webhook_secret',
            self::SECRET
        );

    }

    private function createGeneration(): Generation
    {
        $user = User::factory()->create();

        app(WalletService::class)->addDemoCredits(
            $user,
            10_000_000_000,
            'test:lifecycle-credit:'.$user->id
        );

        return app(GenerationService::class)->create(
            $user,
            (string) Str::uuid(),
            'A futuristic city at sunset',
            '1024x1024',
            'high',
            [],
            150_000_000
        );
    }

    private function submitGeneration(
        Generation $generation
    ): void {
        $client = Mockery::mock(PixelforgeClient::class);

        $client->shouldReceive(
            'createGenerationFromPayload'
        )
            ->once()
            ->with(
                $generation->request_key,
                $generation->provider_request_payload
            )
            ->andReturn([
                'id' => self::PROVIDER_ID,
                'status' => 'queued',
            ]);

        $job = new SubmitPixelforgeGeneration(
            $generation->id
        );

        $job->handle(
            $client,
            app(GenerationSubmissionClaimService::class)
        );
    }

    private function webhookPayload(
        string $eventId
    ): array {
        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'generation.succeeded',
            'created_at' => now()->toIso8601String(),
            'data' => [
                'object' => [
                    'id' => self::PROVIDER_ID,
                    'object' => 'generation',
                    'status' => 'succeeded',
                    'metadata' => [],
                ],
            ],
        ];
    }

    private function sendSignedWebhook(
        array $payload
    ): void {
        $body = json_encode(
            $payload,
            JSON_THROW_ON_ERROR
        );

        $timestamp = time();

        $signature = hash_hmac(
            'sha256',
            $timestamp.'.'.$body,
            self::SECRET
        );

        $this->call(
            'POST',
            '/api/webhooks/pixelforge',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            $body
        )
            ->assertOk()
            ->assertJson(['received' => true]);
    }

    private function providerResult(): array
    {
        return [
            'id' => self::PROVIDER_ID,
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
            'images' => [
                [
                    'url' => self::IMAGE_URL,
                ],
            ],
        ];
    }

    private function processWebhook(
        string $eventId
    ): void {
        $event = ProviderWebhookEvent::query()
            ->where('provider_event_id', $eventId)
            ->firstOrFail();

        $client = Mockery::mock(PixelforgeClient::class);

        $client->shouldReceive('retrieveGeneration')
            ->once()
            ->with(self::PROVIDER_ID)
            ->andReturn($this->providerResult());

        $job = new ProcessPixelforgeWebhook(
            $event->id
        );

        $job->handle(
            $client,
            app(PixelforgeResultProcessor::class),
            app(GenerationImagePersistenceDispatcher::class)
        );
    }

    public function test_complete_generation_lifecycle(): void
    {
        Bus::fake();

        $generation = $this->createGeneration();

        $this->assertDatabaseHas(
            'wallet_reservations',
            [
                'generation_id' => $generation->id,
                'status' => 'active',
                'amount_units' => 150_000_000,
            ]
        );

        $this->submitGeneration($generation);

        $submitted = $generation->fresh();

        $this->assertSame(
            self::PROVIDER_ID,
            $submitted->provider_job_id
        );

        $this->assertSame(
            Generation::SUBMISSION_ACCEPTED,
            $submitted->submission_status
        );

        $this->assertSame(
            1,
            $submitted->submission_attempts
        );

        $this->sendSignedWebhook(
            $this->webhookPayload('evt_lifecycle001')
        );

        Bus::assertDispatched(
            ProcessPixelforgeWebhook::class,
            1
        );

        $this->processWebhook('evt_lifecycle001');

        $completed = $generation->fresh();

        $this->assertTrue($completed->isCompleted());

        $this->assertSame(
            85_000_000,
            $completed->provider_cost_units
        );

        $this->assertSame(
            106_250_000,
            $completed->customer_charge_units
        );

        $this->assertSame(
            [self::IMAGE_URL],
            $completed->result_image_urls
        );

        $this->assertNotNull(
            $completed->completed_at
        );

        $wallet = $completed->user->wallet->fresh();

        $this->assertSame(
            9_893_750_000,
            $wallet->available_units
        );

        $this->assertSame(
            0,
            $wallet->reserved_units
        );

        $this->assertDatabaseHas(
            'provider_webhook_events',
            [
                'provider_event_id' => 'evt_lifecycle001',
                'status' => ProviderWebhookEvent::STATUS_PROCESSED,
            ]
        );
    }

    public function test_duplicate_webhook_does_not_charge_twice(): void
    {
        Bus::fake();

        $generation = $this->createGeneration();

        $this->submitGeneration($generation);

        $payload = $this->webhookPayload(
            'evt_lifecycle002'
        );

        $this->sendSignedWebhook($payload);

        $this->processWebhook('evt_lifecycle002');

        $first = $generation->fresh();

        $walletBefore = $first->user->wallet->fresh();

        $this->assertTrue($first->isCompleted());

        // Deliver exactly the same event again.
        $this->sendSignedWebhook($payload);

        $this->assertDatabaseCount(
            'provider_webhook_events',
            1
        );

        Bus::assertDispatched(
            ProcessPixelforgeWebhook::class,
            1
        );

        // Simulate a duplicate job execution.
        $client = Mockery::mock(PixelforgeClient::class);

        $client->shouldNotReceive(
            'retrieveGeneration'
        );

        $event = ProviderWebhookEvent::query()
            ->where(
                'provider_event_id',
                'evt_lifecycle002'
            )
            ->firstOrFail();

        (new ProcessPixelforgeWebhook($event->id))
            ->handle(
                $client,
                app(PixelforgeResultProcessor::class),
                app(GenerationImagePersistenceDispatcher::class)
            );

        $second = $generation->fresh();

        $walletAfter = $second->user->wallet->fresh();

        $this->assertTrue($second->isCompleted());

        $this->assertSame(
            $first->customer_charge_units,
            $second->customer_charge_units
        );

        $this->assertSame(
            $first->result_image_urls,
            $second->result_image_urls
        );

        $this->assertSame(
            $walletBefore->available_units,
            $walletAfter->available_units
        );

        $this->assertSame(
            $walletBefore->reserved_units,
            $walletAfter->reserved_units
        );

        $this->assertDatabaseHas(
            'wallet_reservations',
            [
                'generation_id' => $generation->id,
                'status' => 'settled',
            ]
        );
    }

    public function test_timeout_recovery_preserves_idempotency_and_wallet(): void
    {
        Queue::fake();

        $generation = $this->createGeneration();

        $originalRequestKey = $generation->request_key;
        $originalPayload = $generation->provider_request_payload;

        $walletBefore = $generation->user->wallet->fresh();

        // Initial generation creation must reserve funds.
        $this->assertDatabaseHas('wallet_reservations', [
            'generation_id' => $generation->id,
            'status' => 'active',
            'amount_units' => 150_000_000,
        ]);

        $client = Mockery::mock(PixelforgeClient::class);

        // The provider may have accepted this request,
        // but the HTTP response was lost.
        $client->shouldReceive('createGenerationFromPayload')
            ->once()
            ->with($originalRequestKey, $originalPayload)
            ->andThrow(
                new ConnectionException(
                    'Provider response timed out.'
                )
            );

        $initialJob = new SubmitPixelforgeGeneration(
            $generation->id
        );

        try {
            $initialJob->handle(
                $client,
                app(GenerationSubmissionClaimService::class)
            );

            $this->fail('Expected a provider timeout.');
        } catch (ConnectionException $exception) {
            $this->assertSame(
                'Provider response timed out.',
                $exception->getMessage()
            );
        }

        $uncertain = $generation->fresh();

        $this->assertSame(
            Generation::SUBMISSION_UNCERTAIN,
            $uncertain->submission_status
        );

        $this->assertSame(1, $uncertain->submission_attempts);
        $this->assertNull($uncertain->provider_job_id);
        $this->assertNotNull(
            $uncertain->submission_reconciliation_due_at
        );

        $this->assertDatabaseHas('wallet_reservations', [
            'generation_id' => $generation->id,
            'status' => 'active',
            'amount_units' => 150_000_000,
        ]);

        $walletAfterTimeout = $generation->user->wallet->fresh();

        $this->assertSame(
            $walletBefore->available_units,
            $walletAfterTimeout->available_units
        );

        $this->assertSame(
            $walletBefore->reserved_units,
            $walletAfterTimeout->reserved_units
        );

        // Not eligible for recovery before the due time.
        $recovery = app(GenerationSubmissionRecoveryService::class);

        $this->assertNull(
            $recovery->reserve($generation->id)
        );

        // Simulate the two-minute reconciliation delay expiring.
        $uncertain->forceFill([
            'submission_reconciliation_due_at' => now()->subSecond(),
        ])->save();

        // The queue fake captures the scheduled recovery job.

        $exitCode = Artisan::call(
            'pixelforge:recover-submissions'
        );

        $this->assertSame(0, $exitCode);

        Queue::assertPushed(
            SubmitPixelforgeGeneration::class,
            1
        );

        $recoveryJob = Queue::pushed(
            SubmitPixelforgeGeneration::class
        )->first();

        $this->assertSame(
            $generation->id,
            $recoveryJob->generationId
        );

        $this->assertNotNull(
            $recoveryJob->dispatchToken
        );

        // The retry must use the exact same idempotency key
        // and immutable provider payload.
        $retryClient = Mockery::mock(PixelforgeClient::class);

        $retryClient
            ->shouldReceive('createGenerationFromPayload')
            ->once()
            ->with($originalRequestKey, $originalPayload)
            ->andReturn([
                'id' => self::PROVIDER_ID,
                'status' => 'queued',
            ]);

        $recoveryJob->handle(
            $retryClient,
            app(GenerationSubmissionClaimService::class)
        );

        $accepted = $generation->fresh();

        $this->assertSame(
            Generation::SUBMISSION_ACCEPTED,
            $accepted->submission_status
        );

        $this->assertSame(
            self::PROVIDER_ID,
            $accepted->provider_job_id
        );

        $this->assertSame(
            2,
            $accepted->submission_attempts
        );

        $this->assertSame(
            $originalRequestKey,
            $accepted->request_key
        );

        $this->assertSame(
            $originalPayload,
            $accepted->provider_request_payload
        );

        $this->assertNull($accepted->submission_claim_token);
        $this->assertNull($accepted->recovery_dispatch_token);

        // Recovery must not create another reservation.
        $this->assertDatabaseCount('wallet_reservations', 1);

        $this->assertDatabaseHas('wallet_reservations', [
            'generation_id' => $generation->id,
            'status' => 'active',
            'amount_units' => 150_000_000,
        ]);

        $walletAfterRecovery = $generation->user->wallet->fresh();

        $this->assertSame(
            $walletBefore->available_units,
            $walletAfterRecovery->available_units
        );

        $this->assertSame(
            $walletBefore->reserved_units,
            $walletAfterRecovery->reserved_units
        );

        // Complete the recovered generation using the same
        // signed webhook and result-processing path.
        $this->sendSignedWebhook(
            $this->webhookPayload('evt_recovered001')
        );

        $this->processWebhook('evt_recovered001');

        $completed = $generation->fresh();

        $this->assertTrue($completed->isCompleted());

        $this->assertSame(
            [self::IMAGE_URL],
            $completed->result_image_urls
        );

        $this->assertSame(
            85_000_000,
            $completed->provider_cost_units
        );

        $this->assertSame(
            106_250_000,
            $completed->customer_charge_units
        );

        $finalWallet = $completed->user->wallet->fresh();

        $this->assertSame(
            9_893_750_000,
            $finalWallet->available_units
        );

        $this->assertSame(0, $finalWallet->reserved_units);

        $this->assertDatabaseHas('wallet_reservations', [
            'generation_id' => $generation->id,
            'status' => 'settled',
        ]);

        $this->assertDatabaseHas('provider_webhook_events', [
            'provider_event_id' => 'evt_recovered001',
            'status' => ProviderWebhookEvent::STATUS_PROCESSED,
        ]);
    }
}
