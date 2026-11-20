<?php

namespace Tests\Feature;

use App\Jobs\SubmitPixelforgeGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\GenerationSubmissionClaimService;
use App\Services\GenerationSubmissionRecoveryService;
use App\Services\WalletService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RecoverPixelforgeSubmissionsTest extends TestCase
{
    use RefreshDatabase;

    private GenerationSubmissionRecoveryService $recovery;

    private GenerationSubmissionClaimService $claims;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recovery = app(
            GenerationSubmissionRecoveryService::class
        );

        $this->claims = app(
            GenerationSubmissionClaimService::class
        );
    }

    private function generation(
        array $attributes = []
    ): Generation {
        $user = User::factory()->create();

        app(WalletService::class)->addDemoCredits(
            $user,
            10_000_000_000,
            'test:recovery-credit:'.$user->id
        );

        $generation = app(GenerationService::class)->create(
            $user,
            (string) Str::uuid(),
            'A futuristic city at sunset',
            '1024x1024',
            'high',
            [],
            30_000_000
        );

        if ($attributes !== []) {
            $generation->forceFill($attributes)->save();
        }

        return $generation->fresh();
    }

    private function oldPendingGeneration(): Generation
    {
        $generation = $this->generation();

        $generation->forceFill([
            'created_at' => now()->subMinutes(5),
        ])->save();

        return $generation->fresh();
    }

    private function assertReservationActive(
        Generation $generation
    ): void {
        $this->assertDatabaseHas('wallet_reservations', [
            'generation_id' => $generation->id,
            'status' => 'active',
            'amount_units' => 30_000_000,
        ]);
    }

    public function test_old_pending_generation_is_recovered(): void
    {
        Queue::fake();

        $generation = $this->oldPendingGeneration();

        Artisan::call('pixelforge:recover-submissions');

        Queue::assertPushed(
            SubmitPixelforgeGeneration::class,
            function ($job) use ($generation) {
                return $job->generationId === $generation->id
                    && is_string($job->dispatchToken)
                    && $job->dispatchToken !== '';
            }
        );

        $fresh = $generation->fresh();

        $this->assertNotNull(
            $fresh->recovery_dispatch_token
        );

        $this->assertNotNull(
            $fresh->recovery_dispatch_until
        );

        $this->assertSame(
            Generation::SUBMISSION_PENDING,
            $fresh->submission_status
        );

        $this->assertSame(
            0,
            $fresh->submission_attempts
        );

        $this->assertReservationActive($generation);
    }

    public function test_recent_pending_generation_is_skipped(): void
    {
        Queue::fake();

        $this->generation();

        Artisan::call('pixelforge:recover-submissions');

        Queue::assertNotPushed(
            SubmitPixelforgeGeneration::class
        );
    }

    public function test_due_uncertain_generation_is_recovered(): void
    {
        Queue::fake();

        $generation = $this->generation([
            'submission_status' => Generation::SUBMISSION_UNCERTAIN,
            'submission_reconciliation_due_at' => now()->subMinute(),
        ]);

        Artisan::call('pixelforge:recover-submissions');

        Queue::assertPushed(
            SubmitPixelforgeGeneration::class,
            function ($job) use ($generation) {
                return $job->generationId === $generation->id
                    && $job->dispatchToken !== null;
            }
        );

        $this->assertSame(
            Generation::SUBMISSION_UNCERTAIN,
            $generation->fresh()->submission_status
        );

        $this->assertReservationActive($generation);
    }

    public function test_uncertain_generation_not_yet_due_is_skipped(): void
    {
        Queue::fake();

        $this->generation([
            'submission_status' => Generation::SUBMISSION_UNCERTAIN,
            'submission_reconciliation_due_at' => now()->addMinutes(5),
        ]);

        Artisan::call('pixelforge:recover-submissions');

        Queue::assertNotPushed(
            SubmitPixelforgeGeneration::class
        );
    }

    public function test_expired_provider_claim_is_recovered(): void
    {
        Queue::fake();

        $generation = $this->generation([
            'submission_status' => Generation::SUBMISSION_SUBMITTING,
            'submission_claim_token' => (string) Str::uuid(),
            'submission_claimed_until' => now()->subMinute(),
        ]);

        Artisan::call('pixelforge:recover-submissions');

        Queue::assertPushed(
            SubmitPixelforgeGeneration::class,
            function ($job) use ($generation) {
                return $job->generationId === $generation->id
                    && $job->dispatchToken !== null;
            }
        );
    }

    public function test_active_provider_claim_is_skipped(): void
    {
        Queue::fake();

        $this->generation([
            'submission_status' => Generation::SUBMISSION_SUBMITTING,
            'submission_claim_token' => (string) Str::uuid(),
            'submission_claimed_until' => now()->addMinutes(5),
        ]);

        Artisan::call('pixelforge:recover-submissions');

        Queue::assertNotPushed(
            SubmitPixelforgeGeneration::class
        );
    }

    public function test_active_recovery_dispatch_prevents_duplicate(): void
    {
        Queue::fake();

        $generation = $this->oldPendingGeneration();

        Artisan::call('pixelforge:recover-submissions');
        Artisan::call('pixelforge:recover-submissions');

        Queue::assertPushed(
            SubmitPixelforgeGeneration::class,
            1
        );

        $this->assertNotNull(
            $generation->fresh()->recovery_dispatch_token
        );
    }

    public function test_expired_recovery_dispatch_can_be_replaced(): void
    {
        $generation = $this->oldPendingGeneration();

        $oldToken = (string) Str::uuid();

        $generation->forceFill([
            'recovery_dispatch_token' => $oldToken,
            'recovery_dispatch_until' => now()->subMinute(),
        ])->save();

        $newToken = $this->recovery->reserve(
            $generation->id
        );

        $this->assertNotNull($newToken);
        $this->assertNotSame($oldToken, $newToken);

        $this->assertSame(
            $newToken,
            $generation->fresh()->recovery_dispatch_token
        );
    }

    public function test_valid_recovery_token_can_acquire_provider_claim(): void
    {
        $generation = $this->oldPendingGeneration();

        $dispatchToken = $this->recovery->reserve(
            $generation->id
        );

        $this->assertNotNull($dispatchToken);

        $claim = $this->claims->acquire(
            $generation->id,
            $dispatchToken
        );

        $this->assertNotNull($claim);

        $fresh = $generation->fresh();

        $this->assertNull(
            $fresh->recovery_dispatch_token
        );

        $this->assertNull(
            $fresh->recovery_dispatch_until
        );

        $this->assertSame(
            Generation::SUBMISSION_SUBMITTING,
            $fresh->submission_status
        );

        $this->assertSame(
            1,
            $fresh->submission_attempts
        );

        $this->assertSame(
            $claim['token'],
            $fresh->submission_claim_token
        );

        $this->assertSame(
            $generation->provider_request_payload,
            $claim['payload']
        );

        $this->assertReservationActive($generation);
    }

    public function test_invalid_recovery_token_cannot_acquire(): void
    {
        $generation = $this->oldPendingGeneration();

        $validToken = $this->recovery->reserve(
            $generation->id
        );

        $this->assertNotNull($validToken);

        $claim = $this->claims->acquire(
            $generation->id,
            (string) Str::uuid()
        );

        $this->assertNull($claim);

        $fresh = $generation->fresh();

        $this->assertSame(
            0,
            $fresh->submission_attempts
        );

        $this->assertSame(
            $validToken,
            $fresh->recovery_dispatch_token
        );

        $this->assertReservationActive($generation);
    }

    public function test_accepted_generation_is_not_recovered(): void
    {
        Queue::fake();

        $generation = $this->oldPendingGeneration();

        $claim = $this->claims->acquire(
            $generation->id
        );

        $this->assertNotNull($claim);

        $this->assertTrue(
            $this->claims->accepted(
                $generation->id,
                $claim['token'],
                'gen_recoveryaccepted123'
            )
        );

        Artisan::call('pixelforge:recover-submissions');

        Queue::assertNotPushed(
            SubmitPixelforgeGeneration::class
        );

        $this->assertSame(
            Generation::SUBMISSION_ACCEPTED,
            $generation->fresh()->submission_status
        );
    }

    public function test_invalid_limit_is_rejected(): void
    {
        Queue::fake();

        $exitCode = Artisan::call(
            'pixelforge:recover-submissions',
            ['--limit' => 0]
        );

        $this->assertSame(1, $exitCode);

        Queue::assertNotPushed(
            SubmitPixelforgeGeneration::class
        );
    }

    public function test_stale_recovery_token_cannot_acquire_after_lease_replacement(): void
    {
        $generation = $this->oldPendingGeneration();

        $oldToken = $this->recovery->reserve(
            $generation->id
        );

        $this->assertNotNull($oldToken);

        $generation->forceFill([
            'recovery_dispatch_until' => now()->subMinute(),
        ])->save();

        $newToken = $this->recovery->reserve(
            $generation->id
        );

        $this->assertNotNull($newToken);
        $this->assertNotSame($oldToken, $newToken);

        $staleClaim = $this->claims->acquire(
            $generation->id,
            $oldToken
        );

        $this->assertNull($staleClaim);

        $validClaim = $this->claims->acquire(
            $generation->id,
            $newToken
        );

        $this->assertNotNull($validClaim);

        $this->assertSame(
            1,
            $generation->fresh()->submission_attempts
        );

        $this->assertReservationActive($generation);
    }

    public function test_recovery_does_not_modify_immutable_provider_payload(): void
    {
        $generation = $this->oldPendingGeneration();

        $originalPayload =
            $generation->provider_request_payload;

        $originalRequestKey =
            $generation->request_key;

        $dispatchToken = $this->recovery->reserve(
            $generation->id
        );

        $this->assertNotNull($dispatchToken);

        $claim = $this->claims->acquire(
            $generation->id,
            $dispatchToken
        );

        $this->assertNotNull($claim);

        $fresh = $generation->fresh();

        $this->assertSame(
            $originalPayload,
            $fresh->provider_request_payload
        );

        $this->assertSame(
            $originalPayload,
            $claim['payload']
        );

        $this->assertSame(
            $originalRequestKey,
            $fresh->request_key
        );

        $this->assertSame(
            $originalRequestKey,
            $claim['request_key']
        );

        $this->assertReservationActive($generation);
    }

    public function test_recovery_does_not_create_duplicate_wallet_reservations(): void
    {
        $generation = $this->oldPendingGeneration();

        $before = DB::table(
            'wallet_reservations'
        )->where(
            'generation_id',
            $generation->id
        )->count();

        $this->assertSame(1, $before);

        $firstToken = $this->recovery->reserve(
            $generation->id
        );

        $this->assertNotNull($firstToken);

        $claim = $this->claims->acquire(
            $generation->id,
            $firstToken
        );

        $this->assertNotNull($claim);

        $this->assertTrue(
            $this->claims->uncertain(
                $generation->id,
                $claim['token'],
                'Simulated provider timeout'
            )
        );

        $generation->refresh();

        $generation->forceFill([
            'submission_reconciliation_due_at' => now()->subMinute(),
        ])->save();

        $secondToken = $this->recovery->reserve(
            $generation->id
        );

        $this->assertNotNull($secondToken);

        $after = DB::table(
            'wallet_reservations'
        )->where(
            'generation_id',
            $generation->id
        )->count();

        $this->assertSame(1, $after);

        $this->assertReservationActive($generation);
    }

    public function test_recovery_skips_generation_requiring_manual_review(): void
    {
        Queue::fake();

        $generation = $this->oldPendingGeneration();

        $generation->forceFill([
            'submission_status' => Generation::SUBMISSION_NEEDS_REVIEW,
            'submission_last_error' => 'Manual reconciliation required',
        ])->save();

        Artisan::call(
            'pixelforge:recover-submissions'
        );

        Queue::assertNotPushed(
            SubmitPixelforgeGeneration::class
        );

        $this->assertReservationActive($generation);
    }

    public function test_recovery_respects_dispatch_limit(): void
    {
        Queue::fake();

        for ($i = 0; $i < 3; $i++) {
            $this->oldPendingGeneration();
        }

        $exitCode = Artisan::call(
            'pixelforge:recover-submissions',
            ['--limit' => 2]
        );

        $this->assertSame(0, $exitCode);

        Queue::assertPushed(
            SubmitPixelforgeGeneration::class,
            2
        );

        $remaining = Generation::query()
            ->whereNull('recovery_dispatch_token')
            ->where(
                'submission_status',
                Generation::SUBMISSION_PENDING
            )
            ->count();

        $this->assertSame(1, $remaining);
    }

    public function test_failed_queue_dispatch_can_be_recovered_after_lease_expiry(): void
    {
        $generation = $this->oldPendingGeneration();

        $originalPayload = $generation->provider_request_payload;
        $originalRequestKey = $generation->request_key;

        // Preserve the real dispatcher before replacing it.
        $originalDispatcher = app(
            Dispatcher::class
        );

        $failureTriggered = false;

        $dispatcher = \Mockery::mock(
            Dispatcher::class
        );

        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(\Mockery::on(
                function ($job) use ($generation): bool {
                    return $job instanceof SubmitPixelforgeGeneration
                        && $job->generationId === $generation->id
                        && $job->dispatchToken !== null
                        && $job->queue === 'generations';
                }
            ))
            ->andReturnUsing(
                function () use (&$failureTriggered) {
                    $failureTriggered = true;

                    throw new \RuntimeException(
                        'Simulated queue backend unavailable'
                    );
                }
            );

        // Replace the dispatcher used by the command.
        $this->app->instance(
            Dispatcher::class,
            $dispatcher
        );

        // The command intentionally reports the exception.
        // Suppress reporting during this expected failure test.
        $this->withoutExceptionHandling();

        $exitCode = Artisan::call(
            'pixelforge:recover-submissions'
        );

        $this->assertSame(0, $exitCode);
        $this->assertTrue($failureTriggered);

        $fresh = $generation->fresh();

        $failedToken = $fresh->recovery_dispatch_token;

        $this->assertNotNull($failedToken);
        $this->assertNotNull($fresh->recovery_dispatch_until);

        $this->assertSame(
            Generation::SUBMISSION_PENDING,
            $fresh->submission_status
        );

        $this->assertSame(
            0,
            $fresh->submission_attempts
        );

        $this->assertReservationActive($generation);

        // The active dispatch lease prevents immediate retry.
        $this->assertNull(
            $this->recovery->reserve($generation->id)
        );

        // Simulate expiry of the failed dispatch lease.
        $fresh->forceFill([
            'recovery_dispatch_until' => now()->subMinute(),
        ])->save();

        // Restore the original Laravel dispatcher.
        $this->app->instance(
            Dispatcher::class,
            $originalDispatcher
        );

        // Fake successful queue dispatch.
        Queue::fake();

        $retryExitCode = Artisan::call(
            'pixelforge:recover-submissions'
        );

        $this->assertSame(0, $retryExitCode);

        Queue::assertPushed(
            SubmitPixelforgeGeneration::class,
            1
        );

        $recovered = $generation->fresh();

        $newToken = $recovered->recovery_dispatch_token;

        $this->assertNotNull($newToken);
        $this->assertNotSame($failedToken, $newToken);

        // Old token must not acquire a provider claim.
        $this->assertNull(
            $this->claims->acquire(
                $generation->id,
                $failedToken
            )
        );

        // Replacement token must work.
        $claim = $this->claims->acquire(
            $generation->id,
            $newToken
        );

        $this->assertNotNull($claim);

        $this->assertSame(
            $originalPayload,
            $claim['payload']
        );

        $this->assertSame(
            $originalRequestKey,
            $claim['request_key']
        );

        $this->assertReservationActive($generation);
    }
}
