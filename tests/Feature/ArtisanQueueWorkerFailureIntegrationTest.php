<?php

namespace Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class ArtisanQueueWorkerFailureIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $queueName;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            ! app()->environment('testing') ||
            DB::getDriverName() !== 'pgsql' ||
            DB::connection()->getDatabaseName() !== 'promptedit_test'
        ) {
            throw new RuntimeException(
                'This test requires the promptedit_test PostgreSQL database.'
            );
        }

        $this->queueName = 'test-artisan-failure-'.Str::uuid();

        config()->set('queue.default', 'database');
        config()->set(
            'queue.connections.database.after_commit',
            false
        );

        config()->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'pgsql',
            'table' => 'failed_jobs',
        ]);

        config()->set('cache.default', 'array');
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->queueName)) {
                DB::table('jobs')
                    ->where('queue', $this->queueName)
                    ->delete();

                DB::table('failed_jobs')
                    ->where('queue', $this->queueName)
                    ->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function runArtisanWorkerOnce(): void
    {
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => $this->queueName,
            '--once' => true,
            '--tries' => 2,
            '--backoff' => 0,
            '--sleep' => 0,
            '--timeout' => 0,
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }

    public function test_artisan_worker_persists_exhausted_job(): void
    {
        Queue::connection('database')->push(
            new AlwaysFailingArtisanQueueTestJob(),
            '',
            $this->queueName
        );

        $this->assertSame(
            1,
            DB::table('jobs')
                ->where('queue', $this->queueName)
                ->count()
        );

        // First attempt: job fails and is released.
        $this->runArtisanWorkerOnce();

        $queuedJob = DB::table('jobs')
            ->where('queue', $this->queueName)
            ->first();

        $this->assertNotNull($queuedJob);
        $this->assertSame(1, (int) $queuedJob->attempts);

        // Make the released job available immediately.
        DB::table('jobs')
            ->where('queue', $this->queueName)
            ->update([
                'available_at' => time() - 1,
            ]);

        // Second attempt: job reaches maximum attempts.
        $this->runArtisanWorkerOnce();

        $this->assertSame(
            0,
            DB::table('jobs')
                ->where('queue', $this->queueName)
                ->count()
        );

        // No manual persistence listener is registered.
        $failedJob = DB::table('failed_jobs')
            ->where('connection', 'database')
            ->where('queue', $this->queueName)
            ->first();

        $this->assertNotNull(
            $failedJob,
            'Artisan worker did not persist the exhausted job.'
        );

        $payload = json_decode(
            $failedJob->payload,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            AlwaysFailingArtisanQueueTestJob::class,
            $payload['displayName']
        );

        $this->assertSame(
            AlwaysFailingArtisanQueueTestJob::class,
            $payload['data']['commandName']
        );

        $this->assertStringContainsString(
            'Intentional Artisan worker failure.',
            $failedJob->exception
        );
    }
}

/**
 * Test-only job that always fails.
 */
final class AlwaysFailingArtisanQueueTestJob implements ShouldQueue
{
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 2;

    public int $backoff = 0;

    public function handle(): void
    {
        throw new RuntimeException(
            'Intentional Artisan worker failure.'
        );
    }
}
