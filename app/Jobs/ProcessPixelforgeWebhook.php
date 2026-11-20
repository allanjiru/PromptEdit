<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Models\ProviderWebhookEvent;
use App\Services\GenerationImagePersistenceDispatcher;
use App\Services\PixelforgeClient;
use App\Services\PixelforgeResultProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessPixelforgeWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 45;

    public function __construct(
        public int $eventId
    ) {}

    public function backoff(): array
    {
        return [10, 30, 60, 120, 300, 600, 900];
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                "pixelforge-webhook:{$this->eventId}"
            ))
                ->shared()
                ->releaseAfter(10)
                ->expireAfter(90),
        ];
    }

    public function handle(
        PixelforgeClient $client,
        PixelforgeResultProcessor $processor,
        GenerationImagePersistenceDispatcher $images
    ): void {
        $event = ProviderWebhookEvent::findOrFail(
            $this->eventId
        );

        if ($event->isProcessed()) {
            return;
        }

        // Avoid retrieving provider state before the local
        // generation has been linked to its provider job.
        $generation = Generation::query()
            ->where('provider_job_id', $event->provider_job_id)
            ->first();

        if ($generation === null) {
            $this->release(30);

            return;
        }

        // A previously completed generation may receive
        // duplicate webhook deliveries. Still reconcile
        // the event's processing status below.
        $providerResult = $client->retrieveGeneration(
            $event->provider_job_id
        );

        $updated = $processor->process(
            $event->provider_job_id,
            $providerResult
        );

        $images->dispatchIfNeeded($updated);

        DB::transaction(function () use ($event) {
            $locked = ProviderWebhookEvent::query()
                ->whereKey($event->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isProcessed()) {
                return;
            }

            $locked->status =
                ProviderWebhookEvent::STATUS_PROCESSED;

            $locked->processed_at = now();
            $locked->processing_error = null;
            $locked->save();
        });
    }

    public function failed(?Throwable $exception): void
    {
        ProviderWebhookEvent::query()
            ->whereKey($this->eventId)
            ->where('status', '!=',
                ProviderWebhookEvent::STATUS_PROCESSED
            )
            ->update([
                'status' => ProviderWebhookEvent::STATUS_FAILED,
                'processing_error' => substr(
                    $exception?->getMessage()
                        ?? 'Webhook processing failed.',
                    0,
                    2000
                ),
            ]);
    }
}
