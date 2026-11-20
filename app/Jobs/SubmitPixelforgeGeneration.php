<?php

namespace App\Jobs;

use App\Services\GenerationSubmissionClaimService;
use App\Services\PixelforgeClient;
use App\Services\PixelforgeReconciliationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;
use Throwable;

final class SubmitPixelforgeGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 45;

    public function __construct(
        public int $generationId,
        public ?string $dispatchToken = null
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 120, 600, 900];
    }

    /** @return list<object> */
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
        GenerationSubmissionClaimService $claims,
        ?PixelforgeReconciliationDispatcher $reconciliation = null
    ): void {
        $claim = $claims->acquire(
            $this->generationId,
            $this->dispatchToken
        );

        if ($claim === null) {
            return;
        }

        try {
            $response = $client->createGenerationFromPayload(
                $claim['request_key'],
                $claim['payload']
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

            $accepted = $claims->accepted(
                $this->generationId,
                $claim['token'],
                $providerId
            );

            if ($accepted) {
                ($reconciliation ?? app(PixelforgeReconciliationDispatcher::class))
                    ->dispatchInitial($this->generationId);
            }
        } catch (Throwable $exception) {
            $claims->uncertain(
                $this->generationId,
                $claim['token'],
                $exception->getMessage()
            );

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        report(
            $exception ?? new RuntimeException(
                'Pixelforge submission exhausted its attempts.'
            )
        );
    }
}
