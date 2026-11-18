<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SubmitPixelforgeGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\GenerationSubmissionClaimService;
use App\Services\PixelforgeClient;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Mockery;
use Tests\TestCase;

final class SubmitPixelforgeGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function generation(): Generation
    {
        $user = User::factory()->create();

        app(WalletService::class)->addDemoCredits(
            $user,
            10_000_000_000,
            'test:submission-credit'
        );

        return app(GenerationService::class)->create(
            $user,
            '22222222-2222-4222-8222-222222222222',
            'A futuristic city',
            '1024x1024',
            'high',
            [],
            30_000_000
        );
    }

    public function test_successful_submission_is_tracked(): void
    {
        $generation = $this->generation();

        $client = Mockery::mock(PixelforgeClient::class);

        $client->shouldReceive('createGenerationFromPayload')
            ->once()
            ->with(
                $generation->request_key,
                $generation->provider_request_payload
            )
            ->andReturn([
                'id' => 'gen_123ABC',
                'status' => 'queued',
            ]);

        $job = new SubmitPixelforgeGeneration(
            $generation->id
        );

        $job->handle(
            $client,
            app(GenerationSubmissionClaimService::class)
        );

        $fresh = $generation->fresh();

        $this->assertSame(
            Generation::SUBMISSION_ACCEPTED,
            $fresh->submission_status
        );

        $this->assertSame(
            'gen_123ABC',
            $fresh->provider_job_id
        );

        $this->assertSame(
            1,
            $fresh->submission_attempts
        );

        $this->assertNull(
            $fresh->submission_claim_token
        );

        $this->assertNull(
            $fresh->submission_claimed_until
        );

        $this->assertNull(
            $fresh->submission_reconciliation_due_at
        );

        $this->assertNotNull(
            $fresh->submitted_at
        );
    }

    public function test_timeout_marks_submission_uncertain(): void
    {
        $generation = $this->generation();

        $client = Mockery::mock(PixelforgeClient::class);

        $client->shouldReceive('createGenerationFromPayload')
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

        $job = new SubmitPixelforgeGeneration(
            $generation->id
        );

        try {
            $job->handle(
                $client,
                app(GenerationSubmissionClaimService::class)
            );

            $this->fail('Expected connection exception.');
        } catch (ConnectionException $exception) {
            $this->assertSame(
                'Provider response timed out.',
                $exception->getMessage()
            );
        }

        $fresh = $generation->fresh();

        $this->assertSame(
            Generation::SUBMISSION_UNCERTAIN,
            $fresh->submission_status
        );

        $this->assertSame(
            1,
            $fresh->submission_attempts
        );

        $this->assertNull(
            $fresh->provider_job_id
        );

        $this->assertNull(
            $fresh->submission_claim_token
        );

        $this->assertNull(
            $fresh->submission_claimed_until
        );

        $this->assertNotNull(
            $fresh->submission_reconciliation_due_at
        );

        $this->assertSame(
            'Provider response timed out.',
            $fresh->submission_last_error
        );

        $this->assertDatabaseHas(
            'wallet_reservations',
            [
                'generation_id' => $generation->id,
                'status' => 'active',
            ]
        );
    }

    public function test_already_submitted_generation_is_skipped(): void
    {
        $generation = $this->generation();

        app(GenerationService::class)->attachProviderJob(
            $generation,
            'gen_existing123'
        );

        $client = Mockery::mock(PixelforgeClient::class);

        $client->shouldNotReceive(
            'createGenerationFromPayload'
        );

        $job = new SubmitPixelforgeGeneration(
            $generation->id
        );

        $job->handle(
            $client,
            app(GenerationSubmissionClaimService::class)
        );

        $fresh = $generation->fresh();

        $this->assertSame(
            'gen_existing123',
            $fresh->provider_job_id
        );

        $this->assertSame(
            0,
            $fresh->submission_attempts
        );

        $this->assertDatabaseHas(
            'wallet_reservations',
            [
                'generation_id' => $generation->id,
                'status' => 'active',
            ]
        );
    }
}
