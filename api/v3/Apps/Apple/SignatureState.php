<?php

declare(strict_types=1);

namespace Api\V3\Apps\Apple;

use Api\V3\Apps\AppPolicy;
use Api\V3\Apps\Verdict;

/**
 * How a postback's signature fared: the Apple signal source's Verdict.
 *
 * The state is what the verifier established; the `trusted` bit stored
 * beside it is what the registration's policy makes of it (Verdict), because
 * "verified against Apple's DEVELOPMENT key" is a true statement that must
 * not count as verified: any developer with an iPhone in Developer Mode can
 * mint Apple-dev-signed postbacks naming any App Store id. Only a
 * registration that accepts test signals (for its own integration testing)
 * turns a development-signed row into a trusted one.
 *
 * The backing values are what the signature_state column stores and what the
 * API's `signature` filter accepts; inside the process the state is this
 * type, so a verdict no policy knows how to price cannot be constructed and
 * every consumer's `match` is exhaustive by the compiler rather than by a
 * runtime membership check.
 */
enum SignatureState: string implements Verdict
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
     * The value for the `trusted` column: 1 counts in every default metric
     * and is never pruned once claimed; 0 is a forged row (refuted); NULL is
     * a row nobody vouched for (unvouched, pruned under that class).
     */
    public function trustBit(AppPolicy $policy): ?int
    {
        return match ($this) {
            self::VALID => 1,
            self::INVALID => 0,
            self::UNVERIFIABLE => null,
            self::DEVELOPMENT => $policy->acceptTestSignals ? 1 : null,
        };
    }

    /** A development-key signature proves a developer made it, nothing more. */
    public function isTest(): bool
    {
        return $this === self::DEVELOPMENT;
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
