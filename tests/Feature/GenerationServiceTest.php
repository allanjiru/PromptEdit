<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\GenerationService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Integration tests for the proposed GenerationService contract.
 * Adjust signatures/status names to the actual implementation before use.
 */
final class GenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private GenerationService $generations;
    private WalletService $wallets;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generations = app(GenerationService::class);
        $this->wallets = app(WalletService::class);
        $this->user = User::factory()->create();
        $this->wallets->addDemoCredits($this->user, 10_000_000_000, 'test:initial-credit');
    }

    private function create(string $key = '11111111-1111-4111-8111-111111111111'): Generation
    {
        return $this->generations->create(
            $this->user,
            $key,
            'A futuristic city at sunset',
            '1024x1024',
            'high',
            [],
            30_000_000
        );
    }

    private function wallet(): array
    {
        $wallet = $this->user->wallet()->firstOrFail()->fresh();
        return [(int) $wallet->available_units, (int) $wallet->reserved_units];
    }

    public function test_creation_reserves_funds_and_persists_request(): void
    {
        $generation = $this->create();

        $this->assertSame($this->user->id, $generation->user_id);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $generation->request_key);
        $this->assertSame([9_970_000_000, 30_000_000], $this->wallet());
        $this->assertDatabaseHas('wallet_reservations', [
            'generation_id' => $generation->id,
            'amount_units' => 30_000_000,
            'status' => 'active',
        ]);
    }

    public function test_retrying_same_request_does_not_reserve_twice(): void
    {
        $first = $this->create();
        $second = $this->create();

        $this->assertSame($first->id, $second->id);
        $this->assertSame([9_970_000_000, 30_000_000], $this->wallet());
        $this->assertDatabaseCount('generations', 1);
        $this->assertDatabaseCount('wallet_reservations', 1);
    }

    public function test_provider_job_id_is_persisted(): void
    {
        $generation = $this->create();
        $this->generations->attachProviderJob($generation, 'gen_test_123');

        $this->assertSame('gen_test_123', $generation->fresh()->provider_job_id);
    }

    public function test_processing_transition_is_persisted(): void
    {
        $generation = $this->create();
        $this->generations->attachProviderJob($generation, 'gen_test_123');
        $this->generations->markProcessing($generation);

        $this->assertSame('processing', $generation->fresh()->status);
        $this->assertSame([9_970_000_000, 30_000_000], $this->wallet());
    }

    public function test_success_settles_actual_charge_and_stores_images(): void
    {
        $generation = $this->create();
        $this->generations->attachProviderJob($generation, 'gen_test_123');
        $this->generations->markProcessing($generation);
        $this->generations->succeed($generation, 19_500_000, ['https://example.test/output.png']);

        $fresh = $generation->fresh();
        $this->assertContains($fresh->status, ['completed', 'succeeded']);
        $this->assertSame(19_500_000, (int) $fresh->provider_cost_units);
        $this->assertSame(24_375_000, (int) $fresh->customer_charge_units);
        $this->assertSame(['https://example.test/output.png'], $fresh->result_image_urls);
        $this->assertSame([9_975_625_000, 0], $this->wallet());
        $this->assertDatabaseHas('wallet_reservations', [
            'generation_id' => $generation->id,
            'status' => 'settled',
        ]);
    }

    public function test_duplicate_success_does_not_charge_twice(): void
    {
        $generation = $this->create();
        $images = ['https://example.test/output.png'];
        $this->generations->succeed($generation, 19_500_000, $images);
        $this->generations->succeed($generation, 19_500_000, $images);

        $this->assertSame([9_975_625_000, 0], $this->wallet());
        $this->assertSame(1, WalletTransaction::query()
            ->where('generation_id', $generation->id)
            ->where('type', WalletTransaction::TYPE_GENERATION_CHARGE)
            ->count());
    }

    public function test_failure_releases_reservation_and_records_reason(): void
    {
        $generation = $this->create();
        $this->generations->fail($generation, 'Provider rejected the request');

        $fresh = $generation->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('Provider rejected the request', $fresh->error_message);
        $this->assertSame([10_000_000_000, 0], $this->wallet());
        $this->assertDatabaseHas('wallet_reservations', [
            'generation_id' => $generation->id,
            'status' => 'released',
        ]);
    }

    public function test_duplicate_failure_does_not_refund_twice(): void
    {
        $generation = $this->create();
        $this->generations->fail($generation, 'Provider failed');
        $this->generations->fail($generation, 'Provider failed');

        $this->assertSame([10_000_000_000, 0], $this->wallet());
        $this->assertDatabaseCount('wallet_reservations', 1);
    }

    public function test_failure_cannot_override_success(): void
    {
        $generation = $this->create();
        $this->generations->succeed($generation, 19_500_000, ['https://example.test/output.png']);

        $this->expectException(LogicException::class);
        $this->generations->fail($generation, 'Late failure callback');
    }

    public function test_success_cannot_override_failure(): void
    {
        $generation = $this->create();
        $this->generations->fail($generation, 'Provider failed');

        $this->expectException(LogicException::class);
        $this->generations->succeed($generation, 19_500_000, ['https://example.test/output.png']);
    }
}