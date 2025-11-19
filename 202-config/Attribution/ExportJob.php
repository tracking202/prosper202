<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use InvalidArgumentException;

/**
 * Immutable value object describing a scheduled attribution export job.
 */
final class ExportJob
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly ?int $exportId,
        public readonly int $userId,
        public readonly int $modelId,
        public readonly ScopeType $scopeType,
        public readonly ?int $scopeId,
        public readonly int $startHour,
        public readonly int $endHour,
        public readonly ExportFormat $format,
        public readonly array $options,
        public readonly ?ExportWebhook $webhook,
        public readonly ExportStatus $status,
        public readonly int $queuedAt,
        public readonly ?int $startedAt,
        public readonly ?int $completedAt,
        public readonly ?int $failedAt,
        public readonly ?string $filePath,
        public readonly ?int $rowsExported,
        public readonly ?string $lastError,
        public readonly ?int $webhookAttemptedAt,
        public readonly ?int $webhookStatusCode,
        public readonly ?string $webhookResponseBody,
        public readonly int $createdAt,
        public readonly int $updatedAt
    ) {
        if ($this->startHour > $this->endHour) {
            throw new InvalidArgumentException('Export start hour must be before the end hour.');
        }

        if ($this->queuedAt <= 0) {
            throw new InvalidArgumentException('Queued timestamp must be provided.');
        }
    }

    /**
     * Hydrates a job from raw database row data.
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $options = self::decodeOptions($row['options'] ?? null);
        $webhook = null;
        if (!empty($row['webhook_url'])) {
            $webhook = new ExportWebhook(
                (string) $row['webhook_url'],
                isset($row['webhook_secret']) && $row['webhook_secret'] !== '' ? (string) $row['webhook_secret'] : null,
                self::decodeHeaders($row['webhook_headers'] ?? null)
            );
        }

        return new self(
            exportId: isset($row['export_id']) ? (int) $row['export_id'] : null,
            userId: (int) $row['user_id'],
            modelId: (int) $row['model_id'],
            scopeType: ScopeType::from((string) $row['scope_type']),
            scopeId: isset($row['scope_id']) ? (int) $row['scope_id'] : null,
            startHour: (int) $row['start_hour'],
            endHour: (int) $row['end_hour'],
            format: ExportFormat::from((string) $row['requested_format']),
            options: $options,
            webhook: $webhook,
            status: ExportStatus::from((string) $row['status']),
            queuedAt: (int) $row['queued_at'],
            startedAt: isset($row['started_at']) ? (int) $row['started_at'] : null,
            completedAt: isset($row['completed_at']) ? (int) $row['completed_at'] : null,
            failedAt: isset($row['failed_at']) ? (int) $row['failed_at'] : null,
            filePath: isset($row['file_path']) ? (string) $row['file_path'] : null,
            rowsExported: isset($row['rows_exported']) ? (int) $row['rows_exported'] : null,
            lastError: isset($row['last_error']) ? (string) $row['last_error'] : null,
            webhookAttemptedAt: isset($row['webhook_attempted_at']) ? (int) $row['webhook_attempted_at'] : null,
            webhookStatusCode: isset($row['webhook_status_code']) ? (int) $row['webhook_status_code'] : null,
            webhookResponseBody: isset($row['webhook_response_body']) ? (string) $row['webhook_response_body'] : null,
            createdAt: (int) $row['created_at'],
            updatedAt: (int) $row['updated_at']
        );
    }

    /**
     * Serialises the job for persistence.
     *
     * @return array<string, mixed>
     */
    public function toDatabaseRow(): array
    {
        return [
            'export_id' => $this->exportId,
            'user_id' => $this->userId,
            'model_id' => $this->modelId,
            'scope_type' => $this->scopeType->value,
            'scope_id' => $this->scopeId,
            'start_hour' => $this->startHour,
            'end_hour' => $this->endHour,
            'requested_format' => $this->format->value,
            'status' => $this->status->value,
            'options' => $this->encodeOptions(),
            'webhook_url' => $this->webhook?->url,
            'webhook_secret' => $this->webhook?->secret,
            'webhook_headers' => $this->encodeHeaders($this->webhook?->headers ?? []),
            'file_path' => $this->filePath,
            'rows_exported' => $this->rowsExported,
            'queued_at' => $this->queuedAt,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'failed_at' => $this->failedAt,
            'last_error' => $this->lastError,
            'webhook_attempted_at' => $this->webhookAttemptedAt,
            'webhook_status_code' => $this->webhookStatusCode,
            'webhook_response_body' => $this->webhookResponseBody,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Provides a normalised payload suitable for API responses.
     *
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        return [
            'export_id' => $this->exportId,
            'model_id' => $this->modelId,
            'status' => $this->status->value,
            'format' => $this->format->value,
            'scope_type' => $this->scopeType->value,
            'scope_id' => $this->scopeId,
            'start_hour' => $this->startHour,
            'end_hour' => $this->endHour,
            'queued_at' => $this->queuedAt,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'failed_at' => $this->failedAt,
            'rows_exported' => $this->rowsExported,
            'file_path' => $this->filePath,
            'last_error' => $this->lastError,
            'webhook' => $this->webhook?->toArray(),
            'webhook_status_code' => $this->webhookStatusCode,
            'webhook_attempted_at' => $this->webhookAttemptedAt,
        ];
    }

    public function withStatus(ExportStatus $status, array $changes = []): self
    {
        return new self(
            exportId: $changes['export_id'] ?? $this->exportId,
            userId: $changes['user_id'] ?? $this->userId,
            modelId: $changes['model_id'] ?? $this->modelId,
            scopeType: $changes['scope_type'] ?? $this->scopeType,
            scopeId: $changes['scope_id'] ?? $this->scopeId,
            startHour: $changes['start_hour'] ?? $this->startHour,
            endHour: $changes['end_hour'] ?? $this->endHour,
            format: $changes['format'] ?? $this->format,
            options: $changes['options'] ?? $this->options,
            webhook: $changes['webhook'] ?? $this->webhook,
            status: $status,
            queuedAt: $changes['queued_at'] ?? $this->queuedAt,
            startedAt: $changes['started_at'] ?? $this->startedAt,
            completedAt: $changes['completed_at'] ?? $this->completedAt,
            failedAt: $changes['failed_at'] ?? $this->failedAt,
            filePath: $changes['file_path'] ?? $this->filePath,
            rowsExported: $changes['rows_exported'] ?? $this->rowsExported,
            lastError: $changes['last_error'] ?? $this->lastError,
            webhookAttemptedAt: $changes['webhook_attempted_at'] ?? $this->webhookAttemptedAt,
            webhookStatusCode: $changes['webhook_status_code'] ?? $this->webhookStatusCode,
            webhookResponseBody: $changes['webhook_response_body'] ?? $this->webhookResponseBody,
            createdAt: $changes['created_at'] ?? $this->createdAt,
            updatedAt: $changes['updated_at'] ?? $this->updatedAt
        );
    }

    /**
     * @param array<string, mixed>|string|null $raw
     * @return array<string, mixed>
     */
    private static function decodeOptions(array|string|null $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param array<string, string>|string|null $raw
     * @return array<string, string>
     */
    private static function decodeHeaders(array|string|null $raw): array
    {
        if (is_array($raw)) {
            $headers = [];
            foreach ($raw as $key => $value) {
                if ($key === '' || !is_string($value)) {
                    continue;
                }
                $headers[(string) $key] = $value;
            }

            return $headers;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return self::decodeHeaders($decoded);
            }
        }

        return [];
    }

    /**
     * @return string|null
     */
    private function encodeOptions(): ?string
    {
        if (empty($this->options)) {
            return null;
        }

        return json_encode($this->options, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $headers
     */
    private function encodeHeaders(array $headers): ?string
    {
        if (empty($headers)) {
            return null;
        }

        return json_encode($headers, JSON_THROW_ON_ERROR);
    }
}
