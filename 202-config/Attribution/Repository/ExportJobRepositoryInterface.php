<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository;

use Prosper202\Attribution\ExportJob;
use Prosper202\Attribution\ExportStatus;

/**
 * Persists and retrieves attribution export jobs.
 */
interface ExportJobRepositoryInterface
{
    public function create(ExportJob $job): ExportJob;

    public function findById(int $jobId): ?ExportJob;

    /**
     * @return ExportJob[]
     */
    public function findPending(int $limit = 10): array;

    public function markProcessing(int $jobId, int $timestamp): void;

    public function markCompleted(int $jobId, string $filePath, int $rowsExported, int $timestamp): void;

    public function markFailed(int $jobId, string $error, int $timestamp): void;

    public function recordWebhookAttempt(int $jobId, int $timestamp, ?int $statusCode, ?string $responseBody): void;

    /**
     * @return ExportJob[]
     */
    public function listRecentForModel(int $userId, int $modelId, int $limit = 20): array;
}
