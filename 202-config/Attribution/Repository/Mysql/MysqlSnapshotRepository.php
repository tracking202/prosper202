<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use mysqli;
use mysqli_stmt;
use Prosper202\Attribution\Repository\SnapshotRepositoryInterface;
use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Snapshot;
use RuntimeException;

final readonly class MysqlSnapshotRepository implements SnapshotRepositoryInterface
{
    public function __construct(
        private mysqli $writeConnection,
        private ?mysqli $readConnection = null
    ) {
    }

    public function findForRange(int $modelId, ScopeType $scopeType, ?int $scopeId, int $startHour, int $endHour, int $limit = 500, int $offset = 0): array
    {
        $sql = 'SELECT * FROM 202_attribution_snapshots WHERE model_id = ? AND scope_type = ? AND date_hour BETWEEN ? AND ?';
        $types = 'isii';
        $values = [$modelId, $scopeType->value, $startHour, $endHour];

        if ($scopeId === null) {
            $sql .= ' AND scope_id IS NULL';
        } else {
            $sql .= ' AND scope_id = ?';
            $types .= 'i';
            $values[] = $scopeId;
        }

        $sql .= ' ORDER BY date_hour ASC LIMIT ? OFFSET ?';
        $types .= 'ii';
        $values[] = max(1, $limit);
        $values[] = max(0, $offset);

        $stmt = $this->prepareRead($sql);
        $this->bind($stmt, $types, $values);
        $stmt->execute();
        $result = $stmt->get_result();
        $snapshots = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $snapshots[] = Snapshot::fromDatabaseRow($row);
            }
        }
        $stmt->close();

        return $snapshots;
    }

    public function findLatest(int $modelId, ScopeType $scopeType, ?int $scopeId): ?Snapshot
    {
        $sql = 'SELECT * FROM 202_attribution_snapshots WHERE model_id = ? AND scope_type = ?';
        $types = 'is';
        $values = [$modelId, $scopeType->value];

        if ($scopeId === null) {
            $sql .= ' AND scope_id IS NULL';
        } else {
            $sql .= ' AND scope_id = ?';
            $types .= 'i';
            $values[] = $scopeId;
        }

        $sql .= ' ORDER BY date_hour DESC LIMIT 1';

        $stmt = $this->prepareRead($sql);
        $this->bind($stmt, $types, $values);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ? Snapshot::fromDatabaseRow($row) : null;
    }

    public function save(Snapshot $snapshot): Snapshot
    {
        $row = $snapshot->toDatabaseRow();
        $scopeId = $row['scope_id'];
        $modelId = (int) $row['model_id'];
        $userId = (int) $row['user_id'];
        $scopeType = (string) $row['scope_type'];
        $dateHour = (int) $row['date_hour'];
        $lookbackStart = (int) $row['lookback_start'];
        $lookbackEnd = (int) $row['lookback_end'];
        $attributedClicks = (int) $row['attributed_clicks'];
        $attributedConversions = (int) $row['attributed_conversions'];
        $attributedRevenue = (float) $row['attributed_revenue'];
        $attributedCost = (float) $row['attributed_cost'];
        $createdAt = (int) $row['created_at'];

        if ($snapshot->snapshotId === null) {
            if ($scopeId === null) {
                $sql = 'INSERT INTO 202_attribution_snapshots (model_id, user_id, scope_type, scope_id, date_hour, lookback_start, lookback_end, attributed_clicks, attributed_conversions, attributed_revenue, attributed_cost, created_at) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)';
                $stmt = $this->prepareWrite($sql);
                $stmt->bind_param(
                    'iisiiiiiddi',
                    $modelId,
                    $userId,
                    $scopeType,
                    $dateHour,
                    $lookbackStart,
                    $lookbackEnd,
                    $attributedClicks,
                    $attributedConversions,
                    $attributedRevenue,
                    $attributedCost,
                    $createdAt
                );
            } else {
                $sql = 'INSERT INTO 202_attribution_snapshots (model_id, user_id, scope_type, scope_id, date_hour, lookback_start, lookback_end, attributed_clicks, attributed_conversions, attributed_revenue, attributed_cost, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $stmt = $this->prepareWrite($sql);
                $stmt->bind_param(
                    'iisiiiiiiddi',
                    $modelId,
                    $userId,
                    $scopeType,
                    $scopeId,
                    $dateHour,
                    $lookbackStart,
                    $lookbackEnd,
                    $attributedClicks,
                    $attributedConversions,
                    $attributedRevenue,
                    $attributedCost,
                    $createdAt
                );
            }
            $stmt->execute();
            $insertId = $stmt->insert_id ?: $this->writeConnection->insert_id;
            $stmt->close();

            return $this->requireSnapshotById((int) $insertId);
        }

        $snapshotId = (int) $snapshot->snapshotId;
        if ($scopeId === null) {
            $sql = 'UPDATE 202_attribution_snapshots SET model_id = ?, user_id = ?, scope_type = ?, scope_id = NULL, date_hour = ?, lookback_start = ?, lookback_end = ?, attributed_clicks = ?, attributed_conversions = ?, attributed_revenue = ?, attributed_cost = ?, created_at = ? WHERE snapshot_id = ? LIMIT 1';
            $stmt = $this->prepareWrite($sql);
            $stmt->bind_param(
                'iisiiiiiddii',
                $modelId,
                $userId,
                $scopeType,
                $dateHour,
                $lookbackStart,
                $lookbackEnd,
                $attributedClicks,
                $attributedConversions,
                $attributedRevenue,
                $attributedCost,
                $createdAt,
                $snapshotId
            );
        } else {
            $sql = 'UPDATE 202_attribution_snapshots SET model_id = ?, user_id = ?, scope_type = ?, scope_id = ?, date_hour = ?, lookback_start = ?, lookback_end = ?, attributed_clicks = ?, attributed_conversions = ?, attributed_revenue = ?, attributed_cost = ?, created_at = ? WHERE snapshot_id = ? LIMIT 1';
            $stmt = $this->prepareWrite($sql);
            $stmt->bind_param(
                'iisiiiiiiddii',
                $modelId,
                $userId,
                $scopeType,
                $scopeId,
                $dateHour,
                $lookbackStart,
                $lookbackEnd,
                $attributedClicks,
                $attributedConversions,
                $attributedRevenue,
                $attributedCost,
                $createdAt,
                $snapshotId
            );
        }
        $stmt->execute();
        $stmt->close();

        return $this->requireSnapshotById($snapshotId);
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $sql = 'DELETE FROM 202_attribution_snapshots WHERE created_at < ?';
        $stmt = $this->prepareWrite($sql);
        $stmt->bind_param('i', $timestamp);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected;
    }

    private function prepareRead(string $sql): mysqli_stmt
    {
        return $this->prepare($this->readConnection ?? $this->writeConnection, $sql);
    }

    private function prepareWrite(string $sql): mysqli_stmt
    {
        return $this->prepare($this->writeConnection, $sql);
    }

    private function prepare(mysqli $connection, string $sql): mysqli_stmt
    {
        $statement = $connection->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Failed to prepare MySQL statement: ' . $connection->error);
        }

        return $statement;
    }

    private function requireSnapshotById(int $snapshotId): Snapshot
    {
        $sql = 'SELECT * FROM 202_attribution_snapshots WHERE snapshot_id = ? LIMIT 1';
        $stmt = $this->prepareRead($sql);
        $stmt->bind_param('i', $snapshotId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('Unable to load attribution snapshot #' . $snapshotId);
        }

        return Snapshot::fromDatabaseRow($row);
    }

    /**
     * @param array<int, mixed> $values
     */
    private function bind(mysqli_stmt $statement, string $types, array $values): void
    {
        $params = [$types];
        foreach ($values as $index => $value) {
            $params[] = &$values[$index];
        }

        if (!call_user_func_array($statement->bind_param(...), $params)) {
            throw new RuntimeException('Failed to bind MySQL parameters.');
        }
    }
}
