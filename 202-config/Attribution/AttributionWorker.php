<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;
use Throwable;

/**
 * The attribution worker (plan §6.3): drains the outbox the conversion
 * ledger writes, and turns each conversion into a journey and one set of
 * credits per active model.
 *
 * One run does three things, in this order:
 *
 * 1. Identity merges. Every merge with requeued_at NULL re-queues the
 *    conversions on the merged person's clicks (reason `identity_merge`),
 *    then sets requeued_at, in one transaction per merge. The request that
 *    merged did only the merge; the fan-out happens here so a merge of two
 *    busy keys never slows a click.
 * 2. Model changes. A model whose recompute_requested_at is set re-queues
 *    the account's attributed conversions from its cursor in batches:
 *    `rebuild_journey` for a journey built narrower than the account's
 *    widest lookback now needs, `model_changed` for the rest.
 * 3. The outbox. Pending rows are processed oldest first. Each conversion is
 *    handled in its own transaction, which rewrites its journey and credits
 *    and deletes the pending row only if its enqueue_seq is still the one
 *    that was read — a row re-queued while it was being processed stays
 *    queued and is processed again.
 *
 * Failure isolation. An exception from one conversion (a ledger row the
 * integrity checks refuse, an amount out of range) is recorded on that
 * pending row with a backoff and the run moves on: one malformed row delays
 * only itself. A database error (QueryException: a missing table, a lost
 * connection, a deadlock) is the engine's failure, not the row's, so it
 * ends the run with every row left as it was; the next run retries them
 * immediately rather than after a backoff they did not earn.
 *
 * Overlap protection is the caller's (runExclusive takes a MySQL named
 * lock): one worker at a time per database.
 */
final class AttributionWorker
{
    public const LOCK_NAME = 'p202_attribution_worker';
    /** The journey lookback never goes below the default model lookback. */
    public const MIN_JOURNEY_LOOKBACK_DAYS = ModelConfig::DEFAULT_LOOKBACK_DAYS;
    public const REASON_RECORDED = 'recorded';
    public const REASON_COUNTED_STATE = 'counted_state';
    public const REASON_IDENTITY_MERGE = 'identity_merge';
    public const REASON_REBUILD_JOURNEY = 'rebuild_journey';
    public const REASON_MODEL_CHANGED = 'model_changed';
    private const MAX_BACKOFF = 86400;
    private const FAN_OUT_BATCH = 1000;

    private ModelRepository $models;
    private JourneyBuilder $journeys;
    private AttributionStore $store;
    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(private Connection $conn, ?callable $clock = null)
    {
        $this->models = new ModelRepository($conn);
        $this->journeys = new JourneyBuilder($conn);
        $this->store = new AttributionStore($conn);
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Run under the worker lock. Returns null when another worker holds it.
     */
    public static function runExclusive(Connection $conn, int $timeBudgetSeconds = 50, int $batchSize = 200): ?WorkerReport
    {
        $got = $conn->fetchOne(self::lockStatement($conn, 'SELECT GET_LOCK(?, 0) AS got'));
        if ($got === null || $got['got'] === null) {
            throw new \RuntimeException('GET_LOCK failed for ' . self::LOCK_NAME);
        }
        if ((int) $got['got'] !== 1) {
            return null;
        }
        try {
            return (new self($conn))->run($timeBudgetSeconds, $batchSize);
        } finally {
            $conn->fetchOne(self::lockStatement($conn, 'SELECT RELEASE_LOCK(?) AS released'));
        }
    }

    /** @return \mysqli_stmt */
    private static function lockStatement(Connection $conn, string $sql)
    {
        $stmt = $conn->prepareWrite($sql);
        $conn->bind($stmt, 's', [self::LOCK_NAME]);

        return $stmt;
    }

    public function run(int $timeBudgetSeconds = 50, int $batchSize = 200): WorkerReport
    {
        $report = new WorkerReport();
        $deadline = ($this->clock)() + max(1, $timeBudgetSeconds);

        $report->mergesRequeued = $this->requeueMerges();
        $report->modelsFannedOut = $this->fanOutModelRecomputes();

        while (($this->clock)() < $deadline) {
            $batch = $this->claim($batchSize);
            if ($batch === []) {
                break;
            }
            $progressed = false;
            foreach ($batch as $pending) {
                if (($this->clock)() >= $deadline) {
                    break;
                }
                $outcome = $this->processPending($pending);
                $report->count($outcome);
                $progressed = $progressed || $outcome !== 'requeued';
            }
            if (!$progressed || count($batch) < $batchSize) {
                break;
            }
        }

        $report->remaining = $this->dueCount();

        return $report;
    }

    /**
     * Re-queue the conversions every unprocessed identity merge can change.
     *
     * The set is every conversion on the merged person's clicks — a superset
     * of the ones whose window overlaps a click from the other side, which
     * the plan describes. Recomputing a conversion whose journey did not
     * change rewrites the same rows (the worker is idempotent), and the
     * person's conversion count is bounded by the quarantine cap on how many
     * keys one signal can join, so the superset is cheap and cannot miss.
     */
    public function requeueMerges(int $limit = 100): int
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT merge_id, user_id, into_key FROM 202_identity_merges WHERE requeued_at IS NULL ORDER BY merge_id LIMIT ?'
        );
        $this->conn->bind($stmt, 'i', [$limit]);
        $merges = $this->conn->fetchAll($stmt);

        $done = 0;
        foreach ($merges as $merge) {
            $this->conn->transaction(function () use ($merge): void {
                $userId = (int) $merge['user_id'];
                $keys = $this->journeys->keysOfPerson($userId, (int) $merge['into_key']);
                $convIds = $keys === [] ? [] : $this->conversionsOnKeys($userId, $keys);
                $this->enqueue($convIds, self::REASON_IDENTITY_MERGE, true);

                $upd = $this->conn->prepareWrite(
                    'UPDATE 202_identity_merges SET requeued_at = ? WHERE merge_id = ? AND requeued_at IS NULL'
                );
                $this->conn->bind($upd, 'ii', [($this->clock)(), (int) $merge['merge_id']]);
                $this->conn->executeUpdate($upd);
            });
            $done++;
        }

        return $done;
    }

    /**
     * @param list<int> $keys
     * @return list<int>
     */
    private function conversionsOnKeys(int $userId, array $keys): array
    {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->conn->prepareWrite(
            "SELECT DISTINCT cl.conv_id
             FROM 202_clicks_visitor cv
             JOIN 202_conversion_logs cl ON cl.click_id = cv.click_id
             WHERE cv.user_id = ? AND cv.visitor_key IN ($placeholders)
               AND cl.user_id = ? AND cl.reverses_conv_id IS NULL
             ORDER BY cl.conv_id"
        );
        $this->conn->bind($stmt, 'i' . str_repeat('i', count($keys)) . 'i', array_merge([$userId], $keys, [$userId]));

        return array_map(static fn (array $r): int => (int) $r['conv_id'], $this->conn->fetchAll($stmt));
    }

    /**
     * Fan a model change out over the account's attributed conversions,
     * one batch per model per run, from the model's cursor.
     */
    public function fanOutModelRecomputes(int $limit = 10): int
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT model_id, user_id, recompute_requested_at, recompute_cursor
             FROM 202_attribution_models WHERE recompute_requested_at IS NOT NULL
             ORDER BY recompute_requested_at, model_id LIMIT ?'
        );
        $this->conn->bind($stmt, 'i', [$limit]);
        $requests = $this->conn->fetchAll($stmt);

        foreach ($requests as $req) {
            $this->conn->transaction(function () use ($req): void {
                $userId = (int) $req['user_id'];
                $modelId = (int) $req['model_id'];
                $requested = (int) $req['recompute_requested_at'];
                $cursor = (int) $req['recompute_cursor'];
                $needed = $this->journeyLookbackDays($this->models->activeModels($userId));

                $stmt = $this->conn->prepareWrite(
                    'SELECT conv_id, built_lookback_days FROM 202_attribution_journey_meta
                     WHERE user_id = ? AND conv_id > ? ORDER BY conv_id LIMIT ?'
                );
                $this->conn->bind($stmt, 'iii', [$userId, $cursor, self::FAN_OUT_BATCH]);
                $rows = $this->conn->fetchAll($stmt);

                $rebuild = [];
                $recompute = [];
                foreach ($rows as $row) {
                    if ((int) $row['built_lookback_days'] < $needed) {
                        $rebuild[] = (int) $row['conv_id'];
                    } else {
                        $recompute[] = (int) $row['conv_id'];
                    }
                }
                $this->enqueue($rebuild, self::REASON_REBUILD_JOURNEY, true);
                $this->enqueue($recompute, self::REASON_MODEL_CHANGED, false);

                // Compare-and-set on the request time: an edit that arrived
                // meanwhile reset the cursor and must restart the fan-out.
                if (count($rows) < self::FAN_OUT_BATCH) {
                    $upd = $this->conn->prepareWrite(
                        'UPDATE 202_attribution_models SET recompute_requested_at = NULL, recompute_cursor = 0
                         WHERE model_id = ? AND recompute_requested_at = ?'
                    );
                    $this->conn->bind($upd, 'ii', [$modelId, $requested]);
                } else {
                    $last = (int) $rows[count($rows) - 1]['conv_id'];
                    $upd = $this->conn->prepareWrite(
                        'UPDATE 202_attribution_models SET recompute_cursor = ?
                         WHERE model_id = ? AND recompute_requested_at = ?'
                    );
                    $this->conn->bind($upd, 'iii', [$last, $modelId, $requested]);
                }
                $this->conn->executeUpdate($upd);
            });
        }

        return count($requests);
    }

    /**
     * Queue conversions. A strong reason (anything that can change the
     * journey) overwrites the pending reason and bumps the sequence; the
     * weak `model_changed` never downgrades a row already queued for a
     * reason that needs a rebuild, and never disturbs one being processed.
     *
     * @param list<int> $convIds
     */
    public function enqueue(array $convIds, string $reason, bool $strong): void
    {
        $now = ($this->clock)();
        foreach (array_chunk(array_values(array_unique($convIds)), 500) as $chunk) {
            $rows = [];
            $values = [];
            foreach ($chunk as $convId) {
                $rows[] = '(?, ?, ?, 1)';
                array_push($values, $convId, $now, $reason);
            }
            $sql = 'INSERT INTO 202_attribution_pending (conv_id, enqueued_at, reason, enqueue_seq) VALUES '
                . implode(', ', $rows)
                . ($strong
                    ? ' ON DUPLICATE KEY UPDATE enqueued_at = VALUES(enqueued_at), reason = VALUES(reason),
                        enqueue_seq = enqueue_seq + 1, attempts = 0, last_error = NULL, retry_at = 0'
                    : ' ON DUPLICATE KEY UPDATE conv_id = conv_id');
            $stmt = $this->conn->prepareWrite($sql);
            $this->conn->bind($stmt, str_repeat('iis', count($chunk)), $values);
            $this->conn->executeUpdate($stmt);
        }
    }

    /** @return list<array{conv_id: int, enqueue_seq: int, reason: string, attempts: int}> */
    private function claim(int $batchSize): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT conv_id, enqueue_seq, reason, attempts FROM 202_attribution_pending
             WHERE retry_at <= ? ORDER BY retry_at, enqueued_at, conv_id LIMIT ?'
        );
        $this->conn->bind($stmt, 'ii', [($this->clock)(), $batchSize]);

        return array_map(static fn (array $r): array => [
            'conv_id' => (int) $r['conv_id'],
            'enqueue_seq' => (int) $r['enqueue_seq'],
            'reason' => (string) $r['reason'],
            'attempts' => (int) $r['attempts'],
        ], $this->conn->fetchAll($stmt));
    }

    private function dueCount(): int
    {
        $stmt = $this->conn->prepareWrite('SELECT COUNT(*) AS c FROM 202_attribution_pending WHERE retry_at <= ?');
        $this->conn->bind($stmt, 'i', [($this->clock)()]);
        $row = $this->conn->fetchOne($stmt);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @param array{conv_id: int, enqueue_seq: int, reason: string, attempts: int} $pending
     * @return string the outcome, for the report
     */
    private function processPending(array $pending): string
    {
        try {
            return $this->conn->transaction(function () use ($pending): string {
                $outcome = $this->processConversion($pending['conv_id'], $pending['reason']);
                $del = $this->conn->prepareWrite(
                    'DELETE FROM 202_attribution_pending WHERE conv_id = ? AND enqueue_seq = ?'
                );
                $this->conn->bind($del, 'ii', [$pending['conv_id'], $pending['enqueue_seq']]);
                $deleted = $this->conn->executeUpdate($del);

                return $deleted === 1 ? $outcome : 'requeued';
            });
        } catch (QueryException $e) {
            // The engine, not this row: stop the run and leave every row as
            // it is. The cron line reports it; the next run retries.
            throw new WorkerHalted(
                'attribution worker stopped at conversion ' . $pending['conv_id'] . ': ' . $e->getMessage(),
                0,
                $e
            );
        } catch (Throwable $e) {
            $this->recordFailure($pending, $e);

            return 'failed';
        }
    }

    /**
     * @param array{conv_id: int, enqueue_seq: int, reason: string, attempts: int} $pending
     */
    private function recordFailure(array $pending, Throwable $e): void
    {
        $attempts = $pending['attempts'] + 1;
        $backoff = min(self::MAX_BACKOFF, 60 * (2 ** min($attempts - 1, 16)));
        $message = mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 255);
        error_log('p202 attribution: conversion ' . $pending['conv_id'] . ' failed (attempt ' . $attempts . '): ' . $message);

        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_attribution_pending SET attempts = ?, last_error = ?, retry_at = ?
             WHERE conv_id = ? AND enqueue_seq = ?'
        );
        $this->conn->bind($stmt, 'isiii', [$attempts, $message, ($this->clock)() + $backoff, $pending['conv_id'], $pending['enqueue_seq']]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * Rewrite one conversion's journey and credits from the current ledger
     * and identity graph. Runs inside the caller's transaction.
     *
     * @return string credited | cleared | missing | reversal | user_deleted
     */
    public function processConversion(int $convId, string $reason): string
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT c.conv_id, c.click_id, c.user_id, c.click_payout, c.payable, c.deleted, c.superseded_reason,
                    c.reverses_conv_id, c.conv_time, c.click_time, u.user_deleted
             FROM 202_conversion_logs c LEFT JOIN 202_users u ON u.user_id = c.user_id
             WHERE c.conv_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$convId]);
        $row = $this->conn->fetchOne($stmt);

        if ($row === null) {
            $this->store->clear($convId);
            return 'missing';
        }
        if ((int) ($row['user_deleted'] ?? 0) === 1) {
            // UserDataPurge removed this account's MTA state; a conversion
            // that arrives (or was queued) after that must not rebuild it —
            // journeys, credits, and a default model the account no longer has.
            $this->store->clear($convId);
            return 'user_deleted';
        }
        if ($row['reverses_conv_id'] !== null) {
            // A reversal has no journey of its own: it nets against the row it
            // names, which the ledger queued beside it.
            $this->store->clear($convId);
            return 'reversal';
        }

        // The one rule every read that shows this amount beside its credits
        // also uses (CountedAmount): net of the reversals naming it, or null.
        $amount = CountedAmount::of($this->conn, $row, true);
        if ($amount === null) {
            $this->store->clear($convId);
            return 'cleared';
        }

        $userId = (int) $row['user_id'];
        $convTime = (int) $row['conv_time'];
        $models = $this->models->activeModels($userId);
        if ($models === [] && $this->models->defaultRow($userId) === null) {
            // An account that somehow has no default (a user-creation path
            // that could not create it) gets it here, the first time a
            // conversion needs it, rather than silently no credits forever.
            DefaultModel::ensureFor($this->conn, $userId);
            $models = $this->models->activeModels($userId);
        }
        $needed = $this->journeyLookbackDays($models);

        $journey = null;
        if ($reason === self::REASON_MODEL_CHANGED) {
            // Only a model change can reuse the stored journey, and only one
            // built at least as wide as the widest model now reads.
            $stored = $this->store->loadJourney($convId);
            if ($stored !== null && $stored->builtLookbackDays >= $needed) {
                $journey = $stored;
            }
        }
        if ($journey === null) {
            $journey = $this->journeys->build($userId, (int) $row['click_id'], (int) $row['click_time'], $convTime, $needed);
            $this->store->saveJourney($convId, $userId, $convTime, $journey);
        }

        $credits = [];
        foreach ($models as $model) {
            $credits[$model->id] = CreditCalculator::credits($model, $journey, $convTime, $amount);
        }
        $this->store->saveCredits($convId, $convTime, $credits);

        return 'credited';
    }

    /**
     * The lookback a journey must be built with: the widest active model's,
     * never less than the default.
     *
     * @param list<Model> $models
     */
    private function journeyLookbackDays(array $models): int
    {
        $days = self::MIN_JOURNEY_LOOKBACK_DAYS;
        foreach ($models as $m) {
            $days = max($days, $m->lookbackDays);
        }

        return $days;
    }
}
