<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

/**
 * The ledger's "which rows count" in SQL, for reports that group income by
 * what generated it (Group Overview's Transaction ID and Goal / source
 * levels).
 *
 * ClickValueCalculator is the definition; this reads the state the
 * recompute persisted from it. A non-reversal row counts when it is
 * payable, live and not superseded — the recompute writes every derived
 * supersession (replace, batch) under the click lock, and the fixed ones
 * (pre_ledger, replay, reevaluation) are never cleared by it, so "no
 * superseded_reason" is exactly the calculator's winners. A reversal counts
 * when it is payable and live and its target counts, or its target is a
 * live pre-ledger row while the click's legacy_baseline row counts (rule 4).
 * LedgerReportSqlIntegrationTest runs both over the same rows, written
 * through the real repository in every shape the rules name, and requires
 * the same set.
 *
 * Every report part is one counted row. Clicks, click-throughs, leads and
 * cost belong to the click, not to a conversion, so exactly one part of
 * each click carries them: the click's latest counted non-reversal row
 * (`primary_part` = 1). Children of a group then add up to the group, and
 * a click with three transactions shows three amounts and one lead.
 */
final class LedgerReportSql
{
    private function __construct()
    {
    }

    /**
     * The predicate that a row aliased `$row` counts toward its click.
     * Correlated subqueries use the aliases `$row`_t and `$row`_b.
     */
    public static function countedPredicate(string $row): string
    {
        self::alias($row);
        $t = $row . '_t';
        $b = $row . '_b';
        $live = static fn (string $a): string => "$a.deleted = 0 AND $a.payable = 1";
        $unsuperseded = static fn (string $a): string => "($a.superseded_reason IS NULL OR $a.superseded_reason = '')";

        return '(' . $live($row) . ' AND ('
            . "($row.reverses_conv_id IS NULL AND " . $unsuperseded($row) . ')'
            . " OR ($row.reverses_conv_id IS NOT NULL AND EXISTS (SELECT 1 FROM 202_conversion_logs $t"
            . " WHERE $t.conv_id = $row.reverses_conv_id AND $t.click_id = $row.click_id AND $t.reverses_conv_id IS NULL AND " . $live($t)
            . ' AND (' . $unsuperseded($t)
            . " OR ($t.superseded_reason = 'pre_ledger' AND EXISTS (SELECT 1 FROM 202_conversion_logs $b"
            . " WHERE $b.click_id = $row.click_id AND $b.source = '" . ConversionSource::LEGACY_BASELINE->value . "'"
            . " AND $b.reverses_conv_id IS NULL AND " . $live($b) . ' AND ' . $unsuperseded($b) . '))'
            . ')))'
            . '))';
    }

    /**
     * A derived table of the counted rows of the clicks `$clickScope`
     * selects (an SQL predicate over `202_dataengine` aliased `s`), one row
     * per part: conv_id, click_id, amount, transaction_id, source,
     * goal_id (0 unless a goal row), goal_name, primary_part.
     *
     * `$clickScope` is inlined: callers pass SQL they built from integers
     * and fixed text only.
     */
    public static function partsTable(string $clickScope): string
    {
        $later = "NOT EXISTS (SELECT 1 FROM 202_conversion_logs lx WHERE lx.click_id = lp.click_id AND lx.conv_id > lp.conv_id"
            . " AND lx.reverses_conv_id IS NULL AND lx.deleted = 0 AND lx.payable = 1"
            . " AND (lx.superseded_reason IS NULL OR lx.superseded_reason = ''))";
        $goalId = "IF(lp.source = '" . ConversionSource::GOAL->value . "' AND lp.source_ref REGEXP '^goal:[0-9]+:[0-9]+\$',"
            . " CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(lp.source_ref, ':', 2), ':', -1) AS UNSIGNED), 0)";

        return '(SELECT lp.conv_id, lp.click_id, lp.click_payout AS amount, lp.transaction_id, lp.source,'
            . " $goalId AS goal_id, lg.name AS goal_name,"
            . " IF(lp.reverses_conv_id IS NULL AND $later, 1, 0) AS primary_part"
            . ' FROM 202_conversion_logs lp'
            . " LEFT JOIN 202_goals lg ON lg.goal_id = $goalId AND lg.user_id = lp.user_id"
            . ' WHERE lp.click_id IN (SELECT s.click_id FROM 202_dataengine s WHERE ' . $clickScope . ')'
            . ' AND ' . self::countedPredicate('lp')
            . ')';
    }

    private static function alias(string $alias): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,15}$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('"' . $alias . '" is not a table alias');
        }
    }
}
