<?php

namespace App\Http\Controllers;

use App\Http\Responses\GenerationResponseFactory;
use App\Models\Generation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LatestGenerationController extends Controller
{
    public function __invoke(
        Request $request,
        GenerationResponseFactory $response
    ): JsonResponse {
        $generation = Generation::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->first();

        if ($generation === null) {
            return response()->json([
                'generation' => null,
            ]);
        }

        return response()->json([
            'generation' => $response->make($generation),
        ]);
    }
}
