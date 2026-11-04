<?php

declare(strict_types=1);

namespace Prosper202\Alerts;

use Prosper202\Database\Connection;

/**
 * A steady campaign that stopped converting while it kept buying clicks.
 * Steady is a live conversion in at least minConversions distinct hours of
 * the window (uploads and bursts are not a rhythm: CampaignActivity). It
 * alerts after no conversion for gapMultiple times its usual gap between
 * converting hours (an hour at least), with as many times its usual real
 * clicks per converting hour arrived since its last one, and a real click
 * within the last RECENT_CLICK seconds (a paused campaign is not losing
 * money). The incident is the campaign's last conversion, so the next
 * conversion re-arms it.
 */
final class ConversionsStoppedDetector implements AlertDetector
{
    public const MIN_SILENCE = 3600;
    public const RECENT_CLICK = 7200;

    public function kind(): AlertKind
    {
        return AlertKind::ConversionsStopped;
    }

    public function detect(Connection $conn, AccountActivity $activity, AlertSettings $settings): array
    {
        $alerts = [];
        foreach ($activity->campaigns as $campaign) {
            $gap = $campaign->usualGap();
            $perConversion = $campaign->clicksPerArrival();
            $last = $campaign->lastConversionAt;
            $steady = $campaign->arrivalHours >= $settings->minConversions;
            if (!$steady || $gap === null || $perConversion === null || $last === null) {
                continue;
            }
            $silence = $activity->now - $last;
            if ($silence < max($settings->gapMultiple * $gap, self::MIN_SILENCE)) {
                continue;
            }
            $lastClick = $campaign->lastRealClickAt;
            if ($lastClick === null || $lastClick < $activity->now - self::RECENT_CLICK) {
                continue;
            }
            $clicksSince = $activity->realClicksAfter($conn, $campaign->campaignId, $last);
            if ($clicksSince < max($settings->gapMultiple * $perConversion, 1)) {
                continue;
            }
            $alerts[] = new Alert(
                AlertKind::ConversionsStopped,
                $activity->userId,
                $campaign->campaignId,
                $campaign->name,
                $last,
                sprintf(
                    '%s (campaign #%d) has had no conversion for %s, %s times its usual gap of %s, while %s real clicks'
                    . ' arrived (usually about %s real clicks between conversions). Check its postback or pixel.',
                    $campaign->name,
                    $campaign->campaignId,
                    AlertText::duration($silence),
                    AlertText::times($silence / max($gap, 1)),
                    AlertText::duration((int) round($gap)),
                    number_format($clicksSince),
                    number_format(max($perConversion, 1), $perConversion < 10 ? 1 : 0)
                ),
                [
                    'last_conversion_at' => $last,
                    'seconds_without_conversion' => $silence,
                    'usual_gap_seconds' => (int) round($gap),
                    'clicks_since_last_conversion' => $clicksSince,
                    'usual_clicks_per_converting_hour' => round($perConversion, 2),
                    'conversions_last_7_days' => $campaign->conversions,
                    'converting_hours_last_7_days' => $campaign->arrivalHours,
                    'gap_multiple' => $settings->gapMultiple,
                ]
            );
        }

        return $alerts;
    }
}
