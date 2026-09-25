<?php

declare(strict_types=1);

namespace Api\V3\Apps;

/**
 * What a signal source concluded about one signal, in the vocabulary every
 * source shares (plan §4.4).
 *
 * Each source judges its own signals — Apple's SignatureState from the
 * postback's signature, Android's MatchState from the referrer and the click
 * — and stores that state in its own column. What the state is WORTH is the
 * shared part, and it is always one of three trust classes, stored beside
 * the state as `trusted`:
 *
 *   1     trusted    counts in every default report number
 *   0     refuted    checked and found false (a forged signature, a
 *                    tampered token); counted only in its own column
 *   null  unvouched  nobody could vouch for it either way
 *
 * Implemented by backed enums, so a state no policy knows how to price
 * cannot be constructed and every `match` over one is exhaustive.
 */
interface Verdict
{
    /** 1 trusted, 0 refuted, null unvouched — under the registration's policy. */
    public function trustBit(AppPolicy $policy): ?int;

    /**
     * Whether this is a test signal: real, but proving only that a developer
     * produced it. Governed by the registration's accept_test_signals.
     */
    public function isTest(): bool;
}
