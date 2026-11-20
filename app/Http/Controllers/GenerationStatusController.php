<?php

namespace App\Http\Controllers;

use App\Http\Responses\GenerationResponseFactory;
use App\Models\Generation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GenerationStatusController extends Controller
{
    public function __invoke(
        Request $request,
        Generation $generation,
        GenerationResponseFactory $response
    ): JsonResponse {
        abort_unless(
            $generation->user_id === $request->user()->id,
            404
        );

        return response()->json($response->make($generation));
    }
}
