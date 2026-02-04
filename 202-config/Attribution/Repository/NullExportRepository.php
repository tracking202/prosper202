<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository;

use Prosper202\Attribution\Export\ExportJob;
final class NullExportRepository implements ExportRepositoryInterface
{
    public function create(ExportJob $job): ExportJob
    {
        return $job;
    }

    public function update(ExportJob $job): ExportJob
    {
        return $job;
    }

    public function findById(int $exportId): ?ExportJob
    {
        return null;
    }

    public function findForUser(int $userId, ?int $modelId = null, int $limit = 25): array
    {
        return [];
    }

    public function claimPending(int $limit = 10): array
    {
        return [];
    }
}
