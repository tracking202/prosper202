<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

/**
 * Derive a click's lead flag and value from its ledger rows.
 *
 * This is the only definition of "what is this click worth". It is pure —
 * rows and a mode in, a ClickValue out — so every rule below is tested
 * directly, and the database writer (MysqlConversionLedger) does nothing
 * but read the rows under the click lock, call this, and write the answer.
 *
 * The rules, in the order they apply:
 *
 * 1. Candidates are rows that are payable, not deleted, not reversals, and
 *    not superseded for a fixed reason (pre-ledger, goal replay or
 *    re-evaluation — decisions this class does not own).
 * 2. Revenue uploads are units of a batch, not of a line. Among the
 *    candidates, the newest upload batch supersedes every older batch's
 *    rows and every plain row recorded before it: that is the upload's
 *    historical "sum within the file, replace across files", kept exactly.
 * 3. REPLACE: the latest remaining entity wins, where the newest batch is
 *    one entity (the sum of its lines) and every other row is its own.
 *    Everything else remaining is superseded by it. ACCUMULATE: every
 *    remaining candidate counts.
 * 4. A reversal counts exactly when the row it reverses counts, and nets
 *    against it. It never competes for "latest": a $3 sale reversed is $0,
 *    not the reversal's -$3. A sale recorded before the ledger no longer
 *    counts on its own — its click's value was carried in as the
 *    legacy_baseline row — so its reversal nets against that baseline
 *    while the baseline counts.
 * 5. The click is a lead when at least one non-reversal row counts,
 *    whatever the net after reversals. Its value is the sum of the counted
 *    rows. A click that is not a lead has no value here, and the writer
 *    leaves its stored payout alone, which is what clearing a lead always
 *    did.
 *
 * "Latest" is insertion order (conv_id), not conv_time: conv_time can be
 * supplied by a caller and backdated, and the value a click shows has
 * always been set by the most recent write.
 */
final class ClickValueCalculator
{
    /**
     * @param list<LedgerRow> $rows
     */
    public static function calculate(array $rows, PayoutMode $mode): ClickValue
    {
        usort($rows, static fn (LedgerRow $a, LedgerRow $b): int => $a->convId <=> $b->convId);

        $candidates = [];
        $reversals = [];
        foreach ($rows as $row) {
            if ($row->deleted || !$row->payable) {
                continue;
            }
            if ($row->isReversal()) {
                $reversals[] = $row;
                continue;
            }
            if ($row->hasFixedSupersession()) {
                continue;
            }
            $candidates[$row->convId] = $row;
        }

        $superseded = [];

        // Rule 2: the newest upload batch replaces older batches and every
        // plain row recorded before it.
        $newestBatch = null;
        foreach ($candidates as $row) {
            if ($row->source === ConversionSource::REVENUE_UPLOAD && $row->batchId !== null
                && ($newestBatch === null || $row->batchId > $newestBatch)) {
                $newestBatch = $row->batchId;
            }
        }
        $batchRows = [];
        if ($newestBatch !== null) {
            foreach ($candidates as $row) {
                if ($row->source === ConversionSource::REVENUE_UPLOAD && $row->batchId === $newestBatch) {
                    $batchRows[$row->convId] = $row;
                }
            }
            $batchFirst = (int) array_key_first($batchRows);
            foreach ($candidates as $convId => $row) {
                if (isset($batchRows[$convId])) {
                    continue;
                }
                $olderBatch = $row->source === ConversionSource::REVENUE_UPLOAD;
                if ($olderBatch || $convId < $batchFirst) {
                    $superseded[$convId] = ['by' => $batchFirst, 'reason' => SupersededReason::BATCH];
                    unset($candidates[$convId]);
                }
            }
        }

        // Rule 3.
        if ($mode === PayoutMode::REPLACE && $candidates !== []) {
            $latestId = (int) array_key_last($candidates);
            if (isset($batchRows[$latestId])) {
                $winners = $batchRows;
                $winnerId = (int) array_key_first($batchRows);
            } else {
                $winners = [$latestId => $candidates[$latestId]];
                $winnerId = $latestId;
            }
            foreach ($candidates as $convId => $row) {
                if (!isset($winners[$convId])) {
                    $superseded[$convId] = ['by' => $winnerId, 'reason' => SupersededReason::REPLACE];
                }
            }
            $candidates = $winners;
        }

        // Rules 4 and 5.
        $counted = [];
        $value = 0;
        foreach ($candidates as $convId => $row) {
            $counted[$convId] = true;
            $value += $row->amountUnits;
        }
        $lead = $counted !== [];
        foreach (self::nettingReversals($rows, $reversals, $counted) as $reversal) {
            $counted[$reversal->convId] = true;
            $value += $reversal->amountUnits;
        }

        ksort($counted);
        ksort($superseded);

        return new ClickValue($lead, $lead ? $value : null, $superseded, $counted);
    }

    /**
     * The rows that count according to the supersession state as it is
     * stored, before a recompute rewrites it: the counted set the last
     * recompute left behind, by the same rules 1, 4 and 5 (a stored
     * supersession of either kind takes a row out, as the recompute that
     * wrote it decided).
     *
     * A reversal's counted state is never stored — it follows its target —
     * so the recompute compares this with ClickValue::$counted to find the
     * reversals whose state flipped, which no supersession diff can see.
     *
     * @param list<LedgerRow> $rows
     * @return array<int, true>
     */
    public static function countedAsStored(array $rows): array
    {
        $counted = [];
        $reversals = [];
        foreach ($rows as $row) {
            if ($row->deleted || !$row->payable) {
                continue;
            }
            if ($row->isReversal()) {
                $reversals[] = $row;
                continue;
            }
            if ($row->supersededReason === null) {
                $counted[$row->convId] = true;
            }
        }
        foreach (self::nettingReversals($rows, $reversals, $counted) as $reversal) {
            $counted[$reversal->convId] = true;
        }
        ksort($counted);

        return $counted;
    }

    /**
     * Rule 4: the reversals that net, given the non-reversal rows that count.
     *
     * @param list<LedgerRow> $rows
     * @param list<LedgerRow> $reversals Live, payable reversal rows.
     * @param array<int, true> $counted Counted non-reversal rows.
     * @return list<LedgerRow>
     */
    private static function nettingReversals(array $rows, array $reversals, array $counted): array
    {
        $byId = [];
        $baselineCounts = false;
        foreach ($rows as $row) {
            $byId[$row->convId] = $row;
            if ($row->source === ConversionSource::LEGACY_BASELINE && isset($counted[$row->convId])) {
                $baselineCounts = true;
            }
        }
        $netting = [];
        foreach ($reversals as $reversal) {
            $target = $byId[(int) $reversal->reversesConvId] ?? null;
            $nets = isset($counted[(int) $reversal->reversesConvId])
                || ($baselineCounts && $target !== null && !$target->deleted
                    && $target->supersededReason === SupersededReason::PRE_LEDGER);
            if ($nets) {
                $netting[] = $reversal;
            }
        }

        return $netting;
    }
}
