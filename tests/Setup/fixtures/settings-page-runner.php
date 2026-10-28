<?php

/**
 * page-runner.php, for a page that answers a POST with a redirect: after
 * whatever the page printed, one line
 * `P202-RUNNER-END {"status": 303, "flashes": [...]}` with the response code
 * and the flashes the session keeps for the page the redirect opens. The CLI
 * records no Location header, so the redirect's target is the live pass's.
 *
 * argv and env: page-runner.php's.
 */

declare(strict_types=1);

// A checkout served as the web root serves tests/ too, and this signs in
// whichever user its environment names.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

register_shutdown_function(static function (): void {
    echo "\nP202-RUNNER-END ", json_encode([
        'status' => http_response_code(),
        'flashes' => $_SESSION['p202_account_flash'] ?? [],
    ]), "\n";
});

require __DIR__ . '/page-runner.php';
