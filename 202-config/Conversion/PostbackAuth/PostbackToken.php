<?php

declare(strict_types=1);

namespace Prosper202\Conversion\PostbackAuth;

/**
 * An account's postback token: 128 random bits as 32 lower-case hex
 * characters, a shape every network's URL field and macro language passes
 * through unchanged. It travels as `p202_token` in the postback and pixel
 * URLs the account hands out. It is not the key of the signed form: that is
 * the signing secret (PostbackSigningSecret), which no URL carries.
 */
final class PostbackToken
{
    public const PARAMETER = 'p202_token';
    private const SHAPE = '/\A[0-9a-f]{32}\z/D';

    private function __construct()
    {
    }

    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match(self::SHAPE, $token) === 1;
    }

    /** The URL query parameter that carries it, `&p202_token=…`, or '' for no token. */
    public static function queryPart(string $token): string
    {
        return $token === '' ? '' : '&' . self::PARAMETER . '=' . $token;
    }
}
