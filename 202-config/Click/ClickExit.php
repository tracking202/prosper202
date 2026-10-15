<?php

declare(strict_types=1);

namespace Prosper202\Click;

use InvalidArgumentException;

/**
 * A recorded click leaving its landing page by a link an account made: an
 * offer (off.php), an offer rotator (offrtr.php), a simple landing page's
 * offer link (lp.php) or a cloaked click's confirmation (pci.php).
 *
 * $userId is the account whose link it is (the offer's, the rotator's, the
 * landing page's). The click is named by the visitor (a cookie, a public id),
 * so MysqlClickRepository::recordExit() writes it only when it, and the
 * offer and rotator named with it, are that account's.
 *
 * $cloaking is written with the click-out mark; null keeps the click's.
 * $outboundSiteUrlId goes with a redirect URL resolved in the write
 * (202_clicks_site); 0 when the exit records no URLs.
 */
final readonly class ClickExit
{
    public function __construct(
        public int $userId,
        public int $clickId,
        public ?OfferRoute $offer = null,
        public ?RotatorRoute $rotator = null,
        public bool $clickedOut = true,
        public ?int $cloaking = null,
        public int $outboundSiteUrlId = 0,
    ) {
        if ($userId < 1 || $clickId < 1) {
            throw new InvalidArgumentException("A click exit names an account and a click, not $userId and $clickId");
        }
        if ($cloaking !== null && $cloaking !== 0 && $cloaking !== 1) {
            throw new InvalidArgumentException("A click exit's cloaking is 0 or 1, not $cloaking");
        }
        if ($cloaking !== null && !$clickedOut) {
            throw new InvalidArgumentException('A click exit records its cloaking with the click-out mark');
        }
        if ($outboundSiteUrlId < 0) {
            throw new InvalidArgumentException("A click exit's outbound URL is an id or 0, not $outboundSiteUrlId");
        }
    }
}
