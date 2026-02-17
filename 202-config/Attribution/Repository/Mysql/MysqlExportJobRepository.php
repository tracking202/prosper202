<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use mysqli;
use mysqli_stmt;
use Prosper202\Attribution\ExportJob;
use Prosper202\Attribution\Repository\ExportJobRepositoryInterface;
use RuntimeException;

final readonly class MysqlExportJobRepository implements ExportJobRepositoryInterface
{
    public function __construct(
        private mysqli $writeConnection,
        private ?mysqli $readConnection = null
    ) {}

    public function create(ExportJob $job): ExportJob
    {
        $row = $job->toDatabaseRow();
        $sql = 'INSERT INTO 202_attribution_exports (user_id, model_id, scope_type, scope_id, start_hour, end_hour, requested_format, status, options, webhook_url, webhook_secret, webhook_headers, file_path, rows_exported, queued_at, started_at, completed_at, failed_at, last_error, webhook_attempted_at, webhook_status_code, webhook_response_body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $this->prepareWrite($sql);

        $types = '';
        $values = [];

        $this->addIntParam($types, $values, (int) $row['user_id']);
        $this->addIntParam($types, $values, (int) $row['model_id']);
        $this->addStringParam($types, $values, (string) $row['scope_type']);

        $scopeId = $row['scope_id'];
        $this->addIntParam($types, $values, $scopeId === null ? null : (int) $scopeId);

        $this->addIntParam($types, $values, (int) $row['start_hour']);
        $this->addIntParam($types, $values, (int) $row['end_hour']);
        $this->addStringParam($types, $values, (string) $row['requested_format']);
        $this->addStringParam($types, $values, (string) $row['status']);
        $this->addStringParam($types, $values, $row['options']);
        $this->addStringParam($types, $values, $row['webhook_url']);
        $this->addStringParam($types, $values, $row['webhook_secret']);
        $this->addStringParam($types, $values, $row['webhook_headers']);
        $this->addStringParam($types, $values, $row['file_path']);

        $rowsExported = $row['rows_exported'];
        $this->addIntParam($types, $values, $rowsExported === null ? null : (int) $rowsExported);

        $this->addIntParam($types, $values, (int) $row['queued_at']);

        $startedAt = $row['started_at'];
        $this->addIntParam($types, $values, $startedAt === null ? null : (int) $startedAt);

        $completedAt = $row['completed_at'];
        $this->addIntParam($types, $values, $completedAt === null ? null : (int) $completedAt);

        $failedAt = $row['failed_at'];
        $this->addIntParam($types, $values, $failedAt === null ? null : (int) $failedAt);

        $this->addStringParam($types, $values, $row['last_error']);

        $webhookAttemptedAt = $row['webhook_attempted_at'];
        $this->addIntParam($types, $values, $webhookAttemptedAt === null ? null : (int) $webhookAttemptedAt);

        $webhookStatusCode = $row['webhook_status_code'];
        $this->addIntParam($types, $values, $webhookStatusCode === null ? null : (int) $webhookStatusCode);

        $this->addStringParam($types, $values, $row['webhook_response_body']);
        $this->addIntParam($types, $values, (int) $row['created_at']);
        $this->addIntParam($types, $values, (int) $row['updated_at']);

        $this->bind($stmt, $types, $values);
        $stmt->execute();
        $insertId = $stmt->insert_id ?: $this->writeConnection->insert_id;
        $stmt->close();

        $created = $this->findById((int) $insertId);
        if ($created === null) {
            throw new RuntimeException('Failed to persist attribution export job.');
        }

        return $created;
    }

    public function findById(int $jobId): ?ExportJob
    {
        $sql = 'SELECT * FROM 202_attribution_exports WHERE export_id = ? LIMIT 1';
        $stmt = $this->prepareRead($sql);
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ? ExportJob::fromDatabaseRow($row) : null;
    }

    public function findPending(int $limit = 10): array
    {
        $sql = 'SELECT * FROM 202_attribution_exports WHERE status = \'pending\' ORDER BY queued_at ASC LIMIT ?';
        $stmt = $this->prepareRead($sql);
        $limit = max(1, $limit);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $jobs = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $jobs[] = ExportJob::fromDatabaseRow($row);
            }
        }
        $stmt->close();

        return $jobs;
    }

    public function markProcessing(int $jobId, int $timestamp): void
    {
        $sql = 'UPDATE 202_attribution_exports SET status = \'processing\', started_at = ?, updated_at = ? WHERE export_id = ? LIMIT 1';
        $stmt = $this->prepareWrite($sql);
        $stmt->bind_param('iii', $timestamp, $timestamp, $jobId);
        $stmt->execute();
        $stmt->close();
    }

    public function markCompleted(int $jobId, string $filePath, int $rowsExported, int $timestamp): void
    {
        $sql = 'UPDATE 202_attribution_exports SET status = \'completed\', file_path = ?, rows_exported = ?, completed_at = ?, updated_at = ?, last_error = NULL WHERE export_id = ? LIMIT 1';
        $stmt = $this->prepareWrite($sql);
        $stmt->bind_param('siiii', $filePath, $rowsExported, $timestamp, $timestamp, $jobId);
        $stmt->execute();
        $stmt->close();
    }

    public function markFailed(int $jobId, string $error, int $timestamp): void
    {
        $sql = 'UPDATE 202_attribution_exports SET status = \'failed\', failed_at = ?, updated_at = ?, last_error = ? WHERE export_id = ? LIMIT 1';
        $stmt = $this->prepareWrite($sql);
        $stmt->bind_param('iisi', $timestamp, $timestamp, $error, $jobId);
        $stmt->execute();
        $stmt->close();
    }

    public function recordWebhookAttempt(int $jobId, int $timestamp, ?int $statusCode, ?string $responseBody): void
    {
        $sql = 'UPDATE 202_attribution_exports SET webhook_attempted_at = ?, webhook_status_code = ?, webhook_response_body = ?, updated_at = ? WHERE export_id = ? LIMIT 1';
        $stmt = $this->prepareWrite($sql);

        $types = '';
        $values = [];

        $this->addIntParam($types, $values, $timestamp);
        $this->addIntParam($types, $values, $statusCode);
        $this->addStringParam($types, $values, $responseBody);
        $this->addIntParam($types, $values, $timestamp);
        $this->addIntParam($types, $values, $jobId);

        $this->bind($stmt, $types, $values);
        $stmt->execute();
        $stmt->close();
    }

    public function listRecentForModel(int $userId, int $modelId, int $limit = 20): array
    {
        $sql = 'SELECT * FROM 202_attribution_exports WHERE user_id = ? AND model_id = ? ORDER BY queued_at DESC LIMIT ?';
        $stmt = $this->prepareRead($sql);
        $limit = max(1, $limit);
        $stmt->bind_param('iii', $userId, $modelId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $jobs = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $jobs[] = ExportJob::fromDatabaseRow($row);
            }
        }
        $stmt->close();

        return $jobs;
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

    /**
     * @param array<int, mixed> $values
     */
    private function bind(mysqli_stmt $statement, string $types, array $values): void
    {
        // Ensure all values are passed by reference for mysqli bind_param.
        array_walk($values, function (&$v) {});

        if (!$statement->bind_param($types, ...$values)) {
            throw new RuntimeException('Failed to bind MySQL parameters.');
        }

        unset($value);
    }

    private function addIntParam(string &$types, array &$values, ?int $value): void
    {
        if ($value === null) {
            $types .= 's';
            $values[] = null;

            return;
        }

        $types .= 'i';
        $values[] = $value;
    }

    private function addStringParam(string &$types, array &$values, ?string $value): void
    {
        $types .= 's';
        $values[] = $value;
    }
}
