<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ValidationException;
use Api\V3\Support\MysqliStatements;
use Api\V3\Support\ResponseSanitizer;

/**
 * GET /apps/notifications: the traffic-source postbacks the app installs'
 * goals queued (plan §5.5, §5.10) — the notification outbox as the operator
 * reads it, read-only.
 *
 * A row belongs to an app when the conversion it announces is an install's
 * goal outcome (202_goal_outcomes, subject `install`); its registration is
 * that outcome's. Web clicks' postbacks go through the same outbox and are
 * not listed here. A row is one destination — one URL of a server pixel's
 * code, `destination` its position from 0 — so a pixel with two URLs has two
 * rows per announcement, each sent, retried and failed on its own. `meta.summary` counts every matching row by status —
 * pending (waiting, or backing off after a failed attempt), sent, failed
 * (attempts ran out), cancelled (replaced before it went out) and
 * suppressed (a correction no correction URL could carry) — under the same
 * filters except `status`, so the summary still says what the other states
 * hold when the list is narrowed to one.
 */
final class AppNotificationsController
{
    use MysqliStatements;

    public const STATUSES = ['pending', 'sent', 'failed', 'cancelled', 'suppressed'];
    public const KINDS = ['reached', 'correction', 'retraction'];

    /** The outcome of an install that a conversion is, one per conversion. */
    private const APP_CONVERSIONS = "SELECT conversion_id, MIN(outcome_id) AS outcome_id FROM 202_goal_outcomes
        WHERE user_id = ? AND subject_type = 'install' AND conversion_id IS NOT NULL GROUP BY conversion_id";

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /** @param array<string, mixed> $params */
    public function list(array $params): array
    {
        $limit = AppPostbacksController::boundedInt($params, 'limit', 50, 1, 500);
        $offset = AppPostbacksController::boundedInt($params, 'offset', 0, 0, PHP_INT_MAX);
        [$where, $binds, $types] = $this->filters($params, true);
        [$summaryWhere, $summaryBinds, $summaryTypes] = $this->filters($params, false);

        $from = ' FROM 202_notification_pending n JOIN (' . self::APP_CONVERSIONS . ') ac ON ac.conversion_id = n.conv_id
            JOIN 202_goal_outcomes o ON o.outcome_id = ac.outcome_id
            LEFT JOIN 202_goals g ON g.goal_id = o.goal_id
            LEFT JOIN 202_app_registrations r ON r.registration_id = o.app_registration_id AND r.user_id = o.user_id';

        $summary = array_fill_keys(self::STATUSES, 0);
        foreach ($this->fetchAll(
            'SELECT n.status, COUNT(*) AS n' . $from . ' WHERE ' . implode(' AND ', $summaryWhere) . ' GROUP BY n.status',
            'i' . $summaryTypes,
            [$this->userId, ...$summaryBinds]
        ) as $row) {
            $summary[(string)$row['status']] = (int)$row['n'];
        }

        $total = (int)($this->fetchAll('SELECT COUNT(*) AS n' . $from . ' WHERE ' . implode(' AND ', $where), 'i' . $types, [$this->userId, ...$binds])[0]['n'] ?? 0);
        $rows = [];
        foreach ($this->fetchAll(
            'SELECT n.notification_id, n.conv_id, n.pixel_id, n.destination, n.kind, n.status, n.url, n.attempts, n.next_attempt_at, n.last_error,
                    n.created_at, n.sent_at, o.app_registration_id AS registration_id, r.app_name, o.goal_id, g.name AS goal_name,
                    o.subject_id AS install_row_id, o.campaign_id'
            . $from . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY n.notification_id DESC LIMIT ? OFFSET ?',
            'i' . $types . 'ii',
            [$this->userId, ...$binds, $limit, $offset]
        ) as $row) {
            foreach (['notification_id', 'conv_id', 'pixel_id', 'destination', 'attempts', 'next_attempt_at', 'created_at', 'registration_id', 'goal_id', 'install_row_id', 'campaign_id', 'sent_at'] as $int) {
                $row[$int] = $row[$int] === null ? null : (int)$row[$int];
            }
            // The URL was filled with click tokens a visitor chose (keyword,
            // c1–c4, utm_*), and the error quotes it.
            // A URL is kept whole (up to the 4 KB a pixel URL may resolve to):
            // a cut one could not be compared with what the network logged.
            $row = ResponseSanitizer::cleanRowFields($row, ['url'], 4096);
            $rows[] = ResponseSanitizer::cleanRowFields($row, ['last_error', 'app_name', 'goal_name']);
        }

        return [
            'data' => $rows,
            'pagination' => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
            'meta' => ['summary' => $summary],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: list<string>, 1: list<mixed>, 2: string}
     */
    private function filters(array $params, bool $withStatus): array
    {
        $where = ['n.user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';
        if (isset($params['registration_id']) && $params['registration_id'] !== '') {
            $where[] = 'o.app_registration_id = ?';
            $binds[] = AppPostbacksController::strictInt($params['registration_id'], 'registration_id', 'Invalid filter value', 'Must be an integer');
            $types .= 'i';
        }
        foreach (['time_from' => 'n.created_at >= ?', 'time_to' => 'n.created_at <= ?'] as $param => $condition) {
            if (isset($params[$param]) && $params[$param] !== '') {
                $where[] = $condition;
                $binds[] = AppPostbacksController::strictInt($params[$param], $param, 'Invalid time filter', 'Must be a unix timestamp');
                $types .= 'i';
            }
        }
        foreach (['status' => self::STATUSES, 'kind' => self::KINDS] as $param => $allowed) {
            if (!isset($params[$param]) || $params[$param] === '') {
                continue;
            }
            $value = strtolower(trim((string)$params[$param]));
            if (!in_array($value, $allowed, true)) {
                throw new ValidationException('Invalid filter value', [$param => 'Must be one of: ' . implode(', ', $allowed)]);
            }
            if ($param === 'status' && !$withStatus) {
                continue;
            }
            $where[] = 'n.' . $param . ' = ?';
            $binds[] = $value;
            $types .= 's';
        }

        return [$where, $binds, $types];
    }

    /**
     * @param list<mixed> $binds
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, string $types, array $binds): array
    {
        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Notifications query failed');
        $result = $this->result($stmt);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}
