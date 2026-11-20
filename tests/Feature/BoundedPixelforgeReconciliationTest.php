<?php

namespace Tests\Feature;

use App\Jobs\ReconcilePixelforgeGeneration;
use App\Jobs\SubmitPixelforgeGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\GenerationSubmissionClaimService;
use App\Services\PixelforgeClient;
use App\Services\PixelforgeReconciliationState;
use App\Services\PixelforgeResultProcessor;
use App\Services\PixelforgeResultReconciler;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class BoundedPixelforgeReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.pixelforge.api_key' => 'test-key',
            'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
        ]);
    }

    private function generation(string $providerId = 'gen_bounded123'): Generation
    {
        $user = User::factory()->create();
        app(WalletService::class)->addDemoCredits(
            $user,
            10_000_000_000,
            (string) Str::uuid()
        );

        $generation = app(GenerationService::class)->create(
            $user,
            (string) Str::uuid(),
            'Bounded reconciliation',
            '1024x1024',
            'medium',
            [],
            100_000_000
        );
        app(GenerationService::class)->attachProviderJob(
            $generation,
            $providerId
        );
        $generation->forceFill([
            'submission_status' => Generation::SUBMISSION_ACCEPTED,
        ])->save();

        return $generation->refresh();
    }

    private function success(string $providerId): array
    {
        return [
            'id' => $providerId,
            'status' => 'succeeded',
            'usage' => [
                'input_tokens' => 1000,
                'output_tokens' => 2000,
            ],
            'rates' => [
                'input_tokens' => '5.00',
                'output_tokens' => '40.00',
            ],
            'image_url' => 'https://pixelforge.promptedit.dev/images/bounded.png',
        ];
    }

    public function test_accepted_generation_schedules_initial_check(): void
    {
        Queue::fake();
        $generation = $this->generation();
        $generation->provider_job_id = null;
        $generation->submission_status = Generation::SUBMISSION_PENDING;
        $generation->submitted_at = null;
        $generation->save();

        $client = Mockery::mock(PixelforgeClient::class);
        $client->shouldReceive('createGenerationFromPayload')
            ->once()
            ->andReturn(['id' => 'gen_new123', 'status' => 'queued']);

        (new SubmitPixelforgeGeneration($generation->id))->handle(
            $client,
            app(GenerationSubmissionClaimService::class)
        );

        Queue::assertPushedOn(
            'generations',
            ReconcilePixelforgeGeneration::class
        );
        $this->assertNotNull(
            $generation->refresh()->result_reconciliation_due_at
        );
    }

    public function test_completion_is_detected_and_settled_once(): void
    {
        Queue::fake();
        $generation = $this->generation();
        $client = Mockery::mock(PixelforgeClient::class);
        $client->shouldReceive('retrieveGeneration')
            ->once()
            ->andReturn($this->success($generation->provider_job_id));
        $this->app->instance(PixelforgeClient::class, $client);

        $result = app(PixelforgeResultReconciler::class)->reconcile(
            $generation->id
        );
        $wallet = $generation->user->wallet->fresh();

        $this->assertSame('terminal', $result['outcome']);
        $this->assertSame(0, $wallet->reserved_units);
        $this->assertSame(
            Generation::STATUS_COMPLETED,
            $generation->refresh()->status
        );

        $second = app(PixelforgeResultReconciler::class)->reconcile(
            $generation->id
        );
        $this->assertSame('skipped', $second['outcome']);
        $this->assertSame(
            $wallet->available_units,
            $generation->user->wallet->fresh()->available_units
        );
    }

    public function test_pending_result_schedules_exact_next_delay(): void
    {
        $generation = $this->generation();
        $client = Mockery::mock(PixelforgeClient::class);
        $client->shouldReceive('retrieveGeneration')->once()->andReturn([
            'id' => $generation->provider_job_id,
            'status' => 'processing',
        ]);
        $this->app->instance(PixelforgeClient::class, $client);

        $result = app(PixelforgeResultReconciler::class)->reconcile(
            $generation->id
        );

        $this->assertSame('pending', $result['outcome']);
        $this->assertSame(10, $result['delay']);
        $this->assertSame(
            1,
            $generation->refresh()->result_reconciliation_attempts
        );
    }

    public function test_terminal_generation_never_calls_provider(): void
    {
        $generation = $this->generation();
        $generation->status = Generation::STATUS_FAILED;
        $generation->failed_at = now();
        $generation->save();

        $client = Mockery::mock(PixelforgeClient::class);
        $client->shouldNotReceive('retrieveGeneration');
        $this->app->instance(PixelforgeClient::class, $client);

        $result = app(PixelforgeResultReconciler::class)->reconcile(
            $generation->id
        );

        $this->assertSame('skipped', $result['outcome']);
    }

    public function test_webhook_completion_neutralizes_pending_check(): void
    {
        Queue::fake();
        $generation = $this->generation();
        app(PixelforgeResultProcessor::class)->process(
            $generation->provider_job_id,
            $this->success($generation->provider_job_id)
        );

        $client = Mockery::mock(PixelforgeClient::class);
        $client->shouldNotReceive('retrieveGeneration');
        $this->app->instance(PixelforgeClient::class, $client);

        $this->assertSame(
            'skipped',
            app(PixelforgeResultReconciler::class)
                ->reconcile($generation->id)['outcome']
        );
    }

    public function test_concurrent_claims_share_one_provider_request_budget(): void
    {
        $generation = $this->generation();
        $state = app(PixelforgeReconciliationState::class);

        $first = $state->claim($generation->id);
        $second = $state->claim($generation->id);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(
            1,
            $generation->refresh()->result_reconciliation_attempts
        );
    }

    public function test_transient_error_uses_safe_backoff_without_failure(): void
    {
        $generation = $this->generation();
        Http::fake([
            '*' => Http::response(['message' => 'Unavailable'], 503),
        ]);

        $result = app(PixelforgeResultReconciler::class)->reconcile(
            $generation->id
        );

        $this->assertSame('error', $result['outcome']);
        $this->assertSame(10, $result['delay']);
        $this->assertFalse($generation->refresh()->isTerminal());
    }

    public function test_rate_limit_retry_after_extends_backoff(): void
    {
        $generation = $this->generation();
        Http::fake([
            '*' => Http::response(
                ['message' => 'Rate limited'],
                429,
                ['Retry-After' => '120']
            ),
        ]);

        $result = app(PixelforgeResultReconciler::class)->reconcile(
            $generation->id
        );

        $this->assertSame('error', $result['outcome']);
        $this->assertSame(120, $result['delay']);
    }

    public function test_retry_after_beyond_queue_cap_is_durably_deferred(): void
    {
        $generation = $this->generation();
        Http::fake([
            '*' => Http::response(
                ['message' => 'Rate limited'],
                429,
                ['Retry-After' => '172800']
            ),
        ]);

        $result = app(PixelforgeResultReconciler::class)->reconcile(
            $generation->id
        );

        $this->assertSame('error', $result['outcome']);
        $this->assertNull($result['delay']);
        $this->assertTrue(
            $generation->refresh()->result_reconciliation_due_at
                ->greaterThanOrEqualTo(now()->addSeconds(172799))
        );
    }

    public function test_extreme_retry_after_explicitly_pauses_reconciliation(): void
    {
        $generation = $this->generation();
        Http::fake([
            '*' => Http::response(
                ['message' => 'Rate limited'],
                429,
                ['Retry-After' => '31536001']
            ),
        ]);

        $result = app(PixelforgeResultReconciler::class)->reconcile(
            $generation->id
        );

        $paused = $generation->refresh();
        $this->assertSame('error', $result['outcome']);
        $this->assertNull($result['delay']);
        $this->assertNull($paused->result_reconciliation_due_at);
        $this->assertNotNull($paused->result_reconciliation_exhausted_at);
        $this->assertFalse($paused->isTerminal());
    }

    public function test_expired_crash_lease_is_recoverable(): void
    {
        $generation = $this->generation();
        $state = app(PixelforgeReconciliationState::class);

        $this->assertNotNull($state->claim($generation->id));
        $this->travel(76)->seconds();
        $this->assertNotNull($state->claim($generation->id));
        $this->assertSame(
            2,
            $generation->refresh()->result_reconciliation_attempts
        );
    }

    public function test_provider_request_budget_is_strictly_bounded(): void
    {
        $generation = $this->generation();
        $state = app(PixelforgeReconciliationState::class);

        for (
            $attempt = 1;
            $attempt <= PixelforgeReconciliationState::MAX_PROVIDER_REQUESTS;
            $attempt++
        ) {
            $claim = $state->claim($generation->id);
            $this->assertNotNull($claim);
            $state->release(
                $generation->id,
                $claim['token'],
                $claim['attempt']
            );

            Generation::query()
                ->whereKey($generation->id)
                ->update(['result_reconciliation_due_at' => now()]);
        }

        $this->assertNull($state->claim($generation->id));
        $this->assertSame(
            PixelforgeReconciliationState::MAX_PROVIDER_REQUESTS,
            $generation->refresh()->result_reconciliation_attempts
        );
        $exhausted = $generation->refresh();
        $this->assertNotNull(
            $exhausted->result_reconciliation_exhausted_at
        );
        $this->assertSame(
            Generation::SUBMISSION_ACCEPTED,
            $exhausted->submission_status
        );
        $this->assertFalse($exhausted->isTerminal());

        $this->actingAs($exhausted->user)
            ->getJson("/generations/{$exhausted->id}")
            ->assertOk()
            ->assertJsonPath('status', Generation::STATUS_QUEUED)
            ->assertJsonPath(
                'submission_status',
                Generation::SUBMISSION_ACCEPTED
            )
            ->assertJsonPath('reconciliation_paused', true);
    }
}
