<?php

declare(strict_types=1);

/**
 * Android intake worker. Run every minute.
 *
 * Three jobs the request path leaves to a worker (plan §5.2, §5.3, §5.6,
 * §7.3), in this order, so an install settled by the first two has its
 * postback sent by the third in the same run. The minutely
 * 202-cronjobs/index.php runs all three too — it is the only job a default
 * deployment schedules — so this file is for deployments that run workers
 * on their own (docker-compose.coolify.yaml). Steps 0 and 1 are
 * Api\V3\Apps\Android\AndroidIntakeJob, which holds a named lock so the
 * two entry points never run them at once:
 *
 *  0. Decode Play Integrity tokens (Api\V3\Apps\Android\Integrity\
 *     IntegrityVerifier): installs that arrived under `observe` or
 *     `require` with a token. The verdict is judged against the documented
 *     policy; an install waiting as `pending_integrity` is then attributed
 *     (and paid) or not. Google's answer is retried with backoff (1 min
 *     doubling, at most 1 h) for up to 24 hours, then recorded as `error`.
 *     Set P202_PLAY_INTEGRITY_ENDPOINT only to point a test at a loopback
 *     fake of Google (see GooglePlayIntegrityClient); in production it is
 *     unset and the client talks to Google's pinned endpoints.
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

use Api\V3\Apps\Android\AndroidIntakeJob;
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

    // The same work the minutely 202-cronjobs/index.php runs, under the
    // same lock: a deployment that schedules both never runs it twice at once.
    $intake = AndroidIntakeJob::runExclusive($db);
    if ($intake === null) {
        echo "play integrity / pending clicks: another run holds the lock; skipped\n";
    } else {
        echo AndroidIntakeJob::summary($intake), "\n";
    }

    $sent = (new NotificationOutbox(new Connection($db)))->sendDue(500);
    printf("notifications: %d sent, %d retrying, %d failed\n", $sent['sent'], $sent['retrying'], $sent['failed']);

    if ($intake !== null && $intake['integrity']['failed'] > 0) {
        $fail($intake['integrity']['failed'] . ' install(s) could not be verified; see the error log');
    }
    if ($intake !== null && $intake['settle']['failed'] > 0) {
        $fail($intake['settle']['failed'] . ' install(s) could not be settled; see the error log');
    }
} catch (Throwable $e) {
    $fail($e->getMessage());
}

echo "app-installs completed\n";
