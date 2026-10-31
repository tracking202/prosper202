<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android\Integrity;

/**
 * Where an install's Play Integrity verdict stands
 * (202_app_installs.integrity_state):
 *
 *   not_requested  mode off, and the SDK sent no token
 *   received       mode off, and the SDK sent one: kept, not decoded
 *   missing        mode observe/require, and the SDK sent no token
 *   pending        queued for the verdict worker (retried with backoff)
 *   valid          Google decoded the token and it passes the policy
 *   invalid        it was decoded and fails the policy, Google refused it as
 *                  undecodable, or a verified install already owns it
 *   error          no verdict could be obtained before the deadline (Google
 *                  unreachable, out of quota, the credential refused or absent)
 *   skipped        the install was refuted on its referrer alone; its token
 *                  is not decoded, so a forger cannot spend the quota
 *
 * `valid` is the only state that satisfies `require`.
 */
enum IntegrityState: string
{
    case NOT_REQUESTED = 'not_requested';
    case RECEIVED = 'received';
    case MISSING = 'missing';
    case PENDING = 'pending';
    case VALID = 'valid';
    case INVALID = 'invalid';
    case ERROR = 'error';
    case SKIPPED = 'skipped';

    /** The state an install arrives in, from the mode it arrived under and whether it carried a token. */
    public static function onArrival(IntegrityMode $mode, bool $hasToken): self
    {
        if (!$mode->decodes()) {
            return $hasToken ? self::RECEIVED : self::NOT_REQUESTED;
        }

        return $hasToken ? self::PENDING : self::MISSING;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
