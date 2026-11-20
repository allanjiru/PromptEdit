<?php

namespace App\Services;

use App\Jobs\ReconcilePixelforgeGeneration;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

final class PixelforgeReconciliationDispatcher
{
    public function __construct(
        private readonly PixelforgeReconciliationState $state,
        private readonly Dispatcher $bus,
        private readonly Repository $cache
    ) {}

    public function dispatchInitial(int $generationId): bool
    {
        $this->state->scheduleInitial($generationId);

        $job = (new ReconcilePixelforgeGeneration($generationId))
            ->onQueue('generations')
            ->delay(PixelforgeReconciliationState::INITIAL_DELAY_SECONDS);
        $lock = new UniqueLock($this->cache);

        if (! $lock->acquire($job)) {
            return false;
        }

        try {
            $this->bus->dispatch($job);
        } catch (Throwable $exception) {
            $lock->release($job);
            report($exception);

            return false;
        }

        return true;
    }
}
