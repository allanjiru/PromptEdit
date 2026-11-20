<?php

declare(strict_types=1);

use App\Jobs\SubmitPixelforgeGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Models\WalletReservation;
use App\Services\GenerationService;
use App\Services\WalletService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$port = 18991;
$baseUrl = 'http://127.0.0.1:'.$port.'/v1';
$testToken = 'integration-test-only';

if (
    ! app()->environment('testing')
    || DB::getDriverName() !== 'pgsql'
    || DB::connection()->getDatabaseName() !== 'promptedit_test'
    || DB::connection()->transactionLevel() !== 0
    || config('queue.default') !== 'database'
    || config('cache.default') !== 'database'
    || config('services.pixelforge.base_url') !== $baseUrl
    || config('services.pixelforge.api_key') !== $testToken
) {
    throw new RuntimeException('Unsafe configuration. Use the exact testing command in the instructions.');
}

if (! DB::getSchemaBuilder()->hasTable('cache_locks')) {
    throw new RuntimeException('The test database requires a cache_locks table.');
}

$portCheck = @stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $error, 0.3);
if ($portCheck !== false) {
    fclose($portCheck);
    throw new RuntimeException('Port 18991 is already occupied. Stop that service before testing.');
}

$state = sys_get_temp_dir().'/promptedit-concurrency-'.Str::uuid();
if (! mkdir($state, 0700)) {
    throw new RuntimeException('Unable to create isolated test state directory.');
}

$queue = 'test-concurrency-'.Str::uuid();
$processes = [];
$generation = null;
$user = null;
$passed = false;

$environment = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'pgsql',
    'DB_DATABASE' => 'promptedit_test',
    'QUEUE_CONNECTION' => 'database',
    'CACHE_STORE' => 'database',
    'PIXELFORGE_BASE_URL' => $baseUrl,
    'PIXELFORGE_API_KEY' => $testToken,
    'PIXELFORGE_TEST_STATE_DIR' => $state,
    'PIXELFORGE_TEST_TOKEN' => $testToken,
];

$waitUntil = static function (callable $condition, float $seconds, string $error): void {
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        if ($condition()) {
            return;
        }
        usleep(50_000);
    }
    throw new RuntimeException($error);
};

$startWorker = static function () use ($root, $environment, $queue, &$processes): Process {
    $process = new Process([
        PHP_BINARY, $root.'/artisan', 'queue:work', 'database',
        '--queue='.$queue, '--once', '--tries=6', '--timeout=45',
        '--sleep=0', '--no-interaction',
    ], $root, $environment);
    $process->setTimeout(55);
    $process->start();
    $processes[] = $process;

    return $process;
};

try {
    $server = new Process([
        PHP_BINARY, '-S', '127.0.0.1:'.$port,
        $root.'/tests/Support/pixelforge-concurrency-router.php',
    ], $root, $environment);
    $server->setTimeout(30);
    $server->start();
    $processes[] = $server;

    $waitUntil(static function () use ($server, $port, $testToken, $state): bool {
        if (! $server->isRunning()) {
            throw new RuntimeException('Mock provider exited: '.$server->getErrorOutput());
        }
        $context = stream_context_create([
            'http' => [
                'timeout' => 0.3,
                'ignore_errors' => true,
                'header' => 'Authorization: Bearer '.$testToken."\r\n",
            ],
        ]);
        $result = @file_get_contents('http://127.0.0.1:'.$port.'/__health', false, $context);

        return $result === basename($state);
    }, 4, 'Mock provider health check failed.');

    echo "Mock Pixelforge server ready.\n";

    $user = User::factory()->create();
    app(WalletService::class)->addDemoCredits(
        $user, 10_000_000_000, 'test:concurrency-credit:'.$user->id
    );
    $generation = app(GenerationService::class)->create(
        $user, (string) Str::uuid(), 'A futuristic city at sunset',
        '1024x1024', 'high', [], 150_000_000
    );

    $reservation = $generation->reservation()->firstOrFail();
    if ($reservation->status !== WalletReservation::STATUS_ACTIVE) {
        throw new RuntimeException('Expected an active initial reservation.');
    }

    for ($i = 0; $i < 2; $i++) {
        Queue::connection('database')->push(
            new SubmitPixelforgeGeneration($generation->id), '', $queue
        );
    }

    if (DB::table('jobs')->where('queue', $queue)->count() !== 2) {
        throw new RuntimeException('Expected exactly two queued jobs.');
    }

    echo "Starting Worker A...\n";
    $workerA = $startWorker();
    $waitUntil(
        static fn (): bool => file_exists($state.'/request-started'),
        6, 'Worker A did not reach the local provider.'
    );
    if (! $workerA->isRunning()) {
        throw new RuntimeException('Worker A exited before contention could be tested.');
    }

    echo "Starting Worker B while Worker A is blocked...\n";
    $workerB = $startWorker();
    $waitUntil(
        static fn (): bool => ! $workerB->isRunning(),
        5, 'Worker B did not finish while Worker A held the lock.'
    );
    if ($workerB->getExitCode() !== 0) {
        throw new RuntimeException('Worker B exited abnormally: '.$workerB->getErrorOutput());
    }
    if (! $workerA->isRunning()) {
        throw new RuntimeException('Worker A ended before Worker B completed.');
    }

    $remaining = DB::table('jobs')
        ->where('queue', $queue)
        ->get();

    if ($remaining->count() !== 2) {
        throw new RuntimeException(
            'Expected two jobs during contention; found '
            .$remaining->count().'.'
        );
    }

    $activeJobs = $remaining->filter(
        fn ($job) => $job->reserved_at !== null
    );

    $releasedJobs = $remaining->filter(
        fn ($job) => $job->reserved_at === null
    );

    if (
        $activeJobs->count() !== 1
        || $releasedJobs->count() !== 1
    ) {
        throw new RuntimeException(
            'Expected one active job and one released job.'
        );
    }

    $released = $releasedJobs->first();

    if (
        (int) $released->attempts !== 1
        || (int) $released->available_at <= time()
    ) {
        throw new RuntimeException(
            'The overlapping job was not released with a delay.'
        );
    }

    $recorded = file($state.'/requests.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($recorded === false || count($recorded) !== 1) {
        throw new RuntimeException('Expected one provider request during lock contention.');
    }
    echo "Worker B released its job without another provider request.\n";

    file_put_contents($state.'/release-response', '1');
    $waitUntil(
        static fn (): bool => ! $workerA->isRunning(),
        6, 'Worker A did not finish after provider release.'
    );
    if ($workerA->getExitCode() !== 0) {
        throw new RuntimeException('Worker A exited abnormally: '.$workerA->getErrorOutput());
    }

    $generation->refresh();
    $reservation->refresh();
    $recorded = file($state.'/requests.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($recorded === false || count($recorded) !== 1) {
        throw new RuntimeException('Expected exactly one provider submission in total.');
    }
    $request = json_decode($recorded[0], true, 512, JSON_THROW_ON_ERROR);
    if (
        ($request['idempotency_key'] ?? null) !== $generation->request_key
        || ($request['body'] ?? null) !== $generation->provider_request_payload
    ) {
        throw new RuntimeException('Provider request key or payload differs from generation.');
    }
    if (
        $generation->submission_status !== Generation::SUBMISSION_ACCEPTED
        || (int) $generation->submission_attempts !== 1
        || $generation->provider_job_id !== 'gen_concurrency123'
        || $reservation->status !== WalletReservation::STATUS_ACTIVE
        || $generation->reservation()->count() !== 1
    ) {
        throw new RuntimeException('Generation or wallet reservation state is inconsistent.');
    }

    $passed = true;
    echo "\nPHASE 7.4.3 PASSED\n";
    echo "Provider requests: 1\nSubmission attempts: 1\n";
    echo "Generation submission: accepted\nWallet reservation: active\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "\nPHASE 7.4.3 FAILED: ".$exception->getMessage()."\n");
    exit(1);
} finally {
    foreach ($processes as $process) {
        if ($process->isRunning()) {
            $process->stop(2);
        }
    }

    DB::table('jobs')->where('queue', $queue)->delete();

    foreach (['requests.log', 'request-started', 'release-response'] as $file) {
        if (is_file($state.'/'.$file)) {
            unlink($state.'/'.$file);
        }
    }
    @rmdir($state);

    if ($generation !== null) {
        echo 'Retained test generation ID: '.$generation->id."\n";
    }
    if ($user !== null) {
        echo 'Retained test user ID: '.$user->id."\n";
    }
    echo "Only this test's queued jobs were cleaned up; financial test records were retained.\n";
}
