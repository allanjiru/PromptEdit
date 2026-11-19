<?php

declare(strict_types=1);

$state = getenv('PIXELFORGE_TEST_STATE_DIR');
$token = getenv('PIXELFORGE_TEST_TOKEN');

if (! is_string($state) || ! is_dir($state) || ! is_string($token) || $token === '') {
    http_response_code(503);
    exit;
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$receivedToken = preg_replace('/^Bearer\s+/i', '', $authorization);

if (! is_string($receivedToken) || ! hash_equals($token, $receivedToken)) {
    http_response_code(403);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/__health' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    header('Content-Type: text/plain');
    echo basename($state);
    exit;
}

if ($path !== '/v1/generations' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(404);
    exit;
}

$body = file_get_contents('php://input');
$payload = json_decode($body === false ? '' : $body, true);

if (! is_array($payload)) {
    http_response_code(400);
    exit;
}

file_put_contents(
    $state.'/requests.log',
    json_encode([
        'time' => microtime(true),
        'idempotency_key' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
        'body' => $payload,
    ], JSON_THROW_ON_ERROR)."\n",
    FILE_APPEND | LOCK_EX
);

file_put_contents($state.'/request-started', '1');

$deadline = microtime(true) + 12;
while (! file_exists($state.'/release-response') && microtime(true) < $deadline) {
    usleep(50_000);
}

if (! file_exists($state.'/release-response')) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Mock provider gate timed out']);
    exit;
}

http_response_code(202);
header('Content-Type: application/json');
echo json_encode(['id' => 'gen_concurrency123', 'status' => 'queued'], JSON_THROW_ON_ERROR);
