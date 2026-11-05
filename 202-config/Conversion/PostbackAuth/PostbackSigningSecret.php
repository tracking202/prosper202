<?php

declare(strict_types=1);

namespace Prosper202\Conversion\PostbackAuth;

/**
 * An account's postback signing secret: 256 random bits as 64 lower-case hex
 * characters, the HMAC key of a signed postback (PostbackSignature). Unlike
 * the token it is never written into a URL, so a URL that leaks (a pixel on
 * an advertiser's page, a network's log) hands out nothing that signs.
 */
final class PostbackSigningSecret
{
    private const SHAPE = '/\A[0-9a-f]{64}\z/D';

    private function __construct()
    {
    }

    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function isWellFormed(string $secret): bool
    {
        return preg_match(self::SHAPE, $secret) === 1;
    }
}
