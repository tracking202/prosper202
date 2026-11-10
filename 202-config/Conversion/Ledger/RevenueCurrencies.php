<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

use Prosper202\Database\Connection;

/**
 * The revenue of a period that is not in the account's currency, by the
 * currency its ledger rows recorded.
 *
 * A report's totals come from the clicks' values, which add their rows'
 * numbers and carry no currency of their own. Nearly always every row is in
 * the account's currency and the total is an amount in it. When it is not —
 * the account's currency was changed, or an amount was recorded as sent in
 * another — the total holds numbers in more than one currency, and every
 * reader that shows it says which, and how much of each, from here.
 *
 * The figures are the account's for the period, whatever else the report
 * filters by: the reports' own rows hold no currency to filter with.
 */
final class RevenueCurrencies
{
    /** The period is the clicks' (the reports'). */
    public const BY_CLICK_TIME = 'click_time';

    /** The period is the conversions'. */
    public const BY_CONVERSION_TIME = 'conv_time';

    private function __construct()
    {
    }

    /**
     * What counts of the account's rows in the period, for each currency
     * that is not $accountCurrency (null: none was recorded), in the order
     * of the codes. Empty when every counted amount is in the account's.
     *
     * @return list<array{currency: ?string, conversions: int, amount: string}>
     */
    public static function others(
        Connection $conn,
        int $userId,
        string $accountCurrency,
        ?int $from,
        ?int $to,
        string $basis = self::BY_CLICK_TIME
    ): array {
        if ($basis !== self::BY_CLICK_TIME && $basis !== self::BY_CONVERSION_TIME) {
            throw new \InvalidArgumentException('"' . $basis . '" is not a time the ledger keeps');
        }
        $counted = LedgerReportSql::countedPredicate('cl');
        $sql = 'SELECT cl.currency, SUM(cl.reverses_conv_id IS NULL AND ' . $counted . ') AS conversions,'
            . ' SUM(IF(' . $counted . ', cl.click_payout, 0)) AS amount'
            . ' FROM 202_conversion_logs cl WHERE cl.user_id = ? AND cl.currency <> ?';
        $types = 'is';
        $values = [$userId, $accountCurrency];
        if ($from !== null) {
            $sql .= ' AND cl.' . $basis . ' >= ?';
            $types .= 'i';
            $values[] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND cl.' . $basis . ' <= ?';
            $types .= 'i';
            $values[] = $to;
        }
        $stmt = $conn->prepareRead($sql . ' GROUP BY cl.currency ORDER BY cl.currency');
        $conn->bind($stmt, $types, $values);

        $others = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $conversions = (int) ($row['conversions'] ?? 0);
            $units = Amount::toUnits((string) ($row['amount'] ?? '0'));
            if ($conversions === 0 && $units === 0) {
                continue;
            }
            $others[] = [
                'currency' => RowCurrency::forAnswer($row['currency'] ?? null),
                'conversions' => $conversions,
                'amount' => Amount::fromUnits($units),
            ];
        }

        return $others;
    }

    /**
     * The same for an API answer: amounts as numbers.
     *
     * @param list<array{currency: ?string, conversions: int, amount: string}> $others
     * @return list<array{currency: ?string, conversions: int, amount: float}>
     */
    public static function forAnswer(array $others): array
    {
        return array_map(
            static fn (array $o): array => ['currency' => $o['currency'], 'conversions' => $o['conversions'], 'amount' => (float) $o['amount']],
            $others
        );
    }

    /**
     * What a reader is told beside a total, or '' when there is nothing to
     * tell.
     *
     * @param list<array{currency: ?string, conversions: int, amount: string}> $others
     */
    public static function sentence(array $others, string $accountCurrency): string
    {
        if ($others === []) {
            return '';
        }
        $parts = [];
        foreach ($others as $o) {
            $count = number_format($o['conversions']) . ' conversion' . ($o['conversions'] === 1 ? '' : 's');
            $amount = number_format((float) $o['amount'], 2);
            $parts[] = $o['currency'] !== null
                ? $o['currency'] . ' ' . $amount . ' (' . $count . ')'
                : $amount . ' with no currency recorded (' . $count . ')';
        }

        return 'Not all revenue in this period is in ' . $accountCurrency . '. Your account also recorded '
            . implode(', ', $parts) . '. Totals add these amounts as they were recorded; nothing converts them to '
            . $accountCurrency . '. The conversion log shows each conversion in its own currency.';
    }

    /**
     * sentence() as the notice a report page shows above its figures, for
     * the account and period the page reads; '' when every amount is in the
     * account's currency, and for a user who is not shown money at all.
     */
    public static function notice(\mysqli $db, int $userId, int $from, int $to): string
    {
        if (\Prosper202\Report\CampaignDataMask::hidden()) {
            return '';
        }
        $conn = new Connection($db);
        $accountCurrency = RowCurrency::ofAccount($conn, $userId);
        $sentence = self::sentence(self::others($conn, $userId, $accountCurrency, $from, $to), $accountCurrency);
        if ($sentence === '') {
            return '';
        }

        return '<div class="alert alert-warning p202-flash" role="alert"><i class="bi bi-exclamation-triangle"></i>'
            . '<div class="p202-flash__body">' . htmlspecialchars($sentence, ENT_QUOTES, 'UTF-8') . '</div></div>';
    }
}
