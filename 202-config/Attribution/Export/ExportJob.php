<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Export;

use Prosper202\Attribution\ScopeType;

final class ExportJob
{
    /**
     * @param array<string, string> $webhookHeaders
     */
    public function __construct(
        public ?int $exportId,
        public int $userId,
        public int $modelId,
        public ScopeType $scopeType,
        public ?int $scopeId,
        public int $startHour,
        public int $endHour,
        public ExportFormat $format,
        public ExportStatus $status,
        public ?string $filePath,
        public ?string $downloadToken,
        public ?string $webhookUrl,
        public string $webhookMethod,
        public array $webhookHeaders,
        public ?int $webhookStatusCode,
        public ?string $webhookResponseBody,
        public ?int $lastAttemptedAt,
        public ?int $completedAt,
        public ?string $errorMessage,
        public int $createdAt,
        public int $updatedAt,
    ) {
    }

    public function markProcessing(int $timestamp): void
    {
        $this->status = ExportStatus::PROCESSING;
        $this->lastAttemptedAt = $timestamp;
        $this->updatedAt = $timestamp;
        $this->errorMessage = null;
    }

    public function markCompleted(string $filePath, int $timestamp, ?int $statusCode = null, ?string $responseBody = null, ?string $error = null): void
    {
        $this->status = ExportStatus::COMPLETED;
        $this->filePath = $filePath;
        $this->completedAt = $timestamp;
        $this->lastAttemptedAt = $timestamp;
        $this->updatedAt = $timestamp;
        $this->webhookStatusCode = $statusCode;
        $this->webhookResponseBody = $responseBody;
        $this->errorMessage = $error;
    }

    public function markFailed(string $message, int $timestamp): void
    {
        $this->status = ExportStatus::FAILED;
        $this->errorMessage = $message;
        $this->lastAttemptedAt = $timestamp;
        $this->updatedAt = $timestamp;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'export_id' => $this->exportId,
            'user_id' => $this->userId,
            'model_id' => $this->modelId,
            'scope_type' => $this->scopeType->value,
            'scope_id' => $this->scopeId,
            'start_hour' => $this->startHour,
            'end_hour' => $this->endHour,
            'format' => $this->format->value,
            'status' => $this->status->value,
            'file_path' => $this->filePath,
            'download_token' => $this->downloadToken,
            'webhook_url' => $this->webhookUrl,
            'webhook_method' => $this->webhookMethod,
            'webhook_headers' => $this->webhookHeaders,
            'webhook_status_code' => $this->webhookStatusCode,
            'webhook_response_body' => $this->webhookResponseBody,
            'last_attempted_at' => $this->lastAttemptedAt,
            'completed_at' => $this->completedAt,
            'error_message' => $this->errorMessage,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
