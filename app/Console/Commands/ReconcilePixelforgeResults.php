<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\PixelforgeReconciliationState;
use App\Services\PixelforgeResultReconciler;
use Illuminate\Console\Command;

class ReconcilePixelforgeResults extends Command
{
    protected $signature = 'pixelforge:reconcile-results
        {--limit=50 : Maximum generations to inspect}
        {--id= : Reconcile one generation by local ID}';

    protected $description =
        'Reconcile accepted Pixelforge generations with provider results.';

    public function handle(
        PixelforgeResultReconciler $reconciler
    ): int {
        $limit = filter_var(
            $this->option('limit'),
            FILTER_VALIDATE_INT
        );

        if (
            $limit === false ||
            $limit < 1 ||
            $limit > 500
        ) {
            $this->error('Limit must be between 1 and 500.');

            return self::FAILURE;
        }

        $id = $this->option('id');

        if ($id !== null) {
            $id = filter_var(
                $id,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($id === false) {
                $this->error('Invalid generation ID.');

                return self::FAILURE;
            }
        }

        $query = Generation::query()
            ->whereNotNull('provider_job_id')
            ->whereIn('status', [
                Generation::STATUS_QUEUED,
                Generation::STATUS_PROCESSING,
            ]);

        if ($id !== null) {
            $query->whereKey($id);
        } else {
            $query->where(function ($query) {
                $query->where(
                    'result_reconciliation_due_at',
                    '<=',
                    now()
                )->orWhere(function ($query) {
                    $query->whereNull('result_reconciliation_due_at')
                        ->whereNull('result_reconciliation_exhausted_at')
                        ->where(
                            'submitted_at',
                            '<=',
                            now()->subSeconds(30)
                        );
                });
            })->where(
                'result_reconciliation_attempts',
                '<',
                PixelforgeReconciliationState::MAX_PROVIDER_REQUESTS
            );
        }

        $generations = $query
            ->orderBy('id')
            ->limit($id !== null ? 1 : $limit)
            ->get();

        $processed = 0;
        $pending = 0;
        $errors = 0;

        foreach ($generations as $generation) {
            $result = $reconciler->reconcile($generation->id);

            if ($result['outcome'] === 'terminal') {
                $processed++;
            } elseif ($result['outcome'] === 'pending') {
                $pending++;
            } elseif ($result['outcome'] === 'error') {
                $errors++;
            }
        }

        $this->info(
            "Completed: {$processed}; ".
            "Pending: {$pending}; ".
            "Errors: {$errors}."
        );

        return $errors > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
