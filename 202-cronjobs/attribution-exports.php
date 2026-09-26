<?php

declare(strict_types=1);

/**
 * The attribution export runner (plan §6.3 "Exports"). Run every minute.
 *
 * Runs every export job whose time has come: builds the breakdown it names,
 * writes the CSV to the export directory, and sends it to the job's webhook
 * when it has one (Prosper202\Attribution\WebhookSender: https only, every
 * resolved address checked, the connection pinned to the checked address,
 * no redirects). Two runners never take the same job: claiming is a
 * conditional UPDATE.
 *
 * The minutely 202-cronjobs/index.php run calls the runner too, so the one
 * documented crontab line is enough; this entry point is for deployments
 * that schedule jobs separately, and for running exports by hand.
 *
 * Options:
 *   --budget=N   seconds to keep running jobs (default 50, 1-3600)
 *
 * Exit codes: 0 when the run finished (jobs that failed are recorded on
 * their rows, not here), 1 on a bad option or a database failure.
 */

use Prosper202\Attribution\ExportRunner;
use Prosper202\Database\Connection;

error_reporting(E_ALL);

require_once __DIR__ . '/../202-config/connect.php';

set_time_limit(0);

$fail = static function (string $message): never {
    if (defined('STDERR')) {
        fwrite(STDERR, 'attribution-exports: ' . $message . "\n");
    } else {
        error_log('attribution-exports: ' . $message);
    }
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(500);
    }
    exit(1);
};

$budget = 50;
$argv = PHP_SAPI === 'cli' ? array_values((array) ($_SERVER['argv'] ?? [])) : [];
for ($i = 1; $i < count($argv); $i++) {
    $arg = is_string($argv[$i]) ? $argv[$i] : '';
    if (preg_match('/^--budget=(\d{1,4})$/D', $arg, $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] <= 3600) {
        $budget = (int) $m[1];
        continue;
    }
    $fail('unrecognised argument "' . $arg . '"; this job takes --budget=N (1-3600)');
}

if (!isset($db) || !($db instanceof mysqli)) {
    $fail('database connection unavailable');
}

try {
    $report = (new ExportRunner(new Connection($db)))->run($budget);
} catch (Throwable $e) {
    $fail(get_class($e) . ': ' . $e->getMessage());
}

echo 'attribution-exports: ' . $report['completed'] . ' completed, ' . $report['failed'] . ' failed, '
    . $report['retrying'] . ' to retry, ' . $report['lost'] . ' taken by another run or deleted while running, '
    . $report['reclaimed'] . ' reclaimed from a stopped run' . "\n";
