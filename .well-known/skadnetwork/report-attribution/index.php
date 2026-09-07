<?php

declare(strict_types=1);

/**
 * SKAdNetwork install-validation postback endpoint.
 *
 * Devices POST signed SKAN postbacks here when an advertised iOS app names
 * this Prosper202 install in its Info.plist:
 *
 *   NSAdvertisingAttributionReportEndpoint = https://your-domain.com
 *
 * (Apple appends /.well-known/skadnetwork/report-attribution/ itself.) The
 * same URL works as an ad network's registered postback endpoint. This is a
 * physical directory index on purpose: it needs no rewrite rules, and the
 * shipped Apache/nginx configs already keep /.well-known/ servable while
 * denying other dotfiles.
 *
 * All parsing, validation, signature verification, and storage live in
 * Api\V3\Skan\PostbackReceiver; this file is only HTTP plumbing.
 */

$root = dirname(__DIR__, 3);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

$respond = static function (int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        http_response_code(500);
        $json = '{"error":true,"message":"Response encoding failed","status":500}';
    }
    echo $json;
    exit;
};

if (!file_exists($root . '/202-config.php') || !file_exists($root . '/vendor/autoload.php')) {
    $respond(503, ['error' => true, 'message' => 'Service unavailable', 'status' => 503]);
}

require_once $root . '/vendor/autoload.php';

// Load config at file scope so the DB globals ($dbhost, $dbuser, ...) are
// visible to the DB class constructor via `global` — the same reason
// api/v3/index.php loads it here rather than inside Bootstrap::init().
require_once $root . '/202-config.php';

use Api\V3\Bootstrap;
use Api\V3\Skan\PostbackReceiver;
use Api\V3\Skan\PostbackVerifier;
use Api\V3\Support\ServerStateStore;

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET' || $method === 'HEAD') {
    // Setup probe: lets an operator confirm the endpoint is reachable at the
    // exact URL Apple will use, before pointing an app at it.
    $respond(200, [
        'data' => [
            'endpoint' => 'skadnetwork-report-attribution',
            'status' => 'ready',
            'accepts' => 'POST application/json (SKAdNetwork install-validation postbacks)',
        ],
    ]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    $respond(405, ['error' => true, 'message' => 'Method not allowed', 'status' => 405]);
}

try {
    Bootstrap::init();
    $db = Bootstrap::db();
} catch (\Throwable) {
    // Non-200: the device retries later, so a DB outage loses nothing.
    $respond(503, ['error' => true, 'message' => 'Service unavailable', 'status' => 503]);
}

$forwardedFor = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
$remoteIp = $forwardedFor !== ''
    ? trim(explode(',', $forwardedFor)[0])
    : (string)($_SERVER['REMOTE_ADDR'] ?? '');

// Soft per-source rate limit. Postbacks come from individual devices, each
// sending a handful over an install's lifetime, so a generous per-IP window
// only throttles floods from a single source. Never let the limiter's own
// failure block ingestion.
$stateStore = null;
try {
    $stateStore = new ServerStateStore();
    $rate = $stateStore->consumeRateLimit('skan:ip:' . $remoteIp, 240, 60);
    if (!$rate['allowed']) {
        $retryAfter = max(1, (int)$rate['reset_at'] - time());
        header('Retry-After: ' . $retryAfter);
        $respond(429, [
            'error' => true,
            'message' => 'Rate limit exceeded',
            'status' => 429,
            'retry_after_seconds' => $retryAfter,
        ]);
    }
} catch (\Throwable $e) {
    error_log('p202 skan: rate limiter unavailable, accepting postback: ' . $e->getMessage());
}

$rawBody = file_get_contents('php://input', false, null, 0, PostbackReceiver::MAX_BODY_BYTES + 1);
if ($rawBody === false) {
    $respond(500, ['error' => true, 'message' => 'Failed to read request body', 'status' => 500]);
}

try {
    $receiver = new PostbackReceiver($db, new PostbackVerifier());
    $result = $receiver->receive($rawBody, $remoteIp);
} catch (\Throwable $e) {
    error_log('p202 skan: postback processing failed: ' . $e->getMessage());
    $respond(500, ['error' => true, 'message' => 'Internal server error', 'status' => 500]);
}

if ($stateStore !== null) {
    try {
        if ($result['status'] === 200) {
            $duplicate = (bool)(($result['body']['data']['duplicate'] ?? false));
            $stateStore->incrementMetric($duplicate ? 'skan_postbacks_duplicate' : 'skan_postbacks_received', 1);
        } else {
            $stateStore->incrementMetric('skan_postbacks_rejected', 1);
        }
    } catch (\Throwable) {
        // Metrics are best-effort; the postback outcome stands.
    }
}

$respond($result['status'], $result['body']);
