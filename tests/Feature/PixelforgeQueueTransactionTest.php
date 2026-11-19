<?php

namespace Tests\Feature;

use App\Jobs\SubmitPixelforgeGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class PixelforgeQueueTransactionTest extends TestCase
{
    private string $queueName;

    protected function setUp(): void
    {
        parent::setUp();

        // Never run transaction tests against production
        // or the development database.
        $database = DB::connection()->getDatabaseName();

        if (
            ! app()->environment('testing') ||
            DB::getDriverName() !== 'pgsql' ||
            $database !== 'promptedit_test'
        ) {
            throw new RuntimeException(
                'This test requires the isolated PostgreSQL '.
                'database named promptedit_test.'
            );
        }

        // Each test gets a unique queue name.
        // This prevents interference with other jobs.
        $this->queueName = 'test-transaction-'.Str::uuid();

        // Override phpunit.xml's sync queue driver.
        config()->set('queue.default', 'database');

        // Queue jobs must wait until the outermost
        // database transaction commits.
        config()->set(
            'queue.connections.database.after_commit',
            true
        );

        // Resolve the database queue using the test configuration.
        $queue = Queue::connection('database');

        $this->assertTrue(
            config('queue.connections.database.after_commit')
        );
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            if (isset($this->queueName)) {
                DB::table('jobs')
                    ->where('queue', $this->queueName)
                    ->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function matchingJobs(): int
    {
        return DB::table('jobs')
            ->where('queue', $this->queueName)
            ->count();
    }

    private function dispatchMarkedJob(): void
    {
        // The generation ID is deliberately synthetic.
        // No worker should execute this test queue.
        $job = new SubmitPixelforgeGeneration(123456789);

        Queue::connection('database')->push(
            $job,
            '',
            $this->queueName
        );
    }

    public function test_dispatch_waits_until_commit(): void
    {
        // No enclosing RefreshDatabase transaction.
        $this->assertSame(
            0,
            DB::transactionLevel()
        );

        $this->assertSame(
            0,
            $this->matchingJobs()
        );

        DB::beginTransaction();

        try {
            $this->dispatchMarkedJob();

            // The job must not be inserted while
            // the transaction is still open.
            $this->assertSame(
                0,
                $this->matchingJobs()
            );

            DB::commit();

            // Once committed, exactly one job
            // should appear in the database queue.
            $this->assertSame(
                1,
                $this->matchingJobs()
            );
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public function test_rollback_discards_dispatch(): void
    {
        $this->assertSame(
            0,
            DB::transactionLevel()
        );

        $this->assertSame(
            0,
            $this->matchingJobs()
        );

        DB::beginTransaction();

        try {
            $this->dispatchMarkedJob();

            // No job should exist before commit.
            $this->assertSame(
                0,
                $this->matchingJobs()
            );

            // Roll back the application transaction.
            DB::rollBack();

            // Laravel should discard the pending
            // queue dispatch callback.
            $this->assertSame(
                0,
                $this->matchingJobs()
            );
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }
}
