<?php

namespace App\Http\Controllers;

use App\Http\Responses\GenerationResponseFactory;
use App\Models\Generation;
use App\Services\GenerationImagePersistenceDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RetryGenerationImagesController extends Controller
{
    public function __invoke(
        Request $request,
        Generation $generation,
        GenerationImagePersistenceDispatcher $images,
        GenerationResponseFactory $response
    ): JsonResponse {
        abort_unless(
            $generation->user_id === $request->user()->id,
            404
        );
        abort_unless($generation->isCompleted(), 409);

        $images->dispatchIfNeeded($generation);

        return response()->json($response->make($generation->refresh()));
    }
}
