<?php

declare(strict_types=1);

namespace Prosper202\GeoIp;

/**
 * A MaxMind account ID and licence key, as the download's Basic
 * authentication takes them. Never rendered: the API and Settings say only
 * whether each is stored.
 */
final class Credentials
{
    public const ACCOUNT_PATTERN = '/^[1-9][0-9]{0,9}$/D';
    public const LICENSE_PATTERN = '/^[A-Za-z0-9_-]{6,128}$/D';

    public function __construct(
        public readonly string $accountId,
        public readonly string $licenseKey,
    ) {
    }

    /** Why a posted account ID is refused, or null. */
    public static function accountIdError(string $accountId): ?string
    {
        return preg_match(self::ACCOUNT_PATTERN, $accountId) === 1
            ? null
            : 'Enter the account ID MaxMind shows on your account page: a number, such as 123456.';
    }

    /** Why a posted licence key is refused, or null. */
    public static function licenseKeyError(string $licenseKey): ?string
    {
        return preg_match(self::LICENSE_PATTERN, $licenseKey) === 1
            ? null
            : 'Enter the licence key as MaxMind showed it when it was made: letters, digits, _ and -, with no spaces.';
    }
}
