<?php

declare(strict_types=1);

namespace Tracking202\Report;

use mysqli;
use Prosper202\Database\Connection;
use Prosper202\DataEngine\UserPrefFilters;

/**
 * The figures of Overview › Rotator Breakdown: each of an account's
 * rotators, each of its rules, and its default, over a window.
 *
 * A click's rotator and rule are its 202_clicks_rotator row, which every
 * rotator entry point writes — rtr.php for a redirector tracker, offrtr.php
 * for a landing page's offer rotator — and which the dataengine copies, so
 * GET /rotators/{id}/stats (ReportsController::rotatorStats) reads the same
 * two columns. The page used to count from 202_clicks instead, which was
 * wrong two ways:
 *
 *  - a rule's row counted the clicks whose 202_clicks.rule_id was the rule's
 *    id, and rtr.php fills that column with the chosen REDIRECT's id: a rule
 *    with several redirects had its clicks spread over other rules' rows (or
 *    none), and a redirect id equal to another rule's id credited that rule;
 *  - a rotator's row counted 202_clicks.rotator_id, which offrtr.php did not
 *    write, so every click a landing page sent through its offer rotator was
 *    missing from the report.
 *
 * The money is the classic page's: income is the payout of the clicks that
 * converted, cost their CPC. Payout is income per lead, as the Analyze
 * reports compute it; the classic page selected one campaign's payout
 * outside the GROUP BY, which is whichever row the server read first.
 *
 * Every rule is listed, a rule with no clicks at zero; a rule since deleted
 * that still has clicks in the window is listed after them, flagged, so the
 * rules and the default add up to the rotator's totals, as the API's do.
 */
final class RotatorBreakdown
{
    private readonly Connection $conn;

    public function __construct(mysqli $db)
    {
        $this->conn = new Connection($db);
    }

    /**
     * Each figures array is {clicks: int, leads: int, income: float, cost: float}.
     *
     * @param string $show the "show" preference (all, real, filtered, filtered_bot, leads)
     * @return list<array{
     *   id: int, name: string, totals: array{clicks: int, leads: int, income: float, cost: float},
     *   rules: list<array{id: int, name: ?string, deleted: bool, figures: array{clicks: int, leads: int, income: float, cost: float}}>,
     *   default: array{clicks: int, leads: int, income: float, cost: float}
     * }>
     */
    public function rotators(int $userId, int $from, int $to, string $show): array
    {
        $stmt = $this->conn->prepareRead('SELECT id, name FROM 202_rotators WHERE user_id = ? ORDER BY id ASC');
        $this->conn->bind($stmt, 'i', [$userId]);
        $rotators = $this->conn->fetchAll($stmt);
        if ($rotators === []) {
            return [];
        }

        $stmt = $this->conn->prepareRead(
            'SELECT ru.id, ru.rotator_id, ru.rule_name FROM 202_rotator_rules ru'
            . ' INNER JOIN 202_rotators ro ON ro.id = ru.rotator_id'
            . ' WHERE ro.user_id = ? ORDER BY ru.id ASC'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $rulesByRotator = [];
        foreach ($this->conn->fetchAll($stmt) as $rule) {
            $rulesByRotator[(int) $rule['rotator_id']][(int) $rule['id']] = (string) $rule['rule_name'];
        }

        // One pass over the window: every click of the account that went
        // through one of its rotators, by the rotator and the rule it
        // matched (0: no rule matched, the rotator's default).
        $stmt = $this->conn->prepareRead(
            'SELECT cr.rotator_id, cr.rule_id,'
            . ' COUNT(*) AS clicks, SUM(c.click_lead) AS leads,'
            . ' SUM(c.click_payout * c.click_lead) AS income, SUM(c.click_cpc) AS cost'
            . ' FROM 202_clicks AS c'
            . ' INNER JOIN 202_clicks_rotator AS cr ON cr.click_id = c.click_id'
            . ' INNER JOIN 202_rotators AS ro ON ro.id = cr.rotator_id AND ro.user_id = c.user_id'
            . ' WHERE c.user_id = ? AND c.click_time >= ? AND c.click_time <= ?'
            . UserPrefFilters::showFilter($show, 'c.')
            . ' GROUP BY cr.rotator_id, cr.rule_id'
        );
        $this->conn->bind($stmt, 'iii', [$userId, $from, $to]);
        $byRule = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $byRule[(int) $row['rotator_id']][(int) $row['rule_id']] = self::figures($row);
        }

        $out = [];
        foreach ($rotators as $rotator) {
            $id = (int) $rotator['id'];
            $groups = $byRule[$id] ?? [];
            $totals = self::zero();
            foreach ($groups as $figures) {
                $totals = self::add($totals, $figures);
            }

            $rules = [];
            foreach ($rulesByRotator[$id] ?? [] as $ruleId => $name) {
                $rules[] = ['id' => $ruleId, 'name' => $name, 'deleted' => false, 'figures' => $groups[$ruleId] ?? self::zero()];
                unset($groups[$ruleId]);
            }
            $default = $groups[0] ?? self::zero();
            unset($groups[0]);
            ksort($groups);
            foreach ($groups as $ruleId => $figures) {
                $rules[] = ['id' => $ruleId, 'name' => null, 'deleted' => true, 'figures' => $figures];
            }

            $out[] = ['id' => $id, 'name' => (string) $rotator['name'], 'totals' => $totals, 'rules' => $rules, 'default' => $default];
        }

        return $out;
    }

    /** @return array{clicks: int, leads: int, income: float, cost: float} */
    public static function zero(): array
    {
        return ['clicks' => 0, 'leads' => 0, 'income' => 0.0, 'cost' => 0.0];
    }

    /**
     * @param array{clicks: int, leads: int, income: float, cost: float} $a
     * @param array{clicks: int, leads: int, income: float, cost: float} $b
     * @return array{clicks: int, leads: int, income: float, cost: float}
     */
    public static function add(array $a, array $b): array
    {
        return [
            'clicks' => $a['clicks'] + $b['clicks'],
            'leads' => $a['leads'] + $b['leads'],
            'income' => $a['income'] + $b['income'],
            'cost' => $a['cost'] + $b['cost'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{clicks: int, leads: int, income: float, cost: float}
     */
    private static function figures(array $row): array
    {
        return [
            'clicks' => (int) $row['clicks'],
            'leads' => (int) $row['leads'],
            'income' => (float) $row['income'],
            'cost' => (float) $row['cost'],
        ];
    }
}
