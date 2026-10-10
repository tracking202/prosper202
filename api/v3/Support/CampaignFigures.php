<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Prosper202\Report\CampaignDataMask;

/**
 * Campaign figures hidden from a role without access_to_campaign_data, as
 * every report page hides them (Prosper202\Report\CampaignDataMask): the
 * absolute clicks, leads and money become null, the ratios (EPC, CPC, ROI,
 * conversion rate, CPA) stay, and the response says `masked: true`. The
 * pages print '?'; a JSON number field holds null instead, so a client
 * reads "hidden", never a figure.
 *
 * The API answered every role in full: a Campaign viewer's key read the
 * income and cost its pages showed as '?'.
 */
final class CampaignFigures
{
    /** CampaignDataMask::METRICS, as the reports name them. */
    public const array REPORT = [
        'total_clicks', 'total_click_throughs', 'total_leads', 'total_income', 'total_cost', 'total_net',
    ];

    /**
     * A click's and a conversion's money: its cost and what it earned, as
     * the click's conversions panel hides them.
     */
    public const array RECORD = ['click_cpc', 'click_payout', 'amount', 'ledger_value'];

    /**
     * A goal's money, where a goal's definition is in the answer: what a
     * campaign pays for it (`payout`) and its fixed value (the definition's
     * `value.amount`). Not `value`, which a definition's conditions also use
     * for the value they compare an event property with.
     */
    public const array GOAL = ['payout', 'amount'];

    /**
     * An outcome's money, where no definition is in the answer (a goal's
     * outcomes, a re-evaluation): what each is worth (`value`) and what it
     * was or would be recorded at (`payout`, `amount`).
     */
    public const array GOAL_OUTCOME = ['value', 'payout', 'amount'];

    private function __construct()
    {
    }

    /** The permission whose absence hides the figures (CampaignDataMask's). */
    public static function permission(): string
    {
        return 'access_to_campaign_data';
    }

    /**
     * $response with every $keys entry, at any depth, set to null, and
     * `masked: true` beside its data.
     *
     * @param array<mixed> $response
     * @param list<string> $keys
     * @return array<mixed>
     */
    public static function mask(array $response, array $keys): array
    {
        $walk = static function (array $node) use (&$walk, $keys): array {
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $node[$key] = $walk($value);
                } elseif (is_string($key) && in_array($key, $keys, true)) {
                    $node[$key] = null;
                }
            }

            return $node;
        };
        $masked = $walk($response);
        $masked['masked'] = true;

        return $masked;
    }

    /** The metric names the pages mask, for the parity test to compare. */
    public static function pageMetrics(): array
    {
        return CampaignDataMask::METRICS;
    }
}
