<?php

declare(strict_types=1);

namespace Prosper202\Report;

/**
 * The rows of the Analyze downloads (DisplayData::downloadReport() and
 * downloadVariables()): a first cell naming the row, its twelve figures and
 * the two money columns, every cell written by TsvCell.
 */
final class AnalyzeDownload
{
    /** The figures of a row, after its first cell, in column order. */
    public const METRICS = [
        'clicks', 'click_out', 'ctr', 'leads', 'su_ratio', 'payout', 'epc', 'cpc', 'income', 'cost', 'net', 'roi',
    ];

    /** The headers of METRICS and of the two money columns. */
    public const HEADERS = [
        'Clicks', 'Click Throughs', 'LP CTR', 'Leads', 'S/U', 'Payout', 'EPC', 'Avg CPC', 'Income', 'Cost', 'Net',
        'ROI', 'Currency', 'Other currencies in period',
    ];

    private function __construct()
    {
    }

    /** The header line, its first cell naming what the rows are. */
    public static function header(string $first): string
    {
        return TsvCell::row([$first, ...self::HEADERS]);
    }

    /**
     * @param array<array-key, string|int|float|bool|null|array<mixed>> $figures the row's values by METRICS key
     * @param list<string> $money the account's currency and the period's other currencies
     */
    public static function row(string $first, array $figures, array $money): string
    {
        $cells = [$first];
        foreach (self::METRICS as $key) {
            $cells[] = $figures[$key] ?? null;
        }

        return TsvCell::row([...$cells, ...$money]);
    }
}
