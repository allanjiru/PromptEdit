<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\GenerationPricingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationHistoryController extends Controller
{
    public function __invoke(
        Request $request,
        GenerationPricingService $pricing
    ): Response {
        $generations = Generation::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate(12)
            ->through(function (Generation $generation) use ($pricing) {
                $images = collect($generation->result_image_urls ?? [])
                    ->map(function ($path, $index) use ($generation) {
                        if (
                            is_string($path) &&
                            str_starts_with($path, 'generations/')
                        ) {
                            return route('generations.images.show', [
                                'generation' => $generation->id,
                                'index' => $index,
                            ], absolute: false);
                        }

                        // Do not expose expiring provider URLs.
                        return null;
                    })
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'id' => $generation->id,
                    'prompt' => $generation->prompt,
                    'status' => $generation->status,
                    'image_size' => $generation->image_size,
                    'quality' => $generation->quality,
                    'images' => $images,
                    'submission_status' => $generation->submission_status,
                    'estimated_charge_usd' => $generation->estimated_charge_units !== null
                            ? $pricing->formatUsd(
                                $generation->estimated_charge_units
                            )
                            : null,
                    'charge_usd' => $generation->customer_charge_units !== null
                        ? $pricing->formatUsd(
                            $generation->customer_charge_units
                        )
                        : null,
                    'images_pending' => $generation->isCompleted()
                        && count($generation->result_image_urls ?? [])
                            > count($images),
                    'created_at' => $generation->created_at->toIso8601String(),
                ];
            });

        return Inertia::render('generations/History', [
            'generations' => $generations,
        ]);
    }
}
