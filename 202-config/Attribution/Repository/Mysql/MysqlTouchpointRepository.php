<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use mysqli;
use mysqli_stmt;
use Prosper202\Attribution\Repository\TouchpointRepositoryInterface;
use Prosper202\Attribution\Touchpoint;
use RuntimeException;
use Throwable;

final readonly class MysqlTouchpointRepository implements TouchpointRepositoryInterface
{
    public function __construct(
        private mysqli $writeConnection,
        private ?mysqli $readConnection = null
    ) {
    }

    public function findBySnapshot(int $snapshotId): array
    {
        $sql = 'SELECT * FROM 202_attribution_touchpoints WHERE snapshot_id = ? ORDER BY position ASC, touchpoint_id ASC';
        $stmt = $this->prepareRead($sql);
        $stmt->bind_param('i', $snapshotId);
        $stmt->execute();
        $result = $stmt->get_result();
        $touchpoints = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $touchpoints[] = Touchpoint::fromDatabaseRow($row);
            }
        }
        $stmt->close();

        return $touchpoints;
    }

    public function saveBatch(array $touchpoints): void
    {
        if ($touchpoints === []) {
            return;
        }

        $conn = $this->writeConnection;
        $conn->begin_transaction();

        try {
            $sql = 'INSERT INTO 202_attribution_touchpoints (snapshot_id, conv_id, click_id, position, credit, weight, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)';
            $stmt = $this->prepareWrite($sql);

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

                $stmt->bind_param(
                    'iiiiddi',
                    $snapshotId,
                    $conversionId,
                    $clickId,
                    $position,
                    $credit,
                    $weight,
                    $createdAt
                );
                $stmt->execute();
            }

            $stmt->close();
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            if (isset($stmt) && $stmt instanceof mysqli_stmt) {
                $stmt->close();
            }
            throw $exception;
        }
    }

    public function deleteBySnapshot(int $snapshotId): void
    {
        $sql = 'DELETE FROM 202_attribution_touchpoints WHERE snapshot_id = ?';
        $stmt = $this->prepareWrite($sql);
        $stmt->bind_param('i', $snapshotId);
        $stmt->execute();
        $stmt->close();
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
}
