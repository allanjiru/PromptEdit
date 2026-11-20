<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\DemoCreditService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (
    ! app()->environment('testing') ||
    DB::getDriverName() !== 'pgsql' ||
    DB::connection()->getDatabaseName() !== 'promptedit_test'
) {
    throw new RuntimeException('Demo-credit concurrency worker requires promptedit_test.');
}

[$script, $userId, $packageId, $requestKey] = $argv;

config([
    'demo_credits.enabled' => true,
    'demo_credits.max_total_units' => 100_000_000_000,
]);

$transaction = app(DemoCreditService::class)->purchase(
    User::findOrFail((int) $userId),
    $packageId,
    $requestKey
);

echo (string) $transaction->id;
