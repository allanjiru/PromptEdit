<?php

namespace App\Services;

use App\Jobs\PersistGenerationImages;
use App\Models\Generation;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;

final class GenerationImagePersistenceDispatcher
{
    public function __construct(
        private readonly GenerationImageStorageService $storage,
        private readonly Dispatcher $bus,
        private readonly Repository $cache
    ) {}

    public function dispatchIfNeeded(Generation $generation): bool
    {
        $generation->refresh();

        if (! $this->storage->needsPersistence($generation)) {
            return false;
        }

        $job = (new PersistGenerationImages($generation->id))
            ->onQueue('images');

        $lock = new UniqueLock($this->cache);

        if (! $lock->acquire($job)) {
            return false;
        }

        try {
            $this->bus->dispatch($job);
        } catch (\Throwable $exception) {
            $lock->release($job);

            throw $exception;
        }

        return true;
    }
}
