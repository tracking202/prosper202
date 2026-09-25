<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Database\Connection;

/**
 * What a conversion is worth to multi-touch attribution: its recorded
 * amount net of the reversals naming it (plan §6.5), or nothing.
 *
 * One rule, read by both sides of MTA: the worker splits this amount into
 * credits, and every read that shows a conversion's amount beside those
 * credits (the journey drill-down, the recent conversions) shows this
 * amount. Two implementations would drift, and a $10 sale reversed by $4
 * would read $10 over columns that each sum to $6.
 */
final class CountedAmount
{
    /** The conversion columns the rule reads, which a caller's row must carry. */
    private const COLUMNS = ['conv_id', 'click_payout', 'payable', 'deleted', 'superseded_reason', 'reverses_conv_id'];

    private function __construct()
    {
    }

    /**
     * The amount in units, or null when the conversion does not count: not
     * payable, deleted, superseded, itself a reversal, or reversed down to
     * nothing. A partial reversal leaves the remainder.
     *
     * @param array<string, mixed> $row a 202_conversion_logs row with conv_id,
     *        click_payout, payable, deleted, superseded_reason and reverses_conv_id
     * @param bool $primary read the reversals from the primary (the worker,
     *        inside its transaction) rather than a read connection
     */
    public static function of(Connection $conn, array $row, bool $primary = false): ?int
    {
        foreach (self::COLUMNS as $column) {
            if (!array_key_exists($column, $row)) {
                throw new \InvalidArgumentException(
                    'the conversion row has no ' . $column . ' column, which the counted amount reads'
                );
            }
        }
        $superseded = $row['superseded_reason'] !== null && $row['superseded_reason'] !== '';
        $reversal = $row['reverses_conv_id'] !== null;
        if ($reversal || $superseded || (int) $row['payable'] !== 1 || (int) $row['deleted'] !== 0) {
            return null;
        }
        $amount = Amount::toUnits((string) $row['click_payout']);

        $sql = 'SELECT click_payout FROM 202_conversion_logs WHERE reverses_conv_id = ? AND deleted = 0';
        $stmt = $primary ? $conn->prepareWrite($sql) : $conn->prepareRead($sql);
        $conn->bind($stmt, 'i', [(int) $row['conv_id']]);
        $reversals = $conn->fetchAll($stmt);
        foreach ($reversals as $r) {
            $amount += Amount::toUnits((string) $r['click_payout']);
        }
        if ($reversals !== [] && $amount <= 0) {
            return null;
        }

        return $amount;
    }

    /**
     * The fields a read shows for a conversion's amount: `amount`, what it
     * counts for (what its credits sum to; 0 when it does not count),
     * `recorded_amount`, the row's own amount, and `counted`.
     *
     * @param array<string, mixed> $row as for of()
     * @return array{amount: string, recorded_amount: string, counted: bool}
     */
    public static function fields(Connection $conn, array $row): array
    {
        $units = self::of($conn, $row);

        return [
            'amount' => Amount::fromUnits($units ?? 0),
            'recorded_amount' => Amount::fromUnits(Amount::toUnits((string) $row['click_payout'])),
            'counted' => $units !== null,
        ];
    }
}
