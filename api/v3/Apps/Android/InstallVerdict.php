<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

use Api\V3\Apps\AppPolicy;
use Api\V3\Apps\Verdict;

/**
 * An install's MatchState together with the SDK's test flag.
 *
 * A debug build marks its installs `test: true` (plan §5.6). They are
 * classified like any other, and count only under the registration's
 * accept_test_signals — the flag that also governs AdAttributionKit
 * development postbacks. So a test install that would be trusted is
 * unvouched (NULL) under a registration that does not accept test signals:
 * it is stored and reported in the test column, and it pays nothing,
 * because only a trusted install lends its click to the goal engine.
 */
final class InstallVerdict implements Verdict
{
    public function __construct(public readonly MatchState $state, public readonly bool $test)
    {
    }

    public function trustBit(AppPolicy $policy): ?int
    {
        $bit = $this->state->trustBit($policy);
        if ($this->test && $bit === 1 && !$policy->acceptTestSignals) {
            return null;
        }

        return $bit;
    }

    public function isTest(): bool
    {
        return $this->test;
    }
}
