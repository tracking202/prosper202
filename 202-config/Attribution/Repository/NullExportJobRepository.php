<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository;

use Prosper202\Attribution\ExportJob;
use RuntimeException;

final class NullExportJobRepository implements ExportJobRepositoryInterface
{
    public function create(ExportJob $job): ExportJob
    {
        throw new RuntimeException('Export job repository is not configured.');
    }

    public function findById(int $jobId): ?ExportJob
    {
        return null;
    }

    public function findPending(int $limit = 10): array
    {
        return [];
    }

    public function markProcessing(int $jobId, int $timestamp): void
    {
    }

    public function markCompleted(int $jobId, string $filePath, int $rowsExported, int $timestamp): void
    {
    }

    public function markFailed(int $jobId, string $error, int $timestamp): void
    {
    }

    public function recordWebhookAttempt(int $jobId, int $timestamp, ?int $statusCode, ?string $responseBody): void
    {
    }

    public function listRecentForModel(int $userId, int $modelId, int $limit = 20): array
    {
        return [];
    }
}
