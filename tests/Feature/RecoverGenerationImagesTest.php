<?php

namespace Tests\Feature;

use App\Jobs\PersistGenerationImages;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecoverGenerationImagesTest extends TestCase
{
    use RefreshDatabase;

    private function generationWithImages(array $urls): Generation
    {
        $generation = Generation::create([
            'user_id' => User::factory()->create()->id,
            'request_key' => (string) Str::uuid(),
            'prompt' => 'Recover this image',
            'image_size' => '1024x1024',
            'quality' => 'medium',
            'reference_image_urls' => [],
        ]);

        $generation->status = Generation::STATUS_COMPLETED;
        $generation->result_image_urls = $urls;
        $generation->completed_at = now();
        $generation->save();

        return $generation->refresh();
    }

    public function test_recovery_deduplicates_pending_jobs_and_can_recover_after_lease_release(): void
    {
        Queue::fake();

        $generation = $this->generationWithImages([
            'https://pixelforge.promptedit.dev/images/recover.png',
        ]);

        Artisan::call('generations:recover-images');
        Artisan::call('generations:recover-images');

        Queue::assertPushedOn('images', PersistGenerationImages::class);
        Queue::assertPushed(PersistGenerationImages::class, 1);

        $this->travel(1801)->seconds();

        Artisan::call('generations:recover-images');

        Queue::assertPushed(PersistGenerationImages::class, 2);
    }

    public function test_generations_with_only_local_images_are_not_recovered(): void
    {
        Queue::fake();

        $generation = $this->generationWithImages([
            'generations/1/1/image-0.png',
        ]);

        Artisan::call('generations:recover-images');

        Queue::assertNotPushed(
            PersistGenerationImages::class,
            fn (PersistGenerationImages $job) => $job->generationId === $generation->id
        );
    }

    public function test_failed_job_lock_release_allows_recovery_dispatch(): void
    {
        Queue::fake();

        $generation = $this->generationWithImages([
            'https://pixelforge.promptedit.dev/images/retry.png',
        ]);

        Artisan::call('generations:recover-images');

        $job = new PersistGenerationImages($generation->id);
        Cache::lock(UniqueLock::getKey($job))->forceRelease();

        Artisan::call('generations:recover-images');

        Queue::assertPushed(PersistGenerationImages::class, 2);
    }

    public function test_completed_generation_without_downloadable_images_is_skipped(): void
    {
        Queue::fake();

        $this->generationWithImages([]);

        Artisan::call('generations:recover-images');

        Queue::assertNothingPushed();
    }
}
