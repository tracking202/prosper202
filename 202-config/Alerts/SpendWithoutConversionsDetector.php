<?php

declare(strict_types=1);

namespace Prosper202\Alerts;

use Prosper202\AdPlatforms\OpenAiAds\ChatGptCampaignsOfCampaign;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;

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
            [$where, $facts] = self::chatGptCampaigns($conn, $activity, $campaign->campaignId);
            $alerts[] = new Alert(
                AlertKind::SpendWithoutConversions,
                $activity->userId,
                $campaign->campaignId,
                $campaign->name,
                $activity->dayStart,
                sprintf(
                    '%s (campaign #%d) has cost %s today (%s) with no conversion, past your daily cap of %s,'
                    . ' over %s clicks. Check its postback or pixel, or pause it.%s',
                    $campaign->name,
                    $campaign->campaignId,
                    AlertText::money($campaign->costToday, $activity->currency),
                    $day,
                    AlertText::money($cap, $activity->currency),
                    number_format($campaign->clicksToday),
                    $where
                ),
                [
                    'day' => $day,
                    'timezone' => $activity->timezone,
                    'cost_today' => round($campaign->costToday, 5),
                    'daily_cost_cap' => $cap,
                    'currency' => $activity->currency,
                    'clicks_today' => $campaign->clicksToday,
                ] + $facts
            );
        }

        return $alerts;
    }

    /**
     * The ChatGPT Ads campaigns that sent this campaign today's clicks, said where it can be paused: a sentence
     * for the message and the payload's facts ('' and none when there are none). A failed read is logged and
     * leaves the alert as it was: an alert is never held back by a hint.
     *
     * @return array{0: string, 1: array<string, int|string>}
     */
    private static function chatGptCampaigns(Connection $conn, AccountActivity $activity, int $campaignId): array
    {
        try {
            $found = ChatGptCampaignsOfCampaign::since($conn, $activity->userId, $campaignId, $activity->dayStart);
        } catch (QueryException $e) {
            error_log('Money-loss alerts: the ChatGPT Ads campaigns of campaign ' . $campaignId . ' could not be read: ' . $e->getMessage());

            return ['', []];
        }
        if ($found === []) {
            return ['', []];
        }
        $first = $found[0];

        return [
            sprintf(
                ' Its clicks come from ChatGPT Ads %s %s: pause %s at Setup › Destinations › ChatGPT Ads, or run p202 ad-campaign pause %d %s.',
                count($found) === 1 ? 'campaign' : 'campaigns',
                implode(', ', array_column($found, 'campaign_id')),
                count($found) === 1 ? 'it' : 'them',
                $first['connection_id'],
                $first['campaign_id']
            ),
            [
                // Each campaign with the connection that reaches it: two connections can share a Prosper202 campaign.
                'chatgpt_campaigns' => implode(',', array_map(static fn (array $f): string => $f['connection_id'] . ':' . $f['campaign_id'], $found)),
                'chatgpt_campaign_ids' => implode(',', array_column($found, 'campaign_id')),
            ],
        ];
    }
}
