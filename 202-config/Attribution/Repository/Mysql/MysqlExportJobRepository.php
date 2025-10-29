<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use mysqli;
use mysqli_stmt;
use Prosper202\Attribution\ExportJob;
use Prosper202\Attribution\Repository\ExportJobRepositoryInterface;
use RuntimeException;

final class MysqlExportJobRepository implements ExportJobRepositoryInterface
{
    public function __construct(
        private readonly mysqli $writeConnection,
        private readonly ?mysqli $readConnection = null
    ) {
    }

    public function create(ExportJob $job): ExportJob
    {
        $row = $job->toDatabaseRow();

        $columns = [
            'user_id' => (int) $row['user_id'],
            'model_id' => (int) $row['model_id'],
            'scope_type' => (string) $row['scope_type'],
            'scope_id' => $row['scope_id'] !== null ? (int) $row['scope_id'] : null,
            'start_hour' => (int) $row['start_hour'],
            'end_hour' => (int) $row['end_hour'],
            'requested_format' => (string) $row['requested_format'],
            'status' => (string) $row['status'],
            'options' => $row['options'],
            'webhook_url' => $row['webhook_url'],
            'webhook_secret' => $row['webhook_secret'],
            'webhook_headers' => $row['webhook_headers'],
            'file_path' => $row['file_path'],
            'rows_exported' => $row['rows_exported'] !== null ? (int) $row['rows_exported'] : null,
            'queued_at' => (int) $row['queued_at'],
            'started_at' => $row['started_at'] !== null ? (int) $row['started_at'] : null,
            'completed_at' => $row['completed_at'] !== null ? (int) $row['completed_at'] : null,
            'failed_at' => $row['failed_at'] !== null ? (int) $row['failed_at'] : null,
            'last_error' => $row['last_error'],
            'webhook_attempted_at' => $row['webhook_attempted_at'] !== null ? (int) $row['webhook_attempted_at'] : null,
            'webhook_status_code' => $row['webhook_status_code'] !== null ? (int) $row['webhook_status_code'] : null,
            'webhook_response_body' => $row['webhook_response_body'],
            'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'],
        ];

        $placeholders = [];
        $values = [];
        foreach ($columns as $column => $value) {
            $placeholders[] = '?';
            $values[] = $value;
        }

        $sql = sprintf(
            'INSERT INTO 202_attribution_exports (%s) VALUES (%s)',
            implode(', ', array_keys($columns)),
            implode(', ', $placeholders)
        );

        $stmt = $this->prepareWrite($sql);

        if ($values !== []) {
            $types = $this->inferParameterTypes($values);
            $this->bind($stmt, $types, $values);
        }

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
        $stmt->bind_param('iisii', $timestamp, $statusCode, $responseBody, $timestamp, $jobId);
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
    private function inferParameterTypes(array $values): string
    {
        $types = '';
        foreach ($values as $value) {
            if (is_int($value) || is_bool($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }

        return $types;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function bind(mysqli_stmt $statement, string $types, array &$values): void
    {
        $references = [];
        foreach ($values as $index => &$value) {
            $references[$index] = &$value;
        }
        unset($value);

        if (!$statement->bind_param($types, ...$references)) {
            throw new RuntimeException('Failed to bind MySQL parameters.');
        }
    }
}
