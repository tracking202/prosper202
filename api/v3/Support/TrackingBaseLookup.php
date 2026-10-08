<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\DatabaseException;
use Prosper202\Click\TrackingBaseUrl;

/**
 * The base every link, pixel and landing-page snippet the API hands out
 * starts with: Prosper202\Click\TrackingBaseUrl over the caller's own
 * tracking domain, this request's server values and the install's
 * directory. One lookup, so a tracker's link (GET /trackers/{id}/url) and
 * the Setup code (GET /landing-pages/{id}/code, GET
 * /conversions/postback-code) can never disagree about where this install
 * is.
 *
 * The caller's, as Get Links and Get LP Code read the signed-in user's, and
 * as this API read it before this lookup was shared: each user sets their
 * own on Personal settings. It read user 1's, so an account with a domain of
 * its own was handed links on the owner's host from the API and on its own
 * from the pages. The answer goes back to the caller, so the caller's choice
 * is theirs to make (CLAUDE.md #16).
 *
 * With no domain stored it is this install on the origin the request came
 * in on, as the setup pages' getTrackingDomain() is: the answer goes back to
 * the caller, and the server's own name and port are not an address a
 * caller behind a proxy or a published container port can reach
 * (TrackingBaseUrl::domainForResponse()).
 *
 * Requires StatementHelpers and the caller's `userId` in the using class.
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

    /** The caller's tracking domain, which the setup pages build their links on; '' when unset. */
    protected function trackingDomain(): string
    {
        $stmt = $this->prepare('SELECT user_tracking_domain FROM 202_users_pref WHERE user_id = ? LIMIT 1');
        $this->bind($stmt, 'i', $this->userId);
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
