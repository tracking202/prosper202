<?php

declare(strict_types=1);

/**
 * Android intake worker. Run every minute.
 *
 * Two jobs the request path leaves to a worker (plan §5.2, §5.3, §7.3):
 *
 *  1. Settle `pending_click` installs: the install token verified but the
 *     click it names had not been written yet. Each is re-classified from
 *     its stored body under the same locks and in the same transaction
 *     shape as the intake (Api\V3\Apps\Android\PendingClickSettler); one
 *     whose click never appears within 24 hours becomes bad_token.
 *  2. Send the traffic-source notifications that are due
 *     (Prosper202\Notifications\NotificationOutbox::sendDue()): the rows
 *     written with each install or goal conversion. The request path makes
 *     no external calls, so this is where postbacks leave. A failed send is
 *     retried with backoff (1 min doubling, at most 6 h) and marked failed
 *     after 8 attempts.
 *
 * Takes no arguments. Exit codes: 0 on success (including nothing to do and
 * a schema that predates the tables), 1 on a database failure. Over HTTP a
 * failure also answers 500, since a URL fetcher never sees the exit code.
 *
 * Deliberately no "#!/usr/bin/env php" line (see app-retention.php: a
 * shebang is output under every SAPI but CLI and breaks strict_types).
 *
 * Example crontab entry:
 *   * * * * * /usr/bin/php /path/to/prosper202/202-cronjobs/app-installs.php \
 *     >> /var/log/prosper202/app-installs.log 2>&1
 */

use Api\V3\Apps\Android\PendingClickSettler;
use Prosper202\Database\Connection;
use Prosper202\Notifications\NotificationOutbox;

error_reporting(E_ALL);

require_once __DIR__ . '/../202-config/connect.php';

set_time_limit(0);

$fail = static function (string $message): never {
    if (defined('STDERR')) {
        fwrite(STDERR, 'app-installs: ' . $message . "\n");
    } else {
        error_log('app-installs: ' . $message);
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo "app-installs: FAILED\n";
    }
    exit(1);
};

$argv = PHP_SAPI === 'cli' ? array_values((array) ($_SERVER['argv'] ?? [])) : [];
if (count($argv) > 1) {
    // Nothing to configure; an argument is a mistake, and a job that writes
    // conversions does not guess what was meant.
    $fail('unrecognised argument "' . (string) $argv[1] . '"; this job takes no arguments');
}

if (!isset($db) || !($db instanceof mysqli)) {
    $fail('database connection unavailable');
}

try {
    foreach (['202_app_installs', '202_notification_pending'] as $table) {
        $tables = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
        if ($tables === false) {
            throw new RuntimeException('could not check for ' . $table . ': ' . $db->error);
        }
        $exists = $tables->num_rows > 0;
        $tables->close();
        if (!$exists) {
            echo "app-installs: {$table} is not installed; nothing to do\n";
            exit(0);
        }
    }

    $settled = (new PendingClickSettler($db))->run(500);
    $byState = [];
    foreach ($settled['settled'] as $state => $n) {
        $byState[] = $state . '=' . $n;
    }
    printf(
        "pending clicks: %d examined, settled %s, %d still pending, %d failed\n",
        $settled['examined'],
        $byState === [] ? 'none' : implode(' ', $byState),
        $settled['still_pending'],
        $settled['failed']
    );

    $sent = (new NotificationOutbox(new Connection($db)))->sendDue(500);
    printf("notifications: %d sent, %d retrying, %d failed\n", $sent['sent'], $sent['retrying'], $sent['failed']);

    if ($settled['failed'] > 0) {
        $fail($settled['failed'] . ' install(s) could not be settled; see the error log');
    }
} catch (Throwable $e) {
    $fail($e->getMessage());
}

echo "app-installs completed\n";
