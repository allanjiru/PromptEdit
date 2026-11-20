<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GenerationImageController extends Controller
{
    public function __invoke(
        Request $request,
        Generation $generation,
        int $index
    ): BinaryFileResponse {
        abort_unless(
            $generation->user_id === $request->user()->id,
            403
        );

        $urls = $generation->result_image_urls ?? [];

        $path = $urls[$index] ?? null;

        abort_unless(
            is_string($path) &&
            preg_match(
                '#^generations/'.
                $generation->user_id.'/'.
                $generation->id.
                '/image-[0-9]+\.png$#',
                $path
            ),
            404
        );

        abort_unless(
            Storage::disk('local')->exists($path),
            404
        );

        return response()->file(
            Storage::disk('local')->path($path),
            [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
