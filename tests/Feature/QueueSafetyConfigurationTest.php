<?php

namespace Tests\Feature;

use App\Jobs\PersistGenerationImages;
use App\Jobs\ProcessPixelforgeWebhook;
use App\Jobs\SubmitPixelforgeGeneration;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Tests\TestCase;

class QueueSafetyConfigurationTest extends TestCase
{
    public function test_database_retry_after_exceeds_all_job_timeouts(): void
    {
        $retryAfter = config('queue.connections.database.retry_after');
        $longestTimeout = max(
            (new PersistGenerationImages(1))->timeout,
            (new ProcessPixelforgeWebhook(1))->timeout,
            (new SubmitPixelforgeGeneration(1))->timeout
        );

        $this->assertGreaterThan($longestTimeout, $retryAfter);
        $this->assertGreaterThanOrEqual(180, $retryAfter);
    }

    public function test_persistence_job_is_unique_and_uses_images_queue(): void
    {
        $job = (new PersistGenerationImages(42))->onQueue('images');

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('42', $job->uniqueId());
        $this->assertSame('images', $job->queue);
        $this->assertGreaterThan($job->timeout, $job->uniqueFor);
    }
}
