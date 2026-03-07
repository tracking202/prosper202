<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use mysqli;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use Prosper202\Database\Connection;
use RuntimeException;
use Throwable;

final readonly class MysqlModelRepository implements ModelRepositoryInterface
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

    public function findById(int $modelId): ?ModelDefinition
    {
        $sql = 'SELECT * FROM 202_attribution_models WHERE model_id = ? LIMIT 1';
        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, 'i', [$modelId]);
        $this->conn->execute($stmt);
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ? ModelDefinition::fromDatabaseRow($row) : null;
    }

    public function findDefaultForUser(int $userId): ?ModelDefinition
    {
        $sql = 'SELECT * FROM 202_attribution_models WHERE user_id = ? AND is_default = 1 LIMIT 1';
        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, 'i', [$userId]);
        $this->conn->execute($stmt);
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ? ModelDefinition::fromDatabaseRow($row) : null;
    }

    public function findForUser(int $userId, ?ModelType $type = null, bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM 202_attribution_models WHERE user_id = ?';
        $types = 'i';
        $values = [$userId];

        if ($type !== null) {
            $sql .= ' AND model_type = ?';
            $types .= 's';
            $values[] = $type->value;
        }

        if ($onlyActive) {
            $sql .= ' AND is_active = 1';
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $values);
        $this->conn->execute($stmt);
        $result = $stmt->get_result();
        $models = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $models[] = ModelDefinition::fromDatabaseRow($row);
            }
        }
        $stmt->close();

        return $models;
    }

    public function findBySlug(int $userId, string $slug): ?ModelDefinition
    {
        $sql = 'SELECT * FROM 202_attribution_models WHERE user_id = ? AND model_slug = ? LIMIT 1';
        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, 'is', [$userId, $slug]);
        $this->conn->execute($stmt);
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ? ModelDefinition::fromDatabaseRow($row) : null;
    }

    public function save(ModelDefinition $model): ModelDefinition
    {
        $row = $model->toDatabaseRow();
        $weightingConfig = $row['weighting_config'];
        $isActive = (int) $row['is_active'];
        $isDefault = (int) $row['is_default'];
        $createdAt = (int) $row['created_at'];
        $updatedAt = (int) $row['updated_at'];

        if ($model->modelId === null) {
            $sql = 'INSERT INTO 202_attribution_models (user_id, model_name, model_slug, model_type, weighting_config, is_active, is_default, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $this->conn->prepareWrite($sql);
            $this->conn->bind($stmt, 'issssiiii', [
                $row['user_id'],
                $row['model_name'],
                $row['model_slug'],
                $row['model_type'],
                $weightingConfig,
                $isActive,
                $isDefault,
                $createdAt,
                $updatedAt,
            ]);
            $insertId = $this->conn->executeInsert($stmt);

            return $this->requireModelById($insertId);
        }

        $sql = 'UPDATE 202_attribution_models SET model_name = ?, model_slug = ?, model_type = ?, weighting_config = ?, is_active = ?, is_default = ?, created_at = ?, updated_at = ? WHERE model_id = ? LIMIT 1';
        $stmt = $this->conn->prepareWrite($sql);
        $modelId = (int) $model->modelId;
        $this->conn->bind($stmt, 'ssssiiiii', [
            $row['model_name'],
            $row['model_slug'],
            $row['model_type'],
            $weightingConfig,
            $isActive,
            $isDefault,
            $createdAt,
            $updatedAt,
            $modelId,
        ]);
        $this->conn->execute($stmt);
        $stmt->close();

        return $this->requireModelById($modelId);
    }

    public function promoteToDefault(ModelDefinition $model): void
    {
        $modelId = $model->modelId;
        if ($modelId === null) {
            throw new RuntimeException('Cannot promote a model without an identifier.');
        }

        $userId = $model->userId;

        $this->conn->transaction(function () use ($userId, $modelId): void {
            $stmtReset = $this->conn->prepareWrite('UPDATE 202_attribution_models SET is_default = 0 WHERE user_id = ?');
            $this->conn->bind($stmtReset, 'i', [$userId]);
            $this->conn->execute($stmtReset);
            $stmtReset->close();

            $stmtSet = $this->conn->prepareWrite('UPDATE 202_attribution_models SET is_default = 1, is_active = 1 WHERE model_id = ? LIMIT 1');
            $this->conn->bind($stmtSet, 'i', [$modelId]);
            $this->conn->execute($stmtSet);
            $stmtSet->close();
        });
    }

    public function setAsDefault(int $userId, int $modelId): bool
    {
        return $this->conn->transaction(function () use ($userId, $modelId): bool {
            // First verify the model exists and belongs to the user
            $checkStmt = $this->conn->prepareWrite('SELECT 1 FROM 202_attribution_models WHERE model_id = ? AND user_id = ? LIMIT 1');
            $this->conn->bind($checkStmt, 'ii', [$modelId, $userId]);
            $this->conn->execute($checkStmt);
            $result = $checkStmt->get_result();
            $exists = $result && $result->num_rows > 0;
            $checkStmt->close();

            if (!$exists) {
                return false;
            }

            // Reset all models for this user to non-default
            $resetStmt = $this->conn->prepareWrite('UPDATE 202_attribution_models SET is_default = 0 WHERE user_id = ?');
            $this->conn->bind($resetStmt, 'i', [$userId]);
            $this->conn->execute($resetStmt);
            $resetStmt->close();

            // Set the specified model as default (and activate it)
            $setStmt = $this->conn->prepareWrite('UPDATE 202_attribution_models SET is_default = 1, is_active = 1 WHERE model_id = ? LIMIT 1');
            $this->conn->bind($setStmt, 'i', [$modelId]);
            $this->conn->execute($setStmt);
            $setStmt->close();

            return true;
        });
    }

    public function delete(int $modelId, int $userId): void
    {
        $this->conn->transaction(function () use ($modelId, $userId): void {
            $stmt = $this->conn->prepareWrite('DELETE tp FROM 202_attribution_touchpoints tp INNER JOIN 202_attribution_snapshots s ON tp.snapshot_id = s.snapshot_id WHERE s.model_id = ?');
            $this->conn->bind($stmt, 'i', [$modelId]);
            $this->conn->execute($stmt);
            $stmt->close();

            $stmt = $this->conn->prepareWrite('DELETE FROM 202_attribution_snapshots WHERE model_id = ?');
            $this->conn->bind($stmt, 'i', [$modelId]);
            $this->conn->execute($stmt);
            $stmt->close();

            $stmt = $this->conn->prepareWrite('DELETE FROM 202_attribution_settings WHERE model_id = ?');
            $this->conn->bind($stmt, 'i', [$modelId]);
            $this->conn->execute($stmt);
            $stmt->close();

            $stmt = $this->conn->prepareWrite('DELETE FROM 202_attribution_models WHERE model_id = ? AND user_id = ?');
            $this->conn->bind($stmt, 'ii', [$modelId, $userId]);
            $this->conn->execute($stmt);
            $stmt->close();
        });
    }

    private function requireModelById(int $modelId): ModelDefinition
    {
        $model = $this->findById($modelId);
        if ($model === null) {
            throw new RuntimeException('Unable to load attribution model #' . $modelId);
        }

        return $model;
    }
}
