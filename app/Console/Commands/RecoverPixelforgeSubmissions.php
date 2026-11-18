<?php

namespace App\Console\Commands;

use App\Jobs\SubmitPixelforgeGeneration;
use App\Models\Generation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class RecoverPixelforgeSubmissions extends Command
{
    protected $signature = 'pixelforge:recover-submissions
        {--limit=100 : Maximum submissions to recover}';

    protected $description =
        'Recover incomplete Pixelforge submissions safely.';

    public function handle(): int
    {
        $limit = filter_var(
            $this->option('limit'),
            FILTER_VALIDATE_INT
        );

        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('Limit must be between 1 and 500.');

            return self::FAILURE;
        }

        $dispatched = 0;

        for ($i = 0; $i < $limit; $i++) {
            $claim = DB::transaction(function () {
                $now = now();

                $generation = Generation::query()
                    ->whereNull('provider_job_id')
                    ->whereNotIn('status', [
                        Generation::STATUS_COMPLETED,
                        Generation::STATUS_FAILED,
                    ])
                    ->where(function ($query) use ($now) {
                        $query
                            ->where(function ($pending) use ($now) {
                                $pending
                                    ->where(
                                        'submission_status',
                                        Generation::SUBMISSION_PENDING
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
                    ->where(function ($query) use ($now) {
                        $query
                            ->whereNull('submission_claimed_until')
                            ->orWhere(
                                'submission_claimed_until',
                                '<=',
                                $now
                            );
                    })
                    ->orderBy('id')
                    ->lock('FOR UPDATE SKIP LOCKED')
                    ->first();

                if ($generation === null) {
                    return null;
                }

                $token = (string) Str::uuid();

                $generation->submission_claim_token = $token;
                $generation->submission_claimed_until =
                    $now->copy()->addMinutes(2);

                // The dispatch reservation is distinct from
                // the actual provider-call claim.
                $generation->submission_status =
                    Generation::SUBMISSION_PENDING;

                $generation->submission_reconciliation_due_at =
                    $now->copy()->addMinutes(2);

                $generation->save();

                return [
                    'id' => $generation->id,
                    'token' => $token,
                ];
            }, 3);

            if ($claim === null) {
                break;
            }

            try {
                SubmitPixelforgeGeneration::dispatch(
                    $claim['id'],
                    $claim['token']
                )->onQueue('generations');

                $dispatched++;
            } catch (Throwable $exception) {
                report($exception);

                // The reservation expires automatically.
                // Do not release financial reservations here.
            }
        }

        $this->info(
            "Dispatched {$dispatched} submission recovery job(s)."
        );

        return self::SUCCESS;
    }
}
