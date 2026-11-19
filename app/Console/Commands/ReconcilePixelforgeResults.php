<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\PixelforgeClient;
use App\Jobs\PersistGenerationImages;
use App\Services\PixelforgeResultProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ReconcilePixelforgeResults extends Command
{
    protected $signature = 'pixelforge:reconcile-results
        {--limit=50 : Maximum generations to inspect}
        {--id= : Reconcile one generation by local ID}';

    protected $description =
        'Reconcile accepted Pixelforge generations with provider results.';

    public function handle(
        PixelforgeClient $client,
        PixelforgeResultProcessor $processor
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
            // Avoid polling jobs that have just been accepted.
            $query->where(
                'submitted_at',
                '<=',
                now()->subSeconds(30)
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
            $lock = Cache::lock(
                "pixelforge:result-reconcile:{$generation->id}",
                90
            );

            if (! $lock->get()) {
                continue;
            }

            try {
                $generation->refresh();

                if (
                    $generation->isTerminal() ||
                    ! $generation->provider_job_id
                ) {
                    continue;
                }

                $remote = $client->retrieveGeneration(
                    $generation->provider_job_id
                );

                $updated = $processor->process(
                    $generation->provider_job_id,
                    $remote
                );

                if ($updated->status === Generation::STATUS_COMPLETED) {
                    PersistGenerationImages::dispatch($updated->id)
                        ->onQueue('images');
                }

                if ($updated->isTerminal()) {
                    $processed++;

                    $this->info(
                        "Generation {$generation->id}: {$updated->status}"
                    );
                } else {
                    $pending++;
                }
            } catch (Throwable $exception) {
                $errors++;

                report($exception);

                $this->error(
                    "Generation {$generation->id}: " .
                    $exception->getMessage()
                );
            } finally {
                $lock->release();
            }
        }

        $this->info(
            "Completed: {$processed}; " .
            "Pending: {$pending}; " .
            "Errors: {$errors}."
        );

        return $errors > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
