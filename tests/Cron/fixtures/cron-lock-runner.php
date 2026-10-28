<?php

/**
 * A run of the cron, as far as its lock goes, for CronLockTest:
 * `php cron-lock-runner.php <lock path> <hold|spawn> [<go file>]`.
 *
 * Waits for the go file when one is named (so several runners take the lock
 * in the same instant), takes the lock and prints `ran`, `busy` or
 * `failed: <message>`. Holding it, `hold` keeps it until its stdin closes;
 * `spawn` starts a process that outlives it and exits at once.
 */

declare(strict_types=1);

// A checkout served as the web root serves tests/ too (TestScriptsAreNotServedTest).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Prosper202\Cron\CronLock;
use Prosper202\Cron\CronLockFailed;

$path = (string) ($argv[1] ?? '');
$then = (string) ($argv[2] ?? 'hold');
$go = $argv[3] ?? null;

if ($go !== null) {
    $deadline = microtime(true) + 10;
    while (!file_exists($go)) {
        if (microtime(true) > $deadline) {
            echo "no go\n";
            exit(4);
        }
        usleep(500);
    }
}

try {
    $lock = CronLock::take($path);
} catch (CronLockFailed $e) {
    echo 'failed: ', $e->getMessage(), "\n";
    exit(3);
}
if ($lock === null) {
    echo "busy\n";
    exit(0);
}
echo "ran\n";

if ($then === 'spawn') {
    $child = proc_open(['sleep', '3'], [], $pipes);
    if ($child === false) {
        echo "could not spawn\n";
        exit(5);
    }
    echo "spawned\n";
    exit(0);
}
stream_get_contents(STDIN);
