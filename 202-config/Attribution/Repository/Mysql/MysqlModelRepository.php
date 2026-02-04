<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use mysqli;
use mysqli_stmt;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use RuntimeException;
use Throwable;

final class MysqlModelRepository implements ModelRepositoryInterface
{
    public function __construct(
        private readonly mysqli $writeConnection,
        private readonly ?mysqli $readConnection = null
    ) {
    }

    public function findById(int $modelId): ?ModelDefinition
    {
        $sql = 'SELECT * FROM 202_attribution_models WHERE model_id = ? LIMIT 1';
        $stmt = $this->prepareRead($sql);
        $stmt->bind_param('i', $modelId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ? ModelDefinition::fromDatabaseRow($row) : null;
    }

    public function findDefaultForUser(int $userId): ?ModelDefinition
    {
        $sql = 'SELECT * FROM 202_attribution_models WHERE user_id = ? AND is_default = 1 LIMIT 1';
        $stmt = $this->prepareRead($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
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

        $stmt = $this->prepareRead($sql);
        $this->bind($stmt, $types, $values);
        $stmt->execute();
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
        $stmt = $this->prepareRead($sql);
        $stmt->bind_param('is', $userId, $slug);
        $stmt->execute();
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
            $stmt = $this->prepareWrite($sql);
            $stmt->bind_param(
                'issssiiii',
                $row['user_id'],
                $row['model_name'],
                $row['model_slug'],
                $row['model_type'],
                $weightingConfig,
                $isActive,
                $isDefault,
                $createdAt,
                $updatedAt
            );
            $stmt->execute();
            $insertId = $stmt->insert_id ?: $this->writeConnection->insert_id;
            $stmt->close();

            return $this->requireModelById((int) $insertId);
        }

        $sql = 'UPDATE 202_attribution_models SET model_name = ?, model_slug = ?, model_type = ?, weighting_config = ?, is_active = ?, is_default = ?, created_at = ?, updated_at = ? WHERE model_id = ? LIMIT 1';
        $stmt = $this->prepareWrite($sql);
        $modelId = (int) $model->modelId;
        $stmt->bind_param(
            'ssssiiiii',
            $row['model_name'],
            $row['model_slug'],
            $row['model_type'],
            $weightingConfig,
            $isActive,
            $isDefault,
            $createdAt,
            $updatedAt,
            $modelId
        );
        $stmt->execute();
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
        $conn = $this->writeConnection;
        $conn->begin_transaction();
        try {
            $stmtReset = $this->prepareWrite('UPDATE 202_attribution_models SET is_default = 0 WHERE user_id = ?');
            $stmtReset->bind_param('i', $userId);
            $stmtReset->execute();
            $stmtReset->close();

            $stmtSet = $this->prepareWrite('UPDATE 202_attribution_models SET is_default = 1, is_active = 1 WHERE model_id = ? LIMIT 1');
            $stmtSet->bind_param('i', $modelId);
            $stmtSet->execute();
            $stmtSet->close();

            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    public function setAsDefault(int $userId, int $modelId): bool
    {
        $conn = $this->writeConnection;
        $conn->begin_transaction();

        try {
            // First verify the model exists and belongs to the user
            $checkStmt = $this->prepareWrite('SELECT 1 FROM 202_attribution_models WHERE model_id = ? AND user_id = ? LIMIT 1');
            $checkStmt->bind_param('ii', $modelId, $userId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $exists = $result && $result->num_rows > 0;
            $checkStmt->close();

            if (!$exists) {
                $conn->rollback();
                return false;
            }

            // Reset all models for this user to non-default
            $resetStmt = $this->prepareWrite('UPDATE 202_attribution_models SET is_default = 0 WHERE user_id = ?');
            $resetStmt->bind_param('i', $userId);
            $resetStmt->execute();
            $resetStmt->close();

            // Set the specified model as default (and activate it)
            $setStmt = $this->prepareWrite('UPDATE 202_attribution_models SET is_default = 1, is_active = 1 WHERE model_id = ? LIMIT 1');
            $setStmt->bind_param('i', $modelId);
            $setStmt->execute();
            $setStmt->close();

            $conn->commit();
            return true;
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    public function delete(int $modelId, int $userId): void
    {
        $conn = $this->writeConnection;
        $conn->begin_transaction();

        try {
            $stmt = $this->prepareWrite('DELETE tp FROM 202_attribution_touchpoints tp INNER JOIN 202_attribution_snapshots s ON tp.snapshot_id = s.snapshot_id WHERE s.model_id = ?');
            $stmt->bind_param('i', $modelId);
            $stmt->execute();
            $stmt->close();

            $stmt = $this->prepareWrite('DELETE FROM 202_attribution_snapshots WHERE model_id = ?');
            $stmt->bind_param('i', $modelId);
            $stmt->execute();
            $stmt->close();

            $stmt = $this->prepareWrite('DELETE FROM 202_attribution_settings WHERE model_id = ?');
            $stmt->bind_param('i', $modelId);
            $stmt->execute();
            $stmt->close();

            $stmt = $this->prepareWrite('DELETE FROM 202_attribution_models WHERE model_id = ? AND user_id = ?');
            $stmt->bind_param('ii', $modelId, $userId);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
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

    private function requireModelById(int $modelId): ModelDefinition
    {
        $model = $this->findById($modelId);
        if ($model === null) {
            throw new RuntimeException('Unable to load attribution model #' . $modelId);
        }

        return $model;
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

        if (!call_user_func_array([$statement, 'bind_param'], $params)) {
            throw new RuntimeException('Failed to bind MySQL parameters.');
        }
    }
}
