<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletReservation;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class WalletService
{
    /**
     * Money is represented as integer nano-USD.
     *
     * 1 USD = 1,000,000 units.
     */
    public const UNITS_PER_USD = 1_000_000_000;

    /**
     * Safely create or retrieve a user's wallet.
     */
    public function ensureWallet(User $user): Wallet
    {
        return DB::transaction(function () use ($user) {
            // Serializes concurrent wallet creation for this user.
            User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            return Wallet::query()->firstOrCreate(
                ['user_id' => $user->getKey()]
            );
        }, 3);
    }

    /**
     * Add sandbox credits exactly once per idempotency key.
     *
     * The caller must authorize and validate this operation.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function addDemoCredits(
        User $user,
        int $amountUnits,
        string $idempotencyKey,
        ?int $maxCumulativeUnits = null,
        string $description = 'Demo credit grant',
        array $metadata = []
    ): WalletTransaction {
        if ($amountUnits < 1_000_000_000 ||
            $amountUnits > 200_000_000_000) {
            throw ValidationException::withMessages([
                'amount' => 'Amount must be between $1 and $200.',
            ]);
        }

        if ($idempotencyKey === '') {
            throw new LogicException('Idempotency key is required.');
        }

        $wallet = $this->ensureWallet($user);

        return DB::transaction(function () use (
            $wallet,
            $amountUnits,
            $idempotencyKey,
            $maxCumulativeUnits,
            $description,
            $metadata
        ) {
            $lockedWallet = $this->lockWallet($wallet->id);

            $existing = WalletTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                $existingMetadata = $existing->getAttribute('metadata');

                if (
                    $existing->wallet_id !== $lockedWallet->id ||
                    $existing->type !== WalletTransaction::TYPE_DEMO_CREDIT ||
                    $existing->available_delta_units !== $amountUnits ||
                    (
                        isset($metadata['package_id']) &&
                        (
                            ! is_array($existingMetadata) ||
                            ($existingMetadata['package_id'] ?? null) !==
                                $metadata['package_id']
                        )
                    )
                ) {
                    throw new LogicException(
                        'Idempotency key reused with different parameters.'
                    );
                }

                return $existing;
            }

            if ($maxCumulativeUnits !== null) {
                $creditedUnits = (int) WalletTransaction::query()
                    ->where('wallet_id', $lockedWallet->id)
                    ->where('type', WalletTransaction::TYPE_DEMO_CREDIT)
                    ->sum('available_delta_units');

                if (
                    $maxCumulativeUnits <= 0 ||
                    $creditedUnits > $maxCumulativeUnits - $amountUnits
                ) {
                    throw ValidationException::withMessages([
                        'package' => 'This purchase would exceed the demo credit limit.',
                    ]);
                }
            }

            $this->assertCanAdd(
                $lockedWallet->available_units,
                $amountUnits
            );

            $lockedWallet->available_units += $amountUnits;
            $lockedWallet->save();

            return $this->recordTransaction(
                $lockedWallet,
                null,
                WalletTransaction::TYPE_DEMO_CREDIT,
                $idempotencyKey,
                $amountUnits,
                0,
                $description,
                $metadata
            );
        }, 3);
    }

    /**
     * Reserve funds before submitting a generation.
     *
     * The generation and reservation are unique.
     */
    public function reserve(
        Generation $generation,
        int $amountUnits
    ): WalletReservation {
        if ($amountUnits <= 0) {
            throw new LogicException(
                'Reservation amount must be positive.'
            );
        }

        $wallet = $this->walletForGeneration($generation);

        return DB::transaction(function () use (
            $wallet,
            $generation,
            $amountUnits
        ) {
            $lockedWallet = $this->lockWallet($wallet->id);

            $reservation = WalletReservation::query()
                ->where('generation_id', $generation->id)
                ->lockForUpdate()
                ->first();

            if ($reservation) {
                if (
                    $reservation->wallet_id !== $lockedWallet->id ||
                    $reservation->amount_units !== $amountUnits
                ) {
                    throw new LogicException(
                        'Generation reservation parameters do not match.'
                    );
                }

                return $reservation;
            }

            if ($lockedWallet->available_units < $amountUnits) {
                throw ValidationException::withMessages([
                    'wallet' => 'Insufficient available credits.',
                ]);
            }

            $this->assertCanAdd(
                $lockedWallet->reserved_units,
                $amountUnits
            );

            $lockedWallet->available_units -= $amountUnits;
            $lockedWallet->reserved_units += $amountUnits;
            $lockedWallet->save();

            $reservation = WalletReservation::create([
                'wallet_id' => $lockedWallet->id,
                'generation_id' => $generation->id,
                'amount_units' => $amountUnits,
                'status' => WalletReservation::STATUS_ACTIVE,
            ]);

            $this->recordTransaction(
                $lockedWallet,
                $generation,
                WalletTransaction::TYPE_RESERVATION,
                "generation:{$generation->id}:reserve",
                -$amountUnits,
                $amountUnits,
                'Generation credit reservation'
            );

            return $reservation;
        }, 3);
    }

    /**
     * Settle a successful generation.
     *
     * Customer charge = actual provider cost + 25%.
     *
     * If the actual charge exceeds the reservation,
     * the difference must be available in the wallet.
     * Otherwise settlement fails without modifying funds.
     */
    public function settleSuccess(
        Generation $generation,
        int $providerCostUnits
    ): WalletTransaction {
        if ($providerCostUnits < 0) {
            throw new LogicException(
                'Provider cost cannot be negative.'
            );
        }

        // Conservative bound prevents integer overflow.
        if ($providerCostUnits > intdiv(PHP_INT_MAX - 3, 5)) {
            throw new LogicException(
                'Provider cost exceeds supported range.'
            );
        }

        // Round the 25% markup up to the nearest micro-USD.
        $markupUnits = intdiv($providerCostUnits + 3, 4);
        $chargeUnits = $providerCostUnits + $markupUnits;

        $wallet = $this->walletForGeneration($generation);

        return DB::transaction(function () use (
            $wallet,
            $generation,
            $providerCostUnits,
            $chargeUnits
        ) {
            $lockedWallet = $this->lockWallet($wallet->id);

            $reservation = $this->lockReservation(
                $generation,
                $lockedWallet
            );

            $key = "generation:{$generation->id}:charge";

            $existing = WalletTransaction::query()
                ->where('idempotency_key', $key)
                ->first();

            if ($existing) {
                // 1. Verify that the existing settlement matches this request.
                if (
                    $reservation->status !==
                        WalletReservation::STATUS_SETTLED ||
                    $existing->type !==
                        WalletTransaction::TYPE_GENERATION_CHARGE ||
                    $existing->wallet_id !== $lockedWallet->id ||
                    $existing->generation_id !== $generation->id ||
                    ($existing->metadata['provider_cost_units'] ?? null)
                        !== $providerCostUnits ||
                    ($existing->metadata['customer_charge_units'] ?? null)
                        !== $chargeUnits
                ) {
                    throw new LogicException(
                        'Conflicting generation settlement.'
                    );
                }

                // 2. Verify that the ledger movement matches the charge.
                if (
                    $existing->available_delta_units > 0 ||
                    $existing->reserved_delta_units > 0 ||
                    (
                        -$existing->available_delta_units
                        - $existing->reserved_delta_units
                    ) !== $chargeUnits
                ) {
                    throw new LogicException(
                        'Stored settlement does not match the expected charge.'
                    );
                }

                // 3. Return the existing transaction without charging again.
                return $existing;
            }

            if ($reservation->status !==
                WalletReservation::STATUS_ACTIVE) {
                throw new LogicException(
                    'Reservation is not active.'
                );
            }

            $held = $reservation->amount_units;

            $consumedFromHold = min($held, $chargeUnits);
            $extraCharge = $chargeUnits - $consumedFromHold;
            $unusedHold = $held - $consumedFromHold;

            if ($lockedWallet->available_units < $extraCharge) {
                throw new LogicException(
                    'Insufficient funds to settle actual charge.'
                );
            }

            if ($lockedWallet->reserved_units < $held) {
                throw new LogicException(
                    'Reserved balance is inconsistent.'
                );
            }

            // Consume reserved funds and any additional available funds.
            $lockedWallet->available_units -= $extraCharge;
            $lockedWallet->reserved_units -= $consumedFromHold;
            $lockedWallet->save();

            $charge = $this->recordTransaction(
                $lockedWallet,
                $generation,
                WalletTransaction::TYPE_GENERATION_CHARGE,
                $key,
                -$extraCharge,
                -$consumedFromHold,
                'Successful image generation charge',
                [
                    'provider_cost_units' => $providerCostUnits,
                    'customer_charge_units' => $chargeUnits,
                    'markup_units' => $chargeUnits - $providerCostUnits,
                    'consumed_from_reservation_units' => $consumedFromHold,
                ]
            );

            if ($unusedHold > 0) {
                $this->assertCanAdd(
                    $lockedWallet->available_units,
                    $unusedHold
                );

                $lockedWallet->available_units += $unusedHold;
                $lockedWallet->reserved_units -= $unusedHold;
                $lockedWallet->save();

                $this->recordTransaction(
                    $lockedWallet,
                    $generation,
                    WalletTransaction::TYPE_RESERVATION_RELEASE,
                    "generation:{$generation->id}:release",
                    $unusedHold,
                    -$unusedHold,
                    'Release unused generation reservation'
                );
            }

            $reservation->status =
                WalletReservation::STATUS_SETTLED;
            $reservation->settled_at = now();
            $reservation->save();

            return $charge;
        }, 3);
    }

    /**
     * Release a failed generation's full reservation.
     *
     * No customer charge is created.
     */
    public function releaseFailure(
        Generation $generation
    ): WalletTransaction {
        $wallet = $this->walletForGeneration($generation);

        return DB::transaction(function () use (
            $wallet,
            $generation
        ) {
            $lockedWallet = $this->lockWallet($wallet->id);

            $reservation = $this->lockReservation(
                $generation,
                $lockedWallet
            );

            $key = "generation:{$generation->id}:release";

            $existing = WalletTransaction::query()
                ->where('idempotency_key', $key)
                ->first();

            if ($existing) {
                if (
                    $reservation->status !==
                        WalletReservation::STATUS_RELEASED ||
                    $existing->type !==
                        WalletTransaction::TYPE_RESERVATION_RELEASE ||
                    $existing->wallet_id !== $lockedWallet->id ||
                    $existing->generation_id !== $generation->id
                ) {
                    throw new LogicException(
                        'Conflicting reservation release.'
                    );
                }

                return $existing;
            }

            if ($reservation->status !==
                WalletReservation::STATUS_ACTIVE) {
                throw new LogicException(
                    'Cannot release a non-active reservation.'
                );
            }

            $held = $reservation->amount_units;

            if ($lockedWallet->reserved_units < $held) {
                throw new LogicException(
                    'Reserved balance is inconsistent.'
                );
            }

            $this->assertCanAdd(
                $lockedWallet->available_units,
                $held
            );

            $lockedWallet->available_units += $held;
            $lockedWallet->reserved_units -= $held;
            $lockedWallet->save();

            $release = $this->recordTransaction(
                $lockedWallet,
                $generation,
                WalletTransaction::TYPE_RESERVATION_RELEASE,
                $key,
                $held,
                -$held,
                'Failed generation: full reservation released'
            );

            $reservation->status =
                WalletReservation::STATUS_RELEASED;
            $reservation->released_at = now();
            $reservation->save();

            return $release;
        }, 3);
    }

    /**
     * Lock wallet row for financial mutations.
     */
    private function lockWallet(int $walletId): Wallet
    {
        return Wallet::query()
            ->whereKey($walletId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Lock and validate the generation reservation.
     */
    private function lockReservation(
        Generation $generation,
        Wallet $wallet
    ): WalletReservation {
        $reservation = WalletReservation::query()
            ->where('generation_id', $generation->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($reservation->wallet_id !== $wallet->id) {
            throw new LogicException(
                'Reservation belongs to a different wallet.'
            );
        }

        return $reservation;
    }

    /**
     * Retrieve the wallet associated with a generation.
     */
    private function walletForGeneration(
        Generation $generation
    ): Wallet {
        return Wallet::query()
            ->where('user_id', $generation->user_id)
            ->firstOrFail();
    }

    /**
     * Prevent integer overflow.
     */
    private function assertCanAdd(
        int $current,
        int $increment
    ): void {
        if (
            $increment < 0 ||
            $current > PHP_INT_MAX - $increment
        ) {
            throw new LogicException(
                'Wallet balance exceeds supported range.'
            );
        }
    }

    /**
     * Append one immutable ledger event.
     *
     * Must be called while the wallet row is locked
     * inside a database transaction.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function recordTransaction(
        Wallet $wallet,
        ?Generation $generation,
        string $type,
        string $idempotencyKey,
        int $availableDelta,
        int $reservedDelta,
        string $description,
        array $metadata = []
    ): WalletTransaction {
        return WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'generation_id' => $generation?->id,
            'type' => $type,
            'idempotency_key' => $idempotencyKey,
            'available_delta_units' => $availableDelta,
            'reserved_delta_units' => $reservedDelta,
            'available_after_units' => $wallet->available_units,
            'reserved_after_units' => $wallet->reserved_units,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }
}
