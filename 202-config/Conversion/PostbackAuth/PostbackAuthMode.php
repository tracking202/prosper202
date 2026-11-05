<?php

declare(strict_types=1);

namespace Prosper202\Conversion\PostbackAuth;

/**
 * What an account's postbacks and pixels must present (PostbackAuthGate):
 *
 * - OFF: nothing; a token or signature that is present is still checked.
 * - TOKEN: the token (p202_token) or a signature.
 * - SIGNED: a signature (p202_ts + p202_sig, keyed with the signing
 *   secret). The token alone is refused, and so is every pixel, which a
 *   browser fetches and cannot sign.
 */
enum PostbackAuthMode: string
{
    case OFF = 'off';
    case TOKEN = 'token';
    case SIGNED = 'signed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }
}
