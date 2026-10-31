<?php

declare(strict_types=1);

namespace Prosper202\Click;

use InvalidArgumentException;

/**
 * The rotator, rule and rule redirect a redirector click went through; the
 * rule and redirect are 0 for the rotator's default. 202_clicks.rule_id
 * holds the redirect, as it always has; 202_clicks_rotator holds the rule
 * and its redirect apart.
 */
final readonly class RotatorRoute
{
    public function __construct(
        public int $rotatorId,
        public int $ruleId,
        public int $ruleRedirectId,
    ) {
        if ($rotatorId < 1) {
            throw new InvalidArgumentException("A redirector click's rotator is a positive id, not $rotatorId");
        }
        if ($ruleId < 0 || $ruleRedirectId < 0) {
            throw new InvalidArgumentException(
                "A redirector click's rule and redirect are ids or 0, not $ruleId and $ruleRedirectId"
            );
        }
    }
}
