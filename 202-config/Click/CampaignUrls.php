<?php

declare(strict_types=1);

namespace Prosper202\Click;

/** Which of a campaign's URLs its redirect takes in turn. */
final class CampaignUrls
{
    private function __construct()
    {
    }

    /**
     * The campaign's first URL alone unless it rotates, then also each of
     * aff_campaign_url_2..5 that is set, in order.
     *
     * @param array<string, mixed> $campaign
     * @return non-empty-list<mixed>
     */
    public static function inRotation(array $campaign): array
    {
        $urls = [$campaign['aff_campaign_url'] ?? null];
        if (empty($campaign['aff_campaign_rotate'])) {
            return $urls;
        }
        foreach (['aff_campaign_url_2', 'aff_campaign_url_3', 'aff_campaign_url_4', 'aff_campaign_url_5'] as $column) {
            if (!empty($campaign[$column])) {
                $urls[] = $campaign[$column];
            }
        }

        return $urls;
    }
}
