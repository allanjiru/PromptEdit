<?php

namespace App\Http\Controllers;

use App\Jobs\SubmitPixelforgeGeneration;
use App\Services\GenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GenerationController extends Controller
{
    public function store(
        Request $request,
        GenerationService $generationService
    ): JsonResponse {
        $validated = $request->validate([
            'request_key' => [
                'required',
                'uuid',
            ],
            'prompt' => [
                'required',
                'string',
                'max:5000',
            ],
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

        // DEVELOPMENT ONLY.
        // Replace with a trusted server-side pricing
        // estimator before enabling real submissions.
        $estimatedChargeUnits = 30_000_000;

        $generation = $generationService->create(
            $request->user(),
            $validated['request_key'],
            $validated['prompt'],
            $validated['image_size'],
            $validated['quality'],
            $validated['reference_image_urls'] ?? [],
            $estimatedChargeUnits
        );

        SubmitPixelforgeGeneration::dispatch(
            $generation->id
        )
            ->onQueue('generations')
            ->afterCommit();

        return response()->json([
            'id' => $generation->id,
            'request_key' => $generation->request_key,
            'status' => $generation->status,
            'estimated_charge_units' =>
                $generation->estimated_charge_units,
        ], 202);
    }
}
