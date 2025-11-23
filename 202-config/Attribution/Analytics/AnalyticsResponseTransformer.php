<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Analytics;

/**
 * Helpers that convert analytics DTOs into serialisable array payloads.
 */
final class AnalyticsResponseTransformer
{
    public static function summaryToArray(AnalyticsSummary $summary): array
    {
        return [
            'totals' => self::normaliseTotals($summary->totals),
            'snapshots' => array_map([self::class, 'snapshotToArray'], $summary->snapshots),
            'touchpoint_mix' => array_map([self::class, 'mixToArray'], $summary->touchpointMix),
            'anomalies' => array_map([self::class, 'anomalyToArray'], $summary->anomalies),
        ];
    }

    /**
     * @param AnalyticsSnapshot[] $snapshots
     * @return array<int, array<string, int|float|null>>
     */
    public static function snapshotsToArray(array $snapshots): array
    {
        return array_map([self::class, 'snapshotToArray'], $snapshots);
    }

    /**
     * @param TouchpointMix[] $mix
     * @return array<int, array<string, int|float|string>>
     */
    public static function mixToArrayList(array $mix): array
    {
        return array_map([self::class, 'mixToArray'], $mix);
    }

    /**
     * @param AnomalyAlert[] $anomalies
     * @return array<int, array<string, float|string>>
     */
    public static function anomaliesToArray(array $anomalies): array
    {
        return array_map([self::class, 'anomalyToArray'], $anomalies);
    }

    /**
     * @return array<string, int|float|null>
     */
    private static function normaliseTotals(array $totals): array
    {
        return [
            'revenue' => isset($totals['revenue']) ? (float) $totals['revenue'] : 0.0,
            'conversions' => isset($totals['conversions']) ? (float) $totals['conversions'] : 0.0,
            'clicks' => isset($totals['clicks']) ? (float) $totals['clicks'] : 0.0,
            'cost' => isset($totals['cost']) ? (float) $totals['cost'] : 0.0,
            'roi' => $totals['roi'] ?? null,
        ];
    }

    /**
     * @return array<string, int|float|null>
     */
    private static function snapshotToArray(AnalyticsSnapshot $snapshot): array
    {
        return [
            'snapshot_id' => $snapshot->snapshotId,
            'date_hour' => $snapshot->dateHour,
            'attributed_clicks' => $snapshot->attributedClicks,
            'attributed_conversions' => $snapshot->attributedConversions,
            'attributed_revenue' => $snapshot->attributedRevenue,
            'attributed_cost' => $snapshot->attributedCost,
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    private static function mixToArray(TouchpointMix $mix): array
    {
        return [
            'bucket' => $mix->bucket,
            'label' => $mix->label,
            'total_credit' => $mix->totalCredit,
            'touch_count' => $mix->touchCount,
            'share' => $mix->share,
        ];
    }

    /**
     * @return array<string, float|string>
     */
    private static function anomalyToArray(AnomalyAlert $alert): array
    {
        return [
            'metric' => $alert->metric,
            'severity' => $alert->severity,
            'direction' => $alert->direction,
            'delta_percent' => $alert->deltaPercent,
            'message' => $alert->message,
        ];
    }
}
