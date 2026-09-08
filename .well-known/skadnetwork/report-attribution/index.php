<?php

declare(strict_types=1);

/**
 * SKAdNetwork install-validation postback endpoint.
 *
 * Devices POST signed SKAdNetwork postbacks here when an advertised iOS app
 * names this Prosper202 install in its Info.plist:
 *
 *   NSAdvertisingAttributionReportEndpoint = https://your-domain.com
 *
 * (Apple appends /.well-known/skadnetwork/report-attribution/ itself.) The
 * same URL works as an ad network's registered postback endpoint. This is a
 * physical directory index on purpose: it needs no rewrite rules, and the
 * shipped Apache/nginx configs already keep /.well-known/ servable while
 * denying other dotfiles.
 *
 * The HTTP plumbing shared by every postback endpoint (probe, method check,
 * rate limit, body limit, error envelopes) lives in
 * Api\V3\Attribution\PostbackEndpoint; parsing, validation and signature
 * verification in Api\V3\Attribution\SkadnetworkProtocol; storage in
 * Api\V3\Attribution\PostbackReceiver. This file only picks the protocol.
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

\Api\V3\Attribution\PostbackEndpoint::serve(
    new \Api\V3\Attribution\SkadnetworkProtocol(new \Api\V3\Attribution\PostbackVerifier())
);
