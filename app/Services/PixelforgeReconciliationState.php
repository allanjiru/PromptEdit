<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PixelforgeReconciliationState
{
    /**
     * Planned checks are due at cumulative offsets of 10s, 20s, 30s,
     * 40s, 50s, 60s, 90s, 150s, 270s, 570s, 1170s, and 2070s.
     * Actual execution is later when HTTP work or the queue takes time.
     */
    public const MAX_PROVIDER_REQUESTS = 12;

    public const INITIAL_DELAY_SECONDS = 10;

    private const CLAIM_SECONDS = 75;

    private const BACKOFF_SECONDS = [
        10,
        10,
        10,
        10,
        10,
        30,
        60,
        120,
        300,
        600,
        900,
    ];

    private const MAX_QUEUED_DELAY_SECONDS = 86_400;

    private const MAX_DURABLE_DELAY_SECONDS = 31_536_000;

    public function scheduleInitial(int $generationId): bool
    {
        return DB::transaction(function () use ($generationId) {
            $generation = Generation::query()
                ->whereKey($generationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($generation->isTerminal() || ! $generation->provider_job_id) {
                return false;
            }

            if (
                $generation->result_reconciliation_attempts > 0 ||
                $generation->result_reconciliation_due_at !== null ||
                $generation->result_reconciliation_claim_token !== null ||
                $generation->result_reconciliation_exhausted_at !== null
            ) {
                return false;
            }

            $generation->result_reconciliation_due_at = now()->addSeconds(
                self::INITIAL_DELAY_SECONDS
            );
            $generation->save();

            return true;
        }, 3);
    }

    /**
     * @return array{token: string, attempt: int, provider_job_id: string}|null
     */
    public function claim(int $generationId): ?array
    {
        return DB::transaction(function () use ($generationId) {
            $generation = Generation::query()
                ->whereKey($generationId)
                ->lockForUpdate()
                ->first();

            if ($generation === null) {
                return null;
            }

            if ($generation->isTerminal() || ! $generation->provider_job_id) {
                $this->clear($generation);

                return null;
            }

            $now = now();

            if (
                $generation->result_reconciliation_due_at !== null &&
                $generation->result_reconciliation_due_at->isFuture()
            ) {
                return null;
            }

            if (
                $generation->result_reconciliation_claim_token !== null &&
                $generation->result_reconciliation_claimed_until !== null &&
                $generation->result_reconciliation_claimed_until->gt($now)
            ) {
                return null;
            }

            if (
                $generation->result_reconciliation_attempts >=
                    self::MAX_PROVIDER_REQUESTS
            ) {
                $generation->result_reconciliation_due_at = null;
                $generation->result_reconciliation_claim_token = null;
                $generation->result_reconciliation_claimed_until = null;
                $generation->result_reconciliation_exhausted_at ??= $now;
                $generation->save();

                return null;
            }

            $token = (string) Str::uuid();
            $attempt = $generation->result_reconciliation_attempts + 1;

            $generation->result_reconciliation_attempts = $attempt;
            $generation->result_reconciliation_due_at = null;
            $generation->result_reconciliation_claim_token = $token;
            $generation->result_reconciliation_claimed_until = $now->copy()
                ->addSeconds(self::CLAIM_SECONDS);
            $generation->save();

            return [
                'token' => $token,
                'attempt' => $attempt,
                'provider_job_id' => $generation->provider_job_id,
            ];
        }, 3);
    }

    public function release(
        int $generationId,
        string $token,
        int $attempt,
        ?int $minimumDelay = null
    ): ?int {
        return DB::transaction(function () use (
            $generationId,
            $token,
            $attempt,
            $minimumDelay
        ) {
            $generation = Generation::query()
                ->whereKey($generationId)
                ->lockForUpdate()
                ->first();

            if (
                $generation === null ||
                $generation->result_reconciliation_claim_token !== $token
            ) {
                return null;
            }

            $generation->result_reconciliation_claim_token = null;
            $generation->result_reconciliation_claimed_until = null;

            if ($generation->isTerminal()) {
                $this->clear($generation);

                return null;
            }

            if ($attempt >= self::MAX_PROVIDER_REQUESTS) {
                $generation->result_reconciliation_due_at = null;
                $generation->result_reconciliation_exhausted_at ??= now();
                $generation->save();

                return null;
            }

            $delay = self::BACKOFF_SECONDS[$attempt - 1];
            $delay = max($delay, $minimumDelay ?? 0);

            if ($delay > self::MAX_DURABLE_DELAY_SECONDS) {
                $generation->result_reconciliation_due_at = null;
                $generation->result_reconciliation_exhausted_at ??= now();
                $generation->save();

                return null;
            }

            $generation->result_reconciliation_due_at = now()->addSeconds(
                $delay
            );
            $generation->save();

            if ($delay > self::MAX_QUEUED_DELAY_SECONDS) {
                // Keep the provider's full minimum wait in durable state.
                // Scheduled recovery will resume it when that time arrives.
                return null;
            }

            return $delay;
        }, 3);
    }

    public function finish(int $generationId, string $token): void
    {
        DB::transaction(function () use ($generationId, $token) {
            $generation = Generation::query()
                ->whereKey($generationId)
                ->lockForUpdate()
                ->first();

            if (
                $generation === null ||
                $generation->result_reconciliation_claim_token !== $token
            ) {
                return;
            }

            $this->clear($generation);
        }, 3);
    }

    private function clear(Generation $generation): void
    {
        $generation->result_reconciliation_due_at = null;
        $generation->result_reconciliation_claim_token = null;
        $generation->result_reconciliation_claimed_until = null;
        $generation->save();
    }
}
