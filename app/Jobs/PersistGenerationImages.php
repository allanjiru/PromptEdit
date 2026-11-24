<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\GenerationImageStorageService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class PersistGenerationImages implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 90;

    public int $uniqueFor = 1800;

    public function __construct(
        public int $generationId
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180, 600];
    }

    public function uniqueId(): string
    {
        return (string) $this->generationId;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                "generation-images:{$this->generationId}"
            ))
                ->shared()
                ->releaseAfter(15)
                ->expireAfter(150),
        ];
    }

    public function handle(
        GenerationImageStorageService $storage
    ): void {
        $generation = Generation::findOrFail(
            $this->generationId
        );

        $storage->persist($generation);
    }
}
