<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Database\Connection;
use Prosper202\Report\RollupDirty;

/**
 * The worker's writes: a conversion's journey, its build metadata and its
 * credits. Every method runs inside the worker's per-conversion
 * transaction, and each one rewrites (delete, then insert) rather than
 * appends, so processing a conversion twice leaves exactly what processing
 * it once does.
 *
 * Every write first marks the report rollup's hours it changes — the hour
 * the conversion's stored rows sit in and the hour of the ones replacing
 * them — in the same transaction (RollupDirty; AttributionRollup rule 2).
 */
final class AttributionStore
{
    public function __construct(private Connection $conn)
    {
    }

    public function saveJourney(int $convId, int $userId, int $convTime, Journey $journey): void
    {
        RollupDirty::conversion($this->conn, $convId, $userId, $convTime);
        $this->deleteFrom('202_attribution_journeys', $convId);

        $rows = [];
        $values = [];
        foreach ($journey->touches as $t) {
            $rows[] = '(?, ?, ?, ?)';
            array_push($values, $convId, $t->position, $t->clickId, $t->clickTime);
        }
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_attribution_journeys (conv_id, position, click_id, click_time) VALUES ' . implode(', ', $rows)
        );
        $this->conn->bind($stmt, str_repeat('iiii', count($rows)), $values);
        $this->conn->executeUpdate($stmt);

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_attribution_journey_meta
                (conv_id, user_id, conv_time, touches, built_lookback_days, built_at, truncated, identified)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), conv_time = VALUES(conv_time),
                touches = VALUES(touches), built_lookback_days = VALUES(built_lookback_days),
                built_at = VALUES(built_at), truncated = VALUES(truncated), identified = VALUES(identified)'
        );
        $this->conn->bind($stmt, 'iiiiiiii', [
            $convId, $userId, $convTime, count($journey->touches), $journey->builtLookbackDays, time(),
            $journey->truncated ? 1 : 0, $journey->identified ? 1 : 0,
        ]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * The stored journey, or null when there is none.
     */
    public function loadJourney(int $convId): ?Journey
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT built_lookback_days, truncated, identified, touches FROM 202_attribution_journey_meta WHERE conv_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$convId]);
        $meta = $this->conn->fetchOne($stmt);
        if ($meta === null) {
            return null;
        }

        $stmt = $this->conn->prepareWrite(
            'SELECT position, click_id, click_time FROM 202_attribution_journeys WHERE conv_id = ? ORDER BY position'
        );
        $this->conn->bind($stmt, 'i', [$convId]);
        $touches = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $touches[] = new Touch((int) $row['position'], (int) $row['click_id'], (int) $row['click_time']);
        }
        if (count($touches) !== (int) $meta['touches']) {
            // Meta and rows disagree: the stored journey cannot be trusted,
            // so the caller rebuilds it rather than crediting half of one.
            return null;
        }

        return new Journey($touches, (int) $meta['built_lookback_days'], (int) $meta['truncated'] === 1, (int) $meta['identified'] === 1);
    }

    /**
     * Replace every credit of a conversion.
     *
     * @param array<int, list<Credit>> $creditsByModel model_id => credits
     */
    public function saveCredits(int $convId, int $userId, int $convTime, array $creditsByModel): void
    {
        RollupDirty::conversion($this->conn, $convId, $userId, $convTime);
        $this->deleteFrom('202_attribution_credits', $convId);

        $rows = [];
        $values = [];
        foreach ($creditsByModel as $modelId => $credits) {
            foreach ($credits as $c) {
                $rows[] = '(?, ?, ?, ?, ?, ?, ?)';
                array_push($values, $convId, $modelId, $c->clickId, $c->position, $c->creditDecimal(), Amount::fromUnits($c->revenueUnits), $convTime);
            }
        }
        if ($rows === []) {
            return;
        }
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_attribution_credits (conv_id, model_id, click_id, position, credit, revenue, conv_time) VALUES '
            . implode(', ', $rows)
        );
        $this->conn->bind($stmt, str_repeat('iiiissi', count($rows)), $values);
        $this->conn->executeUpdate($stmt);
    }

    /** Remove everything the engine holds for a conversion that no longer counts. */
    public function clear(int $convId): void
    {
        RollupDirty::conversion($this->conn, $convId);
        foreach (['202_attribution_credits', '202_attribution_journeys', '202_attribution_journey_meta'] as $table) {
            $this->deleteFrom($table, $convId);
        }
    }

    private function deleteFrom(string $table, int $convId): void
    {
        $stmt = $this->conn->prepareWrite('DELETE FROM ' . $table . ' WHERE conv_id = ?');
        $this->conn->bind($stmt, 'i', [$convId]);
        $this->conn->executeUpdate($stmt);
    }
}
