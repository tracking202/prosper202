<?php

declare(strict_types=1);

namespace Prosper202\Cron;

/**
 * Who started a run of 202-cronjobs/index.php, which decides whether the run
 * is recorded as the cron job's last run (202_cronjob_logs).
 *
 * Every signed-in page's footer pings the cron as it loads
 * (p202CronBeaconStatement()), with the page-ping query below. That run does
 * the cron's work and is not a cron job: recorded as one, Account › Settings
 * read the ping its own page had just sent as "your own cron job ran in the
 * last five minutes" and refused AutoCron on every install, and the "Cron job
 * last ran" tile, health.php, GET /system/info, GET /system/cron and
 * `p202 system cron` reported a cron job on an install that had none.
 *
 * A scheduler is every other caller: the command line (a crontab's
 * `php …/202-cronjobs/index.php`), or a request without the page's query (a
 * crontab's curl or wget, the Docker cron service, AutoCron, none of which
 * can be told to send anything). Nothing proves who a caller is: the URL
 * needs no sign-in, so any other request without the query -- a crawler, a
 * monitor, a tab opened before an upgrade -- is recorded as a run, as every
 * request was before, and one that adds the query is not. Both run the
 * work; what the record decides (Settings' five-minute check, the health
 * readings) is advice to the owner, not access.
 */
final class CronRun
{
    public const PAGE_PING_PARAM = 'page_ping';
    public const PAGE_PING_VALUE = '1';

    /** The query string the footer's ping carries, without the "?". */
    public static function pagePingQuery(): string
    {
        return self::PAGE_PING_PARAM . '=' . self::PAGE_PING_VALUE;
    }

    /**
     * @param string               $sapi  PHP_SAPI
     * @param array<mixed, mixed>  $query $_GET
     */
    public static function isScheduled(string $sapi, array $query): bool
    {
        if ($sapi === 'cli' || $sapi === 'phpdbg') {
            return true;
        }

        return ($query[self::PAGE_PING_PARAM] ?? null) !== self::PAGE_PING_VALUE;
    }
}
