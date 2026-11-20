<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GenerationImageStorageService
{
    private const MAX_IMAGE_BYTES = 20 * 1024 * 1024;

    private const LOCK_SECONDS = 150;

    public function needsPersistence(Generation $generation): bool
    {
        if ($generation->status !== Generation::STATUS_COMPLETED) {
            return false;
        }

        return collect($generation->result_image_urls ?? [])
            ->contains(
                fn ($url) => is_string($url)
                    && $this->isTrustedProviderUrl($url)
            );
    }

    public function persist(Generation $generation): void
    {
        $lock = Cache::lock(
            $this->lockKey($generation->id),
            self::LOCK_SECONDS
        );

        if (! $lock->get()) {
            return;
        }

        try {
            $this->persistLocked($generation);
        } finally {
            $lock->release();
        }
    }

    public function lockKey(int $generationId): string
    {
        return "generation-image-storage:{$generationId}";
    }

    private function persistLocked(Generation $generation): void
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
            if (is_string($url) && str_starts_with($url, 'generations/')) {
                continue;
            }

            if (! is_string($url) || ! $this->isTrustedProviderUrl($url)) {
                throw new RuntimeException('Untrusted image URL.');
            }

            $path = sprintf(
                'generations/%d/%d/image-%d.png',
                $generation->user_id,
                $generation->id,
                $index
            );

            if ($this->isValidStoredPng($path)) {
                $this->commitPath(
                    $generation->id,
                    $index,
                    $url,
                    $path
                );

                continue;
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

            if (strlen($body) > self::MAX_IMAGE_BYTES) {
                throw new RuntimeException('Image exceeds size limit.');
            }

            $info = @getimagesizefromstring($body);

            if (
                $info === false ||
                $info[2] !== IMAGETYPE_PNG
            ) {
                throw new RuntimeException('Invalid PNG image.');
            }

            $temporaryPath = sprintf(
                'generations/%d/%d/.image-%d-%s.tmp',
                $generation->user_id,
                $generation->id,
                $index,
                Str::uuid()
            );

            try {
                if (! Storage::disk('local')->put($temporaryPath, $body)) {
                    throw new RuntimeException('Image storage failed.');
                }

                $this->commitPath(
                    $generation->id,
                    $index,
                    $url,
                    $path,
                    $temporaryPath
                );
            } finally {
                Storage::disk('local')->delete($temporaryPath);
            }
        }
    }

    private function commitPath(
        int $generationId,
        int $index,
        string $expectedUrl,
        string $path,
        ?string $temporaryPath = null
    ): void {
        DB::transaction(function () use (
            $generationId,
            $index,
            $expectedUrl,
            $path,
            $temporaryPath
        ) {
            $generation = Generation::query()
                ->whereKey($generationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($generation->status !== Generation::STATUS_COMPLETED) {
                return;
            }

            $current = $generation->result_image_urls ?? [];

            if (($current[$index] ?? null) === $path) {
                return;
            }

            if (($current[$index] ?? null) !== $expectedUrl) {
                return;
            }

            if (
                ! $this->isValidStoredPng($path) &&
                (
                    $temporaryPath === null ||
                    ! Storage::disk('local')->move($temporaryPath, $path)
                )
            ) {
                throw new RuntimeException('Image storage failed.');
            }

            $current[$index] = $path;
            $generation->result_image_urls = $current;
            $generation->save();
        }, 3);
    }

    private function isValidStoredPng(string $path): bool
    {
        try {
            $disk = Storage::disk('local');

            if (
                ! $disk->exists($path) ||
                $disk->size($path) > self::MAX_IMAGE_BYTES
            ) {
                return false;
            }

            $info = @getimagesizefromstring($disk->get($path));

            return $info !== false && $info[2] === IMAGETYPE_PNG;
        } catch (Throwable) {
            return false;
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
