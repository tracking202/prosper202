<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Calculation;

use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Snapshot;
use Prosper202\Attribution\Touchpoint;

final class TimeDecayStrategy implements AttributionStrategyInterface
{
    private const int DEFAULT_HALF_LIFE_HOURS = 48;

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
            $journey = $conversion->getJourney();
            $credits = $this->calculateJourneyCredits($journey, $conversion->convTime, $decayConstant);

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

            $attributedClicks = 0;
            $attributedRevenue = 0.0;
            $attributedCost = 0.0;

            foreach ($journey as $position => $touch) {
                $credit = $credits[$position] ?? 0.0;
                if ($credit <= 0.0) {
                    continue;
                }

                $attributedClicks++;
                $attributedRevenue += $conversion->clickPayout * $credit;
                $attributedCost += $conversion->clickCost * $credit;

                $touchpointsByHour[$bucket][] = new Touchpoint(
                    touchpointId: null,
                    snapshotId: null,
                    conversionId: $conversion->conversionId,
                    clickId: $touch->clickId,
                    position: $position,
                    credit: $credit,
                    weight: $credit,
                    createdAt: $now
                );
            }

            if ($attributedClicks === 0) {
                $touchpointsByHour[$bucket][] = new Touchpoint(
                    touchpointId: null,
                    snapshotId: null,
                    conversionId: $conversion->conversionId,
                    clickId: $conversion->clickId,
                    position: max(0, count($journey) - 1),
                    credit: 1.0,
                    weight: 1.0,
                    createdAt: $now
                );

                $attributedClicks = 1;
                $attributedRevenue = $conversion->clickPayout;
                $attributedCost = $conversion->clickCost;
            }

            $snapshot = $snapshotsByHour[$bucket];
            $snapshotsByHour[$bucket] = new Snapshot(
                snapshotId: null,
                modelId: $snapshot->modelId,
                userId: $snapshot->userId,
                scopeType: $snapshot->scopeType,
                scopeId: $snapshot->scopeId,
                dateHour: $snapshot->dateHour,
                lookbackStart: $snapshot->lookbackStart,
                lookbackEnd: $snapshot->lookbackEnd,
                attributedClicks: $snapshot->attributedClicks + $attributedClicks,
                attributedConversions: $snapshot->attributedConversions + 1,
                attributedRevenue: $snapshot->attributedRevenue + $attributedRevenue,
                attributedCost: $snapshot->attributedCost + $attributedCost,
                createdAt: $snapshot->createdAt
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

    /**
     * @param ConversionTouchpoint[] $journey
     * @return array<int, float>
     */
    private function calculateJourneyCredits(array $journey, int $conversionTime, float $decayConstant): array
    {
        if ($journey === []) {
            return [];
        }

        $weights = [];
        foreach ($journey as $index => $touch) {
            $hours = max(0, ($conversionTime - $touch->clickTime) / 3600);
            $weights[$index] = (float) exp(-$decayConstant * $hours);
        }

        $total = array_sum($weights);
        if ($total <= 0.0) {
            $count = count($journey);
            $share = 1.0 / $count;
            return array_fill(0, $count, $share);
        }

        foreach ($weights as $index => $weight) {
            $weights[$index] = max(0.0, $weight / $total);
        }

        $normalisedTotal = array_sum($weights);
        if (abs($normalisedTotal - 1.0) > 0.0001) {
            // Guard against empty $weights to avoid invalid array index
            if ($weights !== []) {
                $lastIndex = array_key_last($weights) ?? (count($journey) - 1);
                $weights[$lastIndex] = max(0.0, ($weights[$lastIndex] ?? 0.0) + (1.0 - $normalisedTotal));
            }
        }

        return $weights;
    }
}
