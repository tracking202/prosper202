<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

/**
 * Accumulates the "Totals for report" row while iterating report rows.
 *
 * This reproduces the accumulation loop that was copy-pasted into every
 * report method, including its intentional quirks:
 *  - `payout` is a running average recomputed as (previous + current) / n,
 *    which is how the legacy reports have always displayed it;
 *  - ratios are recomputed from the running totals on every row.
 *
 * Divisions by zero return 0 instead of raising DivisionByZeroError. The
 * legacy code wrapped these divisions in `@round(...)`, which silenced the
 * PHP 7 warning but would fatal on PHP 8; zero is the value those reports
 * have always rendered for an empty denominator.
 */
final class ReportTotals
{
    /** @var array<string, int|float> */
    private array $totals = [
        'clicks' => 0,
        'click_out' => 0,
        'ctr' => 0,
        'cost' => 0,
        'cpc' => 0,
        'leads' => 0,
        'su_ratio' => 0,
        'payout' => 0,
        'income' => 0,
        'epc' => 0,
        'net' => 0,
        'roi' => 0,
    ];

    private int $rowCount = 0;

    /**
     * Fold one report row (as returned by mysqli fetch_assoc) into the totals.
     *
     * @param array<string, mixed> $row
     */
    public function add(array $row): void
    {
        $this->rowCount++;
        $totals = &$this->totals;

        $totals['clicks'] += (float) ($row['clicks'] ?? 0);
        $totals['click_out'] += (float) ($row['click_out'] ?? 0);
        $totals['ctr'] = self::ratio($totals['click_out'] * 100, $totals['clicks'], 2);
        $totals['cost'] += (float) ($row['cost'] ?? 0);
        $totals['cpc'] = self::ratio($totals['cost'], $totals['clicks'], 5);
        $totals['leads'] += (float) ($row['leads'] ?? 0);
        $totals['su_ratio'] = self::ratio($totals['leads'] * 100, $totals['clicks'], 2);
        $totals['payout'] = round(($totals['payout'] + (float) ($row['payout'] ?? 0)) / $this->rowCount, 2);
        $totals['income'] += (float) ($row['income'] ?? 0);
        $totals['epc'] = self::ratio($totals['income'], $totals['clicks'], 5);
        $totals['net'] = $totals['income'] - $totals['cost'];
        $totals['roi'] = self::ratio($totals['net'] * 100, $totals['cost'], 0);
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    /** @return array<string, int|float> */
    public function toArray(): array
    {
        return $this->totals;
    }

    private static function ratio(float $numerator, float $denominator, int $precision): float
    {
        if ($denominator == 0.0) {
            return 0.0;
        }

        return round($numerator / $denominator, $precision);
    }
}
