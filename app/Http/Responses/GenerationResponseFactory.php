<?php

namespace App\Http\Responses;

use App\Models\Generation;
use App\Services\GenerationPricingService;

final class GenerationResponseFactory
{
    public function __construct(
        private readonly GenerationPricingService $pricing
    ) {}

    /** @return array<string, mixed> */
    public function make(Generation $generation): array
    {
        $imageUrls = $this->imageUrls($generation);
        $expectedImageCount = $generation->isCompleted()
            ? count($generation->result_image_urls ?? [])
            : 0;

        return [
            'id' => $generation->id,
            'request_key' => $generation->request_key,
            'prompt' => $generation->prompt,
            'image_size' => $generation->image_size,
            'quality' => $generation->quality,
            'reference_image_urls' => $generation->reference_image_urls ?? [],
            'status' => $generation->status,
            'submission_status' => $generation->submission_status,
            'estimated_charge_units' => $generation->estimated_charge_units,
            'estimated_charge_usd' => $this->pricing->formatUsd(
                $generation->estimated_charge_units
            ),
            'customer_charge_usd' => $generation->customer_charge_units !== null
                    ? $this->pricing->formatUsd(
                        $generation->customer_charge_units
                    )
                    : null,
            'result_image_urls' => $imageUrls,
            'expected_image_count' => $expectedImageCount,
            'images_ready' => $generation->isCompleted()
                && $expectedImageCount > 0
                && count($imageUrls) === $expectedImageCount,
            'reconciliation_paused' => ! $generation->isTerminal()
                && $generation->result_reconciliation_exhausted_at !== null,
            'error_message' => $generation->isFailed()
                ? $generation->error_message
                : null,
            'completed_at' => $generation->completed_at?->toIso8601String(),
        ];
    }

    /** @return array<int, string> */
    private function imageUrls(Generation $generation): array
    {
        if (! $generation->isCompleted()) {
            return [];
        }

        return collect($generation->result_image_urls ?? [])
            ->map(function ($path, $index) use ($generation) {
                if (
                    ! is_string($path) ||
                    ! str_starts_with($path, 'generations/')
                ) {
                    // Provider URLs are temporary and must not be exposed.
                    return null;
                }

                return route('generations.images.show', [
                    'generation' => $generation->id,
                    'index' => $index,
                ], absolute: false);
            })
            ->filter()
            ->values()
            ->all();
    }
}
