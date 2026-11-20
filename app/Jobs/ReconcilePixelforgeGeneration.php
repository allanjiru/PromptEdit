<?php

namespace App\Jobs;

use App\Services\PixelforgeResultReconciler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class ReconcilePixelforgeGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $timeout = 45;

    public int $uniqueFor = 7200;

    public function __construct(public int $generationId) {}

    public function uniqueId(): string
    {
        return (string) $this->generationId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 10, 10, 10, 10, 30, 60, 120, 300, 600, 900];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                "pixelforge:result-reconcile:{$this->generationId}"
            ))
                ->shared()
                ->releaseAfter(15)
                ->expireAfter(75),
        ];
    }

    public function handle(PixelforgeResultReconciler $reconciler): void
    {
        $result = $reconciler->reconcile($this->generationId);

        if (is_int($result['delay'])) {
            $this->release($result['delay']);
        }
    }
}
