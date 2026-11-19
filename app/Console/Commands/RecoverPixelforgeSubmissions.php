<?php

namespace App\Console\Commands;

use App\Jobs\SubmitPixelforgeGeneration;
use App\Services\GenerationSubmissionRecoveryService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Throwable;

final class RecoverPixelforgeSubmissions extends Command
{
    protected $signature = 'pixelforge:recover-submissions
        {--limit=100 : Maximum submissions to recover}';

    protected $description =
        'Recover incomplete Pixelforge submissions safely.';

    public function handle(
        GenerationSubmissionRecoveryService $recovery,
        Dispatcher $dispatcher
    ): int {
        $limit = filter_var(
            $this->option('limit'),
            FILTER_VALIDATE_INT
        );

        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('Limit must be between 1 and 500.');

            return self::FAILURE;
        }

        $dispatched = 0;

        $candidates = $recovery->candidates($limit);

        foreach ($candidates as $generationId) {
            $token = $recovery->reserve((int) $generationId);

            if ($token === null) {
                continue;
            }

            try {
                $job = new SubmitPixelforgeGeneration(
                    (int) $generationId,
                    $token
                );

                $job->onQueue('generations');

                // Enqueue immediately rather than relying on
                // PendingDispatch destructor behavior.
                $dispatcher->dispatch($job);

                $dispatched++;
            } catch (Throwable $exception) {
                report($exception);

                // Preserve the lease and wallet reservation.
                // Recovery becomes eligible after lease expiry.
            }
        }

        $this->info(
            "Dispatched {$dispatched} submission recovery job(s)."
        );

        return self::SUCCESS;
    }
}
