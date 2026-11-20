<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use LogicException;

class GenerationEstimateService
{
    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly GenerationPricingService $pricing,
    ) {}

    public function estimate(
        string $prompt,
        string $size,
        string $quality,
        array $referenceUrls = []
    ): array {
        $catalog = Cache::remember(
            'pixelforge:pricing:v1',
            now()->addMinutes(15),
            fn () => $this->client->pricing()
        );

        if (
            ($catalog['currency'] ?? null) !== 'usd' ||
            ($catalog['unit'] ?? null) !== 'per_1m_tokens'
        ) {
            throw new LogicException(
                'Unsupported Pixelforge pricing format.'
            );
        }

        $tokens = $catalog['tokens'] ?? [];
        $rates = $catalog['rates'] ?? [];

        $charactersPerToken =
            $tokens['characters_per_text_token'] ?? null;

        $variance = $tokens['text_token_variance'] ?? null;

        $referenceTokens =
            $tokens['per_reference_image'] ?? null;

        $outputTokens =
            $tokens['output'][$quality][$size] ?? null;

        $reasoningMax =
            $tokens['reasoning'][$quality]['max'] ?? null;

        if (
            ! is_int($charactersPerToken) ||
            $charactersPerToken <= 0 ||
            ! is_numeric($variance) ||
            (float) $variance < 0 ||
            ! is_int($referenceTokens) ||
            $referenceTokens < 0 ||
            ! is_int($outputTokens) ||
            $outputTokens < 0 ||
            ! is_int($reasoningMax) ||
            $reasoningMax < 0
        ) {
            throw new InvalidArgumentException(
                'Invalid Pixelforge pricing configuration.'
            );
        }

        $textTokens = (int) ceil(
            mb_strlen($prompt) / $charactersPerToken
        );

        $textTokens = (int) ceil(
            $textTokens * (1 + (float) $variance)
        );

        $usage = [
            'input_text_tokens' => $textTokens,
            'input_image_tokens' => count($referenceUrls) * $referenceTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoningMax,
        ];

        $providerUnits = $this->pricing->providerCostUnits(
            $usage,
            $rates
        );

        $customerUnits = $this->pricing->customerChargeUnits(
            $providerUnits
        );

        return [
            'estimated_provider_units' => $providerUnits,
            'estimated_charge_units' => $customerUnits,
            'estimated_charge_usd' => $this->pricing->formatUsd($customerUnits),
            'estimated_usage' => $usage,
        ];
    }
}
