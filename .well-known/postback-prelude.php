<?php

declare(strict_types=1);

/**
 * The HTTP prelude every /.well-known/ postback entry point runs before it
 * picks a protocol: the security headers, the unconfigured-install 503, the
 * autoloader, the request-shape checks that need no database, and — only for
 * the method that needs one — configuration.
 *
 * It lives beside the entry points rather than under api/v3/ for two
 * reasons: it has to be loadable before the autoloader exists (that is the
 * whole point of the 503 below, the only answer an unconfigured install can
 * give), and it is web-tier glue for this tree, not a class in the
 * Api\V3\Attribution namespace. With it, each entry point is this require
 * plus its PostbackEndpoint::serve() line, so a header added for one
 * endpoint is added for all of them and the next protocol's index.php is
 * three lines rather than a third fifty-line copy.
 *
 * Both shipped server configs (the nginx and Apache examples in README.md,
 * and the Docker web container) exempt /.well-known/ from the dotfile deny
 * so ACME can use it, and hand every *.php beneath it to PHP — so this file
 * is requestable on its own. Requested directly it is a fragment, not a
 * resource: it answers 404 and does nothing else. Without that guard a POST
 * straight to it would load configuration, and with it two MySQL
 * connections, outside the rate limiter that exists to bound exactly that.
 */

if (!defined('P202_POSTBACK_ENTRY')) {
    http_response_code(404);
    exit;
}

$p202PostbackRoot = dirname(__DIR__);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

// Pre-autoload responder: only the unconfigured-install case may answer
// before the framework is loadable. Everything after the requires responds
// through Bootstrap so the envelope shape has one owner.
if (!file_exists($p202PostbackRoot . '/202-config.php') || !file_exists($p202PostbackRoot . '/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":true,"message":"Service unavailable","status":503}';
    exit;
}

require_once $p202PostbackRoot . '/vendor/autoload.php';

// Request-shape checks that need no database — an unsupported method (405)
// and a body that declares itself over the cap (413) — run here, above the
// configuration require, so a flood of either costs no MySQL connection at
// all.
//
// The rate limiter deliberately does NOT move up with them, and must not be
// "tidied" up here later: ServerStateStore scopes its state directory by
// database identity, read from the $dbname/$dbhost globals that
// 202-config.php defines. Built before that require it logs "no database
// identity in scope when resolving the v3 API state directory" and falls
// back to the unscoped /tmp/p202-api-v3-state — a different store than the
// web tier's, so the limit would be counted in buckets nothing else reads.
// Confirmed by running it both ways, not inferred from the code.
$p202PostbackMethod = \Api\V3\Attribution\PostbackEndpoint::preflight();

// Configuration is what costs the two connections: 202-config.php builds
// DB::getInstance() at file scope, whose constructor opens a read-write and
// a read-only mysqli. Only the method that needs a database pays for it —
// the GET/HEAD probe is answered from class constants, and every other
// method was already refused above.
//
// It must be required at *file* scope: the DB constructor reads $dbhost,
// $dbuser, $dbpass and $dbname through `global`, so a require inside a
// function would leave them local. That is the same reason api/v3/index.php
// loads it at file scope rather than inside Bootstrap::init().
if ($p202PostbackMethod === 'POST') {
    require_once $p202PostbackRoot . '/202-config.php';
}
