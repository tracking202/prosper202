<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use mysqli;
use Prosper202\Attribution\Export\ExportFormat;
use Prosper202\Attribution\Export\ExportJob;
use Prosper202\Attribution\Export\ExportStatus;
use Prosper202\Attribution\Repository\ExportRepositoryInterface;
use Prosper202\Attribution\ScopeType;

final class MysqlExportRepository implements ExportRepositoryInterface
{
    private mysqli $write;
    private mysqli $read;

    public function __construct(mysqli $write, ?mysqli $read = null)
    {
        $this->write = $write;
        $this->read = $read ?? $write;
    }

    public function create(ExportJob $job): ExportJob
    {
        $sql = 'INSERT INTO 202_attribution_exports (user_id, model_id, scope_type, scope_id, start_hour, end_hour, format, status, file_path, download_token, webhook_url, webhook_method, webhook_headers, webhook_status_code, webhook_response_body, last_attempted_at, completed_at, error_message, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $this->write->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Unable to prepare export insert statement: ' . $this->write->error);
        }

        $scopeType = $job->scopeType->value;
        $scopeId = $job->scopeId;
        $startHour = $job->startHour;
        $endHour = $job->endHour;
        $format = $job->format->value;
        $status = $job->status->value;
        $filePath = $job->filePath;
        $downloadToken = $job->downloadToken;
        $webhookUrl = $job->webhookUrl;
        $webhookMethod = $job->webhookMethod;
        $webhookHeaders = $this->encodeWebhookHeaders($job->webhookHeaders);
        $webhookStatusCode = $job->webhookStatusCode;
        $webhookResponse = $job->webhookResponseBody;
        $lastAttempted = $job->lastAttemptedAt;
        $completedAt = $job->completedAt;
        $errorMessage = $job->errorMessage;
        $createdAt = $job->createdAt;
        $updatedAt = $job->updatedAt;

        $userId = $job->userId;
        $modelId = $job->modelId;

        $stmt->bind_param(
            'iississssssssssssssi',
            $userId,
            $modelId,
            $scopeType,
            $scopeId,
            $startHour,
            $endHour,
            $format,
            $status,
            $filePath,
            $downloadToken,
            $webhookUrl,
            $webhookMethod,
            $webhookHeaders,
            $webhookStatusCode,
            $webhookResponse,
            $lastAttempted,
            $completedAt,
            $errorMessage,
            $createdAt,
            $updatedAt
        );

        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to insert attribution export: ' . $stmt->error);
        }

        $job->exportId = (int) $stmt->insert_id;
        $stmt->close();

        return $job;
    }

    public function update(ExportJob $job): ExportJob
    {
        $sql = 'UPDATE 202_attribution_exports SET status = ?, file_path = ?, download_token = ?, webhook_url = ?, webhook_method = ?, webhook_headers = ?, webhook_status_code = ?, webhook_response_body = ?, last_attempted_at = ?, completed_at = ?, error_message = ?, updated_at = ? WHERE export_id = ? LIMIT 1';
        $stmt = $this->write->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Unable to prepare export update statement: ' . $this->write->error);
        }

        $status = $job->status->value;
        $filePath = $job->filePath;
        $downloadToken = $job->downloadToken;
        $webhookUrl = $job->webhookUrl;
        $webhookMethod = $job->webhookMethod;
        $webhookHeaders = $this->encodeWebhookHeaders($job->webhookHeaders);
        $webhookStatusCode = $job->webhookStatusCode;
        $webhookResponse = $job->webhookResponseBody;
        $lastAttempted = $job->lastAttemptedAt;
        $completedAt = $job->completedAt;
        $errorMessage = $job->errorMessage;
        $updatedAt = $job->updatedAt;
        $exportId = $job->exportId;

        $stmt->bind_param(
            'ssssssssssssi',
            $status,
            $filePath,
            $downloadToken,
            $webhookUrl,
            $webhookMethod,
            $webhookHeaders,
            $webhookStatusCode,
            $webhookResponse,
            $lastAttempted,
            $completedAt,
            $errorMessage,
            $updatedAt,
            $exportId
        );

        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to update attribution export: ' . $stmt->error);
        }

        $stmt->close();

        return $job;
    }

    public function findById(int $exportId): ?ExportJob
    {
        $sql = 'SELECT * FROM 202_attribution_exports WHERE export_id = ? LIMIT 1';
        $stmt = $this->read->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Unable to prepare export lookup: ' . $this->read->error);
        }

        $stmt->bind_param('i', $exportId);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to fetch export job: ' . $stmt->error);
        }

        $result = $stmt->get_result();
        $row = $result?->fetch_assoc();
        $stmt->close();

        return $row ? $this->hydrate($row) : null;
    }

    public function findForUser(int $userId, ?int $modelId = null, int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT * FROM 202_attribution_exports WHERE user_id = ?';
        $types = 'i';
        $params = [$userId];

        if ($modelId !== null) {
            $sql .= ' AND model_id = ?';
            $types .= 'i';
            $params[] = $modelId;
        }

        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $types .= 'i';
        $params[] = $limit;

        $stmt = $this->read->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Unable to prepare export listing: ' . $this->read->error);
        }

        $paramsRefs = [];
        foreach ($params as $index => $value) {
            $paramsRefs[$index] = &$params[$index];
        }
        array_unshift($paramsRefs, $types);
        $stmt->bind_param(...$paramsRefs);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to list exports: ' . $stmt->error);
        }

        $result = $stmt->get_result();
        $jobs = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $jobs[] = $this->hydrate($row);
            }
        }

        $stmt->close();

        return $jobs;
    }

    public function claimPending(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $now = time();
        $sql = 'SELECT * FROM 202_attribution_exports WHERE status = ? ORDER BY created_at ASC LIMIT ?';
        $stmt = $this->write->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Unable to prepare pending selection: ' . $this->write->error);
        }

        $pending = ExportStatus::PENDING->value;
        $stmt->bind_param('si', $pending, $limit);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to query pending exports: ' . $stmt->error);
        }

        $result = $stmt->get_result();
        $jobs = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $job = $this->hydrate($row);
                $update = $this->write->prepare('UPDATE 202_attribution_exports SET status = ?, last_attempted_at = ?, updated_at = ? WHERE export_id = ? AND status = ? LIMIT 1');
                if (!$update) {
                    throw new \RuntimeException('Unable to prepare claim update: ' . $this->write->error);
                }

                $processing = ExportStatus::PROCESSING->value;
                $exportId = $job->exportId;
                $update->bind_param('siiis', $processing, $now, $now, $exportId, $pending);

                if ($update->execute() && $update->affected_rows === 1) {
                    $job->markProcessing($now);
                    $jobs[] = $job;
                }

                $update->close();
            }
        }

        $stmt->close();

        return $jobs;
    }

    /**
     * @param array<string, string> $headers
     */
    private function encodeWebhookHeaders(array $headers): ?string
    {
        return $headers !== [] ? json_encode($headers, JSON_THROW_ON_ERROR) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ExportJob
    {
        $headers = [];
        if (!empty($row['webhook_headers'])) {
            $decoded = json_decode((string) $row['webhook_headers'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    $headers[(string) $key] = (string) $value;
                }
            }
        }

        return new ExportJob(
            exportId: isset($row['export_id']) ? (int) $row['export_id'] : null,
            userId: (int) $row['user_id'],
            modelId: (int) $row['model_id'],
            scopeType: ScopeType::from((string) $row['scope_type']),
            scopeId: isset($row['scope_id']) ? (int) $row['scope_id'] : null,
            startHour: (int) $row['start_hour'],
            endHour: (int) $row['end_hour'],
            format: ExportFormat::from((string) $row['format']),
            status: ExportStatus::from((string) $row['status']),
            filePath: $row['file_path'] !== null ? (string) $row['file_path'] : null,
            downloadToken: $row['download_token'] !== null ? (string) $row['download_token'] : null,
            webhookUrl: $row['webhook_url'] !== null ? (string) $row['webhook_url'] : null,
            webhookMethod: (string) ($row['webhook_method'] ?? 'POST'),
            webhookHeaders: $headers,
            webhookStatusCode: isset($row['webhook_status_code']) ? (int) $row['webhook_status_code'] : null,
            webhookResponseBody: $row['webhook_response_body'] !== null ? (string) $row['webhook_response_body'] : null,
            lastAttemptedAt: isset($row['last_attempted_at']) ? (int) $row['last_attempted_at'] : null,
            completedAt: isset($row['completed_at']) ? (int) $row['completed_at'] : null,
            errorMessage: $row['error_message'] !== null ? (string) $row['error_message'] : null,
            createdAt: (int) $row['created_at'],
            updatedAt: (int) $row['updated_at'],
        );
    }
}
