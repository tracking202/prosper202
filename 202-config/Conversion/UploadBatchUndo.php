<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Conversion\Ledger\ClickValue;
use Prosper202\Conversion\Ledger\ClickValueCalculator;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Conversion\Ledger\LedgerRow;
use Prosper202\Conversion\Ledger\MysqlConversionLedger;
use Prosper202\Conversion\Ledger\RowCurrency;
use Prosper202\Conversion\Ledger\SourceRef;
use Prosper202\Database\Connection;
use RuntimeException;

/**
 * Undo one revenue upload: every live ledger row its batch recorded is
 * soft-deleted, click by click, and each click is recomputed, so the value
 * the batch replaced (an earlier batch's, or a conversion's) counts again and
 * the reports read as they did before the upload.
 *
 * The rows are found by what the importer wrote on each one (source
 * revenue_upload, source_ref "batch:N"), never by the batch row's counts.
 * Each click is its own transaction (MysqlConversionRepository::
 * undoUploadBatchOnClick()), so an undo that stops part-way leaves every
 * click either undone or untouched, and BatchInterrupted says how many were.
 * Running it again removes what is left: the batch's undone_at is a log of
 * the last complete undo, not a gate.
 */
final class UploadBatchUndo
{
    /** The most rows, and the most clicks, one answer lists; the counts are exact. */
    public const LISTED = 1000;

    /** Passes over the batch's clicks before rows still arriving are reported. */
    private const MAX_PASSES = 5;

    public function __construct(
        private readonly Connection $conn,
        private readonly MysqlConversionRepository $conversions,
    ) {
    }

    /**
     * The batch row, or null when it is not the account's.
     *
     * @return array{batch_id: int, file_name: string, uploaded_at: int, line_count: int, recorded_count: int,
     *     skipped_count: int, undone_at: ?int, undone_rows: ?int}|null
     */
    public function batch(int $userId, int $batchId): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT batch_id, file_name, uploaded_at, line_count, recorded_count, skipped_count, undone_at, undone_rows
             FROM 202_conversion_uploads WHERE batch_id = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$batchId, $userId]);
        $row = $this->conn->fetchOne($stmt);

        return $row === null ? null : self::batchRow($row);
    }

    /**
     * The account's uploads, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function batches(int $userId, int $limit, ?int $beforeBatchId): array
    {
        $sql = 'SELECT batch_id, file_name, uploaded_at, line_count, recorded_count, skipped_count, undone_at, undone_rows
                FROM 202_conversion_uploads WHERE user_id = ?'
            . ($beforeBatchId !== null ? ' AND batch_id < ?' : '')
            . ' ORDER BY batch_id DESC LIMIT ?';
        $stmt = $this->conn->prepareWrite($sql);
        $this->conn->bind(
            $stmt,
            $beforeBatchId !== null ? 'iii' : 'ii',
            $beforeBatchId !== null ? [$userId, $beforeBatchId, $limit] : [$userId, $limit]
        );

        return array_map(self::batchRow(...), $this->conn->fetchAll($stmt));
    }

    /**
     * What undo() would change, writing nothing: the batch's live rows
     * (counted, summed per currency, the first LISTED listed with the CSV
     * line each came from and its currency), the reversals that would stop netting, the postbacks that would
     * be cancelled or retracted, and each click's value before and after.
     *
     * @return array<string, mixed>
     */
    public function preview(int $userId, int $batchId): array
    {
        $batch = $this->batch($userId, $batchId);
        if ($batch === null) {
            throw new UploadBatchNotFound($batchId);
        }
        $sourceRef = SourceRef::uploadBatch($batchId);
        $source = ConversionSource::REVENUE_UPLOAD->value;

        $stmt = $this->conn->prepareWrite(
            'SELECT COUNT(*) AS n, COUNT(DISTINCT click_id) AS clicks
             FROM 202_conversion_logs WHERE user_id = ? AND source = ? AND source_ref = ? AND deleted = 0'
        );
        $this->conn->bind($stmt, 'iss', [$userId, $source, $sourceRef]);
        $totals = $this->conn->fetchOne($stmt);
        if ($totals === null) {
            throw new RuntimeException('batch ' . $batchId . ': its rows could not be counted');
        }

        // Summed per currency: a row's amount is in the currency it recorded.
        $stmt = $this->conn->prepareWrite(
            'SELECT currency, SUM(click_payout) AS amount
             FROM 202_conversion_logs WHERE user_id = ? AND source = ? AND source_ref = ? AND deleted = 0
             GROUP BY currency'
        );
        $this->conn->bind($stmt, 'iss', [$userId, $source, $sourceRef]);
        $units = [];
        foreach ($this->conn->fetchAll($stmt) as $r) {
            $code = RowCurrency::read($r['currency']);
            $units[$code] = ($units[$code] ?? 0) + Amount::toUnits((string) $r['amount']);
        }
        ksort($units, SORT_STRING);
        $amounts = [];
        foreach ($units as $code => $sum) {
            $amounts[] = ['currency' => RowCurrency::forAnswer($code), 'amount' => Amount::fromUnits($sum)];
        }

        $stmt = $this->conn->prepareWrite(
            'SELECT COUNT(*) AS n FROM 202_conversion_logs r
             JOIN 202_conversion_logs b ON b.conv_id = r.reverses_conv_id AND b.user_id = r.user_id
             WHERE b.user_id = ? AND b.source = ? AND b.source_ref = ? AND b.deleted = 0 AND r.deleted = 0'
        );
        $this->conn->bind($stmt, 'iss', [$userId, $source, $sourceRef]);
        $reversals = $this->conn->fetchOne($stmt);

        // As ConversionsController::deletePreview() counts them for one row.
        $stmt = $this->conn->prepareWrite(
            "SELECT COALESCE(SUM(n.status = 'pending' AND n.attempts = 0), 0) AS cancel,
                    COALESCE(SUM(n.status <> 'cancelled' AND NOT (n.status = 'pending' AND n.attempts = 0)), 0) AS retract
             FROM 202_notification_pending n
             JOIN 202_conversion_logs b ON b.conv_id = n.conv_id AND b.user_id = n.user_id
             WHERE b.user_id = ? AND b.source = ? AND b.source_ref = ? AND b.deleted = 0 AND n.kind = 'reached'"
        );
        $this->conn->bind($stmt, 'iss', [$userId, $source, $sourceRef]);
        $postbacks = $this->conn->fetchOne($stmt);
        if ($reversals === null || $postbacks === null) {
            throw new RuntimeException('batch ' . $batchId . ': what its undo would settle could not be read');
        }

        $stmt = $this->conn->prepareWrite(
            'SELECT conv_id, click_id, click_payout, currency, dedupe_key FROM 202_conversion_logs
             WHERE user_id = ? AND source = ? AND source_ref = ? AND deleted = 0 ORDER BY conv_id LIMIT ?'
        );
        $this->conn->bind($stmt, 'issi', [$userId, $source, $sourceRef, self::LISTED]);
        $rows = [];
        foreach ($this->conn->fetchAll($stmt) as $r) {
            $rows[] = [
                'conv_id' => (int) $r['conv_id'],
                'click_id' => (int) $r['click_id'],
                'line' => self::lineOf((string) $r['dedupe_key'], $batchId),
                'payout' => Amount::fromUnits(Amount::toUnits((string) $r['click_payout'])),
                'currency' => RowCurrency::forAnswer($r['currency']),
            ];
        }

        $count = (int) $totals['n'];
        $clicks = (int) $totals['clicks'];

        return [
            'batch' => $batch,
            'would_remove' => $count,
            'amounts' => $amounts,
            'clicks' => $clicks,
            'reversals_stop_netting' => (int) $reversals['n'],
            'postbacks_cancelled' => (int) $postbacks['cancel'],
            'postbacks_retracted' => (int) $postbacks['retract'],
            'rows' => $rows,
            'rows_unlisted' => max(0, $count - count($rows)),
            'click_values' => $this->clickValues($userId, $batchId),
            'click_values_unlisted' => max(0, $clicks - self::LISTED),
        ];
    }

    /**
     * Undo the batch. Answers the rows removed and clicks changed, and the
     * batch row as it now stands.
     *
     * @return array{batch: array<string, mixed>, removed: int, clicks: int}
     * @throws UploadBatchNotFound when the batch is not the account's
     * @throws BatchInterrupted when a click's undo fails after others committed,
     *         or rows of the batch were still being written when the undo ended
     */
    public function undo(int $userId, int $batchId): array
    {
        // Read before any write: before the upgrade adds undone_at this
        // fails here, with nothing undone.
        if ($this->batch($userId, $batchId) === null) {
            throw new UploadBatchNotFound($batchId);
        }

        $removed = 0;
        $clicks = 0;
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $ids = $this->liveClicks($userId, $batchId);
            if ($ids === []) {
                $this->markUndone($userId, $batchId, $removed, $clicks);
                $batch = $this->batch($userId, $batchId);
                if ($batch === null) {
                    throw new RuntimeException('batch ' . $batchId . ' was undone and then could not be read');
                }

                return ['batch' => $batch, 'removed' => $removed, 'clicks' => $clicks];
            }
            foreach ($ids as $clickId) {
                try {
                    $n = $this->conversions->undoUploadBatchOnClick($userId, $batchId, $clickId);
                } catch (\Throwable $e) {
                    throw new BatchInterrupted('click ' . $clickId, $clicks, null, $e);
                }
                if ($n > 0) {
                    $removed += $n;
                    $clicks++;
                }
            }
        }

        throw new BatchInterrupted(
            'pass ' . self::MAX_PASSES,
            $clicks,
            null,
            new RuntimeException('rows of batch ' . $batchId . ' were still being recorded (is its import still running?);'
                . ' undo it again once the import has finished')
        );
    }

    /** @return list<int> */
    private function liveClicks(int $userId, int $batchId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT DISTINCT click_id FROM 202_conversion_logs
             WHERE user_id = ? AND source = ? AND source_ref = ? AND deleted = 0 ORDER BY click_id'
        );
        $this->conn->bind($stmt, 'iss', [$userId, ConversionSource::REVENUE_UPLOAD->value, SourceRef::uploadBatch($batchId)]);

        return array_map(static fn (array $r): int => (int) $r['click_id'], $this->conn->fetchAll($stmt));
    }

    /**
     * The log of the undo, kept with what this run removed: a later run that
     * finds nothing left keeps the count of the run that did the work.
     */
    private function markUndone(int $userId, int $batchId, int $removed, int $clicks): void
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_conversion_uploads
             SET undone_at = ?, undone_rows = IF(? > 0 OR undone_rows IS NULL, ?, undone_rows)
             WHERE batch_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'iiiii', [time(), $removed, $removed, $batchId, $userId]);
        try {
            $this->conn->executeUpdate($stmt);
        } catch (\Throwable $e) {
            if ($removed === 0) {
                throw $e;
            }
            // The rows are gone and stay gone; only the log was not written.
            throw new BatchInterrupted('logging the undo of batch ' . $batchId, $clicks, null, $e);
        }
    }

    /**
     * Each listed click's value now and once the batch's rows are deleted,
     * computed by the same rules the recompute applies, under no lock. A
     * click that retention has removed (its conversions outlive it) has no
     * value for a report to count: before and after are null.
     *
     * @return list<array{click_id: int, before: ?array{lead: bool, value: ?string}, after: ?array{lead: bool, value: ?string}}>
     */
    private function clickValues(int $userId, int $batchId): array
    {
        $ledger = new MysqlConversionLedger($this->conn);
        $stmt = $this->conn->prepareWrite(
            'SELECT b.click_id, c.aff_campaign_id FROM (
                 SELECT DISTINCT click_id FROM 202_conversion_logs
                 WHERE user_id = ? AND source = ? AND source_ref = ? AND deleted = 0
                 ORDER BY click_id LIMIT ?
             ) b LEFT JOIN 202_clicks c ON c.click_id = b.click_id AND c.user_id = ?
             ORDER BY b.click_id'
        );
        $this->conn->bind($stmt, 'issii', [$userId, ConversionSource::REVENUE_UPLOAD->value, SourceRef::uploadBatch($batchId), self::LISTED, $userId]);

        $modes = [];
        $values = [];
        foreach ($this->conn->fetchAll($stmt) as $r) {
            $clickId = (int) $r['click_id'];
            if ($r['aff_campaign_id'] === null) {
                $values[] = ['click_id' => $clickId, 'before' => null, 'after' => null];
                continue;
            }
            $campaignId = (int) $r['aff_campaign_id'];
            $modes[$campaignId] ??= $ledger->campaignTerms($campaignId, $userId)['mode'];
            $rows = $ledger->loadRows($clickId);
            $after = array_map(
                static fn (LedgerRow $row): LedgerRow => $row->source === ConversionSource::REVENUE_UPLOAD && $row->batchId === $batchId && !$row->deleted
                    ? new LedgerRow($row->convId, $row->amountUnits, $row->payable, true, $row->reversesConvId, $row->source,
                        $row->batchId, $row->supersededReason, $row->supersededBy)
                    : $row,
                $rows
            );
            $values[] = [
                'click_id' => $clickId,
                'before' => self::value(ClickValueCalculator::calculate($rows, $modes[$campaignId])),
                'after' => self::value(ClickValueCalculator::calculate($after, $modes[$campaignId])),
            ];
        }

        return $values;
    }

    /** @return array{lead: bool, value: ?string} value: what the reports count for the click; null when it is no lead */
    private static function value(ClickValue $v): array
    {
        return ['lead' => $v->lead, 'value' => $v->lead ? Amount::fromUnits((int) $v->valueUnits) : null];
    }

    /** The CSV line a row came from, read from its dedupe key ("up:<batch>:<line>"). */
    private static function lineOf(string $dedupeKey, int $batchId): ?int
    {
        return preg_match('/\Aup:' . $batchId . ':([1-9][0-9]{0,9})\z/', $dedupeKey, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{batch_id: int, file_name: string, uploaded_at: int, line_count: int, recorded_count: int,
     *     skipped_count: int, undone_at: ?int, undone_rows: ?int}
     */
    private static function batchRow(array $row): array
    {
        return [
            'batch_id' => (int) $row['batch_id'],
            'file_name' => (string) $row['file_name'],
            'uploaded_at' => (int) $row['uploaded_at'],
            'line_count' => (int) $row['line_count'],
            'recorded_count' => (int) $row['recorded_count'],
            'skipped_count' => (int) $row['skipped_count'],
            'undone_at' => $row['undone_at'] !== null ? (int) $row['undone_at'] : null,
            'undone_rows' => $row['undone_rows'] !== null ? (int) $row['undone_rows'] : null,
        ];
    }
}
