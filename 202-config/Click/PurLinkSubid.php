<?php

declare(strict_types=1);

namespace Prosper202\Click;

use InvalidArgumentException;

/** A click and an advanced-page offer carried through an offer's SubID. */
final class PurLinkSubid
{
    public const MAX_LIFETIME = 365 * 24 * 60 * 60;
    private const DOMAIN = "prosper202-purlink-subid-v1\0";
    private const SHAPE = '/\Ap1\.([1-9][0-9]{0,18})\.([1-9][0-9]{0,18})\.'
        . '([1-9][0-9]{0,18})\.([1-9][0-9]{0,18})\.([1-9][0-9]{0,18})\.([A-Za-z0-9_-]{22})\z/D';

    private function __construct()
    {
    }

    /**
     * Issue from a valid exit proof after the caller has resolved the offer
     * against the page's account. No arbitrary click ID is signed.
     */
    public static function issue(
        string $exitProof,
        mixed $campaignPublicId,
        int $now,
        int $lifetime,
        string $key
    ): string {
        $claims = PurLinkProof::verify($exitProof, PurLinkProof::EXIT, $now, $key);
        $campaign = PublicId::parse($campaignPublicId);
        if (
            $claims === null || $campaign === null || $lifetime < 1 || $lifetime > self::MAX_LIFETIME
            || $now > PHP_INT_MAX - $lifetime
        ) {
            throw new InvalidArgumentException('A valid PurLink exit proof, campaign and lifetime are required');
        }
        $payload = implode('.', [
            'p1', $claims['click_id'], $claims['landing_page_id'], $claims['user_id'], $campaign, $now + $lifetime,
        ]);

        return $payload . '.' . self::mac($payload, $key);
    }

    /** @return array{click_id: int, landing_page_id: int, user_id: int, campaign_public_id: int, expires: int}|null */
    public static function verify(string $subid, int $now, string $key): ?array
    {
        if (strlen($key) !== 32) {
            throw new InvalidArgumentException('The PurLink SubID key must be 32 bytes');
        }
        if ($now < 1 || preg_match(self::SHAPE, $subid, $parts) !== 1) {
            return null;
        }
        foreach ([1, 2, 3, 4, 5] as $index) {
            if ((string) (int) $parts[$index] !== $parts[$index]) {
                return null;
            }
        }
        $expires = (int) $parts[5];
        if ($expires <= $now || $expires - $now > self::MAX_LIFETIME) {
            return null;
        }
        $payload = substr($subid, 0, strrpos($subid, '.'));
        if (!hash_equals(self::mac($payload, $key), $parts[6])) {
            return null;
        }

        return [
            'click_id' => (int) $parts[1],
            'landing_page_id' => (int) $parts[2],
            'user_id' => (int) $parts[3],
            'campaign_public_id' => (int) $parts[4],
            'expires' => $expires,
        ];
    }

    /**
     * Parse an old advanced-page SubID. This is not proof of the offer:
     * callers must verify the click already visited that account's campaign.
     *
     * @return array{click_id: int, campaign_public_id: int}|null
     */
    public static function legacy(string $subid): ?array
    {
        if (preg_match('/\A([1-9][0-9]{0,18})-([1-9][0-9]{0,18})\z/D', $subid, $parts) !== 1) {
            return null;
        }
        $click = ClickId::parse($parts[1]);
        $campaign = PublicId::parse($parts[2]);
        if ($click === null || $campaign === null) {
            return null;
        }

        return ['click_id' => $click, 'campaign_public_id' => $campaign];
    }

    private static function mac(string $payload, string $key): string
    {
        $raw = substr(hash_hmac('sha256', self::DOMAIN . $payload, $key, true), 0, 16);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
