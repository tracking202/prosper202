<?php

declare(strict_types=1);

/**
 * Landing-page personalization redemption endpoint (public, like the other
 * static pixel/postback endpoints).
 *
 * Contract: POST (preferred) or GET with `token`; responds with the
 * personalization payload as JSON, or `{}` — and `{}` looks identical for
 * unknown token / expired token / disabled feature / rate limit / internal
 * error, so the endpoint is not an oracle. Tokens are random 256-bit bearer
 * capabilities; on first use the payload is sealed and replays return the
 * sealed snapshot only (see MysqlPersonalizationRepository).
 *
 * No PII is ever logged here.
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
header('Pragma: no-cache');
// Landing pages live on their own domains and call this endpoint cross-origin
// via XHR. A wildcard is safe here: the posted token IS the capability — no
// cookies or credentialed state are used to authorize the response.
header('Access-Control-Allow-Origin: *');

include_once(substr(__DIR__, 0, -19) . '/202-config/connect2.php');

$emptyResponse = static function (): never {
    echo '{}';
    exit;
};

$token = '';
foreach ([$_POST, $_GET] as $source) {
    if (isset($source['token']) && is_scalar($source['token']) && trim((string) $source['token']) !== '') {
        $token = trim((string) $source['token']);
        break;
    }
}
if ($token === '') {
    $emptyResponse();
}

// Per-IP fixed-window rate limit. Fail-open: a broken limiter must not take
// personalization down, and the tokens themselves are unguessable.
try {
    $stateStore = new \Api\V3\Support\ServerStateStore();
    $ip = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $ip = trim(explode(',', $ip)[0]);
    $rate = $stateStore->consumeRateLimit('p13n:' . $ip, 60, 60);
    if (!$rate['allowed']) {
        $emptyResponse();
    }
} catch (\Throwable $rateError) {
    error_log('p13n: rate limiter unavailable: ' . $rateError->getMessage());
}

try {
    $conn = new \Prosper202\Database\Connection($db);
    $repo = new \Prosper202\Ltv\MysqlPersonalizationRepository($conn);
    $payload = $repo->redeem($token, time());
} catch (\Throwable $e) {
    error_log('p13n: redeem failed: ' . $e->getMessage());
    $emptyResponse();
}

$encoded = json_encode((object) $payload, JSON_UNESCAPED_UNICODE);
echo $encoded !== false ? $encoded : '{}';
