<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

use Prosper202\Database\Connection;

/**
 * The database half of the conversion ledger: the only code that writes a
 * click's `click_lead` and `click_payout` once the click exists.
 *
 * Every method here must run inside the transaction that holds the click's
 * row lock (`SELECT ... FOR UPDATE` on 202_clicks). The callers are
 * MysqlConversionRepository::record() and ::softDelete() and the bulk clear
 * below; ClickValueWritersTest fails if any other code updates those two
 * columns, which is what keeps the cached value equal to its rows.
 */
final class MysqlConversionLedger
{
    public function __construct(private Connection $conn)
    {
    }

    /**
     * The campaign's payout mode and default payout for a click's campaign.
     *
     * A click whose campaign row is gone keeps the behaviour every campaign
     * had before the ledger (REPLACE). A campaign row whose payout_mode is
     * neither value throws, naming it.
     *
     * @return array{mode: PayoutMode, default_payout: string}
     */
    public function campaignTerms(int $campaignId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT payout_mode, aff_campaign_payout FROM 202_aff_campaigns WHERE aff_campaign_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$campaignId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return ['mode' => PayoutMode::REPLACE, 'default_payout' => '0'];
        }

        try {
            $mode = PayoutMode::fromStored($row['payout_mode'] ?? null);
        } catch (\UnexpectedValueException $e) {
            throw new LedgerIntegrityException('campaign ' . $campaignId . ': ' . $e->getMessage(), 0, $e);
        }

        return ['mode' => $mode, 'default_payout' => (string) ($row['aff_campaign_payout'] ?? '0')];
    }

    /**
     * Carry a pre-ledger click's value into the ledger before anything else
     * changes it.
     *
     * The upgrade marks every row that existed before the ledger PRE_LEDGER:
     * the value a click shows was set by writes that left no row (revenue
     * uploads) or overwrote each other, so those rows cannot be re-added into
     * it. A click that is still a lead with no ledger-managed row therefore
     * holds a value only its cache knows. The first ledger write on such a
     * click inserts that value as a `legacy_baseline` row, so the recompute
     * preserves the income and the breakdown shows where it came from. A
     * pre-ledger click that is not a lead needs nothing: its old rows are
     * already out of the count, so a conversion cleared before the upgrade
     * cannot come back.
     *
     * @param array{click_id: int|string, user_id?: int|string, aff_campaign_id: int|string, click_payout: int|float|string, click_lead: int|string, click_time: int|string} $click
     *        The click row, read under its lock.
     * @return int|null The baseline's conv_id when one was inserted.
     */
    public function ensureManaged(array $click, int $userId): ?int
    {
        if ((int) $click['click_lead'] !== 1) {
            return null;
        }
        $clickId = (int) $click['click_id'];

        $stmt = $this->conn->prepareWrite(
            "SELECT 1 FROM 202_conversion_logs
             WHERE click_id = ? AND (superseded_reason IS NULL OR superseded_reason <> 'pre_ledger')
             LIMIT 1"
        );
        $this->conn->bind($stmt, 'i', [$clickId]);
        if ($this->conn->fetchOne($stmt) !== null) {
            return null;
        }

        $clickTime = (int) $click['click_time'];
        $insert = $this->conn->prepareWrite(
            "INSERT INTO 202_conversion_logs
                (click_id, transaction_id, campaign_id, click_payout, user_id, click_time, conv_time,
                 time_difference, ip, pixel_type, user_agent, deleted,
                 source, source_ref, event_name, payable, superseded_by, superseded_reason, reverses_conv_id, dedupe_key)
             VALUES (?, NULL, ?, ?, ?, ?, ?, '', '', 0, '', 0, ?, NULL, NULL, 1, NULL, NULL, NULL, ?)"
        );
        $this->conn->bind($insert, 'iisiiiss', [
            $clickId,
            (int) $click['aff_campaign_id'],
            Amount::fromUnits(Amount::toUnits((string) $click['click_payout'])),
            $userId,
            $clickTime,
            $clickTime,
            ConversionSource::LEGACY_BASELINE->value,
            DedupeKey::legacy(),
        ]);
        $baselineId = $this->conn->executeInsert($insert);
        if ($baselineId <= 0) {
            throw new LedgerIntegrityException('click ' . $clickId . ': the legacy baseline row was not inserted');
        }

        // Point the pre-ledger rows at the value that now stands for them,
        // so the breakdown can say what replaced them.
        $link = $this->conn->prepareWrite(
            "UPDATE 202_conversion_logs SET superseded_by = ?
             WHERE click_id = ? AND superseded_reason = 'pre_ledger' AND superseded_by IS NULL"
        );
        $this->conn->bind($link, 'ii', [$baselineId, $clickId]);
        $this->conn->executeUpdate($link);

        return $baselineId;
    }

    /**
     * Recompute the click's lead flag and value from its rows and write them,
     * with every derived supersession, to the ledger and to the click and
     * spy tables. Rows whose counted state changed are queued for MTA.
     */
    public function recompute(int $clickId, int $campaignId): ClickValue
    {
        $terms = $this->campaignTerms($campaignId);
        $rows = $this->loadRows($clickId);

        $value = ClickValueCalculator::calculate($rows, $terms['mode']);

        $changed = [];
        foreach ($rows as $row) {
            if ($row->hasFixedSupersession()) {
                continue;
            }
            $want = $value->derivedSupersessions[$row->convId] ?? null;
            $haveReason = $row->supersededReason;
            $same = $want === null
                ? $haveReason === null && $row->supersededBy === null
                : $haveReason === $want['reason'] && $row->supersededBy === $want['by'];
            if ($same) {
                continue;
            }

            $stmt = $this->conn->prepareWrite(
                'UPDATE 202_conversion_logs SET superseded_by = ?, superseded_reason = ? WHERE conv_id = ?'
            );
            $this->conn->bind($stmt, 'isi', [
                $want['by'] ?? null,
                $want !== null ? $want['reason']->value : null,
                $row->convId,
            ]);
            $this->conn->executeUpdate($stmt);
            $changed[] = $row->convId;
        }

        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            if ($value->lead) {
                $stmt = $this->conn->prepareWrite(
                    'UPDATE ' . $table . ' SET click_lead = 1, click_payout = ? WHERE click_id = ?'
                );
                $this->conn->bind($stmt, 'si', [Amount::fromUnits((int) $value->valueUnits), $clickId]);
            } else {
                $stmt = $this->conn->prepareWrite(
                    'UPDATE ' . $table . ' SET click_lead = 0 WHERE click_id = ?'
                );
                $this->conn->bind($stmt, 'i', [$clickId]);
            }
            $this->conn->executeUpdate($stmt);
        }

        if ($changed !== []) {
            $this->enqueue($changed, 'counted_state');
        }

        return $value;
    }

    /**
     * Queue conversions for the MTA worker. The outbox row is written in the
     * caller's transaction, so a conversion and its pending row commit or
     * roll back together. Re-queuing a row that is already pending bumps its
     * sequence number, which is how the worker knows the row changed again
     * while it was being processed and must not be deleted. It also clears
     * the worker's failure state: the change may be what fixes the row.
     *
     * @param list<int> $convIds
     */
    public function enqueue(array $convIds, string $reason): void
    {
        $now = time();
        foreach (array_unique($convIds) as $convId) {
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_attribution_pending (conv_id, enqueued_at, reason, enqueue_seq)
                 VALUES (?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE enqueued_at = VALUES(enqueued_at), reason = VALUES(reason),
                    enqueue_seq = enqueue_seq + 1, attempts = 0, last_error = NULL, retry_at = 0'
            );
            $this->conn->bind($stmt, 'iis', [(int) $convId, $now, $reason]);
            $this->conn->executeUpdate($stmt);
        }
    }

    /**
     * @return list<LedgerRow>
     */
    public function loadRows(int $clickId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT conv_id, click_payout, payable, deleted, reverses_conv_id, source, source_ref,
                    superseded_reason, superseded_by
             FROM 202_conversion_logs WHERE click_id = ? ORDER BY conv_id'
        );
        $this->conn->bind($stmt, 'i', [$clickId]);

        $rows = [];
        foreach ($this->conn->fetchAll($stmt) as $r) {
            $rows[] = self::rowFrom($r);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $r
     */
    public static function rowFrom(array $r): LedgerRow
    {
        $convId = (int) $r['conv_id'];
        $source = ConversionSource::tryFrom((string) ($r['source'] ?? ''));
        if ($source === null) {
            throw new LedgerIntegrityException(
                'conversion ' . $convId . ' has source "' . (string) ($r['source'] ?? '') . '", which is not a ledger source'
            );
        }

        $reason = null;
        if ($r['superseded_reason'] !== null && $r['superseded_reason'] !== '') {
            $reason = SupersededReason::tryFrom((string) $r['superseded_reason']);
            if ($reason === null) {
                throw new LedgerIntegrityException(
                    'conversion ' . $convId . ' has superseded_reason "' . (string) $r['superseded_reason'] . '", which is not a known reason'
                );
            }
        }

        $batchId = null;
        if ($source === ConversionSource::REVENUE_UPLOAD) {
            if (preg_match('/^batch:([1-9][0-9]{0,18})$/D', (string) ($r['source_ref'] ?? ''), $m) !== 1) {
                throw new LedgerIntegrityException(
                    'conversion ' . $convId . ' is a revenue upload row whose source_ref "'
                    . (string) ($r['source_ref'] ?? '') . '" names no upload batch'
                );
            }
            $batchId = (int) $m[1];
        }

        try {
            $amount = Amount::toUnits((string) $r['click_payout']);
        } catch (\InvalidArgumentException $e) {
            throw new LedgerIntegrityException('conversion ' . $convId . ': ' . $e->getMessage(), 0, $e);
        }

        return new LedgerRow(
            convId: $convId,
            amountUnits: $amount,
            payable: (int) $r['payable'] === 1,
            deleted: (int) $r['deleted'] === 1,
            reversesConvId: $r['reverses_conv_id'] !== null ? (int) $r['reverses_conv_id'] : null,
            source: $source,
            batchId: $batchId,
            supersededReason: $reason,
            supersededBy: $r['superseded_by'] !== null ? (int) $r['superseded_by'] : null,
        );
    }
}
