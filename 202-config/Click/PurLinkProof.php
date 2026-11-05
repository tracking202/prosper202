<?php

declare(strict_types=1);

namespace Prosper202\Click;

use InvalidArgumentException;

/** A purpose-scoped proof for a click allocated by the current request. */
final class PurLinkProof
{
    public const EXIT = 'exit';
    public const PIXELS = 'pixels';
    public const MAX_LIFETIME = 30 * 24 * 60 * 60;

    private const DOMAIN = "prosper202-purlink-proof-v1\0";
    private const SHAPE = '/\A(exit|pixels)\.([1-9][0-9]{0,18})\.([1-9][0-9]{0,18})\.'
        . '([1-9][0-9]{0,18})\.([1-9][0-9]{0,18})\.([A-Za-z0-9_-]{43})\z/D';

    private function __construct()
    {
    }

    /**
     * Issue only for a click this request allocated, after its record commits.
     * $landingPageId and $userId are internal IDs from the recording request.
     */
    public static function issue(
        string $purpose,
        int $clickId,
        int $landingPageId,
        int $userId,
        int $now,
        int $lifetime,
        string $key
    ): string {
        self::checkKey($key);
        if (
            !in_array($purpose, [self::EXIT, self::PIXELS], true)
            || $clickId < 1 || $landingPageId < 1 || $userId < 1 || $now < 1
            || $lifetime < 1 || $lifetime > self::MAX_LIFETIME || $now > PHP_INT_MAX - $lifetime
        ) {
            throw new InvalidArgumentException('Invalid PurLink proof fields');
        }
        if (!RecordedClicks::has($clickId)) {
            throw new InvalidArgumentException('A PurLink proof requires a click allocated by this request');
        }

        $payload = implode('.', [$purpose, $clickId, $landingPageId, $userId, $now + $lifetime]);

        return $payload . '.' . self::mac($payload, $key);
    }

    /** @return array{click_id: int, landing_page_id: int, user_id: int, expires: int}|null */
    public static function verify(string $proof, string $purpose, int $now, string $key): ?array
    {
        self::checkKey($key);
        if (
            $now < 1 || !in_array($purpose, [self::EXIT, self::PIXELS], true)
            || preg_match(self::SHAPE, $proof, $parts) !== 1 || $parts[1] !== $purpose
        ) {
            return null;
        }
        foreach ([2, 3, 4, 5] as $index) {
            if ((string) (int) $parts[$index] !== $parts[$index]) {
                return null;
            }
        }
        $expires = (int) $parts[5];
        if ($expires <= $now || $expires - $now > self::MAX_LIFETIME) {
            return null;
        }
        $payload = substr($proof, 0, strrpos($proof, '.'));
        if (!hash_equals(self::mac($payload, $key), $parts[6])) {
            return null;
        }

        return [
            'click_id' => (int) $parts[2],
            'landing_page_id' => (int) $parts[3],
            'user_id' => (int) $parts[4],
            'expires' => $expires,
        ];
    }

    private static function mac(string $payload, string $key): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', self::DOMAIN . $payload, $key, true)), '+/', '-_'), '=');
    }

    private static function checkKey(string $key): void
    {
        if (strlen($key) !== 32) {
            throw new InvalidArgumentException('The PurLink proof key must be 32 bytes');
        }
    }
}
