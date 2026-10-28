<?php

declare(strict_types=1);

/**
 * The multi-touch attribution worker (plan §6.3). Run every minute.
 *
 * Drains 202_attribution_pending — the outbox every conversion path writes
 * in its own transaction — into journeys and per-model credits, re-queues
 * the conversions identity merges and model changes affect, and exits.
 * Overlap protection is a MySQL named lock: a second copy started while one
 * runs exits at once and reports that it did.
 *
 * The minutely 202-cronjobs/index.php run also calls the worker (with a
 * shorter budget), so an install whose crontab has only that one line still
 * attributes; this entry point is for deployments that schedule workers
 * separately (docker-compose.coolify.yaml).
 *
 * Options:
 *   --budget=N    seconds to keep processing (default 50, 1–3600)
 *   --retry-now   make every row that failed eligible again immediately
 *                 (after fixing whatever the rows' last_error names)
 *
 * Exit codes: 0 when the run finished (including "another worker is
 * running"), 1 on a bad option or a database failure. Over HTTP a failure
 * also answers 500, because a URL fetcher never sees the exit status.
 *
 * Example crontab entry:
 *   * * * * * /usr/bin/php /path/to/prosper202/202-cronjobs/attribution-worker.php >> /var/log/prosper202/attribution.log 2>&1
 */

use Prosper202\Attribution\AttributionWorker;
use Prosper202\Database\Connection;

error_reporting(E_ALL);

require_once __DIR__ . '/../202-config/connect.php';

set_time_limit(0);

$buffering = PHP_SAPI !== 'cli' && ob_start();

$fail = static function (string $message) use (&$buffering): never {
    if (defined('STDERR')) {
        fwrite(STDERR, 'attribution-worker: ' . $message . "\n");
    } else {
        error_log('attribution-worker: ' . $message);
    }
    if (PHP_SAPI !== 'cli') {
        if (!headers_sent()) {
            http_response_code(500);
        } else {
            echo "attribution-worker: FAILED\n";
        }
        if ($buffering) {
            ob_end_flush();
            $buffering = false;
        }
    }
    exit(1);
};

$budget = 50;
$retryNow = false;
$argv = PHP_SAPI === 'cli' ? array_values((array) ($_SERVER['argv'] ?? [])) : [];
for ($i = 1; $i < count($argv); $i++) {
    $arg = is_string($argv[$i]) ? $argv[$i] : '';
    if ($arg === '--retry-now') {
        $retryNow = true;
        continue;
    }
    if (preg_match('/^--budget=(\d{1,4})$/D', $arg, $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] <= 3600) {
        $budget = (int) $m[1];
        continue;
    }
    // A mistyped option must not run with defaults the operator did not ask for.
    $fail('unrecognised argument "' . $arg . '"; this job takes --budget=N (1-3600) and --retry-now');
}

if (!isset($db) || !($db instanceof mysqli)) {
    $fail('database connection unavailable');
}

try {
    $conn = new Connection($db);
    if ($retryNow) {
        $stmt = $conn->prepareWrite('UPDATE 202_attribution_pending SET retry_at = 0 WHERE retry_at > 0');
        $reset = $conn->executeUpdate($stmt);
        echo 'attribution-worker: ' . $reset . " failed row(s) made due again\n";
    }
    $report = AttributionWorker::runExclusive($conn, $budget);
} catch (Throwable $e) {
    $fail(get_class($e) . ': ' . $e->getMessage());
}

echo 'attribution-worker: ' . ($report === null
    ? 'another worker holds the lock; nothing done'
    : $report->summary()) . "\n";

if ($buffering) {
    ob_end_flush();
}
