<?php

namespace Tests\Feature;

use App\Jobs\ProcessPixelforgeWebhook;
use App\Jobs\SubmitPixelforgeGeneration;
use App\Models\Generation;
use App\Models\ProviderWebhookEvent;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\PixelforgeClient;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class PixelforgeDatabaseQueueIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', 'database');
        config()->set('cache.default', 'array');

        // Do not fake Queue or Bus: this test must
        // use Laravel's real database queue.
    }

    private function createGeneration(): Generation
    {
        $user = User::factory()->create();

        app(WalletService::class)->addDemoCredits(
            $user,
            10_000_000_000,
            'test:queue-credit:'.$user->id
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

    private function executeNextJob(string $queue): void
    {
        $connection = Queue::connection('database');

        $job = $connection->pop($queue);

        $this->assertNotNull(
            $job,
            "Expected a job on the {$queue} queue."
        );

        // Execute the real serialized queue payload
        // through Laravel's queued job handler.
        $job->fire();

        if (! $job->isDeleted() && ! $job->isReleased()) {
            $job->delete();
        }
    }

    public function test_generation_job_is_persisted_and_executed(): void
    {
        $generation = $this->createGeneration();

        $client = Mockery::mock(PixelforgeClient::class);

        $client
            ->shouldReceive('createGenerationFromPayload')
            ->once()
            ->with(
                $generation->request_key,
                $generation->provider_request_payload
            )
            ->andReturn([
                'id' => 'gen_queue123',
                'status' => 'queued',
            ]);

        $this->app->instance(
            PixelforgeClient::class,
            $client
        );

        SubmitPixelforgeGeneration::dispatch(
            $generation->id
        )->onQueue('generations');

        $this->assertSame(
            1,
            DB::table('jobs')
                ->where('queue', 'generations')
                ->count()
        );

        $this->executeNextJob('generations');

        $submitted = $generation->fresh();

        $this->assertSame(
            Generation::SUBMISSION_ACCEPTED,
            $submitted->submission_status
        );

        $this->assertSame(
            'gen_queue123',
            $submitted->provider_job_id
        );

        $this->assertSame(
            1,
            $submitted->submission_attempts
        );

        $this->assertSame(
            1,
            DB::table('jobs')
                ->where('queue', 'generations')
                ->count()
        );
        $this->assertNotNull(
            $submitted->result_reconciliation_due_at
        );

        $this->assertDatabaseHas('wallet_reservations', [
            'generation_id' => $generation->id,
            'status' => 'active',
            'amount_units' => 150_000_000,
        ]);
    }

    public function test_webhook_job_is_persisted_and_executed(): void
    {
        $generation = $this->createGeneration();

        $generation->forceFill([
            'provider_job_id' => 'gen_queue456',
            'submission_status' => Generation::SUBMISSION_ACCEPTED,
        ])->save();

        $event = ProviderWebhookEvent::query()->create([
            'provider_event_id' => 'evt_queue456',
            'event_type' => 'generation.succeeded',
            'provider_job_id' => 'gen_queue456',
            'status' => ProviderWebhookEvent::STATUS_RECEIVED,
            'payload' => [
                'id' => 'evt_queue456',
                'type' => 'generation.succeeded',
                'data' => [
                    'object' => [
                        'id' => 'gen_queue456',
                        'status' => 'succeeded',
                    ],
                ],
            ],
        ]);

        $client = Mockery::mock(PixelforgeClient::class);

        $client
            ->shouldReceive('retrieveGeneration')
            ->once()
            ->with('gen_queue456')
            ->andReturn([
                'id' => 'gen_queue456',
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
                        'url' => 'https://example.com/queue-result.png',
                    ],
                ],
            ]);

        $this->app->instance(
            PixelforgeClient::class,
            $client
        );

        ProcessPixelforgeWebhook::dispatch(
            $event->id
        )->onQueue('webhooks');

        $this->assertSame(
            1,
            DB::table('jobs')
                ->where('queue', 'webhooks')
                ->count()
        );

        $this->executeNextJob('webhooks');

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
            ['https://example.com/queue-result.png'],
            $completed->result_image_urls
        );

        $this->assertDatabaseHas('provider_webhook_events', [
            'id' => $event->id,
            'status' => ProviderWebhookEvent::STATUS_PROCESSED,
        ]);

        $this->assertSame(
            0,
            DB::table('jobs')
                ->where('queue', 'webhooks')
                ->count()
        );

        $wallet = $completed->user->wallet->fresh();

        $this->assertSame(
            9_893_750_000,
            $wallet->available_units
        );

        $this->assertSame(0, $wallet->reserved_units);
    }
}
