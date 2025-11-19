<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Attribution\Calculation\AttributionStrategyInterface;
use Prosper202\Attribution\Calculation\ConversionBatch;
use Prosper202\Attribution\Calculation\LastTouchStrategy;
use Prosper202\Attribution\Calculation\TimeDecayStrategy;
use Prosper202\Attribution\Calculation\PositionBasedStrategy;
use Prosper202\Attribution\Calculation\AssistedStrategy;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\Repository\AuditRepositoryInterface;
use Prosper202\Attribution\Repository\ConversionRepositoryInterface;
use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use Prosper202\Attribution\Repository\SnapshotRepositoryInterface;
use Prosper202\Attribution\Repository\TouchpointRepositoryInterface;
use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Snapshot;
use Prosper202\Attribution\Touchpoint;
use RuntimeException;

final class AttributionJobRunner
{
    private const BATCH_LIMIT = 5000;

    public function __construct(
        private readonly ModelRepositoryInterface $modelRepository,
        private readonly SnapshotRepositoryInterface $snapshotRepository,
        private readonly TouchpointRepositoryInterface $touchpointRepository,
        private readonly ConversionRepositoryInterface $conversionRepository,
        private readonly AuditRepositoryInterface $auditRepository
    ) {
    }

    public function runForUser(int $userId, int $startTime, int $endTime): void
    {
        if ($startTime >= $endTime) {
            throw new RuntimeException('Start time must be earlier than end time for attribution job.');
        }

        $models = $this->modelRepository->findForUser($userId, null, true);
        if ($models === []) {
            return;
        }

        $modelsState = [];
        foreach ($models as $model) {
            if ($model->modelId === null) {
                continue;
            }
            $modelsState[$model->modelId] = $this->initialiseModelState($model, $userId, $startTime, $endTime);
        }

        if ($modelsState === []) {
            return;
        }

        $lastConversionId = null;
        $processed = false;

        do {
            $batch = $this->conversionRepository->fetchForUser($userId, $startTime, $endTime, $lastConversionId, self::BATCH_LIMIT);
            if ($batch->isEmpty()) {
                break;
            }

            $processed = true;

            foreach ($models as $model) {
                if ($model->modelId === null) {
                    continue;
                }

                $state = &$modelsState[$model->modelId];
                $this->processBatch($model, $batch, $state);
            }

            $conversions = $batch->conversions;
            $lastRecord = $conversions !== [] ? end($conversions) : null;
            $lastConversionId = $lastRecord?->conversionId;
        } while ($lastConversionId !== null);

        if (!$processed) {
            return;
        }

        $summaries = [];

        foreach ($models as $model) {
            if ($model->modelId === null) {
                continue;
            }

            $state = $modelsState[$model->modelId];
            $summaries[] = [$model, $this->finaliseSnapshots($model, $state)];
        }

        foreach ($summaries as [$model, $summary]) {
            $this->auditRepository->record(
                $summary['user_id'],
                $model->modelId,
                'snapshot_rebuild',
                [
                    'lookback_start' => $summary['lookback_start'],
                    'lookback_end' => $summary['lookback_end'],
                    'batches' => $summary['metrics']['batches'],
                    'hours_processed' => count($summary['hours']),
                    'clicks' => $summary['metrics']['clicks'],
                    'conversions' => $summary['metrics']['conversions'],
                    'revenue' => $summary['metrics']['revenue'],
                    'cost' => $summary['metrics']['cost'],
                ]
            );

            $this->logModelRun($model, $summary);
        }
    }

    private function resolveStrategy(ModelDefinition $model): AttributionStrategyInterface
    {
        return match ($model->type) {
            ModelType::LAST_TOUCH => new LastTouchStrategy(),
            ModelType::TIME_DECAY => new TimeDecayStrategy(),
            ModelType::POSITION_BASED => new PositionBasedStrategy(),
            ModelType::ASSISTED => new AssistedStrategy(),
            default => new LastTouchStrategy(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function initialiseModelState(ModelDefinition $model, int $userId, int $startTime, int $endTime): array
    {
        $state = [
            'user_id' => $userId,
            'lookback_start' => $startTime,
            'lookback_end' => $endTime,
            'snapshot_ids' => [],
            'snapshot_totals' => [],
            'created_at' => [],
            'metrics' => [
                'clicks' => 0,
                'conversions' => 0,
                'revenue' => 0.0,
                'cost' => 0.0,
                'batches' => 0,
            ],
        ];

        if ($model->modelId === null) {
            return $state;
        }

        $startHour = $this->floorToHour($startTime);
        $endHour = $this->floorToHour($endTime);

        $existingSnapshots = $this->snapshotRepository->findForRange($model->modelId, ScopeType::GLOBAL, null, $startHour, $endHour, 10000, 0);
        foreach ($existingSnapshots as $snapshot) {
            if ($snapshot->snapshotId === null) {
                continue;
            }

            $bucket = $snapshot->dateHour;
            $state['snapshot_ids'][$bucket] = (int) $snapshot->snapshotId;
            $state['created_at'][$bucket] = $snapshot->createdAt;

            $this->touchpointRepository->deleteBySnapshot((int) $snapshot->snapshotId);
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function processBatch(ModelDefinition $model, ConversionBatch $batch, array &$state): void
    {
        $strategy = $this->resolveStrategy($model);
        $result = $strategy->calculate($model, $batch);

        foreach ($result->snapshotsByHour as $bucket => $snapshot) {
            if (!isset($state['snapshot_ids'][$bucket])) {
                $placeholder = new Snapshot(
                    snapshotId: null,
                    modelId: (int) ($model->modelId ?? 0),
                    userId: $state['user_id'],
                    scopeType: ScopeType::GLOBAL,
                    scopeId: null,
                    dateHour: $bucket,
                    lookbackStart: $state['lookback_start'],
                    lookbackEnd: $state['lookback_end'],
                    attributedClicks: 0,
                    attributedConversions: 0,
                    attributedRevenue: 0.0,
                    attributedCost: 0.0,
                    createdAt: time()
                );

                $persisted = $this->snapshotRepository->save($placeholder);
                $state['snapshot_ids'][$bucket] = (int) ($persisted->snapshotId ?? 0);
                $state['created_at'][$bucket] = $persisted->createdAt;

                if ($state['snapshot_ids'][$bucket] === 0) {
                    throw new RuntimeException('Failed to create attribution snapshot placeholder.');
                }
            }

            $totals = $state['snapshot_totals'][$bucket] ?? [
                'clicks' => 0,
                'conversions' => 0,
                'revenue' => 0.0,
                'cost' => 0.0,
            ];

            $totals['clicks'] += $snapshot->attributedClicks;
            $totals['conversions'] += $snapshot->attributedConversions;
            $totals['revenue'] += $snapshot->attributedRevenue;
            $totals['cost'] += $snapshot->attributedCost;

            $state['snapshot_totals'][$bucket] = $totals;

            $state['metrics']['clicks'] += $snapshot->attributedClicks;
            $state['metrics']['conversions'] += $snapshot->attributedConversions;
            $state['metrics']['revenue'] += $snapshot->attributedRevenue;
            $state['metrics']['cost'] += $snapshot->attributedCost;

            $snapshotId = $state['snapshot_ids'][$bucket];
            $touchpointsToSave = [];
            $hourTouchpoints = $result->touchpointsByHour[$bucket] ?? [];
            foreach ($hourTouchpoints as $point) {
                $touchpointsToSave[] = new Touchpoint(
                    touchpointId: null,
                    snapshotId: $snapshotId,
                    conversionId: $point->conversionId,
                    clickId: $point->clickId,
                    position: $point->position,
                    credit: $point->credit,
                    weight: $point->weight,
                    createdAt: $point->createdAt
                );
            }

            if ($touchpointsToSave !== []) {
                $this->touchpointRepository->saveBatch($touchpointsToSave);
            }
        }

        $state['metrics']['batches']++;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function finaliseSnapshots(ModelDefinition $model, array $state): array
    {
        if ($model->modelId === null) {
            return [];
        }

        foreach ($state['snapshot_totals'] as $bucket => $totals) {
            $snapshotId = $state['snapshot_ids'][$bucket] ?? null;
            if ($snapshotId === null) {
                continue;
            }

            $snapshot = new Snapshot(
                snapshotId: $snapshotId,
                modelId: (int) $model->modelId,
                userId: $state['user_id'],
                scopeType: ScopeType::GLOBAL,
                scopeId: null,
                dateHour: (int) $bucket,
                lookbackStart: $state['lookback_start'],
                lookbackEnd: $state['lookback_end'],
                attributedClicks: $totals['clicks'],
                attributedConversions: $totals['conversions'],
                attributedRevenue: $totals['revenue'],
                attributedCost: $totals['cost'],
                createdAt: $state['created_at'][$bucket] ?? time()
            );

            $this->snapshotRepository->save($snapshot);
        }

        return [
            'user_id' => $state['user_id'],
            'lookback_start' => $state['lookback_start'],
            'lookback_end' => $state['lookback_end'],
            'hours' => array_keys($state['snapshot_totals']),
            'metrics' => $state['metrics'],
        ];
    }

    private function floorToHour(int $timestamp): int
    {
        return (int) ($timestamp - ($timestamp % 3600));
    }

    private function logModelRun(ModelDefinition $model, array $summary): void
    {
        if (!function_exists('prosper_log')) {
            return;
        }

        $metrics = $summary['metrics'] ?? [];
        $message = sprintf(
            'model_id=%d user_id=%d batches=%d hours=%d clicks=%d conversions=%d revenue=%0.4f cost=%0.4f window=%d-%d',
            $model->modelId ?? 0,
            $summary['user_id'] ?? 0,
            (int) ($metrics['batches'] ?? 0),
            count($summary['hours'] ?? []),
            (int) ($metrics['clicks'] ?? 0),
            (int) ($metrics['conversions'] ?? 0),
            (float) ($metrics['revenue'] ?? 0.0),
            (float) ($metrics['cost'] ?? 0.0),
            $summary['lookback_start'] ?? 0,
            $summary['lookback_end'] ?? 0
        );

        prosper_log('attribution_job', $message);
    }
}
