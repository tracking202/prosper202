<?php

declare(strict_types=1);

namespace Prosper202\Click;

use InvalidArgumentException;

/**
 * A cloaked click's public id (the pci in cl.php, pci.php and off.php links,
 * and the tracking202pci cookie), from 1.9.80.
 *
 * It used to be the click id with a random digit on each side, so anyone
 * holding one cloaked link reached a neighbouring click in at most 81 tries,
 * and cl.php showed that click's offer URL, which cloaking exists to hide.
 * Now it is "1", the click id padded to 12 digits, and six digits of an HMAC
 * of the click id under a key only the install (and an edge it is given to)
 * holds: 19 digits, one chance in a million per guess per click.
 *
 * The leading 1 puts every id issued this way at or above FLOOR, and an id
 * issued the old way is below it for any click id under 10^16 (two digits
 * longer than the click id), so the two can never name each other's clicks
 * whatever order click ids were allocated in. Readers keep looking the id up
 * in 202_clicks_record.click_id_public, so a link handed out before 1.9.80
 * still resolves.
 *
 * The decimal form is built as text, so a reader without 64-bit integers
 * (JavaScript's numbers are exact only to 2^53) builds and compares the same
 * string; a reader that parses it as a JavaScript number loses its last
 * digits. tests/fixtures/click-public-id holds the vectors and a Web Crypto
 * version of this, which the edge is held to.
 */
final class PublicClickId
{
    /** Every id issued this way is at least this; every id issued before 1.9.80 is below it. */
    public const FLOOR = 1_000_000_000_000_000_000;

    /** The largest click id that fits the 12 digits (the edge's range ends at 89,999,999,999). */
    public const MAX_CLICK_ID = 999_999_999_999;

    public const SUFFIX_DIGITS = 6;

    /** What the HMAC is taken over, before the click id: changing it is a new version of the format. */
    public const MESSAGE_PREFIX = 'p202-click-public-id:v1:';

    private function __construct()
    {
    }

    /** The public id of a click, under the install's key (32 bytes). */
    public static function issue(int $clickId, string $key): string
    {
        return self::compose($clickId, self::suffix($clickId, $key));
    }

    /**
     * The public id of a click with a random suffix, for a click recorded
     * while the key cannot be read: as hard to guess, and it resolves through
     * the stored column like any other, but clickOf() will not vouch for it.
     */
    public static function issueUnkeyed(int $clickId): string
    {
        return self::compose($clickId, random_int(0, 999_999));
    }

    /** The six digits that follow the click id, as an integer below 10^6. */
    public static function suffix(int $clickId, string $key): int
    {
        self::assertClickId($clickId);
        if (strlen($key) !== 32) {
            throw new InvalidArgumentException('The click public id key must be 32 bytes');
        }
        $mac = hash_hmac('sha256', self::MESSAGE_PREFIX . $clickId, $key, true);
        $word = unpack('N', substr($mac, 0, 4));
        if ($word === false) {
            throw new \LogicException('unpack() could not read four bytes of a SHA-256 HMAC');
        }

        return $word[1] % 1_000_000;
    }

    /**
     * The click a public id issued under this key names, or null for any
     * other value: an id issued before 1.9.80, one issued without the key, a
     * guess. Lets a reader refuse a guess without a lookup.
     */
    public static function clickOf(mixed $publicId, string $key): ?int
    {
        if (!is_string($publicId) || preg_match('/\A1(\d{12})(\d{6})\z/D', $publicId, $m) !== 1) {
            return null;
        }
        $clickId = (int) $m[1];
        if ($clickId < 1) {
            return null;
        }

        return hash_equals(self::issue($clickId, $key), $publicId) ? $clickId : null;
    }

    /** Whether a stored public id was issued before 1.9.80 (or is 0, none). */
    public static function isLegacy(int $publicId): bool
    {
        return $publicId < self::FLOOR;
    }

    private static function compose(int $clickId, int $suffix): string
    {
        self::assertClickId($clickId);

        return '1' . str_pad((string) $clickId, 12, '0', STR_PAD_LEFT)
            . str_pad((string) $suffix, self::SUFFIX_DIGITS, '0', STR_PAD_LEFT);
    }

    private static function assertClickId(int $clickId): void
    {
        if ($clickId < 1 || $clickId > self::MAX_CLICK_ID) {
            throw new InvalidArgumentException(
                'A click id for a public id is 1 to ' . self::MAX_CLICK_ID . ', not ' . $clickId
            );
        }
    }
}
