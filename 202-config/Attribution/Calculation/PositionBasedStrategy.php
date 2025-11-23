<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Calculation;

use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Snapshot;
use Prosper202\Attribution\Touchpoint;

final class PositionBasedStrategy implements AttributionStrategyInterface
{
    private const DEFAULT_FIRST_TOUCH_WEIGHT = 0.4;
    private const DEFAULT_LAST_TOUCH_WEIGHT = 0.4;

    public function calculate(ModelDefinition $model, ConversionBatch $batch): CalculationResult
    {
        if ($batch->isEmpty()) {
            return new CalculationResult([], []);
        }

        $weights = $this->resolveWeights($model);
        $snapshotsByHour = [];
        $touchpointsByHour = [];
        $now = time();

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
                    createdAt: $now
                );
                $touchpointsByHour[$bucket] = [];
            }

            $journey = $conversion->getJourney();
            $credits = $this->calculateJourneyCredits($journey, $weights);

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

    /**
     * @return array{first: float, middle: float, last: float}
     */
    private function resolveWeights(ModelDefinition $model): array
    {
        $config = $model->weightingConfig;
        $first = isset($config['first_touch_weight']) ? (float) $config['first_touch_weight'] : self::DEFAULT_FIRST_TOUCH_WEIGHT;
        $last = isset($config['last_touch_weight']) ? (float) $config['last_touch_weight'] : self::DEFAULT_LAST_TOUCH_WEIGHT;
        $middle = max(0.0, 1.0 - ($first + $last));

        if ($first < 0.0 || $last < 0.0) {
            $first = self::DEFAULT_FIRST_TOUCH_WEIGHT;
            $last = self::DEFAULT_LAST_TOUCH_WEIGHT;
            $middle = 1.0 - ($first + $last);
        }

        return [
            'first' => $first,
            'middle' => $middle,
            'last' => $last,
        ];
    }

    /**
     * @param ConversionTouchpoint[] $journey
     * @param array{first: float, middle: float, last: float} $weights
     * @return array<int, float>
     */
    private function calculateJourneyCredits(array $journey, array $weights): array
    {
        $count = count($journey);
        if ($count === 0) {
            return [];
        }

        if ($count === 1) {
            return [0 => 1.0];
        }

        if ($count === 2) {
            $firstWeight = max(0.0, $weights['first']);
            $lastWeight = max(0.0, $weights['last']);
            $total = $firstWeight + $lastWeight;

            if ($total <= 0.0) {
                return [0 => 0.5, 1 => 0.5];
            }

            return [
                0 => $firstWeight / $total,
                1 => $lastWeight / $total,
            ];
        }

        $credits = [];
        $firstWeight = max(0.0, $weights['first']);
        $lastWeight = max(0.0, $weights['last']);
        $middleWeight = max(0.0, $weights['middle']);

        $credits[0] = $firstWeight;
        $credits[$count - 1] = $lastWeight;

        $middleCount = $count - 2;
        if ($middleCount > 0) {
            $share = $middleCount > 0 ? $middleWeight / $middleCount : 0.0;
            for ($i = 1; $i < $count - 1; $i++) {
                $credits[$i] = $share;
            }
        }

        $sum = array_sum($credits);
        if ($sum <= 0.0) {
            $equalShare = 1.0 / $count;
            $credits = [];
            for ($i = 0; $i < $count; $i++) {
                $credits[$i] = $equalShare;
            }

            return $credits;
        }

        $scale = 1.0 / $sum;
        for ($i = 0; $i < $count; $i++) {
            $value = $credits[$i] ?? 0.0;
            $credits[$i] = max(0.0, $value * $scale);
        }

        $total = array_sum($credits);
        if (abs($total - 1.0) > 0.0001) {
            $difference = 1.0 - $total;
            $lastIndex = $count - 1;
            $credits[$lastIndex] = max(0.0, $credits[$lastIndex] + $difference);
        }

        return $credits;
    }
}
