<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository;

use Prosper202\Attribution\Export\ExportJob;

interface ExportRepositoryInterface
{
    public function create(ExportJob $job): ExportJob;

    public function update(ExportJob $job): ExportJob;

    public function findById(int $exportId): ?ExportJob;

    /**
     * @return ExportJob[]
     */
    public function findForUser(int $userId, ?int $modelId = null, int $limit = 25): array;

    /**
     * Claims a batch of pending jobs and marks them as processing.
     *
     * @return ExportJob[]
     */
    public function claimPending(int $limit = 10): array;
}
