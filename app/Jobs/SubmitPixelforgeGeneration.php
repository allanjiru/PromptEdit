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

        // Never resubmit a terminal generation.
        if ($generation->isTerminal()) {
            return;
        }

        // The provider job has already been recorded.
        if ($generation->provider_job_id !== null) {
            return;
        }

        // Reuse the immutable provider request payload.
        $payload = $generation->provider_request_payload;

        // Legacy generations may not have a saved snapshot.
        // Preserve their original empty metadata.
        if ($payload === null) {
            $payload = [
                'prompt' => $generation->prompt,
                'size' => $generation->image_size,
                'quality' => $generation->quality,
                'reference_images' =>
                    $generation->reference_image_urls ?? [],
                'metadata' => [],
            ];
        }

        // Retry with the original idempotency key
        // and exactly the same request parameters.
        $response = $client->createGenerationFromPayload(
            $generation->request_key,
            $payload
        );

        $providerId = $response['id'] ?? null;

        if (
            ! is_string($providerId) ||
            ! preg_match('/^gen_[A-Za-z0-9]+$/', $providerId)
        ) {
            throw new RuntimeException(
                'Pixelforge returned an invalid generation ID.'
            );
        }

        // Refresh in case another worker updated the record.
        $generation->refresh();

        // Never overwrite a terminal generation.
        if ($generation->isTerminal()) {
            return;
        }

        // Persist the provider generation identifier.
        $service->attachProviderJob(
            $generation,
            $providerId
        );
    }

    public function failed(?Throwable $exception): void
    {
        // Do not automatically release reserved credits.
        // The provider may have accepted the request
        // even if PromptEdit lost its HTTP response.
        report(
            $exception ?? new RuntimeException(
                'Pixelforge submission exhausted its attempts.'
            )
        );
    }
}
