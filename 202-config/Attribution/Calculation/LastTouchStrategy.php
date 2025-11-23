<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Calculation;

use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Snapshot;
use Prosper202\Attribution\Touchpoint;

/**
 * Simplistic last-touch attribution strategy that mirrors legacy Prosper behaviour.
 */
final class LastTouchStrategy implements AttributionStrategyInterface
{
    public function calculate(ModelDefinition $model, ConversionBatch $batch): CalculationResult
    {
        if ($batch->isEmpty()) {
            return new CalculationResult([], []);
        }

        $snapshotsByHour = [];
        $touchpointsByHour = [];

        $createdAt = time();

        foreach ($batch->conversions as $conversion) {
            $bucket = (int) ($conversion->convTime - ($conversion->convTime % 3600));

            if (!isset($snapshotsByHour[$bucket])) {
                $snapshotsByHour[$bucket] = new Snapshot(
                    snapshotId: null,
                    modelId: (int) ($model->modelId ?? 0),
                    userId: $batch->userId,
                    scopeType: ScopeType::GLOBAL,
                    scopeId: null,
                    dateHour: $bucket,
                    lookbackStart: $batch->startTime,
                    lookbackEnd: $batch->endTime,
                    attributedClicks: 0,
                    attributedConversions: 0,
                    attributedRevenue: 0.0,
                    attributedCost: 0.0,
                    createdAt: $createdAt
                );
                $touchpointsByHour[$bucket] = [];
            }

            $snapshot = $snapshotsByHour[$bucket];
            $snapshot = new Snapshot(
                snapshotId: null,
                modelId: $snapshot->modelId,
                userId: $snapshot->userId,
                scopeType: $snapshot->scopeType,
                scopeId: $snapshot->scopeId,
                dateHour: $snapshot->dateHour,
                lookbackStart: $snapshot->lookbackStart,
                lookbackEnd: $snapshot->lookbackEnd,
                attributedClicks: $snapshot->attributedClicks + 1,
                attributedConversions: $snapshot->attributedConversions + 1,
                attributedRevenue: $snapshot->attributedRevenue + $conversion->clickPayout,
                attributedCost: $snapshot->attributedCost + $conversion->clickCost,
                createdAt: $snapshot->createdAt
            );

            $snapshotsByHour[$bucket] = $snapshot;

            $touchpointsByHour[$bucket][] = new Touchpoint(
                touchpointId: null,
                snapshotId: null,
                conversionId: $conversion->conversionId,
                clickId: $conversion->clickId,
                position: 0,
                credit: 1.0,
                weight: 1.0,
                createdAt: $createdAt
            );
        }

        return new CalculationResult($snapshotsByHour, $touchpointsByHour);
    }
}
