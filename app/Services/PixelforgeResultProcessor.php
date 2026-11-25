<?php

namespace App\Services;

use App\Models\Generation;
use LogicException;

class PixelforgeResultProcessor
{
    public function __construct(
        private readonly GenerationService $generations,
        private readonly GenerationPricingService $pricing,
    ) {}

    /**
     * Process a trusted provider generation response.
     *
     * The caller must authenticate webhook payloads
     * or obtain results directly through PixelforgeClient.
     *
     * @param  array<string, mixed>  $payload
     */
    public function process(
        string $providerJobId,
        array $payload
    ): Generation {
        if (
            ! str_starts_with($providerJobId, 'gen_') ||
            ($payload['id'] ?? null) !== $providerJobId
        ) {
            throw new LogicException(
                'Provider generation ID mismatch.'
            );
        }

        $generation = Generation::query()
            ->where('provider_job_id', $providerJobId)
            ->firstOrFail();

        $status = $payload['status'] ?? null;

        if (! is_string($status)) {
            throw new LogicException(
                'Provider status is missing.'
            );
        }

        return match ($status) {
            'queued' => $generation->refresh(),

            'processing' => $this->processProcessing(
                $generation
            ),

            'succeeded' => $this->processSuccess(
                $generation,
                $payload
            ),

            'failed' => $this->processFailure(
                $generation,
                $payload
            ),

            default => throw new LogicException(
                "Unsupported provider status: {$status}"
            ),
        };
    }

    private function processProcessing(
        Generation $generation
    ): Generation {
        $generation->refresh();

        // Late processing events must not undo completion.
        if ($generation->isTerminal()) {
            return $generation;
        }

        if ($generation->status === Generation::STATUS_PROCESSING) {
            return $generation;
        }

        return $this->generations->markProcessing(
            $generation
        );
    }

    /** @param array<string, mixed> $payload */
    private function processSuccess(
        Generation $generation,
        array $payload
    ): Generation {
        $generation->refresh();

        if ($generation->status === Generation::STATUS_COMPLETED) {
            return $generation;
        }

        if ($generation->status === Generation::STATUS_FAILED) {
            throw new LogicException(
                'A failed generation cannot be completed.'
            );
        }

        $usage = $payload['usage'] ?? null;
        $rates = $payload['rates'] ?? null;

        if (! is_array($usage) || ! is_array($rates)) {
            throw new LogicException(
                'Successful provider result lacks usage or rates.'
            );
        }

        $urls = [];

        // Support provider responses containing an images array.
        if (isset($payload['images'])) {
            if (
                ! is_array($payload['images']) ||
                $payload['images'] === []
            ) {
                throw new LogicException(
                    'Successful provider result has no images.'
                );
            }

            foreach ($payload['images'] as $image) {
                if (
                    ! is_array($image) ||
                    ! is_string($image['url'] ?? null) ||
                    ! filter_var(
                        $image['url'],
                        FILTER_VALIDATE_URL
                    )
                ) {
                    throw new LogicException(
                        'Invalid provider image URL.'
                    );
                }

                $urls[] = $image['url'];
            }
        } elseif (
            is_string($payload['image_url'] ?? null) &&
            filter_var(
                $payload['image_url'],
                FILTER_VALIDATE_URL
            )
        ) {
            // Pixelforge's retrieveGeneration() response
            // supplies a single signed image_url.
            $urls[] = $payload['image_url'];
        }

        if ($urls === []) {
            throw new LogicException(
                'Successful provider result has no valid image URL.'
            );
        }

        $billableCategories = [
            'input_text_tokens',
            'input_image_tokens',
            'output_tokens',
            'reasoning_tokens',
        ];

        $expectedUsageCategories = [
            ...$billableCategories,
            'total_tokens',
        ];

        $actualUsageCategories = array_keys($usage);
        sort($actualUsageCategories);
        sort($expectedUsageCategories);

        if ($actualUsageCategories !== $expectedUsageCategories) {
            throw new LogicException(
                'Provider usage does not contain the documented categories.'
            );
        }

        $billableUsage = [];

        foreach ($billableCategories as $category) {
            $tokens = $usage[$category];

            if (! is_int($tokens) || $tokens < 0) {
                throw new LogicException(
                    "Invalid provider usage for {$category}."
                );
            }

            $billableUsage[$category] = $tokens;
        }

        if (
            ! is_int($usage['total_tokens']) ||
            $usage['total_tokens'] !== array_sum($billableUsage)
        ) {
            throw new LogicException(
                'Provider total token usage is inconsistent.'
            );
        }

        $rateCategories = array_keys($rates);
        $expectedRateCategories = $billableCategories;
        sort($rateCategories);
        sort($expectedRateCategories);

        if ($rateCategories !== $expectedRateCategories) {
            throw new LogicException(
                'Provider rates do not contain the documented categories.'
            );
        }

        $providerCostUnits = $this->pricing->providerCostUnits(
            $billableUsage,
            $rates
        );

        return $this->generations->succeed(
            $generation,
            $providerCostUnits,
            $urls,
            $usage,
            $rates
        );
    }

    /** @param array<string, mixed> $payload */
    private function processFailure(
        Generation $generation,
        array $payload
    ): Generation {
        $reason = $payload['error']['message']
            ?? 'Pixelforge generation failed.';

        if (! is_string($reason) || trim($reason) === '') {
            $reason = 'Pixelforge generation failed.';
        }

        return $this->generations->fail(
            $generation,
            $reason
        );
    }
}
