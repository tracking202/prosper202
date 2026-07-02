<?php

declare(strict_types=1);

/**
 * Public site-event beacon for manual ABM instrumentation.
 *
 * POST token=<personalization token>&event=<slug>. The token — a random
 * 256-bit bearer capability minted only for recognized customers — proves
 * "this browser belongs to customer X" without exposing any enumerable id;
 * sealing does not matter here because recording an event is a write, not a
 * data read. Any token inside its 30-day replay window may record events.
 *
 * Always responds `{}` (unknown token / bad event / rate limit / error are
 * indistinguishable — no oracle, and instrumentation must never break a
 * page). Event names are normalized to a strict slug; junk is dropped.
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');

include_once(substr(__DIR__, 0, -19) . '/202-config/connect2.php');

$emptyResponse = static function (): never {
    echo '{}';
    exit;
};

$token = isset($_POST['token']) && is_scalar($_POST['token']) ? trim((string) $_POST['token']) : '';
$eventName = isset($_POST['event']) && is_scalar($_POST['event']) ? trim((string) $_POST['event']) : '';
if ($token === '' || $eventName === '') {
    $emptyResponse();
}

// Per-IP fixed-window rate limit (fail-open; tokens are unguessable and
// events are low-sensitivity writes).
try {
    $stateStore = new \Api\V3\Support\ServerStateStore();
    $ip = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $ip = trim(explode(',', $ip)[0]);
    $rate = $stateStore->consumeRateLimit('p13n_event:' . $ip, 120, 60);
    if (!$rate['allowed']) {
        $emptyResponse();
    }
} catch (\Throwable $rateError) {
    error_log('p13n_event: rate limiter unavailable: ' . $rateError->getMessage());
}

try {
    $conn = new \Prosper202\Database\Connection($db);
    $tokens = new \Prosper202\Ltv\MysqlPersonalizationRepository($conn);

    $resolved = $tokens->customerForToken($token, time());
    if ($resolved !== null) {
        // Optional numeric value for depth metrics (seconds on page, scroll
        // or video percentage). Non-numeric input means no value, never junk.
        $eventValue = isset($_POST['value']) && is_numeric($_POST['value'])
            ? (float) $_POST['value']
            : null;

        $engagement = new \Prosper202\Ltv\MysqlEngagementRepository($conn);
        $engagement->recordEvent(
            $resolved['userId'],
            $resolved['customerId'],
            $eventName,
            'site',
            $resolved['clickId'],
            null,
            $eventValue
        );
    }
} catch (\Throwable $e) {
    // Includes invalid event names — dropped, uniform response, no PII logged.
    error_log('p13n_event: ' . $e->getMessage());
}

echo '{}';
