<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

use Prosper202\Database\Connection;

/**
 * The dirty hours, 202_dirty_hours: the ranges of an account's clicks whose
 * report rows a change made wrong, for the cron job's pass to roll up again
 * (DataEngine::processDirtyHours()). Every write of the table is here
 * (QueueStatesHaveOneWriterTest), each state change a move DirtyHourState
 * allows, asked of the row in the statement's own WHERE.
 *
 * A row is its account, traffic source account, campaign and range (the
 * primary key). Queuing a range already there puts its row back to Queued
 * whatever the pass has done with it, so a change made while the pass rolls
 * the range up is rolled up by the next pass.
 *
 * Every method throws on a statement the server refuses (Connection).
 *
 * @phpstan-type Hour array{id: int, user_id: int, ppc_account_id: int, aff_campaign_id: int, click_time_from: int, click_time_to: int}
 */
final class DirtyHours
{
    /**
     * Every state may become Queued, so the upsert names no from-state. Its
     * SET list is DirtyHourState::SET_QUEUED written out, which
     * QueueStatesHaveOneWriterTest holds it to: ClickValueWritersTest reads
     * an upsert's columns from its own text.
     */
    private const QUEUE = 'INSERT INTO 202_dirty_hours (ppc_account_id, aff_campaign_id, user_id, click_time_from,'
        . ' click_time_to, landing_page_id, aff_network_id, text_ad_id, ppc_network_id) VALUES %s'
        . ' ON DUPLICATE KEY UPDATE processed = 0, deleted = 0';

    private const TAKE = 'UPDATE 202_dirty_hours SET ' . DirtyHourState::SET_TAKEN
        . ' WHERE id = ? AND ' . DirtyHourState::IS_QUEUED;

    /** Every taken range, by the pass that holds the lock: left by a pass that has gone, or its own roll-up failed. */
    private const REQUEUE = 'UPDATE 202_dirty_hours SET ' . DirtyHourState::SET_QUEUED
        . ' WHERE ' . DirtyHourState::IS_TAKEN;

    /** A range rolled up, unless a writer queued it again meanwhile. */
    private const FINISH = 'DELETE FROM 202_dirty_hours WHERE id = ? AND ' . DirtyHourState::IS_TAKEN;

    /** Rows per INSERT. */
    private const CHUNK = 500;

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * One range of $userId's clicks, of one traffic source account or
     * campaign when either is given. The pass reads those and the range;
     * the other ids are kept as the writer knew them.
     */
    public function queue(
        int $userId,
        int $from,
        int $to,
        int $ppcAccountId = 0,
        int $campaignId = 0,
        int $landingPageId = 0,
        int $affNetworkId = 0,
        int $textAdId = 0,
        int $ppcNetworkId = 0
    ): void {
        $this->insert([[
            $ppcAccountId, $campaignId, $userId, $from, $to, $landingPageId, $affNetworkId, $textAdId, $ppcNetworkId,
        ]]);
    }

    /**
     * Ranges of $userId's clicks, each of one traffic source account.
     *
     * @param list<array{int, int, int}> $ranges traffic source account, from, to
     */
    public function queueRanges(int $userId, array $ranges): void
    {
        $rows = [];
        foreach ($ranges as [$ppcAccountId, $from, $to]) {
            $rows[] = [$ppcAccountId, 0, $userId, $from, $to, 0, 0, 0, 0];
        }
        $this->insert($rows);
    }

    /**
     * The cron job's pass: each queued range taken, rolled up by $rollUp,
     * and deleted. One pass at a time: null when another holds the queue,
     * which is doing this work.
     *
     * A range a writer queues again while $rollUp runs is left queued. When
     * $rollUp throws the pass ends there with the range queued again; if
     * that write fails too, the next pass queues it.
     *
     * @param callable(Hour): void $rollUp
     * @return array{rolled_up: int, queued_again: int}|null
     */
    public function pass(callable $rollUp): ?array
    {
        $lock = QueueLock::name($this->conn, 'dirty hours pass');
        if (!QueueLock::tryTake($this->conn, $lock)) {
            return null;
        }
        try {
            // The lock is this pass's, so a row found taken was left by a pass that has gone.
            $left = $this->conn->executeUpdate($this->conn->prepareWrite(self::REQUEUE));
            if ($left > 0) {
                error_log('DataEngine dirty hours: ' . $left . ' taken by a pass that ended without finishing them;'
                    . ' queued again.');
            }
            $done = ['rolled_up' => 0, 'queued_again' => 0];
            foreach ($this->queued() as $hour) {
                if ($this->one(self::TAKE, $hour['id']) === 0) {
                    // Removed since it was read: nothing asks for it any more.
                    continue;
                }
                try {
                    $rollUp($hour);
                } catch (\Throwable $e) {
                    $this->requeueAfter($hour['id']);
                    throw $e;
                }
                $done[$this->one(self::FINISH, $hour['id']) > 0 ? 'rolled_up' : 'queued_again']++;
            }

            return $done;
        } finally {
            try {
                QueueLock::release($this->conn, $lock);
            } catch (\Throwable $e) {
                // The lock goes with the connection.
                error_log('DataEngine dirty hours: the pass\'s lock was not released: ' . $e->getMessage());
            }
        }
    }

    /**
     * The roll-up of range $id threw. The lock is this pass's, so the one
     * taken row is that range. Never throws: the roll-up's failure is the
     * one the caller hears.
     */
    private function requeueAfter(int $id): void
    {
        try {
            $this->conn->executeUpdate($this->conn->prepareWrite(self::REQUEUE));
        } catch (\Throwable $e) {
            error_log('DataEngine dirty hours: range ' . $id . ' was not queued again after its roll-up failed ('
                . $e->getMessage() . '); the next pass queues it.');
        }
    }

    /** How many ranges are still to be rolled up: one row, `waiting`. */
    public static function waitingSql(): string
    {
        return 'SELECT COUNT(*) AS waiting FROM 202_dirty_hours';
    }

    /** @return list<Hour> */
    private function queued(): array
    {
        $rows = $this->conn->fetchAll($this->conn->prepareWrite(
            'SELECT id, user_id, ppc_account_id, aff_campaign_id, click_time_from, click_time_to'
            . ' FROM 202_dirty_hours WHERE ' . DirtyHourState::IS_QUEUED . ' ORDER BY id'
        ));
        $hours = [];
        foreach ($rows as $row) {
            $hours[] = [
                'id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'ppc_account_id' => (int) $row['ppc_account_id'],
                'aff_campaign_id' => (int) $row['aff_campaign_id'],
                'click_time_from' => (int) $row['click_time_from'],
                'click_time_to' => (int) $row['click_time_to'],
            ];
        }

        return $hours;
    }

    /** @param list<list<int>> $rows each in QUEUE's column order */
    private function insert(array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $stmt = $this->conn->prepareWrite(sprintf(
                self::QUEUE,
                implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?)'))
            ));
            $this->conn->bind($stmt, str_repeat('iiiiiiiii', count($chunk)), array_merge(...$chunk));
            $this->conn->executeUpdate($stmt);
        }
    }

    /** @return int rows changed: 0 when row $id is not in the state $sql asks for */
    private function one(string $sql, int $id): int
    {
        $stmt = $this->conn->prepareWrite($sql);
        $this->conn->bind($stmt, 'i', [$id]);

        return $this->conn->executeUpdate($stmt);
    }
}
