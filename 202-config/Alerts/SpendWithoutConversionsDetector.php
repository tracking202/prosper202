<?php

declare(strict_types=1);

namespace Prosper202\Alerts;

use Prosper202\Database\Connection;

/**
 * A campaign whose cost today, in the account's zone, reached the daily cap
 * with no conversion today. The incident is the day, so a campaign is told
 * at most once a day.
 */
final class SpendWithoutConversionsDetector implements AlertDetector
{
    public function kind(): AlertKind
    {
        return AlertKind::SpendWithoutConversions;
    }

    public function detect(Connection $conn, AccountActivity $activity, AlertSettings $settings): array
    {
        if (!$settings->hasCostCap()) {
            return [];
        }
        $cap = (float) $settings->dailyCostCap;
        $day = (new \DateTimeImmutable('@' . $activity->dayStart))
            ->setTimezone(new \DateTimeZone($activity->timezone))
            ->format('Y-m-d');
        $alerts = [];
        foreach ($activity->campaigns as $campaign) {
            if ($campaign->conversionsToday > 0 || round($campaign->costToday, 2) < $cap) {
                continue;
            }
            $alerts[] = new Alert(
                AlertKind::SpendWithoutConversions,
                $activity->userId,
                $campaign->campaignId,
                $campaign->name,
                $activity->dayStart,
                sprintf(
                    '%s (campaign #%d) has cost %s today (%s) with no conversion, past your daily cap of %s,'
                    . ' over %s clicks. Check its postback or pixel, or pause it.',
                    $campaign->name,
                    $campaign->campaignId,
                    AlertText::money($campaign->costToday, $activity->currency),
                    $day,
                    AlertText::money($cap, $activity->currency),
                    number_format($campaign->clicksToday)
                ),
                [
                    'day' => $day,
                    'timezone' => $activity->timezone,
                    'cost_today' => round($campaign->costToday, 5),
                    'daily_cost_cap' => $cap,
                    'currency' => $activity->currency,
                    'clicks_today' => $campaign->clicksToday,
                ]
            );
        }

        return $alerts;
    }
}
