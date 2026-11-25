<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;
use Prosper202\Goals\WebEvents;
use Prosper202\Identity\RequestSignals;

/**
 * The click a landing page's redirect link (go.php?lpip=) leaves on, for a
 * visitor whose cookie names none: the one the page's own visitor id
 * (p202lpid, which landing.php adds to links into the tracker) was last seen
 * on, as p202.track() finds it (WebEvents::clickForVisitor()).
 *
 * A visitor held back by the privacy setting is set no click cookie, so the
 * redirect link sent every such visitor to the offer with an empty subid and
 * the sale could not be credited (measured under 'eu'), while the PurLink on
 * the same page credited them. Nothing new is stored: the id was already
 * observed on the click by its pageview.
 *
 * The request's own refusal (p202_consent other than 1) and a campaign whose
 * identity capture is off find nothing, whatever an earlier visit stored.
 */
final class LandingPageVisitorClick
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $request the redirect link's own query (not the referer's)
     * @param array<string, mixed> $page    ClickPathReads::landingPageOffer()'s row
     */
    public static function find(
        Connection $conn,
        string $landingPagePublicId,
        array $request,
        array $page,
        int $now
    ): ?int {
        $campaignAllows = RequestSignals::campaignAllows(
            array_key_exists('identity_signals', $page) ? $page['identity_signals'] : null
        );
        if (!RequestSignals::consentGiven($request, $campaignAllows)) {
            return null;
        }
        $lpid = RequestSignals::landingPageId($request);
        if ($lpid === null) {
            return null;
        }
        $click = (new WebEvents($conn))->clickForVisitor($landingPagePublicId, $lpid, $now);
        if (
            $click === null
            || $click['user_id'] !== (int) $page['user_id']
            || $click['campaign_id'] !== (int) $page['aff_campaign_id']
        ) {
            return null;
        }

        return $click['click_id'];
    }
}
