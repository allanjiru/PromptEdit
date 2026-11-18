<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\GenerationService;
use App\Services\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;
use Throwable;

class SubmitPixelforgeGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 45;

    public function __construct(
        public int $generationId
    ) {}

    public function backoff(): array
    {
        return [10, 30, 120, 600, 900];
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                "pixelforge-submit:{$this->generationId}"
            ))
                ->shared()
                ->releaseAfter(10)
                ->expireAfter(90),
        ];
    }

    
    public function handle(
        PixelforgeClient $client,
        GenerationService $service
    ): void {
        $generation = Generation::query()
            ->findOrFail($this->generationId);

        // Never submit a terminal generation again.
        if ($generation->isTerminal()) {
            return;
        }

        // Provider submission already recorded.
        if ($generation->provider_job_id !== null) {
            return;
        }

        // The request key is persisted when the generation
        // is created. Every retry must reuse this exact key.
        $response = $client->createGeneration(
            $generation->request_key,
            $generation->prompt,
            $generation->image_size,
            $generation->quality,
            $generation->reference_image_urls ?? []
        );

        $providerId = $response['id'] ?? null;

        if (
            ! is_string($providerId) ||
            ! str_starts_with($providerId, 'gen_')
        ) {
            throw new RuntimeException(
                'Pixelforge returned an invalid generation ID.'
            );
        }

        // A callback could have finalized the generation
        // while the provider HTTP request was in flight.
        $generation->refresh();

        if ($generation->isTerminal()) {
            // Do not overwrite the terminal state.
            // A reconciliation handler will handle any
            // missing provider identifier separately.
            return;
        }

        $service->attachProviderJob(
            $generation,
            $providerId
        );
    }


    public function failed(?Throwable $exception): void
    {
        // Do not automatically release the reservation.
        //
        // A timeout may mean Pixelforge accepted the
        // generation even though Laravel lost the reply.
        //
        // Record the failure for operational recovery,
        // then reconcile with Pixelforge before deciding
        // whether a refund is appropriate.
        report($exception ?? new RuntimeException(
            'Pixelforge submission exhausted its attempts.'
        ));
    }
}
