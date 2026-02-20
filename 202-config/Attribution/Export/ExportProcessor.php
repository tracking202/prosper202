<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Export;

use Prosper202\Attribution\Repository\ExportRepositoryInterface;
use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use Prosper202\Attribution\Repository\SnapshotRepositoryInterface;

final readonly class ExportProcessor
{
    public function __construct(
        private ExportRepositoryInterface $exportRepository,
        private SnapshotRepositoryInterface $snapshotRepository,
        private ModelRepositoryInterface $modelRepository,
        private SnapshotExporter $snapshotExporter,
        private WebhookDispatcher $webhookDispatcher,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function processPending(int $limit = 5): array
    {
        $jobs = $this->exportRepository->claimPending($limit);
        $results = [];

        foreach ($jobs as $job) {
            try {
                $model = $this->modelRepository->findById($job->modelId);
                if ($model === null || $model->userId !== $job->userId) {
                    throw new \RuntimeException('Attribution model no longer available.');
                }

                $snapshots = $this->snapshotRepository->findForRange(
                    $job->modelId,
                    $job->scopeType,
                    $job->scopeId,
                    $job->startHour,
                    $job->endHour,
                    1000,
                    0
                );

                $filePath = $this->snapshotExporter->export($job, $snapshots);
                $webhookResult = $this->webhookDispatcher->dispatch($job, $filePath);
                $job->markCompleted($filePath, time(), $webhookResult->statusCode, $webhookResult->responseBody, $webhookResult->errorMessage);
                $this->exportRepository->update($job);

                $results[] = [
                    'export_id' => $job->exportId,
                    'status' => 'completed',
                    'webhook_status_code' => $webhookResult->statusCode,
                    'webhook_error' => $webhookResult->errorMessage,
                ];
            } catch (\Throwable $exception) {
                $job->markFailed($exception->getMessage(), time());
                $this->exportRepository->update($job);

                $results[] = [
                    'export_id' => $job->exportId,
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $results;
    }
}
