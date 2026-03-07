<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use mysqli;
use Prosper202\Attribution\Repository\TouchpointRepositoryInterface;
use Prosper202\Attribution\Touchpoint;
use Prosper202\Database\Connection;
use RuntimeException;

final readonly class MysqlTouchpointRepository implements TouchpointRepositoryInterface
{
    private Connection $conn;

    /**
     * @param Connection|mysqli $connection Connection instance or legacy mysqli for backwards compatibility
     */
    public function __construct(Connection|mysqli $connection, ?mysqli $readConnection = null)
    {
        if ($connection instanceof Connection) {
            $this->conn = $connection;
        } else {
            $this->conn = new Connection($connection, $readConnection);
        }
    }

    public function findBySnapshot(int $snapshotId): array
    {
        $sql = 'SELECT * FROM 202_attribution_touchpoints WHERE snapshot_id = ? ORDER BY position ASC, touchpoint_id ASC';
        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, 'i', [$snapshotId]);
        $rows = $this->conn->fetchAll($stmt);

        $touchpoints = [];
        foreach ($rows as $row) {
            $touchpoints[] = Touchpoint::fromDatabaseRow($row);
        }

        return $touchpoints;
    }

    public function saveBatch(array $touchpoints): void
    {
        if ($touchpoints === []) {
            return;
        }

        $this->conn->transaction(function () use ($touchpoints): void {
            $sql = 'INSERT INTO 202_attribution_touchpoints (snapshot_id, conv_id, click_id, position, credit, weight, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)';
            $stmt = $this->conn->prepareWrite($sql);

            foreach ($touchpoints as $touchpoint) {
                if (!$touchpoint instanceof Touchpoint) {
                    throw new RuntimeException('Unexpected touchpoint payload.');
                }

                if ($touchpoint->snapshotId === null) {
                    throw new RuntimeException('Touchpoint snapshot identifier must be set before persistence.');
                }

                $snapshotId = (int) $touchpoint->snapshotId;
                $conversionId = (int) $touchpoint->conversionId;
                $clickId = (int) $touchpoint->clickId;
                $position = (int) $touchpoint->position;
                $credit = (float) $touchpoint->credit;
                $weight = (float) $touchpoint->weight;
                $createdAt = (int) $touchpoint->createdAt;

                $this->conn->bind($stmt, 'iiiiddi', [$snapshotId, $conversionId, $clickId, $position, $credit, $weight, $createdAt]);
                $this->conn->execute($stmt);
            }

            $stmt->close();
        });
    }

    public function deleteBySnapshot(int $snapshotId): void
    {
        $sql = 'DELETE FROM 202_attribution_touchpoints WHERE snapshot_id = ?';
        $stmt = $this->conn->prepareWrite($sql);
        $this->conn->bind($stmt, 'i', [$snapshotId]);
        $this->conn->executeUpdate($stmt);
    }
}
