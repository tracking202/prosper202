<?php

declare(strict_types=1);

namespace Prosper202\Click;

use InvalidArgumentException;

/**
 * The offer a click leaves its landing page for: the click takes the offer's
 * campaign and is seeded with its payout, unless it has converted (a
 * converted click's value belongs to its conversion ledger).
 */
final readonly class OfferRoute
{
    public function __construct(
        public int $campaignId,
        public string $payout,
    ) {
        if ($campaignId < 1) {
            throw new InvalidArgumentException("An offer's campaign is a positive id, not $campaignId");
        }
        if (preg_match('/\A-?\d+(?:\.\d+)?\z/', $payout) !== 1) {
            throw new InvalidArgumentException("An offer's payout is a decimal number, not '$payout'");
        }
    }
}
