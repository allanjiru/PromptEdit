<?php

namespace App\Jobs;

use App\Models\ProviderWebhookEvent;
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
        PixelforgeResultProcessor $processor
    ): void {
        $event = ProviderWebhookEvent::findOrFail(
            $this->eventId
        );

        if ($event->isProcessed()) {
            return;
        }

        // Fetch canonical provider state instead of
        // trusting potentially stale webhook contents.
        $providerResult = $client->retrieveGeneration(
            $event->provider_job_id
        );

        // May throw when provider_job_id has not yet been
        // attached to the local generation.
        // Queue retries allow time for submission to finish.
        $processor->process(
            $event->provider_job_id,
            $providerResult
        );

        DB::transaction(function () use ($event) {
            $locked = ProviderWebhookEvent::query()
                ->whereKey($event->id)
                ->lockForUpdate()
                ->firstOrFail();

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
                'processing_error' =>
                    substr(
                        $exception?->getMessage()
                            ?? 'Webhook processing failed.',
                        0,
                        2000
                    ),
            ]);
    }
}
