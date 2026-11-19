<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\GenerationPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GenerationStatusController extends Controller
{
    public function __invoke(
        Request $request,
        Generation $generation,
        GenerationPricingService $pricing
    ): JsonResponse {
        abort_unless(
            $generation->user_id === $request->user()->id,
            404
        );

        return response()->json([
            'id' => $generation->id,
            'status' => $generation->status,
            'submission_status' =>
                $generation->submission_status,
            'estimated_charge_usd' =>
                $pricing->formatUsd(
                    $generation->estimated_charge_units
                ),
            'customer_charge_usd' =>
                $generation->customer_charge_units !== null
                    ? $pricing->formatUsd(
                        $generation->customer_charge_units
                    )
                    : null,
            'result_image_urls' => $generation->status === Generation::STATUS_COMPLETED
                ? collect($generation->result_image_urls ?? [])
                    ->map(function ($path, $index) use ($generation) {
                        if (is_string($path) && str_starts_with($path, 'generations/')) {
                            return route('generations.images.show', [
                                'generation' => $generation->id,
                                'index' => $index,
                            ]);
                        }

                        return $path;
                    })
                    ->values()
                    ->all()
                : [],
            'error_message' =>
                $generation->isFailed()
                    ? $generation->error_message
                    : null,
            'completed_at' =>
                $generation->completed_at?->toIso8601String(),
        ]);
    }
}
