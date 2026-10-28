<?php

declare(strict_types=1);

/**
 * The LTV webhook dispatcher on its own (Prosper202\Ltv\WebhookDispatcher):
 * sends the deliveries the /ltv API writes queue, and exits.
 *
 * The minutely 202-cronjobs/index.php runs the same dispatch with a shorter
 * budget, so an install whose crontab has only that one line (and every
 * Docker and AutoCron install) sends its webhooks; this entry point is for
 * deployments that schedule workers separately (docker-compose.coolify.yaml).
 * Both take the dispatcher's MySQL named lock: a copy started while another
 * runs exits at once and says so.
 *
 * Options:
 *   --budget=N    seconds to keep sending (default 50, 1-3600); a delivery
 *                 begun before the end may take its timeout past it
 *
 * Exit codes: 0 when the run finished (including "another run holds the
 * lock"), 1 on a bad option or a database failure. Over HTTP a failure also
 * answers 500, because a URL fetcher never sees the exit status.
 *
 * Example crontab entry:
 *   * * * * * /usr/bin/php /path/to/prosper202/202-cronjobs/ltv_webhooks.php >> /var/log/p202-webhooks.log 2>&1
 */

use Prosper202\Database\Connection;
use Prosper202\Ltv\WebhookDispatcher;

error_reporting(E_ALL);

require_once __DIR__ . '/../202-config/connect.php';

set_time_limit(0);

$buffering = PHP_SAPI !== 'cli' && ob_start();

$fail = static function (string $message) use (&$buffering): never {
    if (defined('STDERR')) {
        fwrite(STDERR, 'ltv_webhooks: ' . $message . "\n");
    } else {
        error_log('ltv_webhooks: ' . $message);
    }
    if (PHP_SAPI !== 'cli') {
        if (!headers_sent()) {
            http_response_code(500);
        } else {
            echo "ltv_webhooks: FAILED\n";
        }
        if ($buffering) {
            ob_end_flush();
            $buffering = false;
        }
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
    // A mistyped option must not run with defaults the operator did not ask for.
    $fail('unrecognised argument "' . $arg . '"; this job takes --budget=N (1-3600)');
}

if (!isset($db) || !($db instanceof mysqli)) {
    $fail('database connection unavailable');
}

try {
    $report = WebhookDispatcher::runExclusive(new Connection($db), time() + $budget);
} catch (Throwable $e) {
    error_log('ltv_webhooks failed: ' . $e->getMessage());
    $fail(get_class($e) . ': ' . $e->getMessage());
}

echo 'ltv_webhooks: ' . ($report === null
    ? 'another run holds the lock; nothing done'
    : WebhookDispatcher::summary($report)) . "\n";

if ($buffering) {
    ob_end_flush();
}
