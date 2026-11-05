<?php

declare(strict_types=1);

namespace Prosper202\Alerts;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;
use Prosper202\InboundLog\InboundLog;

/**
 * The account's inbound postbacks and pixels being refused far more than
 * usual (the inbound log, InboundLog): a network that changed its postback
 * format, a rotated token or signature secret, a campaign deleted under a
 * live offer. Conversions that arrive and are refused are revenue the
 * reports never show, and the conversions-stopped alert only sees it once a
 * campaign has gone quiet for hours.
 *
 * It alerts when the last hour holds at least MIN_REFUSED refusals and at
 * least SPIKE_MULTIPLE times the account's average refusals per hour over
 * the BASELINE_HOURS before it. An account that is refused at the same rate
 * all day is not a jump (its log shows it). The alert is about the account,
 * not a campaign (campaign 0, "all campaigns"), and is sent at most once a
 * day while the jump lasts: the incident is the day it is found in.
 */
final class PostbackRefusalSpikeDetector implements AlertDetector
{
    public const WINDOW = 3600;
    public const BASELINE_HOURS = 24;
    public const MIN_REFUSED = 10;
    public const SPIKE_MULTIPLE = 5;
    /** How many of the hour's reasons the alert names, most frequent first. */
    public const TOP_REASONS = 3;

    public function kind(): AlertKind
    {
        return AlertKind::PostbackRefusalsSpiked;
    }

    public function detect(Connection $conn, AccountActivity $activity, AlertSettings $settings): array
    {
        $now = $activity->now;
        $hourStart = $now - self::WINDOW;
        $baselineStart = $hourStart - self::BASELINE_HOURS * 3600;

        $stmt = $conn->prepareRead(
            'SELECT SUM(received_at >= ?) AS recent, SUM(received_at < ?) AS earlier'
            . ' FROM `' . TableRegistry::INBOUND_LOG . '`'
            . ' WHERE user_id = ? AND accepted = 0 AND received_at >= ? AND received_at <= ?'
        );
        $conn->bind($stmt, 'iiiii', [$hourStart, $hourStart, $activity->userId, $baselineStart, $now]);
        $counts = $conn->fetchOne($stmt) ?? [];
        $recent = (int) ($counts['recent'] ?? 0);
        $usual = (int) ($counts['earlier'] ?? 0) / self::BASELINE_HOURS;
        if ($recent < self::MIN_REFUSED || $recent < self::SPIKE_MULTIPLE * $usual) {
            return [];
        }

        $stmt = $conn->prepareRead(
            'SELECT outcome, COUNT(*) AS n FROM `' . TableRegistry::INBOUND_LOG . '`'
            . ' WHERE user_id = ? AND accepted = 0 AND received_at >= ? AND received_at <= ?'
            . ' GROUP BY outcome ORDER BY n DESC, outcome ASC LIMIT ' . self::TOP_REASONS
        );
        $conn->bind($stmt, 'iii', [$activity->userId, $hourStart, $now]);
        $reasons = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $reasons[(string) $row['outcome']] = (int) $row['n'];
        }
        $named = implode(', ', array_map(
            static fn (string $outcome, int $n): string => $outcome . ' (' . number_format($n) . ')',
            array_keys($reasons),
            $reasons
        ));

        return [new Alert(
            AlertKind::PostbackRefusalsSpiked,
            $activity->userId,
            0,
            'all campaigns',
            intdiv($now, 86400),
            sprintf(
                '%s inbound postbacks and pixels were refused in the last hour, against about %s an hour over the'
                . ' %d hours before%s. Most were: %s. Check Setup › Inbound Log for the requests and why each'
                . ' was refused.',
                number_format($recent),
                number_format($usual, $usual < 10 ? 1 : 0),
                self::BASELINE_HOURS,
                $usual > 0 ? ' (' . AlertText::times($recent / $usual) . ' as many)' : '',
                $named === '' ? 'unknown' : $named
            ),
            [
                'refused_last_hour' => $recent,
                'usual_refused_per_hour' => round($usual, 2),
                'baseline_hours' => self::BASELINE_HOURS,
                'top_refusals' => json_encode($reasons, JSON_UNESCAPED_SLASHES) ?: '{}',
                'inbound_log_kept_per_account' => InboundLog::KEEP_REFUSED,
            ]
        )];
    }
}
