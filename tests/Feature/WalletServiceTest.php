<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $walletService;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->walletService = app(WalletService::class);
        $this->user = User::factory()->create();
    }

    private function createGeneration(): Generation
    {
        return Generation::create([
            'user_id' => $this->user->id,
            'request_key' => (string) str()->uuid(),
            'prompt' => 'A futuristic city at sunset',
            'image_size' => '1024x1024',
            'quality' => 'high',
            'reference_image_urls' => [],
        ]);
    }

    private function fundWallet(
        int $amountUnits = 10_000_000_000
    ): void {
        $this->walletService->addDemoCredits(
            $this->user,
            $amountUnits,
            'test:initial-credit'
        );
    }

    public function test_it_creates_only_one_wallet_per_user(): void
    {
        $first = $this->walletService
            ->ensureWallet($this->user);

        $second = $this->walletService
            ->ensureWallet($this->user);

        $this->assertEquals($first->id, $second->id);

        $this->assertDatabaseCount('wallets', 1);
    }

    public function test_it_adds_demo_credits(): void
    {
        $this->fundWallet();

        $wallet = $this->user->wallet()->firstOrFail();

        $this->assertSame(
            10_000_000_000,
            $wallet->available_units
        );

        $this->assertSame(
            0,
            $wallet->reserved_units
        );

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_DEMO_CREDIT,
            'available_delta_units' => 10_000_000_000,
        ]);
    }

    public function test_duplicate_credit_requests_do_not_add_money_twice(): void
    {
        $first = $this->walletService->addDemoCredits(
            $this->user,
            5_000_000_000,
            'test:duplicate-credit'
        );

        $second = $this->walletService->addDemoCredits(
            $this->user,
            5_000_000_000,
            'test:duplicate-credit'
        );

        $this->assertSame($first->id, $second->id);

        $wallet = $this->user->wallet()->firstOrFail();

        $this->assertSame(
            5_000_000_000,
            $wallet->available_units
        );

        $this->assertDatabaseCount(
            'wallet_transactions',
            1
        );
    }

    public function test_reusing_credit_key_with_different_amount_is_rejected(): void
    {
        $this->walletService->addDemoCredits(
            $this->user,
            5_000_000_000,
            'test:conflicting-credit'
        );

        $this->expectException(LogicException::class);

        $this->walletService->addDemoCredits(
            $this->user,
            10_000_000_000,
            'test:conflicting-credit'
        );
    }

    public function test_it_reserves_funds_without_charging_customer(): void
    {
        $this->fundWallet();

        $generation = $this->createGeneration();

        $reservation = $this->walletService->reserve(
            $generation,
            30_000_000
        );

        $wallet = $this->user->wallet()->firstOrFail();

        $this->assertSame(
            9_970_000_000,
            $wallet->available_units
        );

        $this->assertSame(
            30_000_000,
            $wallet->reserved_units
        );

        $this->assertSame(
            'active',
            $reservation->status
        );

        $this->assertDatabaseMissing(
            'wallet_transactions',
            [
                'generation_id' => $generation->id,
                'type' =>
                    WalletTransaction::TYPE_GENERATION_CHARGE,
            ]
        );
    }

    public function test_duplicate_reservations_do_not_hold_funds_twice(): void
    {
        $this->fundWallet();

        $generation = $this->createGeneration();

        $first = $this->walletService->reserve(
            $generation,
            30_000_000
        );

        $second = $this->walletService->reserve(
            $generation,
            30_000_000
        );

        $this->assertSame($first->id, $second->id);

        $wallet = $this->user->wallet()->firstOrFail();

        $this->assertSame(
            9_970_000_000,
            $wallet->available_units
        );

        $this->assertSame(
            30_000_000,
            $wallet->reserved_units
        );

        $this->assertDatabaseCount(
            'wallet_reservations',
            1
        );
    }

    public function test_insufficient_funds_prevents_reservation(): void
    {
        $this->fundWallet(1_000_000_000);

        $generation = $this->createGeneration();

        try {
            $this->walletService->reserve(
                $generation,
                2_000_000_000
            );

            $this->fail(
                'Expected insufficient funds validation error.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'wallet',
                $exception->errors()
            );
        }

        $wallet = $this->user->wallet()->firstOrFail();

        $this->assertSame(
            1_000_000_000,
            $wallet->available_units
        );

        $this->assertSame(
            0,
            $wallet->reserved_units
        );

        $this->assertDatabaseCount(
            'wallet_reservations',
            0
        );
    }

    public function test_successful_generation_charges_actual_cost_plus_markup(): void
    {
        $this->fundWallet();

        $generation = $this->createGeneration();

        // Reserve $0.030000000.
        $this->walletService->reserve(
            $generation,
            30_000_000
        );

        // Provider cost: $0.019500000.
        // Customer charge: $0.024375000.
        $charge = $this->walletService->settleSuccess(
            $generation,
            19_500_000
        );

        $wallet = $this->user->wallet()->firstOrFail();

        $this->assertSame(
            9_975_625_000,
            $wallet->available_units
        );

        $this->assertSame(
            0,
            $wallet->reserved_units
        );

        $this->assertSame(
            24_375_000,
            $charge->metadata['customer_charge_units']
        );

        $this->assertSame(
            19_500_000,
            $charge->metadata['provider_cost_units']
        );

        $this->assertSame(
            4_875_000,
            $charge->metadata['markup_units']
        );

        $this->assertDatabaseHas(
            'wallet_reservations',
            [
                'generation_id' => $generation->id,
                'status' => 'settled',
            ]
        );
    }

    public function test_duplicate_success_callback_does_not_charge_twice(): void
    {
        $this->fundWallet();

        $generation = $this->createGeneration();

        $this->walletService->reserve(
            $generation,
            30_000_000
        );

        $first = $this->walletService->settleSuccess(
            $generation,
            19_500_000
        );

        $second = $this->walletService->settleSuccess(
            $generation,
            19_500_000
        );

        $this->assertSame($first->id, $second->id);

        $wallet = $this->user->wallet()->firstOrFail();

        $this->assertSame(
            9_975_625_000,
            $wallet->available_units
        );

        $this->assertSame(
            1,
            WalletTransaction::query()
                ->where('generation_id', $generation->id)
                ->where(
                    'type',
                    WalletTransaction::TYPE_GENERATION_CHARGE
                )
                ->count()
        );
    }

    public function test_failed_generation_releases_full_reservation(): void
    {
        $this->fundWallet();

        $generation = $this->createGeneration();

        $this->walletService->reserve(
            $generation,
            30_000_000
        );

        $this->walletService->releaseFailure(
            $generation
        );

        $wallet = $this->user->wallet()->firstOrFail();

        $this->assertSame(
            10_000_000_000,
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
                'status' => 'released',
            ]
        );

        $this->assertDatabaseMissing(
            'wallet_transactions',
            [
                'generation_id' => $generation->id,
                'type' =>
                    WalletTransaction::TYPE_GENERATION_CHARGE,
            ]
        );
    }

    public function test_duplicate_failure_callback_does_not_release_twice(): void
    {
        $this->fundWallet();

        $generation = $this->createGeneration();

        $this->walletService->reserve(
            $generation,
            30_000_000
        );

        $first = $this->walletService->releaseFailure(
            $generation
        );

        $second = $this->walletService->releaseFailure(
            $generation
        );

        $this->assertSame($first->id, $second->id);

        $wallet = $this->user->wallet()->firstOrFail();

        $this->assertSame(
            10_000_000_000,
            $wallet->available_units
        );

        $this->assertSame(
            0,
            $wallet->reserved_units
        );
    }

    public function test_failed_generation_cannot_be_settled_later(): void
    {
        $this->fundWallet();

        $generation = $this->createGeneration();

        $this->walletService->reserve(
            $generation,
            30_000_000
        );

        $this->walletService->releaseFailure(
            $generation
        );

        $this->expectException(LogicException::class);

        $this->walletService->settleSuccess(
            $generation,
            19_500_000
        );
    }

    public function test_settled_generation_cannot_be_failed_later(): void
    {
        $this->fundWallet();

        $generation = $this->createGeneration();

        $this->walletService->reserve(
            $generation,
            30_000_000
        );

        $this->walletService->settleSuccess(
            $generation,
            19_500_000
        );

        $this->expectException(LogicException::class);

        $this->walletService->releaseFailure(
            $generation
        );
    }

    public function test_settlement_fails_atomically_when_extra_funds_are_unavailable(): void
    {
        $this->fundWallet(1_000_000_000);

        $generation = $this->createGeneration();

        $this->walletService->reserve(
            $generation,
            1_000_000_000
        );

        // Actual customer charge would be $1.250000000.
        $this->expectException(LogicException::class);

        try {
            $this->walletService->settleSuccess(
                $generation,
                1_000_000_000
            );
        } finally {
            $wallet = $this->user->wallet()->firstOrFail();

            $this->assertSame(
                0,
                $wallet->available_units
            );

            $this->assertSame(
                1_000_000_000,
                $wallet->reserved_units
            );

            $this->assertDatabaseMissing(
                'wallet_transactions',
                [
                    'generation_id' => $generation->id,
                    'type' =>
                        WalletTransaction::TYPE_GENERATION_CHARGE,
                ]
            );
        }
    }


    public function test_zero_cost_generation_releases_entire_reservation(): void
    {
        $this->fundWallet();

        $generation = $this->createGeneration();

        $this->walletService->reserve(
            $generation,
            30_000_000
        );

        $charge = $this->walletService->settleSuccess(
            $generation,
            0
        );

        $wallet = $this->user->wallet()->firstOrFail();

        $this->assertSame(
            10_000_000_000,
            $wallet->available_units
        );

        $this->assertSame(
            0,
            $wallet->reserved_units
        );

        $this->assertSame(
            0,
            $charge->metadata['provider_cost_units']
        );

        $this->assertSame(
            0,
            $charge->metadata['customer_charge_units']
        );

        $this->assertDatabaseHas('wallet_reservations', [
            'generation_id' => $generation->id,
            'status' => 'settled',
        ]);
    }

    public function test_markup_rounds_up_to_nearest_nanodollar(): void
    {
        $this->fundWallet();

        $generation = $this->createGeneration();

        $this->walletService->reserve(
            $generation,
            10
        );

        // 1 nanodollar provider cost.
        // 25% markup rounds up to 1 nanodollar.
        // Customer pays 2 nanodollars.
        $charge = $this->walletService->settleSuccess(
            $generation,
            1
        );

        $this->assertSame(
            2,
            $charge->metadata['customer_charge_units']
        );

        $this->assertSame(
            1,
            $charge->metadata['markup_units']
        );
    }

    public function test_settlement_with_different_provider_cost_is_rejected(): void
    {
        $this->fundWallet();

        $generation = $this->createGeneration();

        $this->walletService->reserve(
            $generation,
            30_000_000
        );

        $this->walletService->settleSuccess(
            $generation,
            19_500_000
        );

        try {
            $this->walletService->settleSuccess(
                $generation,
                20_000_000
            );

            $this->fail(
                'Conflicting settlement should have been rejected.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Conflicting generation settlement.',
                $exception->getMessage()
            );
        }

        $wallet = $this->user->wallet()->firstOrFail();

        $this->assertSame(
            9_975_625_000,
            $wallet->available_units
        );

        $this->assertSame(
            0,
            $wallet->reserved_units
        );

        $this->assertSame(
            1,
            WalletTransaction::query()
                ->where('generation_id', $generation->id)
                ->where(
                    'type',
                    WalletTransaction::TYPE_GENERATION_CHARGE
                )
                ->count()
        );
    }

    public function test_released_reservation_cannot_be_reserved_again(): void
    {
        $this->fundWallet();

        $generation = $this->createGeneration();

        $this->walletService->reserve(
            $generation,
            30_000_000
        );

        $this->walletService->releaseFailure(
            $generation
        );

        $reservation = $this->walletService->reserve(
            $generation,
            30_000_000
        );

        $this->assertSame(
            'released',
            $reservation->status
        );

        $wallet = $this->user->wallet()->firstOrFail();

        $this->assertSame(
            10_000_000_000,
            $wallet->available_units
        );

        $this->assertSame(
            0,
            $wallet->reserved_units
        );
    }

    public function test_reservation_rejects_different_amount_on_retry(): void
    {
        $this->fundWallet();

        $generation = $this->createGeneration();

        $this->walletService->reserve(
            $generation,
            30_000_000
        );

        $this->expectException(LogicException::class);

        $this->walletService->reserve(
            $generation,
            40_000_000
        );
    }

    public function test_demo_credit_rejects_amount_below_minimum(): void
    {
        $this->expectException(ValidationException::class);

        $this->walletService->addDemoCredits(
            $this->user,
            999_999_999,
            'test:below-minimum'
        );
    }

    public function test_demo_credit_rejects_amount_above_maximum(): void
    {
        $this->expectException(ValidationException::class);

        $this->walletService->addDemoCredits(
            $this->user,
            200_000_000_001,
            'test:above-maximum'
        );
    }

    public function test_failed_reservation_does_not_create_ledger_event(): void
    {
        $this->fundWallet(1_000_000_000);

        $generation = $this->createGeneration();

        try {
            $this->walletService->reserve(
                $generation,
                2_000_000_000
            );

            $this->fail('Expected insufficient funds.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'wallet',
                $exception->errors()
            );
        }

        $this->assertSame(
            0,
            WalletTransaction::query()
                ->where('generation_id', $generation->id)
                ->count()
        );
    }

}
