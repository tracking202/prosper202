<?php

declare(strict_types=1);

/**
 * The LTV maintenance jobs on their own (Prosper202\Ltv\LtvMaintenance): the
 * subscription lifecycle sweep, the customer rollup reconcile, the next-offer
 * transitions, company linking and the personalization token purge, and
 * exits.
 *
 * The cron's 202-cronjobs/index.php runs the same jobs (the sweep hourly, the
 * rest daily), so an install whose crontab has only that one line (and every
 * Docker and AutoCron install) needs nothing more; this entry point is for a
 * full reconcile, or a backlog an operator wants through at once. Both take
 * the maintenance's MySQL named lock: a copy started while another runs exits
 * at once and says so.
 *
 * Options:
 *   --full            reconcile every customer (default: those active in the
 *                     last 48 hours)
 *   --batch-size=N    subscriptions or customers per statement (default 500,
 *                     50-5000)
 *   --budget=N        seconds to keep working (default 300, 1-86400); a batch
 *                     begun before the end finishes past it
 *
 * Exit codes: 0 when the run finished (including "another run holds the
 * lock", and a run the budget ended with work left), 1 on a bad option, a
 * database failure or a job that failed. Over HTTP a failure also answers
 * 500, because a URL fetcher never sees the exit status.
 */

use Prosper202\Database\Connection;
use Prosper202\Ltv\LtvMaintenance;

error_reporting(E_ALL);

require_once __DIR__ . '/../202-config/connect.php';

set_time_limit(0);

$buffering = PHP_SAPI !== 'cli' && ob_start();

$fail = static function (string $message) use (&$buffering): never {
    if (defined('STDERR')) {
        fwrite(STDERR, 'ltv_maintenance: ' . $message . "\n");
    } else {
        error_log('ltv_maintenance: ' . $message);
    }
    if (PHP_SAPI !== 'cli') {
        if (!headers_sent()) {
            http_response_code(500);
        } else {
            echo "ltv_maintenance: FAILED\n";
        }
        if ($buffering) {
            ob_end_flush();
            $buffering = false;
        }
    }
    exit(1);
};

$full = false;
$batch = LtvMaintenance::BATCH;
$budget = 300;
$argv = PHP_SAPI === 'cli' ? array_values((array) ($_SERVER['argv'] ?? [])) : [];
for ($i = 1; $i < count($argv); $i++) {
    $arg = is_string($argv[$i]) ? $argv[$i] : '';
    if ($arg === '--full') {
        $full = true;
        continue;
    }
    if (preg_match('/^--batch-size=(\d{1,4})$/D', $arg, $m) === 1 && (int) $m[1] >= 50 && (int) $m[1] <= 5000) {
        $batch = (int) $m[1];
        continue;
    }
    if (preg_match('/^--budget=(\d{1,5})$/D', $arg, $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] <= 86400) {
        $budget = (int) $m[1];
        continue;
    }
    // A mistyped option must not run with defaults the operator did not ask for.
    $fail('unrecognised argument "' . $arg . '"; this job takes --full, --batch-size=N (50-5000) and --budget=N (1-86400)');
}

if (!isset($db) || !($db instanceof mysqli)) {
    $fail('database connection unavailable');
}

try {
    $report = LtvMaintenance::runExclusive(new Connection($db), time() + $budget, LtvMaintenance::JOBS, $full, $batch);
} catch (Throwable $e) {
    error_log('ltv_maintenance failed: ' . $e->getMessage());
    $fail(get_class($e) . ': ' . $e->getMessage());
}

if ($report === null) {
    echo "ltv_maintenance: another run holds the lock; nothing done\n";
} else {
    echo 'ltv_maintenance: ' . LtvMaintenance::summary($report) . "\n";
    if ($report['failed'] !== []) {
        $fail(implode('; ', array_map(
            static fn (string $job, string $message): string => $job . ' failed: ' . $message,
            array_keys($report['failed']),
            $report['failed']
        )));
    }
}

if ($buffering) {
    ob_end_flush();
}
