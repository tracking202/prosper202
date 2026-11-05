<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Api\V3\Apps\Android\InstallTokenKey;
use Prosper202\Database\Connection;
use RuntimeException;

/**
 * The click a subid names, as every reader of a network's or provider's subid
 * reads it: an exact click id, or a signed PurLink SubID (`click-offer-mac`).
 * parse() is pure; a signed value is not trusted until verify() checks its MAC.
 */
final class ClickRef
{
    private function __construct(
        public readonly int $clickId,
        public readonly ?string $purLinkSubid,
    ) {
    }

    public static function click(int $clickId): self
    {
        if ($clickId <= 0) {
            throw new \InvalidArgumentException('a click id is a positive integer');
        }

        return new self($clickId, null);
    }

    /** Null when the value is neither an exact click id nor a signed PurLink SubID. */
    public static function parse(mixed $value): ?self
    {
        $clickId = ClickId::parse($value);
        if ($clickId !== null) {
            return new self($clickId, null);
        }
        $signed = PurLinkSubid::parse($value);

        return $signed === null ? null : new self($signed['click_id'], (string) $value);
    }

    /**
     * A signed SubID's claims and the key that verified them; null when its
     * MAC does not match its click's page and account (or the click is gone).
     *
     * @return array{claims: array{click_id: int, landing_page_id: int, user_id: int, campaign_public_id: int}, key: string}|null
     * @throws RuntimeException when the installation key cannot be read
     */
    public static function verify(Connection $conn, string $purLinkSubid): ?array
    {
        $key = InstallTokenKey::load($conn->writeConnection());
        if ($key === null) {
            throw new RuntimeException('installation token key is missing');
        }
        $claims = (new PurLinkExit($conn))->claims($purLinkSubid, $key);

        return $claims === null ? null : ['claims' => $claims, 'key' => $key];
    }
}
