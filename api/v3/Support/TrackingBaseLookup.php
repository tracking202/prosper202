<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\DatabaseException;
use Prosper202\Click\TrackingBaseUrl;

/**
 * The base every link, pixel and landing-page snippet the API hands out
 * starts with: Prosper202\Click\TrackingBaseUrl over user 1's tracking
 * domain (getTrackingDomain()'s source for the redirects), this request's
 * server values and the install's directory. One lookup, so a tracker's link
 * (GET /trackers/{id}/url) and the Setup code (GET /landing-pages/{id}/code,
 * GET /conversions/postback-code) can never disagree about where this
 * install is.
 *
 * With no domain stored it is this install on the origin the request came
 * in on, as the setup pages' getTrackingDomain() is: the answer goes back to
 * the caller, and the server's own name and port are not an address a
 * caller behind a proxy or a published container port can reach
 * (TrackingBaseUrl::domainForResponse()).
 *
 * Requires StatementHelpers in the using class.
 */
trait TrackingBaseLookup
{
    /**
     * `scheme://host[:port]/<install path>/`.
     *
     * @param array<string, mixed>|null $server the request ($_SERVER)
     */
    protected function trackingBaseUrl(?array $server = null): string
    {
        return TrackingBaseUrl::buildForResponse($this->trackingDomain(), $server ?? $_SERVER, dirname(__DIR__, 3));
    }

    /** user 1's tracking domain, which getTrackingDomain() builds every UI link on; '' when unset. */
    protected function trackingDomain(): string
    {
        $stmt = $this->prepare('SELECT user_tracking_domain FROM 202_users_pref WHERE user_id = 1 LIMIT 1');
        $this->execute($stmt, 'Failed to query tracking domain');
        $result = $stmt->get_result();
        if ($result === false) {
            // Read as "no domain", every link would be built on this
            // server's own name instead of the one the user configured.
            $stmt->close();
            throw new DatabaseException('Failed to query tracking domain');
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        return (string) ($row['user_tracking_domain'] ?? '');
    }
}
