<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GenerationImageStorageService
{
    public function persist(Generation $generation): void
    {
        $generation->refresh();

        if ($generation->status !== Generation::STATUS_COMPLETED) {
            return;
        }

        $urls = $generation->result_image_urls ?? [];

        if (! is_array($urls) || $urls === []) {
            throw new RuntimeException('No generation images available.');
        }

        foreach ($urls as $index => $url) {
            // Existing local images need no download.
            if (str_starts_with($url, 'generations/')) {
                continue;
            }

            if (! is_string($url) || ! $this->isTrustedProviderUrl($url)) {
                throw new RuntimeException('Untrusted image URL.');
            }

            $response = Http::timeout(30)
                ->connectTimeout(10)
                ->get($url);

            if (! $response->successful()) {
                throw new RuntimeException(
                    'Unable to download generated image.'
                );
            }

            $body = $response->body();

            if (strlen($body) > 20 * 1024 * 1024) {
                throw new RuntimeException('Image exceeds size limit.');
            }

            $info = @getimagesizefromstring($body);

            if (
                $info === false ||
                $info[2] !== IMAGETYPE_PNG
            ) {
                throw new RuntimeException('Invalid PNG image.');
            }

            $path = sprintf(
                'generations/%d/%d/image-%d.png',
                $generation->user_id,
                $generation->id,
                $index
            );

            if (! Storage::disk('local')->put($path, $body)) {
                throw new RuntimeException('Image storage failed.');
            }

            // Save progress per image so retries are safe.
            $generation->refresh();
            $current = $generation->result_image_urls ?? [];
            $current[$index] = $path;
            $generation->result_image_urls = $current;
            $generation->save();
        }
    }

    private function isTrustedProviderUrl(string $url): bool
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) ===
                'pixelforge.promptedit.dev'
            && str_starts_with(
                $parts['path'] ?? '',
                '/images/'
            );
    }
}
