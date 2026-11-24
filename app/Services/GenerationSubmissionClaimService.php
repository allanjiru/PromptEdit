<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class GenerationSubmissionClaimService
{
    private const LEASE_SECONDS = 120;

    /**
     * @return array{
     *     token: string,
     *     request_key: string,
     *     payload: array<string, mixed>
     * }|null
     */
    public function acquire(
        int $generationId,
        ?string $dispatchToken = null
    ): ?array {
        return DB::transaction(function () use (
            $generationId,
            $dispatchToken
        ) {
            $generation = $this->lockGeneration($generationId);
            $now = now();

            if (
                $generation->isTerminal() ||
                $generation->provider_job_id !== null ||
                in_array($generation->submission_status, [
                    Generation::SUBMISSION_NEEDS_REVIEW,
                    Generation::SUBMISSION_ACCEPTED,
                ], true)
            ) {
                return null;
            }

            $dispatchIsActive =
                $generation->recovery_dispatch_token !== null &&
                $generation->recovery_dispatch_until !== null &&
                $generation->recovery_dispatch_until->gt($now);

            if ($dispatchToken !== null) {
                // Recovery worker must own a live dispatch.
                if (
                    ! $dispatchIsActive ||
                    $generation->recovery_dispatch_token !== $dispatchToken
                ) {
                    return null;
                }
            } elseif ($dispatchIsActive) {
                // Ordinary workers cannot steal recovery work.
                return null;
            }

            $providerClaimActive =
                $generation->submission_claim_token !== null &&
                $generation->submission_claimed_until !== null &&
                $generation->submission_claimed_until->gt($now);

            if ($providerClaimActive) {
                return null;
            }

            // Only a validated recovery dispatch may bypass
            // the reconciliation deadline.
            if (
                $dispatchToken === null &&
                $generation->submission_reconciliation_due_at !== null &&
                $generation->submission_reconciliation_due_at->gt($now)
            ) {
                return null;
            }

            if ($generation->provider_request_payload === null) {
                $generation->submission_status =
                    Generation::SUBMISSION_NEEDS_REVIEW;

                $generation->submission_claim_token = null;
                $generation->submission_claimed_until = null;
                $generation->recovery_dispatch_token = null;
                $generation->recovery_dispatch_until = null;
                $generation->submission_reconciliation_due_at = null;

                $generation->submission_last_error =
                    'Missing immutable provider request payload. '
                    .'Manual reconciliation required.';

                $generation->save();

                return null;
            }

            $token = (string) Str::uuid();

            // Consume recovery dispatch authorization.
            $generation->recovery_dispatch_token = null;
            $generation->recovery_dispatch_until = null;

            // Establish the actual provider-call claim.
            $generation->submission_claim_token = $token;
            $generation->submission_claimed_until =
                $now->copy()->addSeconds(self::LEASE_SECONDS);

            $generation->submission_status =
                Generation::SUBMISSION_SUBMITTING;

            $generation->submission_attempts =
                ((int) $generation->submission_attempts) + 1;

            $generation->submission_last_attempted_at = $now;
            $generation->submission_reconciliation_due_at = null;
            $generation->submission_last_error = null;

            $generation->save();

            return [
                'token' => $token,
                'request_key' => $generation->request_key,
                'payload' => $generation->provider_request_payload,
            ];
        }, 3);
    }

    public function accepted(
        int $generationId,
        string $token,
        string $providerJobId
    ): bool {
        if (! preg_match('/^gen_[A-Za-z0-9]+$/', $providerJobId)) {
            throw new LogicException(
                'Invalid provider generation ID.'
            );
        }

        return DB::transaction(function () use (
            $generationId,
            $token,
            $providerJobId
        ) {
            $generation = $this->lockGeneration($generationId);

            if (
                $generation->submission_claim_token !== $token ||
                $generation->isTerminal()
            ) {
                return false;
            }

            if (
                $generation->provider_job_id !== null &&
                $generation->provider_job_id !== $providerJobId
            ) {
                throw new LogicException(
                    'Conflicting provider generation ID.'
                );
            }

            $generation->provider_job_id = $providerJobId;
            $generation->submitted_at ??= now();

            $generation->submission_status =
                Generation::SUBMISSION_ACCEPTED;

            $generation->submission_claim_token = null;
            $generation->submission_claimed_until = null;
            $generation->submission_reconciliation_due_at = null;
            $generation->submission_last_error = null;
            $generation->recovery_dispatch_token = null;
            $generation->recovery_dispatch_until = null;

            $generation->save();

            return true;
        }, 3);
    }

    public function uncertain(
        int $generationId,
        string $token,
        string $reason
    ): bool {
        return DB::transaction(function () use (
            $generationId,
            $token,
            $reason
        ) {
            $generation = $this->lockGeneration($generationId);

            if (
                $generation->submission_claim_token !== $token ||
                $generation->isTerminal() ||
                $generation->provider_job_id !== null
            ) {
                return false;
            }

            $generation->submission_status =
                Generation::SUBMISSION_UNCERTAIN;

            $generation->submission_claim_token = null;
            $generation->submission_claimed_until = null;

            $generation->submission_reconciliation_due_at =
                now()->addMinutes(2);

            $generation->submission_last_error =
                mb_substr($reason, 0, 2000);

            $generation->save();

            return true;
        }, 3);
    }

    private function lockGeneration(int $generationId): Generation
    {
        $generation = Generation::query()
            ->findOrFail($generationId);

        // Consistent lock order across financial operations:
        // wallet first, generation second.
        DB::table('wallets')
            ->where('user_id', $generation->user_id)
            ->lockForUpdate()
            ->first()
            ?? throw new LogicException(
                'Generation wallet does not exist.'
            );

        return Generation::query()
            ->whereKey($generationId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
