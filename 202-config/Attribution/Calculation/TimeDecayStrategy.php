<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Calculation;

use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Snapshot;
use Prosper202\Attribution\Touchpoint;

final class TimeDecayStrategy implements AttributionStrategyInterface
{
    private const DEFAULT_HALF_LIFE_HOURS = 48;

    public function calculate(ModelDefinition $model, ConversionBatch $batch): CalculationResult
    {
        if ($batch->isEmpty()) {
            return new CalculationResult([], []);
        }

        $halfLifeHours = $this->resolveHalfLifeHours($model);
        $decayConstant = log(2) / max($halfLifeHours, 1);
        $now = time();

        $snapshotsByHour = [];
        $touchpointsByHour = [];

        foreach ($batch->conversions as $conversion) {
            $bucket = (int) ($conversion->convTime - ($conversion->convTime % 3600));
            $credit = $this->calculateCredit($conversion, $decayConstant, $now);

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
                    createdAt: $now
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
                attributedRevenue: $snapshot->attributedRevenue + ($conversion->clickPayout * $credit),
                attributedCost: $snapshot->attributedCost + ($conversion->clickCost * $credit),
                createdAt: $snapshot->createdAt
            );

            $snapshotsByHour[$bucket] = $snapshot;

            $touchpointsByHour[$bucket][] = new Touchpoint(
                touchpointId: null,
                snapshotId: null,
                conversionId: $conversion->conversionId,
                clickId: $conversion->clickId,
                position: 0,
                credit: $credit,
                weight: $credit,
                createdAt: $now
            );
        }

        return new CalculationResult($snapshotsByHour, $touchpointsByHour);
    }

    private function resolveHalfLifeHours(ModelDefinition $model): int
    {
        $config = $model->weightingConfig;
        if (isset($config['half_life_hours']) && is_numeric($config['half_life_hours'])) {
            return max(1, (int) $config['half_life_hours']);
        }

        return self::DEFAULT_HALF_LIFE_HOURS;
    }

    private function calculateCredit(ConversionRecord $conversion, float $decayConstant, int $now): float
    {
        $hours = max(0, ($now - $conversion->convTime) / 3600);
        return (float) exp(-$decayConstant * $hours);
    }
}
