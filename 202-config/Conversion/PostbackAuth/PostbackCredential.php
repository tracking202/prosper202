<?php

declare(strict_types=1);

namespace Prosper202\Conversion\PostbackAuth;

/** The two credentials an account's postbacks authenticate with, each rotated on its own. */
enum PostbackCredential: string
{
    case TOKEN = 'token';
    case SIGNING_SECRET = 'signing_secret';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
