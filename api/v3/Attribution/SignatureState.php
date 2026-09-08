<?php

declare(strict_types=1);

namespace Api\V3\Attribution;

/**
 * How a postback's signature fared, and what that is worth.
 *
 * The state is what the verifier established; the trust bit stored beside it
 * (signature_valid) is a policy decision the receiver makes from the state,
 * because "verified against Apple's DEVELOPMENT key" is a true statement
 * that must not count as verified: any developer with an iPhone in Developer
 * Mode can mint Apple-dev-signed postbacks naming any App Store id. Only an
 * app registration that opts in (for its own integration testing) turns a
 * development-signed row into a trusted one.
 */
final class SignatureState
{
    /** Verified against the production key. */
    public const VALID = 'valid';

    /** Checked, and forged or corrupted. */
    public const INVALID = 'invalid';

    /** Could not be judged: unknown version or key, or no OpenSSL. */
    public const UNVERIFIABLE = 'unverifiable';

    /** Verified against one of Apple's development keys. */
    public const DEVELOPMENT = 'development';

    public const ALL = [self::VALID, self::INVALID, self::UNVERIFIABLE, self::DEVELOPMENT];

    /**
     * The value for the signature_valid column: 1 counts in every verified
     * metric and is never pruned once claimed; 0 is a forged row; NULL is a
     * row nobody vouched for (pruned under the unverifiable retention class).
     */
    public static function trustBit(string $state, bool $acceptDevelopment): ?int
    {
        return match ($state) {
            self::VALID => 1,
            self::INVALID => 0,
            self::DEVELOPMENT => $acceptDevelopment ? 1 : null,
            default => null,
        };
    }
}
