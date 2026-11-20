<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GenerationSubmissionRecoveryService
{
    private const DISPATCH_LEASE_SECONDS = 300;

    private const INITIAL_GRACE_SECONDS = 120;

    public function reserve(int $generationId): ?string
    {
        return DB::transaction(function () use ($generationId) {
            // Read the owner before acquiring locks.
            $generation = Generation::query()
                ->findOrFail($generationId);

            // Maintain the same lock order as wallet operations:
            // wallet first, generation second.
            $wallet = DB::table('wallets')
                ->where('user_id', $generation->user_id)
                ->lockForUpdate()
                ->first();

            if ($wallet === null) {
                return null;
            }

            $generation = Generation::query()
                ->whereKey($generationId)
                ->lockForUpdate()
                ->firstOrFail();

            $now = now();

            if (
                $generation->isTerminal() ||
                $generation->provider_job_id !== null
            ) {
                return null;
            }

            if (
                ! in_array(
                    $generation->submission_status,
                    [
                        Generation::SUBMISSION_PENDING,
                        Generation::SUBMISSION_UNCERTAIN,
                        Generation::SUBMISSION_SUBMITTING,
                    ],
                    true
                )
            ) {
                return null;
            }

            // Do not dispatch while another recovery
            // dispatch reservation is still active.
            if (
                $generation->recovery_dispatch_until !== null &&
                $generation->recovery_dispatch_until->isFuture()
            ) {
                return null;
            }

            // Do not interfere with an active provider call.
            if (
                $generation->submission_claim_token !== null &&
                $generation->submission_claimed_until !== null &&
                $generation->submission_claimed_until->isFuture()
            ) {
                return null;
            }

            $eligible = match ($generation->submission_status) {
                Generation::SUBMISSION_PENDING => $generation->created_at !== null &&
                    $generation->created_at->lessThanOrEqualTo(
                        $now->copy()->subSeconds(
                            self::INITIAL_GRACE_SECONDS
                        )
                    ) &&
                    (
                        $generation->submission_reconciliation_due_at === null ||
                        $generation->submission_reconciliation_due_at
                            ->lessThanOrEqualTo($now)
                    ),

                Generation::SUBMISSION_UNCERTAIN => $generation->submission_reconciliation_due_at !== null &&
                    $generation->submission_reconciliation_due_at
                        ->lessThanOrEqualTo($now),

                Generation::SUBMISSION_SUBMITTING => $generation->submission_claimed_until !== null &&
                    $generation->submission_claimed_until
                        ->lessThanOrEqualTo($now),

                default => false,
            };

            if (! $eligible) {
                return null;
            }

            $token = (string) Str::uuid();

            $generation->recovery_dispatch_token = $token;

            $generation->recovery_dispatch_until =
                $now->copy()->addSeconds(
                    self::DISPATCH_LEASE_SECONDS
                );

            // Preserve the original submission status,
            // claim token, payload, and reconciliation time.
            $generation->save();

            return $token;
        }, 3);
    }

    public function candidates(int $limit): array
    {
        $now = now();

        return Generation::query()
            ->whereNull('provider_job_id')
            ->whereNotIn('status', [
                Generation::STATUS_COMPLETED,
                Generation::STATUS_FAILED,
            ])
            ->whereIn('submission_status', [
                Generation::SUBMISSION_PENDING,
                Generation::SUBMISSION_UNCERTAIN,
                Generation::SUBMISSION_SUBMITTING,
            ])
            ->where(function ($query) use ($now) {
                $query
                    ->whereNull('recovery_dispatch_until')
                    ->orWhere(
                        'recovery_dispatch_until',
                        '<=',
                        $now
                    );
            })
            ->where(function ($query) use ($now) {
                $query
                    ->whereNull('submission_claimed_until')
                    ->orWhere(
                        'submission_claimed_until',
                        '<=',
                        $now
                    );
            })
            ->where(function ($query) use ($now) {
                $query
                    ->where(function ($pending) use ($now) {
                        $pending
                            ->where(
                                'submission_status',
                                Generation::SUBMISSION_PENDING
                            )
                            ->where(
                                'created_at',
                                '<=',
                                $now->copy()->subSeconds(
                                    self::INITIAL_GRACE_SECONDS
                                )
                            )
                            ->where(function ($due) use ($now) {
                                $due
                                    ->whereNull(
                                        'submission_reconciliation_due_at'
                                    )
                                    ->orWhere(
                                        'submission_reconciliation_due_at',
                                        '<=',
                                        $now
                                    );
                            });
                    })
                    ->orWhere(function ($uncertain) use ($now) {
                        $uncertain
                            ->where(
                                'submission_status',
                                Generation::SUBMISSION_UNCERTAIN
                            )
                            ->where(
                                'submission_reconciliation_due_at',
                                '<=',
                                $now
                            );
                    })
                    ->orWhere(function ($submitting) use ($now) {
                        $submitting
                            ->where(
                                'submission_status',
                                Generation::SUBMISSION_SUBMITTING
                            )
                            ->where(
                                'submission_claimed_until',
                                '<=',
                                $now
                            );
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();
    }
}
