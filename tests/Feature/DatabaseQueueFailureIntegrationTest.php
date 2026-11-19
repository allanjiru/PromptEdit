<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class DatabaseQueueFailureIntegrationTest extends TestCase
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
                'Queue integration tests require promptedit_test.'
            );
        }

        $this->queueName = 'test-failure-'.Str::uuid();

        config()->set('queue.default', 'database');

        config()->set(
            'queue.connections.database.after_commit',
            false
        );

        config()->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => env('DB_CONNECTION', 'pgsql'),
            'table' => 'failed_jobs',
        ]);

        config()->set(
            'cache.default',
            'array'
        );

        // The test invokes the worker directly, so ensure
        // Laravel's failed-job persistence listener is registered.
        app('queue')->failing(function (JobFailed $event): void {
            app('queue.failer')->log(
                $event->connectionName,
                $event->job->getQueue(),
                $event->job->getRawBody(),
                $event->exception
            );
        });

    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->queueName)) {
                DB::table('jobs')
                    ->where('queue', $this->queueName)
                    ->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function runWorkerOnce(): void
    {
        $worker = new Worker(
            app('queue'),
            app('events'),
            app(ExceptionHandler::class),
            fn (): bool => false
        );

        $options = new WorkerOptions(
            name: 'failure-integration-test',
            backoff: 0,
            memory: 128,
            timeout: 0,
            sleep: 0,
            maxTries: 2,
            force: true,
            stopWhenEmpty: true
        );

        $worker->runNextJob(
            'database',
            $this->queueName,
            $options
        );
    }

    private function pendingJobs(): int
    {
        return DB::table('jobs')
            ->where('queue', $this->queueName)
            ->count();
    }

    public function test_worker_records_job_after_retry_exhaustion(): void
    {
        $failedEvents = [];

        Event::listen(
            JobFailed::class,
            function (JobFailed $event) use (&$failedEvents): void {
                $failedEvents[] = [
                    'connection' => $event->connectionName,
                    'exception' => get_class($event->exception),
                    'message' => $event->exception->getMessage(),
                ];
            }
        );

        $job = new AlwaysFailingQueueTestJob();

        Queue::connection('database')->push(
            $job,
            '',
            $this->queueName
        );

        // Initial queue state.
        $this->assertSame(
            1,
            $this->pendingJobs()
        );

        // First attempt fails and is released.
        $this->runWorkerOnce();

        $this->assertSame(
            1,
            $this->pendingJobs()
        );

        $queuedJob = DB::table('jobs')
            ->where('queue', $this->queueName)
            ->first();

        $this->assertNotNull($queuedJob);

        $this->assertSame(
            1,
            (int) $queuedJob->attempts
        );

        $this->assertNull(
            $queuedJob->reserved_at
        );

        $this->assertCount(
            0,
            $failedEvents
        );

        // Make the released job immediately available.
        DB::table('jobs')
            ->where('queue', $this->queueName)
            ->update([
                'available_at' => time() - 1,
            ]);

        // Second attempt reaches the maximum attempts.
        $this->runWorkerOnce();

        // The failed job must leave the active queue.
        $this->assertSame(
            0,
            $this->pendingJobs()
        );

        // Laravel must emit exactly one failure event.
        $this->assertCount(
            1,
            $failedEvents
        );

        $this->assertSame(
            'database',
            $failedEvents[0]['connection']
        );

        $this->assertSame(
            RuntimeException::class,
            $failedEvents[0]['exception']
        );

        $this->assertSame(
            'Intentional queue integration test failure.',
            $failedEvents[0]['message']
        );

        // Laravel must persist the failure.
        $this->assertDatabaseHas(
            'failed_jobs',
            [
                'connection' => 'database',
                'queue' => $this->queueName,
            ]
        );

        $failedJob = DB::table('failed_jobs')
            ->where('queue', $this->queueName)
            ->first();

        $this->assertNotNull($failedJob);

        $payload = json_decode(
            $failedJob->payload,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            AlwaysFailingQueueTestJob::class,
            $payload['displayName']
        );

        $this->assertSame(
            AlwaysFailingQueueTestJob::class,
            $payload['data']['commandName']
        );

        $this->assertStringContainsString(
            'Intentional queue integration test failure.',
            $failedJob->exception
        );
    }
}

/**
 * Test-only job that always throws.
 *
 * This job must never be dispatched by production code.
 */
final class AlwaysFailingQueueTestJob implements ShouldQueue
{
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 2;

    public int $backoff = 0;

    public function handle(): void
    {
        throw new RuntimeException(
            'Intentional queue integration test failure.'
        );
    }
}
