<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Calculation;

use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Snapshot;
use Prosper202\Attribution\Touchpoint;

final class AssistedStrategy implements AttributionStrategyInterface
{
    public function calculate(ModelDefinition $model, ConversionBatch $batch): CalculationResult
    {
        if ($batch->isEmpty()) {
            return new CalculationResult([], []);
        }

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
            $journeyCount = count($journey);
            $assistCount = $journeyCount > 1 ? $journeyCount - 1 : 1;
            $creditShare = $assistCount > 0 ? 1.0 / $assistCount : 1.0;

            $attributedClicks = 0;
            $attributedRevenue = 0.0;
            $attributedCost = 0.0;

            foreach ($journey as $position => $touch) {
                $isLastTouch = ($position === $journeyCount - 1);
                if ($journeyCount > 1 && $isLastTouch) {
                    continue;
                }

                $credit = $creditShare;
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

            // Fallback for journeys where we skipped every touch (should not happen, but guard anyway).
            if ($attributedClicks === 0 && $journey !== []) {
                $touch = $journey[$journeyCount - 1];
                $credit = 1.0;
                $attributedClicks = 1;
                $attributedRevenue = $conversion->clickPayout;
                $attributedCost = $conversion->clickCost;

                $touchpointsByHour[$bucket][] = new Touchpoint(
                    touchpointId: null,
                    snapshotId: null,
                    conversionId: $conversion->conversionId,
                    clickId: $touch->clickId,
                    position: $journeyCount - 1,
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
}
