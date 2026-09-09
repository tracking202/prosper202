<?php

declare(strict_types=1);

/**
 * Attribution postback retention cronjob. Run hourly (or daily).
 *
 * /.well-known/skadnetwork/report-attribution/ and its AdAttributionKit
 * sibling are open, unauthenticated endpoints — devices cannot present
 * credentials, so anyone can post. Rows nobody will ever act on therefore
 * have windows (PostbackReceiver's retention classes): unclaimed rows no app
 * registration adopted, rows whose signature verified as forged, and rows
 * nobody could vouch for. Verified rows belonging to a user are never
 * touched.
 *
 * The receiver also prunes opportunistically, but that is a safety net, not
 * a policy: it fires on a 1-in-100 lottery, only while new postbacks are
 * still arriving, one bounded batch at a time, and in the web SAPI's
 * environment. This job is the documented pruner — it runs whether or not
 * traffic is arriving, drains the backlog instead of nibbling at it, reports
 * what it removed, and reads its overrides from the crontab's environment.
 *
 * Options:
 *   --dry-run         report the resolved windows and the backlog, delete
 *                     nothing
 *   --max-passes=N    ceiling on delete passes (default 200). Each pass
 *                     removes up to PRUNE_BATCH_LIMIT rows per class, so the
 *                     default clears 100k rows per class per run; a bigger
 *                     backlog is reported and cleared by the next run.
 *
 * Environment (unset means the shipped default; 0 disables that class; a
 * value that is not a whole number of days prunes nothing for that class and
 * is named on stderr rather than falling back to the default):
 *   P202_ATTRIBUTION_RETENTION_DAYS_UNCLAIMED     (default 30)
 *   P202_ATTRIBUTION_RETENTION_DAYS_INVALID       (default 90)
 *   P202_ATTRIBUTION_RETENTION_DAYS_UNVERIFIABLE  (default 90)
 * These are read by the process that prunes, so they belong in the crontab
 * line (or the cron user's environment) — not in php-fpm's, which only
 * configures the opportunistic pass.
 *
 * Exit codes: 0 on success (including "nothing to prune" and a schema that
 * predates the table), 1 on a bad option or a database failure. Fetched over
 * HTTP instead — on hosts whose only scheduler is a URL fetcher, and in the
 * containerised deploy — the exit code is invisible to the caller, so a
 * failure answers 500 as well (see $fail below).
 *
 * Deliberately no "#!/usr/bin/env php" line. A shebang is inline output ahead
 * of the opening tag under every SAPI but CLI, which makes the
 * declare(strict_types=1) above illegal ("strict_types declaration must be
 * the very first statement in the script") and fatals the whole file to an
 * empty HTTP 500. This file is not executable and the crontab below names the
 * interpreter, so the shebang could never have been the thing that ran it.
 *
 * Example crontab entry:
 *   17 * * * * /usr/bin/php /path/to/prosper202/202-cronjobs/attribution-retention.php \
 *     >> /var/log/prosper202/attribution-retention.log 2>&1
 */

use Api\V3\Attribution\PostbackReceiver;

error_reporting(E_ALL);

require_once __DIR__ . '/../202-config/connect.php';

set_time_limit(0);

/**
 * Cron output that is not part of the report. STDERR only exists under the
 * CLI SAPI; 202-cronjobs is also reachable over HTTP on hosts whose only
 * scheduler is a URL fetcher, and there the log is the error log. (Checked:
 * under the built-in server's cli-server SAPI defined('STDERR') is false and
 * error_log() lands in the server's log.)
 */
$warn = static function (string $message): void {
    // One stream, not two: under the CLI SAPI error_log() already writes to
    // stderr unless php.ini redirects it, so doing both prints every warning
    // twice in the common case.
    if (defined('STDERR')) {
        fwrite(STDERR, 'attribution-retention: ' . $message . "\n");
        return;
    }
    error_log('attribution-retention: ' . $message);
};

/**
 * Warn, then abort the run. A URL fetcher never sees the exit status —
 * measured: exit(1) alone answers HTTP 200, which a scheduler reads as a
 * successful prune — so a non-CLI failure is also given a 500.
 *
 * That only works while the response headers are unsent: measured under the
 * built-in server, the status is still settable after 1KB of output but not
 * after 4KB (the output buffer flushes in between). This report is a fixed
 * handful of short lines — one per retention class, twice, plus a summary,
 * ~400 bytes in full — so it cannot grow into that limit no matter how many
 * rows are pruned.
 */
$fail = static function (string $message) use ($warn): never {
    $warn($message);
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    exit(1);
};

$known = ['--dry-run', '--max-passes'];
$argv = PHP_SAPI === 'cli' ? array_values((array) ($_SERVER['argv'] ?? [])) : [];
$restIndex = 1;
$options = PHP_SAPI === 'cli' ? getopt('', ['dry-run', 'max-passes:'], $restIndex) : [];

// getopt() silently drops what it does not recognise, and this job deletes
// rows: a mistyped --dry-run would otherwise prune for real, and a bare
// --max-passes (no value) would fall back to the default bound without
// saying so (error pattern #12 — a flag the layer above discards).
//
// So account for every argument, not just the ones shaped like a long
// option: "-n" and a bare word are typos too, and a guard that only
// inspected "--" prefixes let both through to a real prune. Measured, since
// getopt() advertises none of this: it consumes "-n", "-x" and "--typo"
// alike and still reports a rest_index past them, so rest_index alone is not
// the check either — it only catches operands ("oops", anything after "--").
// This job takes no operands, so anything that is neither a known option nor
// the value getopt() ate for one is a mistake, and a destructive job fails
// closed on input it cannot account for.
for ($i = 1; $i < count($argv); $i++) {
    $arg = is_string($argv[$i]) ? $argv[$i] : '';
    $name = explode('=', $arg, 2)[0];
    if ($i >= $restIndex || !in_array($name, $known, true)) {
        $fail('unrecognised argument "' . $arg . '"; this job takes --dry-run and --max-passes=N');
    }
    if ($name === '--max-passes') {
        if (!isset($options['max-passes'])) {
            $fail('--max-passes needs a value, e.g. --max-passes=50');
        }
        if (!str_contains($arg, '=')) {
            $i++; // "--max-passes 50": skip the operand getopt() took as the value
        }
    }
}

$dryRun = isset($options['dry-run']);

$maxPasses = 200;
if (isset($options['max-passes'])) {
    // A ceiling we cannot parse is not a reason to guess at one: pruning is
    // destructive and the operator asked for a specific bound.
    $raw = is_string($options['max-passes']) ? trim($options['max-passes']) : '';
    if (preg_match('/^\d+$/D', $raw) !== 1 || (int) $raw < 1) {
        $fail('--max-passes must be a whole number of passes, 1 or more (got "'
            . (is_string($options['max-passes']) ? $options['max-passes'] : 'a repeated flag') . '")');
    }
    $maxPasses = (int) $raw;
}

if (!isset($db) || !($db instanceof mysqli)) {
    $fail('database connection unavailable');
}

$now = time();
$passes = 0;

try {
    // An install upgraded from before 1.9.76 has no postback table, and a
    // scheduler that mails every run's stderr should not be told that daily.
    // A FAILED probe is not the same answer as "not there" (error pattern
    // #11), so the false return fails the job instead.
    $tables = $db->query("SHOW TABLES LIKE '202_attribution_postbacks'");
    if ($tables === false) {
        throw new RuntimeException('could not check for the postback table: ' . $db->error);
    }
    $tableExists = $tables->num_rows > 0;
    $tables->close();
    if (!$tableExists) {
        echo "attribution-retention: 202_attribution_postbacks is not installed; nothing to prune\n";
        exit(0);
    }

    $receiver = PostbackReceiver::forMaintenance($db);
    $policy = PostbackReceiver::retentionPolicy($now);
    $before = $receiver->retentionBacklog($now);

    foreach ($policy as $label => $window) {
        if ($window['cutoff'] === null) {
            echo "{$label}: pruning disabled (0-day window), skipped\n";
            continue;
        }
        printf(
            "%s: %d-day window, cutoff %s, %d aged row(s)\n",
            $label,
            $window['days'],
            gmdate('Y-m-d H:i:s', $window['cutoff']) . ' UTC',
            $before[$label]
        );
    }

    $backlog = $before === [] ? 0 : max($before);
    $needed = (int) ceil($backlog / PostbackReceiver::PRUNE_BATCH_LIMIT);

    if ($dryRun) {
        echo 'attribution-retention completed (dry run: nothing deleted, '
            . "{$needed} pass(es) would have run)\n";
        exit(0);
    }

    for ($passes = 0; $passes < min($needed, $maxPasses); $passes++) {
        // One $now for every pass: the cutoffs a run reports are the cutoffs
        // it prunes on, however long the run takes.
        $receiver->prunePostbacks($now);
    }

    $after = $receiver->retentionBacklog($now);
    foreach ($policy as $label => $window) {
        if ($window['cutoff'] === null) {
            continue;
        }
        // Removed is the drop in backlog rather than a delete count: the
        // pruner deletes in bounded batches and the opportunistic pass on the
        // web SAPI may be deleting the same rows, so this is what actually
        // went away, not what this process claims to have done.
        printf(
            "%s: %d aged row(s) removed, %d remaining\n",
            $label,
            max(0, $before[$label] - $after[$label]),
            $after[$label]
        );
    }

    if ($needed > $maxPasses) {
        $warn(sprintf(
            'backlog needed %d passes but --max-passes is %d; %d row(s) still aged. Re-run, or raise --max-passes.',
            $needed,
            $maxPasses,
            $after === [] ? 0 : max($after)
        ));
    }
} catch (Throwable $e) {
    $fail('failed after ' . $passes . ' pass(es): ' . $e->getMessage());
}

echo "attribution-retention completed ({$passes} pass(es))\n";
