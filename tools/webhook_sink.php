<?php

/**
 * Minimal webhook sink for local development and integration tests.
 *
 * Run:
 *   php -S 127.0.0.1:8090 tools/webhook_sink.php
 *
 * Behaviours:
 *   POST /hook          accept and log a delivery, return 200
 *   POST /hook?fail=1   return 500 (exercise retry/backoff)
 *   POST /hook?fail=400 return 400 (exercise non-retryable path)
 *   GET  /health        liveness probe
 *   GET  /deliveries    JSON array of received deliveries
 *
 * Every accepted request is appended to writable/webhook-sink.log as JSON and
 * kept in memory for /deliveries. Set WEBHOOK_SINK_SECRET to verify the
 * X-Signature HMAC produced by the notifier.
 */

declare(strict_types=1);

$logFile = dirname(__DIR__) . '/writable/webhook-sink.log';
$secret  = getenv('WEBHOOK_SINK_SECRET') ?: getenv('CONFIGMON_WEBHOOK_SECRET') ?: '';
$path    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('Content-Type: application/json');

if ($method === 'GET' && $path === '/health') {
    echo json_encode(['status' => 'ok', 'time' => gmdate('c')]);
    return;
}

if ($method === 'GET' && $path === '/deliveries') {
    $rows = [];
    if (is_file($logFile)) {
        foreach (file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
    }
    echo json_encode(['count' => count($rows), 'deliveries' => array_slice($rows, -50)]);
    return;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    return;
}

$body    = file_get_contents('php://input') ?: '';
$headers = function_exists('getallheaders') ? getallheaders() : [];
$lower   = [];
foreach ($headers as $name => $value) {
    $lower[strtolower((string) $name)] = (string) $value;
}

$signature = $lower['x-signature'] ?? '';
$valid     = null;
if ($secret !== '' && $signature !== '') {
    $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
    $valid    = hash_equals($expected, $signature);
}

$status = 200;
if (isset($_GET['fail'])) {
    $status = max(400, min(599, (int) $_GET['fail']));
}

$entry = [
    'received_at'  => gmdate('c'),
    'method'       => $method,
    'path'         => $path . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''),
    'event_id'     => $lower['x-event-id'] ?? null,
    'event_type'   => $lower['x-event-type'] ?? null,
    'signature'    => $signature,
    'signature_ok' => $valid,
    'status'       => $status,
    'body'         => json_decode($body, true) ?? $body,
];

if (is_dir(dirname($logFile))) {
    file_put_contents(
        $logFile,
        json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX,
    );
}

http_response_code($status);
echo json_encode([
    'received'     => $status < 400,
    'signature_ok' => $valid,
    'status'       => $status,
]);
