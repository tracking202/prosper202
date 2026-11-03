<?php

declare(strict_types=1);

namespace Prosper202\Click;

use InvalidArgumentException;

/**
 * A click and an offer carried through an offer's SubID: `<click>-<offer>-<mac>`.
 * The 1.9.56 `click-offer` shape plus a MAC, short and URL-safe enough for
 * networks that cap SubID length. The page and account are not in the value;
 * the MAC binds them, and the verifier reads them from the click.
 */
final class PurLinkSubid
{
    private const DOMAIN = "prosper202-purlink-subid-v2\0";
    private const SHAPE = '/\A([1-9][0-9]{0,18})-([1-9][0-9]{0,18})-([0-9a-f]{12})\z/D';

    private function __construct()
    {
    }

    /**
     * Issue from a valid exit proof after the caller has resolved the offer
     * against the page's account. No arbitrary click ID is signed.
     */
    public static function issue(string $exitProof, mixed $campaignPublicId, int $now, string $key): string
    {
        $claims = PurLinkProof::verify($exitProof, PurLinkProof::EXIT, $now, $key);
        $campaign = PublicId::parse($campaignPublicId);
        if ($claims === null || $campaign === null) {
            throw new InvalidArgumentException('A valid PurLink exit proof and campaign are required');
        }

        return $claims['click_id'] . '-' . $campaign . '-'
            . self::mac($claims['click_id'], $claims['landing_page_id'], $claims['user_id'], $campaign, $key);
    }

    /**
     * The IDs a signed SubID names. Not proof: check() it against the
     * click's own page and account before trusting the offer.
     *
     * @return array{click_id: int, campaign_public_id: int, mac: string}|null
     */
    public static function parse(mixed $subid): ?array
    {
        if (!is_string($subid) || preg_match(self::SHAPE, $subid, $parts) !== 1) {
            return null;
        }
        $click = ClickId::parse($parts[1]);
        $campaign = PublicId::parse($parts[2]);
        if ($click === null || $campaign === null) {
            return null;
        }

        return ['click_id' => $click, 'campaign_public_id' => $campaign, 'mac' => $parts[3]];
    }

    /** @param array{click_id: int, campaign_public_id: int, mac: string} $parsed */
    public static function check(array $parsed, int $landingPageId, int $userId, string $key): bool
    {
        if ($landingPageId < 1 || $userId < 1) {
            return false;
        }

        return hash_equals(
            self::mac($parsed['click_id'], $landingPageId, $userId, $parsed['campaign_public_id'], $key),
            $parsed['mac']
        );
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

    private static function mac(int $click, int $landingPageId, int $userId, int $campaign, string $key): string
    {
        if (strlen($key) !== 32) {
            throw new InvalidArgumentException('The PurLink SubID key must be 32 bytes');
        }
        $payload = self::DOMAIN . implode('.', [$click, $landingPageId, $userId, $campaign]);

        return substr(hash_hmac('sha256', $payload, $key), 0, 12);
    }
}
