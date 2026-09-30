<?php

declare(strict_types=1);

/**
 * Router for the eval instance's `php -S` (started by install-instance.sh).
 *
 * Before PHP 8.4 the built-in server treats any request whose path contains
 * a "." — /.well-known/... included — as a static-file request and never
 * resolves the directory's index.php: under PHP 8.3 the SKAN receiver at
 * /.well-known/skadnetwork/report-attribution/ answered 404 (logged as
 * "- Success", the server having nothing to open) while the same instance
 * worked under 8.4. Apache and nginx resolve the index themselves, so this
 * is a harness-only concern — production needs no router.
 *
 * For a dotted directory path that has an index.php, run that script with the
 * server variables a direct request carries. Every other request returns
 * false and is served exactly as php -S would on its own.
 */

$p202RouterPath = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
$p202RouterRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? getcwd()));
$p202RouterTarget = ($p202RouterRoot !== false && !str_contains($p202RouterPath, "\0"))
    ? realpath($p202RouterRoot . '/' . ltrim($p202RouterPath, '/'))
    : false;

if (
    $p202RouterRoot !== false
    && $p202RouterTarget !== false
    && str_contains($p202RouterPath, '.')
    && str_starts_with($p202RouterTarget . '/', $p202RouterRoot . '/')
    && is_dir($p202RouterTarget)
    && is_file($p202RouterTarget . '/index.php')
) {
    $p202RouterScript = rtrim($p202RouterPath, '/') . '/index.php';
    $_SERVER['SCRIPT_NAME'] = $p202RouterScript;
    $_SERVER['PHP_SELF'] = $p202RouterScript;
    $_SERVER['SCRIPT_FILENAME'] = $p202RouterTarget . '/index.php';
    // php -S logs nothing for a request its router handles, and the server
    // log is what a red CI run is diagnosed from — so log this one in the
    // server's own format once the script has finished and the status is
    // known (error_log() reaches the same stderr under the cli-server SAPI).
    register_shutdown_function(static function (): void {
        error_log(sprintf(
            '%s:%s [%d]: %s %s (router)',
            (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
            (string) ($_SERVER['REMOTE_PORT'] ?? '-'),
            (int) (http_response_code() ?: 200),
            (string) ($_SERVER['REQUEST_METHOD'] ?? '-'),
            (string) ($_SERVER['REQUEST_URI'] ?? '-')
        ));
    });
    // The include runs at file (global) scope on purpose — 202-config.php's
    // database globals must land in the global scope — so drop the router's
    // own variables first; anything left here would leak into the script.
    unset($p202RouterPath, $p202RouterRoot, $p202RouterTarget, $p202RouterScript);
    require $_SERVER['SCRIPT_FILENAME'];
    return;
}

return false;
