<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

/**
 * Say, for every ledger row of a click, whether it counts toward the
 * click's value and, when it does not, why.
 *
 * "Counts" is ClickValueCalculator's answer, never a second opinion: this
 * runs the calculator and reads its `counted` set, so the breakdown and the
 * click's cached value cannot disagree about which rows make it up. What
 * this adds is the reason for each row left out, checked in this order:
 *
 * 1. DELETED   the row was soft-deleted;
 * 2. UNPAID    the row is a tracked outcome (payable = 0);
 * 3. SUPERSEDED another row replaced it — for a fixed reason stored on the
 *              row (pre_ledger, replay, reevaluation), or for the derived
 *              reason the calculator gives now (replace, batch);
 * 4. NOT_NETTED a reversal whose target does not count.
 *
 * A payable, live, non-reversal row that neither counts nor is superseded
 * cannot happen under the calculator's rules; if it ever does, this throws
 * rather than inventing a reason (CLAUDE.md error pattern #11).
 */
final class LedgerExplainer
{
    /**
     * @param list<LedgerRow> $rows
     * @return array{value: ClickValue, rows: array<int, array{counted: bool, reason: ?NotCountedReason, superseded_reason: ?SupersededReason, superseded_by: ?int}>}
     *         rows keyed by conv_id, in conv_id order
     */
    public static function explain(array $rows, PayoutMode $mode): array
    {
        $value = ClickValueCalculator::calculate($rows, $mode);

        usort($rows, static fn (LedgerRow $a, LedgerRow $b): int => $a->convId <=> $b->convId);

        $verdicts = [];
        foreach ($rows as $row) {
            if (isset($value->counted[$row->convId])) {
                $verdicts[$row->convId] = ['counted' => true, 'reason' => null, 'superseded_reason' => null, 'superseded_by' => null];
                continue;
            }
            if ($row->deleted) {
                $verdicts[$row->convId] = ['counted' => false, 'reason' => NotCountedReason::DELETED, 'superseded_reason' => null, 'superseded_by' => null];
                continue;
            }
            if (!$row->payable) {
                $verdicts[$row->convId] = ['counted' => false, 'reason' => NotCountedReason::UNPAID, 'superseded_reason' => null, 'superseded_by' => null];
                continue;
            }
            // In the docblock's order: a row that was superseded is reported
            // as superseded even when it is also a reversal — the reason it
            // was replaced is the one that explains it, and a reversal
            // checked first hid that behind not_netted.
            if ($row->hasFixedSupersession()) {
                $verdicts[$row->convId] = ['counted' => false, 'reason' => NotCountedReason::SUPERSEDED, 'superseded_reason' => $row->supersededReason, 'superseded_by' => $row->supersededBy];
                continue;
            }
            $derived = $value->derivedSupersessions[$row->convId] ?? null;
            if ($derived !== null) {
                $verdicts[$row->convId] = ['counted' => false, 'reason' => NotCountedReason::SUPERSEDED, 'superseded_reason' => $derived['reason'], 'superseded_by' => $derived['by']];
                continue;
            }
            if ($row->isReversal()) {
                $verdicts[$row->convId] = ['counted' => false, 'reason' => NotCountedReason::NOT_NETTED, 'superseded_reason' => null, 'superseded_by' => null];
                continue;
            }
            throw new LedgerIntegrityException(
                'conversion ' . $row->convId . ' is payable and live but neither counts nor is superseded; the ledger rules have no reason for it'
            );
        }

        return ['value' => $value, 'rows' => $verdicts];
    }
}
