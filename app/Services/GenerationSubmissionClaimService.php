<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class GenerationSubmissionClaimService
{
    private const LEASE_SECONDS = 120;

    public function acquire(
        int $generationId,
        ?string $dispatchToken = null
    ): ?array {
        return DB::transaction(function () use (
            $generationId,
            $dispatchToken
        ) {
            $generation = $this->lockGeneration($generationId);

            // Never submit terminal, accepted, or
            // manually blocked generations.
            if (
                $generation->isTerminal() ||
                $generation->provider_job_id !== null ||
                $generation->submission_status ===
                    Generation::SUBMISSION_NEEDS_REVIEW ||
                $generation->submission_status ===
                    Generation::SUBMISSION_ACCEPTED
            ) {
                return null;
            }

            $hasClaim =
                $generation->submission_claim_token !== null;

            $claimIsActive =
                $hasClaim &&
                $generation->submission_claimed_until !== null &&
                $generation->submission_claimed_until->isFuture();

            // A recovery worker may consume its own
            // dispatch reservation, but cannot consume
            // another worker's active claim.
            $isReservedDispatch =
                $dispatchToken !== null &&
                $generation->submission_claim_token ===
                    $dispatchToken &&
                $claimIsActive &&
                $generation->submission_status !==
                    Generation::SUBMISSION_SUBMITTING;

            // Recovery workers must present the current
            // valid dispatch token.
            if (
                $dispatchToken !== null &&
                ! $isReservedDispatch
            ) {
                return null;
            }

            // Ordinary workers cannot bypass an active
            // recovery dispatch or provider-call claim.
            if (
                $claimIsActive &&
                ! $isReservedDispatch
            ) {
                return null;
            }

            // A valid recovery dispatch bypasses the
            // scheduling delay reserved by the command.
            if (
                ! $isReservedDispatch &&
                $generation->submission_reconciliation_due_at !== null &&
                $generation->submission_reconciliation_due_at->isFuture()
            ) {
                return null;
            }

            // Never construct a replacement provider
            // payload during recovery.
            if ($generation->provider_request_payload === null) {
                $generation->submission_status =
                    Generation::SUBMISSION_NEEDS_REVIEW;

                $generation->submission_claim_token = null;
                $generation->submission_claimed_until = null;
                $generation->submission_reconciliation_due_at = null;

                $generation->submission_last_error =
                    'Missing immutable provider request payload. '
                    .'Manual reconciliation required.';

                $generation->save();

                return null;
            }

            // Rotate the dispatch token into a fresh
            // provider-call claim token.
            $token = (string) Str::uuid();

            $generation->submission_claim_token = $token;

            $generation->submission_claimed_until =
                now()->addSeconds(self::LEASE_SECONDS);

            $generation->submission_status =
                Generation::SUBMISSION_SUBMITTING;

            $generation->submission_attempts =
                ((int) $generation->submission_attempts) + 1;

            $generation->submission_last_attempted_at = now();

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

            // A stale worker must never overwrite
            // the current submission claim.
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

            // Ignore stale workers, terminal generations,
            // and submissions already linked to a provider.
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
        // Read the user ID before acquiring locks.
        $generation = Generation::query()
            ->findOrFail($generationId);

        // Preserve the lifecycle service's lock order:
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
