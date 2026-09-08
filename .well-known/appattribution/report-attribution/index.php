<?php

declare(strict_types=1);

/**
 * AdAttributionKit postback-copy endpoint.
 *
 * Devices POST developer copies of winning AdAttributionKit postbacks here
 * when an advertised iOS app names this Prosper202 install in its
 * Info.plist:
 *
 *   AttributionCopyEndpoint = https://your-domain.com
 *
 * (Apple appends /.well-known/appattribution/report-attribution/ itself;
 * re-engagement copies also need the Boolean key
 * EligibleForAdAttributionKitReengagementPostbackCopies.) Like its
 * SKAdNetwork sibling this is a physical directory index on purpose: no
 * rewrite rules, and the shipped Apache/nginx configs keep /.well-known/
 * servable while denying other dotfiles.
 *
 * The HTTP plumbing shared by every postback endpoint lives in
 * Api\V3\Attribution\PostbackEndpoint; JWS decoding, validation and
 * signature verification in Api\V3\Attribution\AdAttributionKitProtocol;
 * storage in Api\V3\Attribution\PostbackReceiver. This file only picks the
 * protocol.
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
    new \Api\V3\Attribution\AdAttributionKitProtocol(new \Api\V3\Attribution\JwsVerifier())
);
