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
 *
 * The backing values are what the signature_state column stores and what the
 * API's `signature` filter accepts; inside the process the state is this
 * type, so a verdict no policy knows how to price cannot be constructed and
 * every consumer's `match` is exhaustive by the compiler rather than by a
 * runtime membership check.
 */
enum SignatureState: string
{
    /** Verified against the production key. */
    case VALID = 'valid';

    /** Checked, and forged or corrupted. */
    case INVALID = 'invalid';

    /** Could not be judged: unknown version or key, or no OpenSSL. */
    case UNVERIFIABLE = 'unverifiable';

    /** Verified against one of Apple's development keys. */
    case DEVELOPMENT = 'development';

    /**
     * The value for the signature_valid column: 1 counts in every verified
     * metric and is never pruned once claimed; 0 is a forged row; NULL is a
     * row nobody vouched for (pruned under the unverifiable retention class).
     */
    public function trustBit(bool $acceptDevelopment): ?int
    {
        return match ($this) {
            self::VALID => 1,
            self::INVALID => 0,
            self::UNVERIFIABLE => null,
            self::DEVELOPMENT => $acceptDevelopment ? 1 : null,
        };
    }

    /**
     * The stored strings, in declaration order — for the API's filter
     * message and the documented enums, which describe the column rather
     * than the type.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
