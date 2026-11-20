<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\GenerationImagePersistenceDispatcher;
use Illuminate\Console\Command;

class RecoverGenerationImages extends Command
{
    protected $signature = 'generations:recover-images
        {--limit=50 : Maximum generations to inspect}';

    protected $description =
        'Retry storage for completed generations with provider image URLs.';

    public function handle(
        GenerationImagePersistenceDispatcher $images
    ): int {
        $limit = max(1, min(500, (int) $this->option('limit')));

        $dispatched = 0;

        Generation::query()
            ->where('status', Generation::STATUS_COMPLETED)
            ->whereNotNull('result_image_urls')
            ->orderBy('id')
            ->chunkById(100, function ($generations) use (
                $images,
                $limit,
                &$dispatched
            ) {
                foreach ($generations as $generation) {
                    if (! $images->dispatchIfNeeded($generation)) {
                        continue;
                    }

                    $dispatched++;

                    if ($dispatched >= $limit) {
                        return false;
                    }
                }
            });

        $this->info("Dispatched {$dispatched} image storage jobs.");

        return self::SUCCESS;
    }
}
