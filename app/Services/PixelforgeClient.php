<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use LogicException;

class PixelforgeClient
{
    private function request(): PendingRequest
    {
        $key = config('services.pixelforge.api_key');

        if (! is_string($key) || $key === '') {
            throw new LogicException(
                'Pixelforge API key is not configured.'
            );
        }

        return Http::baseUrl(
            config('services.pixelforge.base_url')
        )
            ->acceptJson()
            ->asJson()
            ->withToken($key)
            ->connectTimeout(5)
            ->timeout(15);
    }

    /**
     * Submit a generation using a stable idempotency key.
     *
     * Retry orchestration belongs to the queue job,
     * not this method.
     *
     * @throws RequestException
     */
    public function createGeneration(
        string $idempotencyKey,
        string $prompt,
        string $size,
        string $quality,
        array $referenceImages = [],
        array $metadata = []
    ): array {
        $response = $this->request()
            ->withHeaders([
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->post('/generations', [
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'metadata' => $metadata,
            ])
            ->throw();

        $data = $response->json();

        if (
            $response->status() !== 202 ||
            ! is_array($data) ||
            ! is_string($data['id'] ?? null) ||
            ! str_starts_with($data['id'], 'gen_')
        ) {
            throw new LogicException(
                'Unexpected Pixelforge creation response.'
            );
        }

        return $data;
    }

    public function retrieveGeneration(
        string $providerJobId
    ): array {
        if (! preg_match(
            '/^gen_[A-Za-z0-9]+$/',
            $providerJobId
        )) {
            throw new LogicException(
                'Invalid provider generation ID.'
            );
        }

        return $this->request()
            ->get('/generations/'.$providerJobId)
            ->throw()
            ->json();
    }

    public function pricing(): array
    {
        return Http::baseUrl(
            config('services.pixelforge.base_url')
        )
            ->acceptJson()
            ->timeout(15)
            ->get('/pricing')
            ->throw()
            ->json();
    }

    public function usage(
        string $startDate,
        string $endDate
    ): array {
        return $this->request()
            ->get('/usage', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ])
            ->throw()
            ->json();
    }
}
