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
 * Api\V3\Attribution\PostbackReceiver; this file is only HTTP plumbing.
 */

$root = dirname(__DIR__, 3);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

// Pre-autoload responder: only the unconfigured-install case may answer
// before the framework is loadable. Everything after the requires responds
// through Bootstrap so the envelope shape has one owner.
if (!file_exists($root . '/202-config.php') || !file_exists($root . '/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":true,"message":"Service unavailable","status":503}';
    exit;
}

require_once $root . '/vendor/autoload.php';

// Load config at file scope so the DB globals ($dbhost, $dbuser, ...) are
// visible to the DB class constructor via `global` — the same reason
// api/v3/index.php loads it here rather than inside Bootstrap::init().
require_once $root . '/202-config.php';

use Api\V3\Bootstrap;
use Api\V3\Attribution\PostbackReceiver;
use Api\V3\Attribution\PostbackVerifier;
use Api\V3\Support\ServerStateStore;

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET' || $method === 'HEAD') {
    // Setup probe: lets an operator confirm the endpoint is reachable at the
    // exact URL Apple will use, before pointing an app at it.
    Bootstrap::jsonResponse([
        'data' => [
            'endpoint' => 'skadnetwork-report-attribution',
            'status' => 'ready',
            'accepts' => 'POST application/json (SKAdNetwork install-validation postbacks)',
        ],
    ]);
    exit;
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    Bootstrap::errorResponse('Method not allowed', 405);
    exit;
}

try {
    Bootstrap::init();
    $db = Bootstrap::db();
} catch (\Throwable) {
    // Non-200: the device retries later, so a DB outage loses nothing.
    Bootstrap::errorResponse('Service unavailable', 503);
    exit;
}

// Soft rate limit keyed on the validated TCP peer (never client headers —
// see ServerStateStore::softIpRateLimit). Behind a TLS-terminating proxy
// the peer is the proxy, so the ceiling is an aggregate one; devices each
// send a handful of postbacks over an install's lifetime.
try {
    $retryAfter = (new ServerStateStore())->softIpRateLimit('attribution', 600, 60);
} catch (\Throwable $e) {
    error_log('p202 attribution: rate limiter unavailable, accepting postback: ' . $e->getMessage());
    $retryAfter = null;
}
if ($retryAfter !== null) {
    header('Retry-After: ' . $retryAfter);
    Bootstrap::errorResponse('Rate limit exceeded', 429, ['retry_after_seconds' => $retryAfter]);
    exit;
}

$rawBody = file_get_contents('php://input', false, null, 0, PostbackReceiver::MAX_BODY_BYTES + 1);
if ($rawBody === false) {
    Bootstrap::errorResponse('Failed to read request body', 500);
    exit;
}

try {
    // The stored remote_ip is display/forensic data, so the validated
    // XFF-aware helper is right for it; the rate-limit key above is not
    // derived from it.
    $receiver = new PostbackReceiver($db, new PostbackVerifier());
    $result = $receiver->receive($rawBody, \AUTH::client_ip());
} catch (\Throwable $e) {
    error_log('p202 attribution: postback processing failed: ' . $e->getMessage());
    Bootstrap::errorResponse('Internal server error', 500);
    exit;
}

Bootstrap::jsonResponse($result['body'], $result['status']);
