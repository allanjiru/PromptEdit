<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Throwable;

final class PixelforgeResultReconciler
{
    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly PixelforgeResultProcessor $processor,
        private readonly GenerationImagePersistenceDispatcher $images,
        private readonly PixelforgeReconciliationState $state
    ) {}

    /** @return array{outcome: string, delay: int|null} */
    public function reconcile(int $generationId): array
    {
        $claim = $this->state->claim($generationId);

        if ($claim === null) {
            return ['outcome' => 'skipped', 'delay' => null];
        }

        try {
            $payload = $this->client->retrieveGeneration(
                $claim['provider_job_id']
            );
            $generation = $this->processor->process(
                $claim['provider_job_id'],
                $payload
            );

            $this->images->dispatchIfNeeded($generation);

            if ($generation->isTerminal()) {
                $this->state->finish($generationId, $claim['token']);

                return ['outcome' => 'terminal', 'delay' => null];
            }

            return [
                'outcome' => 'pending',
                'delay' => $this->state->release(
                    $generationId,
                    $claim['token'],
                    $claim['attempt']
                ),
            ];
        } catch (RequestException $exception) {
            report($exception);

            return [
                'outcome' => 'error',
                'delay' => $this->state->release(
                    $generationId,
                    $claim['token'],
                    $claim['attempt'],
                    $this->retryAfter($exception)
                ),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'outcome' => 'error',
                'delay' => $this->state->release(
                    $generationId,
                    $claim['token'],
                    $claim['attempt']
                ),
            ];
        }
    }

    private function retryAfter(RequestException $exception): ?int
    {
        $value = $exception->response->header('Retry-After');

        if (trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (ctype_digit($value)) {
            if (strlen($value) > strlen((string) PHP_INT_MAX)) {
                return PHP_INT_MAX;
            }

            return (int) $value;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }
}
