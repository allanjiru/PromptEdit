<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\GenerationSubmissionClaimService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class GenerationSubmissionClaimServiceTest extends TestCase
{
    use RefreshDatabase;

    private GenerationSubmissionClaimService $claims;

    protected function setUp(): void
    {
        parent::setUp();

        $this->claims = app(
            GenerationSubmissionClaimService::class
        );
    }

    private function generation(): Generation
    {
        $user = User::factory()->create();

        app(WalletService::class)->addDemoCredits(
            $user,
            10_000_000_000,
            'test:claim-credit'
        );

        return app(GenerationService::class)->create(
            $user,
            (string) Str::uuid(),
            'A futuristic city at sunset',
            '1024x1024',
            'high',
            [],
            30_000_000
        );
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

    public function test_acquire_creates_claim_and_preserves_payload(): void
    {
        $generation = $this->generation();

        $claim = $this->claims->acquire($generation->id);

        $this->assertNotNull($claim);
        $this->assertSame(
            $generation->request_key,
            $claim['request_key']
        );
        $this->assertSame(
            $generation->provider_request_payload,
            $claim['payload']
        );

        $fresh = $generation->fresh();

        $this->assertSame(
            $claim['token'],
            $fresh->submission_claim_token
        );
        $this->assertSame(
            Generation::SUBMISSION_SUBMITTING,
            $fresh->submission_status
        );
        $this->assertSame(1, $fresh->submission_attempts);
        $this->assertNotNull($fresh->submission_claimed_until);

        $this->assertReservationActive($generation);
    }

    public function test_active_claim_prevents_second_acquisition(): void
    {
        $generation = $this->generation();

        $first = $this->claims->acquire($generation->id);
        $second = $this->claims->acquire($generation->id);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(
            1,
            $generation->fresh()->submission_attempts
        );
    }

    public function test_expired_claim_can_be_reacquired(): void
    {
        $generation = $this->generation();

        $first = $this->claims->acquire($generation->id);

        $generation->forceFill([
            'submission_claimed_until' => now()->subMinute(),
        ])->save();

        $second = $this->claims->acquire($generation->id);

        $this->assertNotNull($second);
        $this->assertNotSame(
            $first['token'],
            $second['token']
        );
        $this->assertSame(
            2,
            $generation->fresh()->submission_attempts
        );
    }

    public function test_stale_token_cannot_accept(): void
    {
        $generation = $this->generation();

        $first = $this->claims->acquire($generation->id);

        $generation->forceFill([
            'submission_claimed_until' => now()->subMinute(),
        ])->save();

        $second = $this->claims->acquire($generation->id);

        $accepted = $this->claims->accepted(
            $generation->id,
            $first['token'],
            'gen_stale123'
        );

        $this->assertFalse($accepted);

        $fresh = $generation->fresh();

        $this->assertNull($fresh->provider_job_id);
        $this->assertSame(
            $second['token'],
            $fresh->submission_claim_token
        );
    }

    public function test_stale_token_cannot_mark_uncertain(): void
    {
        $generation = $this->generation();

        $first = $this->claims->acquire($generation->id);

        $generation->forceFill([
            'submission_claimed_until' => now()->subMinute(),
        ])->save();

        $second = $this->claims->acquire($generation->id);

        $updated = $this->claims->uncertain(
            $generation->id,
            $first['token'],
            'Old worker timed out'
        );

        $this->assertFalse($updated);

        $fresh = $generation->fresh();

        $this->assertSame(
            Generation::SUBMISSION_SUBMITTING,
            $fresh->submission_status
        );
        $this->assertSame(
            $second['token'],
            $fresh->submission_claim_token
        );
    }

    public function test_acceptance_attaches_provider_id_atomically(): void
    {
        $generation = $this->generation();

        $claim = $this->claims->acquire($generation->id);

        $accepted = $this->claims->accepted(
            $generation->id,
            $claim['token'],
            'gen_accepted123'
        );

        $this->assertTrue($accepted);

        $fresh = $generation->fresh();

        $this->assertSame(
            'gen_accepted123',
            $fresh->provider_job_id
        );
        $this->assertSame(
            Generation::SUBMISSION_ACCEPTED,
            $fresh->submission_status
        );
        $this->assertNull($fresh->submission_claim_token);
        $this->assertNull($fresh->submission_claimed_until);
        $this->assertNull(
            $fresh->submission_reconciliation_due_at
        );
        $this->assertNotNull($fresh->submitted_at);

        $this->assertReservationActive($generation);
    }

    public function test_uncertain_submission_preserves_reservation(): void
    {
        $generation = $this->generation();

        $claim = $this->claims->acquire($generation->id);

        $updated = $this->claims->uncertain(
            $generation->id,
            $claim['token'],
            'Connection timed out'
        );

        $this->assertTrue($updated);

        $fresh = $generation->fresh();

        $this->assertSame(
            Generation::SUBMISSION_UNCERTAIN,
            $fresh->submission_status
        );
        $this->assertNull($fresh->provider_job_id);
        $this->assertNull($fresh->submission_claim_token);
        $this->assertNull($fresh->submission_claimed_until);
        $this->assertNotNull(
            $fresh->submission_reconciliation_due_at
        );
        $this->assertSame(
            'Connection timed out',
            $fresh->submission_last_error
        );

        $this->assertReservationActive($generation);
    }

    public function test_missing_snapshot_requires_manual_review(): void
    {
        $generation = $this->generation();

        $generation->provider_request_payload = null;
        $generation->save();

        $claim = $this->claims->acquire($generation->id);

        $this->assertNull($claim);

        $fresh = $generation->fresh();

        $this->assertSame(
            Generation::SUBMISSION_NEEDS_REVIEW,
            $fresh->submission_status
        );
        $this->assertSame(0, $fresh->submission_attempts);
        $this->assertNull($fresh->submission_claim_token);

        $this->assertReservationActive($generation);
    }

    public function test_terminal_generation_cannot_be_claimed(): void
    {
        $generation = $this->generation();

        app(GenerationService::class)->fail(
            $generation,
            'Provider rejected request'
        );

        $claim = $this->claims->acquire($generation->id);

        $this->assertNull($claim);
        $this->assertSame(
            0,
            $generation->fresh()->submission_attempts
        );
    }

    public function test_terminal_generation_cannot_be_accepted(): void
    {
        $generation = $this->generation();

        $claim = $this->claims->acquire($generation->id);

        app(GenerationService::class)->fail(
            $generation,
            'Provider failed'
        );

        $accepted = $this->claims->accepted(
            $generation->id,
            $claim['token'],
            'gen_late123'
        );

        $this->assertFalse($accepted);
        $this->assertNull(
            $generation->fresh()->provider_job_id
        );
    }

    public function test_accepted_generation_cannot_be_claimed_again(): void
    {
        $generation = $this->generation();

        $claim = $this->claims->acquire($generation->id);

        $this->claims->accepted(
            $generation->id,
            $claim['token'],
            'gen_existing123'
        );

        $second = $this->claims->acquire($generation->id);

        $this->assertNull($second);
        $this->assertSame(
            1,
            $generation->fresh()->submission_attempts
        );
    }
}
