<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

use Prosper202\Click\LatestVisit;
use Prosper202\Database\Connection;

/**
 * The database half of the conversion ledger: the only code that writes a
 * click's `click_lead` and `click_payout` once the click exists.
 *
 * Every method here must run inside the transaction that holds the click's
 * lock (lockClick(), or clickTransaction()), taken before the transaction's
 * first plain read. The callers are MysqlConversionRepository::record()
 * and ::softDelete() and the bulk clear below; ClickValueWritersTest fails
 * if any other code updates those two columns, which is what keeps the
 * cached value equal to its rows.
 */
final class MysqlConversionLedger
{
    public function __construct(private Connection $conn)
    {
    }

    /**
     * Lock a click for a ledger write, then read the visit a conversion
     * credits: its latest (LatestVisit). Null when there is no such click
     * (of that account, when $userId is given: a click whose latest visit is
     * another account's row is not this account's, as every other read of
     * the latest visit ties it). click_lead is whether any visit of it is a
     * lead, as LatestVisit::anyVisitIsLead() asks it.
     *
     * The lock is the click's first row in the click_id index, the row every
     * ledger writer locks, so they serialise on it, as does an UPDATE of the
     * click's rows by click_id.
     *
     * The lock is taken before the transaction's first plain read, and a
     * lock asked for after one is refused. Under REPEATABLE READ a plain read
     * answers from the snapshot the transaction's first plain read took, not
     * from the lock (CLAUDE.md #36): a delete that read the conversion to find
     * its click, then waited on the lock for a sale to commit, recomputed the
     * click without the sale, left a reversal recorded in the wait unvoided
     * and inserted a second legacy baseline. Locked first, every plain read
     * after sees what committed before the lock was granted, and nothing that
     * writes the click's ledger rows commits while it is held. A click the
     * transaction has already locked is read again without a second lock.
     *
     * @return array<string, mixed>|null click_id, user_id, aff_campaign_id, click_payout, click_time, click_lead
     * @throws \LogicException when the transaction read before this lock
     */
    public function lockClick(int $clickId, ?int $userId = null): ?array
    {
        $lock = 'click:' . $clickId;
        if (!$this->conn->holdsLock($lock)) {
            if ($this->conn->snapshotTaken()) {
                throw new \LogicException(
                    'Click ' . $clickId . '\'s lock was asked for after its transaction read, and nothing was locked:'
                        . ' what the transaction reads of the click would be its snapshot from before the lock'
                        . ' (CLAUDE.md #36). Lock the click first in the transaction'
                        . ' (MysqlConversionLedger::clickTransaction()), or find what names it before the transaction'
                        . ' begins.'
                );
            }
            if (!$this->lockClickRow($clickId, $userId)) {
                return null;
            }
            $this->conn->holdLock($lock);
        }

        $read = $this->conn->prepareWrite(self::latestVisitSql());
        $this->conn->bind($read, 'i', [$clickId]);

        return $this->ownedVisit($clickId, $userId, $this->conn->fetchOne($read));
    }

    /** lockClick()'s read of the click's (?) latest visit, after the lock. */
    public static function latestVisitSql(): string
    {
        return 'SELECT c.click_id, c.user_id, c.aff_campaign_id, c.click_payout, c.click_time, '
            . LatestVisit::anyVisitIsLead('c') . ' AS click_lead FROM ' . LatestVisit::of('?') . ' AS c';
    }

    /**
     * Run $fn in a transaction whose first statement locks the click, handing
     * it what lockClick() returns.
     *
     * @template T
     * @param callable(array<string, mixed>|null): T $fn
     * @return T
     */
    public function clickTransaction(int $clickId, ?int $userId, callable $fn): mixed
    {
        return $this->conn->transaction(fn (): mixed => $fn($this->lockClick($clickId, $userId)));
    }

    private function lockClickRow(int $clickId, ?int $userId): bool
    {
        $lock = $this->conn->prepareWrite(
            'SELECT click_id FROM 202_clicks WHERE click_id = ?' . ($userId !== null ? ' AND user_id = ?' : '')
            . ' LIMIT 1 FOR UPDATE'
        );
        $this->conn->bind($lock, $userId !== null ? 'ii' : 'i', $userId !== null ? [$clickId, $userId] : [$clickId]);

        return $this->conn->fetchOne($lock) !== null;
    }

    /**
     * @param array<string, mixed>|null $click the locked click's latest visit
     * @return array<string, mixed>|null
     */
    private function ownedVisit(int $clickId, ?int $userId, ?array $click): ?array
    {
        if ($click === null) {
            throw new LedgerIntegrityException('click ' . $clickId . ' is locked, and no visit of it was read');
        }
        if ($userId !== null && self::owner($clickId, $click) !== $userId) {
            return null;
        }

        return $click;
    }

    /**
     * @param array<string, mixed> $click
     */
    private static function owner(int $clickId, array $click): int
    {
        $owner = $click['user_id'] ?? null;
        if (!is_int($owner) && !(is_string($owner) && preg_match('/\A\d+\z/', $owner) === 1)) {
            throw new LedgerIntegrityException('click ' . $clickId . '\'s user_id read as ' . get_debug_type($owner));
        }

        return (int) $owner;
    }

    /**
     * The campaign's payout mode and default payout for a click's campaign.
     *
     * A click whose campaign row is gone keeps the behaviour every campaign
     * had before the ledger (REPLACE). So does a click that names another
     * account's campaign: $userId is the click's owner, and nothing stopped
     * a tracker naming another account's campaign before the API checked
     * linked ids (229df10), so such a click must not be valued by that
     * account's payout or payout mode. A campaign row whose payout_mode is
     * neither value throws, naming it.
     *
     * @return array{mode: PayoutMode, default_payout: string}
     */
    public function campaignTerms(int $campaignId, int $userId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT payout_mode, aff_campaign_payout FROM 202_aff_campaigns WHERE aff_campaign_id = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$campaignId, $userId]);
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
     * The value is the one on the click's latest visit that is a lead, which
     * need not be its latest visit: a re-click written before 1.9.79 stored
     * its row as no lead.
     * The caller holds the click's lock (lockClick()).
     *
     * @return int|null The baseline's conv_id when one was inserted.
     */
    public function ensureManaged(int $clickId, int $userId): ?int
    {
        $leadVisit = $this->conn->prepareWrite(
            'SELECT c.aff_campaign_id, c.click_payout, c.click_time FROM 202_clicks AS c'
            . ' LEFT JOIN 202_clicks_rotator AS r ON r.click_id = c.click_id'
            . ' WHERE c.click_id = ? AND c.click_lead = 1 ' . LatestVisit::orderBy('c', 'r') . ' LIMIT 1'
        );
        $this->conn->bind($leadVisit, 'i', [$clickId]);
        $click = $this->conn->fetchOne($leadVisit);
        if ($click === null) {
            return null;
        }

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
        [$insert, $named] = MatchMethod::prepareInsert(
            $this->conn,
            "INSERT INTO 202_conversion_logs
                (click_id, transaction_id, campaign_id, click_payout, user_id, click_time, conv_time,
                 time_difference, ip, pixel_type, user_agent, deleted,
                 source, source_ref, event_name, payable, superseded_by, superseded_reason, reverses_conv_id, dedupe_key,
                 match_method)
             VALUES (?, NULL, ?, ?, ?, ?, ?, '', '', 0, '', 0, ?, NULL, NULL, 1, NULL, NULL, NULL, ?, ?)",
            "INSERT INTO 202_conversion_logs
                (click_id, transaction_id, campaign_id, click_payout, user_id, click_time, conv_time,
                 time_difference, ip, pixel_type, user_agent, deleted,
                 source, source_ref, event_name, payable, superseded_by, superseded_reason, reverses_conv_id, dedupe_key)
             VALUES (?, NULL, ?, ?, ?, ?, ?, '', '', 0, '', 0, ?, NULL, NULL, 1, NULL, NULL, NULL, ?)"
        );
        // The value stands for pre-ledger rows, whose clicks were found by
        // ways nothing recorded.
        $row = [
            $clickId,
            (int) $click['aff_campaign_id'],
            Amount::fromUnits(Amount::toUnits((string) $click['click_payout'])),
            $userId,
            $clickTime,
            $clickTime,
            ConversionSource::LEGACY_BASELINE->value,
            DedupeKey::legacy(),
        ];
        if ($named) {
            $row[] = MatchMethod::UNKNOWN->value;
        }
        $this->conn->bind($insert, $named ? 'iisiiisss' : 'iisiiiss', $row);
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
     * spy tables. Rows whose counted state changed are queued for MTA —
     * reversals included, though a reversal carries no supersession of its
     * own: it stops netting when its sale is superseded, and starts again
     * when the sale counts again, without any column of its own changing.
     *
     * $userId is the click's owner: the campaign's payout mode applies only
     * when the campaign is that account's (campaignTerms()).
     */
    public function recompute(int $clickId, int $campaignId, int $userId): ClickValue
    {
        $terms = $this->campaignTerms($campaignId, $userId);
        $rows = $this->loadRows($clickId);

        $countedBefore = ClickValueCalculator::countedAsStored($rows);
        $value = ClickValueCalculator::calculate($rows, $terms['mode']);

        // Every row whose counted state flips, whether or not its own
        // supersession column changes (a reversal's never does).
        $changed = [];
        foreach (array_keys($countedBefore + $value->counted) as $convId) {
            if (isset($countedBefore[$convId]) !== isset($value->counted[$convId])) {
                $changed[] = (int) $convId;
            }
        }
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
     * Mark a goal's ledger row superseded by the goals engine: a replay moved
     * its outcome to another event (REPLAY), or a re-evaluation under a newer
     * version replaced it (REEVALUATION). `$byConvId` is the row that replaced
     * it, or null when the outcome was retired with no replacement. These are
     * fixed reasons (SupersededReason): the recompute never clears them, and
     * a superseded row never counts toward the click in either payout mode.
     * MysqlConversionRepository::reviveGoalRowInTransaction() lifts the mark
     * when the engine returns to exactly this outcome, and undoes the
     * engine's other retirement (retireGoalRowInTransaction(), a deletion)
     * the same way.
     *
     * Locks the click, writes the mark, recomputes the click and queues the
     * row for MTA — inside the caller's transaction, like every method here.
     * It reads the row before it locks, so it works only for a caller that
     * locked the row's click first (the goals engine's current() does);
     * otherwise lockClick() refuses it.
     */
    public function supersedeGoalRow(int $convId, ?int $byConvId, SupersededReason $reason): void
    {
        if ($reason !== SupersededReason::REPLAY && $reason !== SupersededReason::REEVALUATION) {
            throw new LedgerIntegrityException('a goal row is superseded by a replay or a re-evaluation, not by "' . $reason->value . '"');
        }
        $click = $this->lockGoalRowsClick($convId);

        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_conversion_logs SET superseded_by = ?, superseded_reason = ? WHERE conv_id = ?'
        );
        $this->conn->bind($stmt, 'isi', [$byConvId, $reason->value, $convId]);
        $this->conn->executeUpdate($stmt);

        $this->recompute($click['click_id'], $click['campaign_id'], $click['user_id']);
        $this->enqueue([$convId], 'counted_state');
    }

    /**
     * The click of a goal row, locked (click before conversion, as on every
     * path that writes both).
     *
     * @return array{click_id: int, campaign_id: int, user_id: int}
     */
    private function lockGoalRowsClick(int $convId): array
    {
        $find = $this->conn->prepareWrite('SELECT click_id, source FROM 202_conversion_logs WHERE conv_id = ? LIMIT 1');
        $this->conn->bind($find, 'i', [$convId]);
        $row = $this->conn->fetchOne($find);
        if ($row === null) {
            throw new LedgerIntegrityException('conversion ' . $convId . ' does not exist');
        }
        if ((string) $row['source'] !== ConversionSource::GOAL->value) {
            throw new LedgerIntegrityException('conversion ' . $convId . ' is a ' . (string) $row['source'] . ' row, not a goal row');
        }
        $click = $this->lockClick((int) $row['click_id']);
        if ($click === null) {
            throw new LedgerIntegrityException('conversion ' . $convId . ' names click ' . (int) $row['click_id'] . ', which does not exist');
        }

        return ['click_id' => (int) $click['click_id'], 'campaign_id' => (int) $click['aff_campaign_id'], 'user_id' => (int) $click['user_id']];
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
