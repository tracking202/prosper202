<?php

declare(strict_types=1);

namespace Prosper202\Notifications;

use Prosper202\Database\Connection;

/**
 * Whether queued traffic-source postbacks are being left unsent
 * (202_notification_pending). A conversion's postbacks are queued with it
 * and sent after the answer where the server can (PHP-FPM, LiteSpeed), and
 * otherwise, and on every retry, by the cron job. With no cron job running
 * they wait for good, and the traffic source never hears of the conversion.
 *
 * Stuck means both: a row was due more than OVERDUE_SECONDS ago, and no
 * scheduler has recorded a run since (202_cronjob_logs, a cron job's or
 * AutoCron's, never a page's ping). Rows waiting while the cron job runs
 * are the destination's (a host that does not answer is retried, one row a
 * run), and are not reported. A read that fails throws.
 */
final class OutboxBacklog
{
    public const OVERDUE_SECONDS = 900;
    /** Overdue rows counted; past this the answer is "more than". */
    public const COUNT_CAP = 1000;

    private function __construct()
    {
    }

    /**
     * @param int|null $userId one account's rows, or every account's
     * @return array{stuck: bool, overdue: int, oldest_due_at: int|null, cron_last_run: int|null, warning: string|null}
     *         overdue is at most COUNT_CAP + 1, which reads "more than COUNT_CAP"
     */
    public static function read(Connection $conn, int $now, ?int $userId = null): array
    {
        // The oldest COUNT_CAP + 1 overdue rows: a queue left for weeks is not counted whole on every page.
        $sql = "SELECT COUNT(*) AS overdue, MIN(next_attempt_at) AS oldest FROM (SELECT next_attempt_at FROM 202_notification_pending"
            . " WHERE status = 'pending' AND next_attempt_at <= ?";
        $types = 'i';
        $binds = [$now - self::OVERDUE_SECONDS];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $types .= 'i';
            $binds[] = $userId;
        }
        $stmt = $conn->prepareRead($sql . ' ORDER BY next_attempt_at LIMIT ' . (self::COUNT_CAP + 1) . ') AS due');
        $conn->bind($stmt, $types, $binds);
        $row = $conn->fetchOne($stmt);
        $overdue = (int) ($row['overdue'] ?? 0);
        $oldest = ($row['oldest'] ?? null) === null ? null : (int) $row['oldest'];

        $stmt = $conn->prepareRead('SELECT last_execution_time FROM 202_cronjob_logs WHERE id = 1');
        $run = $conn->fetchOne($stmt);
        $lastRun = $run === null ? null : (int) $run['last_execution_time'];

        $stuck = $overdue > 0 && ($lastRun === null || $lastRun < $now - self::OVERDUE_SECONDS);

        return [
            'stuck' => $stuck,
            'overdue' => $overdue,
            'oldest_due_at' => $oldest,
            'cron_last_run' => $lastRun,
            'warning' => $stuck ? self::warning($overdue, (int) $oldest, $lastRun, $now) : null,
        ];
    }

    private static function warning(int $overdue, int $oldest, ?int $lastRun, int $now): string
    {
        $waited = self::span($now - $oldest);

        $many = $overdue > self::COUNT_CAP ? 'More than ' . number_format(self::COUNT_CAP) : number_format($overdue);

        return ($overdue === 1 ? '1 conversion postback to a traffic source has' : $many . ' conversion postbacks to your traffic sources have')
            . ' not been sent, the oldest waiting ' . $waited . '. The cron job sends them, and '
            . ($lastRun === null ? 'it has never run on this install' : 'it last ran ' . self::span($now - $lastRun) . ' ago')
            . '. Until it runs, your traffic sources are not told about conversions. Set up the cron job, or turn on AutoCron'
            . ' under Account › Settings.';
    }

    private static function span(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds >= 172800) {
            return intdiv($seconds, 86400) . ' days';
        }
        if ($seconds >= 7200) {
            return intdiv($seconds, 3600) . ' hours';
        }

        return max(1, intdiv($seconds, 60)) . ' minutes';
    }
}
