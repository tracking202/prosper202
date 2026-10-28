<?php

declare(strict_types=1);

namespace Prosper202\Report;

use Prosper202\Database\Connection;

/**
 * The Overview chart's stored settings (202_charts, one row per user): its
 * resolution and its lines, a campaign (0 for all) and a figure each.
 *
 * An account has a row only if the installer made it (the first user) or a
 * write here did. Accounts the API or the Administration page creates have
 * none, and the chart's writes were UPDATEs, which wrote to nothing: the
 * resolution answered 404 and the lines the builder saved were dropped with a
 * 200. Each write here gives such an account the installer's default chart
 * with the change applied.
 *
 * Every write throws on failure (Connection).
 */
final class OverviewChart
{
    /** The resolutions the chart draws at. */
    public const RANGES = ['hours', 'days'];

    /**
     * The figures a line can plot, as DataEngine::getChart() names them (its
     * CHART_METRICS) and the builder offers them.
     */
    public const FIGURES = [
        'clicks', 'click_out', 'ctr', 'leads', 'su_ratio', 'payout', 'epc', 'cpc', 'income', 'cost', 'net', 'roi',
    ];

    /** The most lines a chart keeps: the row is a TEXT column, which a long list would overrun. */
    public const MAX_LINES = 50;

    /** install.php's default chart: clicks, click-throughs and leads for all campaigns. */
    public const DEFAULT_LINES = [
        ['campaign_id' => '0', 'value_type' => 'clicks'],
        ['campaign_id' => '0', 'value_type' => 'click_out'],
        ['campaign_id' => '0', 'value_type' => 'leads'],
    ];

    private function __construct()
    {
    }

    public static function saveRange(Connection $conn, int $userId, string $range): void
    {
        if (!in_array($range, self::RANGES, true)) {
            throw new \InvalidArgumentException('chart_time_range must be one of: ' . implode(', ', self::RANGES));
        }
        if (self::hasRow($conn, $userId)) {
            $stmt = $conn->prepareWrite('UPDATE `202_charts` SET `chart_time_range` = ? WHERE `user_id` = ?');
            $conn->bind($stmt, 'si', [$range, $userId]);
            $conn->executeUpdate($stmt);
            return;
        }
        self::insert($conn, $userId, self::DEFAULT_LINES, $range);
    }

    /**
     * @param list<array{campaign_id: string, value_type: string}> $lines as lines() reads them
     */
    public static function saveLines(Connection $conn, int $userId, array $lines): void
    {
        if (self::hasRow($conn, $userId)) {
            $stmt = $conn->prepareWrite('UPDATE `202_charts` SET `data` = ? WHERE `user_id` = ?');
            $conn->bind($stmt, 'si', [serialize($lines), $userId]);
            $conn->executeUpdate($stmt);
            return;
        }
        self::insert($conn, $userId, $lines, 'days');
    }

    /**
     * The chart builder's posted lines (levels[i][id], types[i][type]), each a
     * whole-number campaign id (0 for all) and a figure from FIGURES.
     *
     * @return list<array{campaign_id: string, value_type: string}>
     * @throws \InvalidArgumentException naming what was refused
     */
    public static function lines(mixed $levels, mixed $types): array
    {
        if (!is_array($levels) || !is_array($types) || $levels === []) {
            throw new \InvalidArgumentException(
                'levels and types: one campaign and one figure per line, at least one line'
            );
        }
        if (array_keys($levels) !== array_keys($types)) {
            throw new \InvalidArgumentException('levels and types: each line needs both a campaign and a figure');
        }
        if (count($levels) > self::MAX_LINES) {
            throw new \InvalidArgumentException('levels: a chart has at most ' . self::MAX_LINES . ' lines');
        }
        $lines = [];
        foreach ($levels as $key => $level) {
            $id = is_array($level) && array_keys($level) === ['id'] ? $level['id'] : null;
            if (!is_string($id) || preg_match('/^\d{1,8}$/D', $id) !== 1) {
                throw new \InvalidArgumentException("levels[$key][id]: a campaign id, or 0 for all campaigns");
            }
            $type = is_array($types[$key]) && array_keys($types[$key]) === ['type'] ? $types[$key]['type'] : null;
            if (!is_string($type) || !in_array($type, self::FIGURES, true)) {
                throw new \InvalidArgumentException("types[$key][type]: one of " . implode(', ', self::FIGURES));
            }
            $lines[] = ['campaign_id' => (string) (int) $id, 'value_type' => $type];
        }

        return $lines;
    }

    private static function hasRow(Connection $conn, int $userId): bool
    {
        $stmt = $conn->prepareWrite('SELECT 1 FROM `202_charts` WHERE `user_id` = ? LIMIT 1');
        $conn->bind($stmt, 'i', [$userId]);

        return $conn->fetchOne($stmt) !== null;
    }

    /** @param list<array{campaign_id: string, value_type: string}> $lines */
    private static function insert(Connection $conn, int $userId, array $lines, string $range): void
    {
        $stmt = $conn->prepareWrite(
            'INSERT INTO `202_charts` (`user_id`, `data`, `chart_time_range`) VALUES (?, ?, ?)'
        );
        $conn->bind($stmt, 'iss', [$userId, serialize($lines), $range]);
        $conn->executeInsert($stmt);
    }
}
