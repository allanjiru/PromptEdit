<?php

namespace Tests\Feature;

use App\Jobs\ReconcilePixelforgeGeneration;
use App\Jobs\SubmitPixelforgeGeneration;
use App\Models\Generation;
use App\Models\ProviderWebhookEvent;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\PixelforgeClient;
use App\Services\PixelforgeReconciliationState;
use App\Services\WalletService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class PixelforgeWorkerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_ID = 'gen_lifecycle123';

    private const IMAGE_URL =
        'https://example.com/worker-result.png';

    private const WEBHOOK_SECRET =
        'worker-integration-secret';

    private string $queueName;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            ! app()->environment('testing') ||
            DB::getDriverName() !== 'pgsql' ||
            DB::connection()->getDatabaseName() !== 'promptedit_test'
        ) {
            throw new RuntimeException(
                'Worker integration tests require promptedit_test.'
            );
        }

        $this->queueName = 'test-worker-'.Str::uuid();

        config()->set('queue.default', 'database');

        // RefreshDatabase uses a transaction.
        // Disable after-commit dispatch for this test class.
        // Transaction safety is tested separately.
        config()->set(
            'queue.connections.database.after_commit',
            false
        );

        // WithoutOverlapping requires cache locks.
        config()->set('cache.default', 'array');

        config()->set(
            'services.pixelforge.webhook_secret',
            self::WEBHOOK_SECRET
        );
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->queueName)) {
                DB::table('jobs')
                    ->where('queue', $this->queueName)
                    ->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function createGeneration(): Generation
    {
        $user = User::factory()->create();

        app(WalletService::class)->addDemoCredits(
            $user,
            10_000_000_000,
            'test:worker-credit:'.$user->id
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

    private function dispatchGeneration(
        Generation $generation
    ): void {
        Queue::connection('database')->push(
            new SubmitPixelforgeGeneration($generation->id),
            '',
            $this->queueName
        );
    }

    private function workerOptions(
        int $maxTries = 6
    ): WorkerOptions {
        return new WorkerOptions(
            name: 'promptedit-integration-test',
            backoff: 0,
            memory: 128,
            timeout: 0,
            sleep: 0,
            maxTries: $maxTries,
            force: true,
            stopWhenEmpty: true
        );
    }

    private function runWorkerOnce(
        ?string $queue = null,
        int $maxTries = 6
    ): void {
        $worker = new Worker(
            app('queue'),
            app('events'),
            app(ExceptionHandler::class),
            fn (): bool => false
        );

        $worker->runNextJob(
            'database',
            $queue ?? $this->queueName,
            $this->workerOptions($maxTries)
        );
    }

    private function pendingJobs(
        ?string $queue = null
    ): int {
        return DB::table('jobs')
            ->where('queue', $queue ?? $this->queueName)
            ->count();
    }

    private function mockSuccessfulSubmission(
        Generation $generation
    ): void {
        $client = Mockery::mock(PixelforgeClient::class);

        $client
            ->shouldReceive('createGenerationFromPayload')
            ->once()
            ->with(
                $generation->request_key,
                $generation->provider_request_payload
            )
            ->andReturn([
                'id' => self::PROVIDER_ID,
                'status' => 'queued',
            ]);

        $this->app->instance(
            PixelforgeClient::class,
            $client
        );
    }

    private function webhookPayload(): array
    {
        return [
            'id' => 'evt_workerwebhook123',
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
            self::WEBHOOK_SECRET
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
            ->assertJson([
                'received' => true,
            ]);
    }

    private function mockSuccessfulProviderResult(): void
    {
        $client = Mockery::mock(PixelforgeClient::class);

        $client
            ->shouldReceive('retrieveGeneration')
            ->once()
            ->with(self::PROVIDER_ID)
            ->andReturn([
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
            ]);

        $this->app->instance(
            PixelforgeClient::class,
            $client
        );
    }

    public function test_worker_executes_generation_submission(): void
    {
        $generation = $this->createGeneration();

        $this->mockSuccessfulSubmission($generation);

        $this->dispatchGeneration($generation);

        $this->assertSame(
            1,
            $this->pendingJobs()
        );

        $this->runWorkerOnce();

        $submitted = $generation->fresh();

        $this->assertSame(
            Generation::SUBMISSION_ACCEPTED,
            $submitted->submission_status
        );

        $this->assertSame(
            self::PROVIDER_ID,
            $submitted->provider_job_id
        );

        $this->assertSame(
            1,
            $submitted->submission_attempts
        );

        $this->assertSame(
            0,
            $this->pendingJobs()
        );

        $this->assertDatabaseHas(
            'wallet_reservations',
            [
                'generation_id' => $generation->id,
                'status' => 'active',
                'amount_units' => 150_000_000,
            ]
        );
    }

    public function test_released_reconciliation_job_executes_its_queued_follow_up(): void
    {
        $generation = $this->createGeneration();
        app(GenerationService::class)->attachProviderJob(
            $generation,
            self::PROVIDER_ID
        );

        $client = Mockery::mock(PixelforgeClient::class);
        $client->shouldReceive('retrieveGeneration')
            ->twice()
            ->with(self::PROVIDER_ID)
            ->andReturn(
                [
                    'id' => self::PROVIDER_ID,
                    'status' => 'processing',
                ],
                [
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
                    'image_url' => self::IMAGE_URL,
                ]
            );
        $this->app->instance(PixelforgeClient::class, $client);

        $state = app(PixelforgeReconciliationState::class);
        $this->assertTrue($state->scheduleInitial($generation->id));

        Bus::dispatch(
            (new ReconcilePixelforgeGeneration($generation->id))
                ->onQueue($this->queueName)
                ->delay(PixelforgeReconciliationState::INITIAL_DELAY_SECONDS)
        );

        $this->assertSame(1, $this->pendingJobs());
        $this->travel(
            PixelforgeReconciliationState::INITIAL_DELAY_SECONDS
        )->seconds();

        $this->runWorkerOnce();

        $pending = $generation->fresh();
        $this->assertSame(Generation::STATUS_PROCESSING, $pending->status);
        $this->assertSame(1, $pending->result_reconciliation_attempts);
        $this->assertSame(1, $this->pendingJobs());
        $this->assertNotNull($pending->result_reconciliation_due_at);

        $queuedFollowUp = DB::table('jobs')
            ->where('queue', $this->queueName)
            ->first();
        $this->assertNotNull($queuedFollowUp);
        $this->assertNull($queuedFollowUp->reserved_at);

        $this->travel(10)->seconds();
        $this->runWorkerOnce();

        $completed = $generation->fresh();
        $this->assertTrue($completed->isCompleted());
        $this->assertSame(2, $completed->result_reconciliation_attempts);
        $this->assertNull($completed->result_reconciliation_due_at);
        $this->assertNull($completed->result_reconciliation_claim_token);
        $this->assertSame(0, $this->pendingJobs());
    }

    public function test_worker_releases_job_after_provider_timeout(): void
    {
        $generation = $this->createGeneration();

        $walletBefore = $generation
            ->user
            ->wallet
            ->fresh();

        $client = Mockery::mock(PixelforgeClient::class);

        $client
            ->shouldReceive('createGenerationFromPayload')
            ->once()
            ->with(
                $generation->request_key,
                $generation->provider_request_payload
            )
            ->andThrow(
                new ConnectionException(
                    'Provider response timed out.'
                )
            );

        $this->app->instance(
            PixelforgeClient::class,
            $client
        );

        $this->dispatchGeneration($generation);

        $this->assertSame(
            1,
            $this->pendingJobs()
        );

        $this->runWorkerOnce();

        $uncertain = $generation->fresh();

        $this->assertSame(
            Generation::SUBMISSION_UNCERTAIN,
            $uncertain->submission_status
        );

        $this->assertSame(
            1,
            $uncertain->submission_attempts
        );

        $this->assertNull(
            $uncertain->provider_job_id
        );

        $this->assertNotNull(
            $uncertain->submission_reconciliation_due_at
        );

        $this->assertSame(
            1,
            $this->pendingJobs()
        );

        $queuedJob = DB::table('jobs')
            ->where('queue', $this->queueName)
            ->first();

        $this->assertNotNull($queuedJob);

        $this->assertSame(
            1,
            (int) $queuedJob->attempts
        );

        $this->assertNull(
            $queuedJob->reserved_at
        );

        $walletAfter = $generation
            ->user
            ->wallet
            ->fresh();

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
                'status' => 'active',
                'amount_units' => 150_000_000,
            ]
        );
    }

    public function test_worker_processes_webhook_and_settles_wallet_once(): void
    {
        $generation = $this->createGeneration();

        // STEP 1: Submit the generation using Laravel's worker.
        $this->mockSuccessfulSubmission($generation);

        $this->dispatchGeneration($generation);

        $this->assertSame(
            1,
            $this->pendingJobs()
        );

        $this->runWorkerOnce();

        $submitted = $generation->fresh();

        $this->assertSame(
            Generation::SUBMISSION_ACCEPTED,
            $submitted->submission_status
        );

        $this->assertSame(
            self::PROVIDER_ID,
            $submitted->provider_job_id
        );

        $this->assertSame(
            0,
            $this->pendingJobs()
        );

        // STEP 2: Deliver a signed completion webhook.
        $payload = $this->webhookPayload();

        $this->sendSignedWebhook($payload);

        $event = ProviderWebhookEvent::query()
            ->where(
                'provider_event_id',
                'evt_workerwebhook123'
            )
            ->firstOrFail();

        $this->assertSame(
            1,
            $this->pendingJobs('webhooks')
        );

        // STEP 3: Mock the completed provider response.
        $this->mockSuccessfulProviderResult();

        // STEP 4: Execute the webhook through the real worker.
        $this->runWorkerOnce(
            'webhooks',
            8
        );

        $completed = $generation->fresh();

        $this->assertTrue(
            $completed->isCompleted()
        );

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

        // STEP 5: Verify wallet settlement.
        $wallet = $completed
            ->user
            ->wallet
            ->fresh();

        $this->assertSame(
            9_893_750_000,
            $wallet->available_units
        );

        $this->assertSame(
            0,
            $wallet->reserved_units
        );

        $this->assertDatabaseHas(
            'wallet_reservations',
            [
                'generation_id' => $generation->id,
                'status' => 'settled',
            ]
        );

        // STEP 6: Verify webhook processing.
        $this->assertSame(
            ProviderWebhookEvent::STATUS_PROCESSED,
            $event->fresh()->status
        );

        $this->assertSame(
            0,
            $this->pendingJobs('webhooks')
        );

        // STEP 7: Deliver the identical webhook again.
        $sendAgain = $payload;

        $this->sendSignedWebhook($sendAgain);

        // Duplicate delivery must not enqueue another job.
        $this->assertSame(
            0,
            $this->pendingJobs('webhooks')
        );

        $this->assertDatabaseCount(
            'provider_webhook_events',
            1
        );

        // STEP 8: Verify no additional wallet charge.
        $walletAfterDuplicate = $completed
            ->user
            ->wallet
            ->fresh();

        $this->assertSame(
            $wallet->available_units,
            $walletAfterDuplicate->available_units
        );

        $this->assertSame(
            $wallet->reserved_units,
            $walletAfterDuplicate->reserved_units
        );

        $this->assertSame(
            106_250_000,
            $generation->fresh()->customer_charge_units
        );

        $this->assertDatabaseCount(
            'wallet_reservations',
            1
        );
    }

    public function test_worker_exhausts_retries_without_refunding_uncertain_generation(): void
    {
        $generation = $this->createGeneration();

        $walletBefore = $generation
            ->user
            ->wallet
            ->fresh();

        $client = Mockery::mock(PixelforgeClient::class);

        // The first provider request times out.
        // Subsequent attempts should not blindly resubmit.
        $client
            ->shouldReceive('createGenerationFromPayload')
            ->once()
            ->with(
                $generation->request_key,
                $generation->provider_request_payload
            )
            ->andThrow(
                new ConnectionException(
                    'Provider response timed out.'
                )
            );

        $this->app->instance(
            PixelforgeClient::class,
            $client
        );

        $failedEvents = [];

        Event::listen(
            JobFailed::class,
            function (JobFailed $event) use (&$failedEvents): void {
                $failedEvents[] = [
                    'connection' => $event->connectionName,
                    'exception' => get_class($event->exception),
                    'message' => $event->exception->getMessage(),
                ];
            }
        );

        $this->dispatchGeneration($generation);

        $this->assertSame(
            1,
            $this->pendingJobs()
        );

        // First execution: provider timeout.
        $this->runWorkerOnce(
            $this->queueName,
            2
        );

        $uncertain = $generation->fresh();

        $this->assertSame(
            Generation::SUBMISSION_UNCERTAIN,
            $uncertain->submission_status
        );

        $this->assertSame(
            1,
            $uncertain->submission_attempts
        );

        $this->assertNull(
            $uncertain->provider_job_id
        );

        $this->assertNotNull(
            $uncertain->submission_reconciliation_due_at
        );

        $this->assertSame(
            1,
            $this->pendingJobs()
        );

        // Make the released job immediately available.
        // This avoids waiting for the real retry delay.
        DB::table('jobs')
            ->where('queue', $this->queueName)
            ->update([
                'available_at' => time() - 1,
            ]);

        // Second execution: maximum attempts is 2.
        $this->runWorkerOnce(
            $this->queueName,
            2
        );

        $afterRetry = $generation->fresh();

        // Provider uncertainty must be preserved.
        $this->assertSame(
            Generation::SUBMISSION_UNCERTAIN,
            $afterRetry->submission_status
        );

        $this->assertNull(
            $afterRetry->provider_job_id
        );

        // No second provider submission.
        $this->assertSame(
            1,
            $afterRetry->submission_attempts
        );

        // The wallet must retain the reservation.
        $walletAfter = $generation
            ->user
            ->wallet
            ->fresh();

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
                'status' => 'active',
                'amount_units' => 150_000_000,
            ]
        );

        // The second worker execution completed without
        // making another provider submission.
        $this->assertSame(
            0,
            $this->pendingJobs()
        );

        // No failed-job event was emitted.
        $this->assertCount(
            0,
            $failedEvents
        );

        // The job was not recorded as failed.
        $this->assertDatabaseCount(
            'failed_jobs',
            0
        );

        // The generation remains uncertain and requires reconciliation.
        $this->assertSame(
            Generation::SUBMISSION_UNCERTAIN,
            $generation->fresh()->submission_status
        );

        $this->assertSame(
            1,
            $generation->fresh()->submission_attempts
        );

    }

    public function test_worker_releases_overlapping_generation_job_without_provider_call(): void
    {
        $generation = $this->createGeneration();

        $walletBefore = $generation->user->wallet->fresh();

        $client = Mockery::mock(PixelforgeClient::class);

        $client->shouldNotReceive('createGenerationFromPayload');

        $this->app->instance(PixelforgeClient::class, $client);

        /*
        * IMPORTANT:
        * Replace this key with the exact lock key used by
        * SubmitPixelforgeGeneration::middleware().
        *
        * Do not assume that this example key matches production.
        */
        $job = new SubmitPixelforgeGeneration($generation->id);

        $middleware = $job->middleware()[0];

        $lockKey = $middleware->getLockKey($job);

        $lock = Cache::lock($lockKey, 90);

        $this->assertTrue(
            $lock->get(),
            'The test could not acquire the overlap lock.'
        );

        try {
            $this->dispatchGeneration($generation);

            $this->assertSame(
                1,
                $this->pendingJobs()
            );

            $this->runWorkerOnce();

            /*
            * WithoutOverlapping should release the competing
            * job instead of executing its handle() method.
            */
            $this->assertSame(
                1,
                $this->pendingJobs()
            );

            $queuedJob = DB::table('jobs')
                ->where('queue', $this->queueName)
                ->first();

            $this->assertNotNull($queuedJob);

            $this->assertSame(
                1,
                (int) $queuedJob->attempts
            );

            $this->assertNull(
                $queuedJob->reserved_at
            );

            /*
            * The generation must remain unchanged because
            * its submission logic was never executed.
            */
            $after = $generation->fresh();

            $this->assertSame(
                0,
                $after->submission_attempts
            );

            $this->assertNull(
                $after->provider_job_id
            );

            /*
            * No wallet reservation may be settled or released.
            */
            $walletAfter = $generation->user->wallet->fresh();

            $this->assertSame(
                $walletBefore->available_units,
                $walletAfter->available_units
            );

            $this->assertSame(
                $walletBefore->reserved_units,
                $walletAfter->reserved_units
            );

            $this->assertDatabaseHas('wallet_reservations', [
                'generation_id' => $generation->id,
                'status' => 'active',
                'amount_units' => 150_000_000,
            ]);
        } finally {
            $lock->release();
        }
    }
}
