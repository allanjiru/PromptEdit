<?php

namespace App\Http\Controllers;

use App\Jobs\SubmitPixelforgeGeneration;
use App\Models\Generation;
use App\Services\GenerationEstimateService;
use App\Services\GenerationPricingService;
use App\Services\GenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GenerationController extends Controller
{
    public function store(
        Request $request,
        GenerationService $generations,
        GenerationEstimateService $estimates,
        GenerationPricingService $pricing
    ): JsonResponse {
        $validated = $request->validate([
            'request_key' => ['required', 'uuid'],
            'prompt' => ['required', 'string', 'max:5000'],
            'image_size' => [
                'required',
                'string',
                'in:1024x1024,1536x1024,1024x1536',
            ],
            'quality' => [
                'required',
                'string',
                'in:low,medium,high',
            ],
            'reference_image_urls' => [
                'sometimes',
                'array',
            ],
            'reference_image_urls.*' => [
                'required',
                'url',
                'max:2048',
            ],
        ]);

        $referenceUrls =
            $validated['reference_image_urls'] ?? [];

        $existing = Generation::query()
            ->where('request_key', $validated['request_key'])
            ->first();

        if ($existing !== null) {
            if (
                $existing->user_id !== $request->user()->id ||
                $existing->prompt !== $validated['prompt'] ||
                $existing->image_size !== $validated['image_size'] ||
                $existing->quality !== $validated['quality'] ||
                ($existing->reference_image_urls ?? []) !==
                    $referenceUrls
            ) {
                throw ValidationException::withMessages([
                    'request_key' =>
                        'This request key is already associated with different generation parameters.',
                ]);
            }

            $estimatedChargeUnits =
                $existing->estimated_charge_units;
        } else {
            $estimate = $estimates->estimate(
                $validated['prompt'],
                $validated['image_size'],
                $validated['quality'],
                $referenceUrls
            );

            $estimatedChargeUnits =
                $estimate['estimated_charge_units'];
        }

        $generation = $generations->create(
            $request->user(),
            $validated['request_key'],
            $validated['prompt'],
            $validated['image_size'],
            $validated['quality'],
            $referenceUrls,
            $estimatedChargeUnits
        );

        if ($generation->wasRecentlyCreated) {
            SubmitPixelforgeGeneration::dispatch($generation->id)
                ->onQueue('generations')
                ->afterCommit();
        }

        return response()->json([
            'id' => $generation->id,
            'request_key' => $generation->request_key,
            'status' => $generation->status,
            'estimated_charge_units' =>
                $generation->estimated_charge_units,
            'estimated_charge_usd' =>
                $pricing->formatUsd(
                    $generation->estimated_charge_units
                ),
        ], 202);
    }
}
