<?php

declare(strict_types=1);

namespace Prosper202\Identity;

/**
 * The first-party signals the identity graph links clicks by. Each may only
 * link, never guess: IP addresses, user agents and fingerprints are never
 * signals (plan §6.2).
 */
enum SignalType: string
{
    /** The tracking domain's p202vid cookie: clicks through redirects in one browser. */
    case VISITOR_COOKIE = 'vid';
    /** The landing page's own first-party id: clicks on the operator's sites. */
    case LANDING_PAGE = 'lpid';
    /** A customer id the operator's server signed: one person across devices. */
    case CUSTOMER = 'cust';
}
