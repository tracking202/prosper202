<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;
use Prosper202\User\InstallHash;

/**
 * Publisher links (`t202pubid`, paid 1.9.48 to 1.9.56): a click arriving on
 * one is credited to the publisher it names only when the link's account has
 * granted that publisher. A grant is the Publisher role on an active user of
 * the same install, and an account that may grant it (one whose role holds
 * add_users, as User management asks). Any other id names nobody: the link's
 * request is the visitor's, so an id is a claim, never trusted on its own.
 *
 * The click stays the link's account's (202_clicks.user_id): every record it
 * names is that account's, and a click moved to another account reads them
 * as none (CLAUDE.md #27). The credit is 202_clicks_publisher, beside it.
 */
final readonly class PublisherCredit
{
    public const PARAMETER = 't202pubid';

    /** 202_roles: Publisher. */
    public const PUBLISHER_ROLE_ID = 6;

    /** The permission that assigns roles (User management). */
    public const GRANT_PERMISSION = 'add_users';

    /** createId(5): ten hex characters, in a VARCHAR(10). */
    private const PUBLIC_ID = '/\A[0-9A-Za-z]{1,10}\z/';

    /** The clicks credited to a publisher, for an IN (...) over a click id. */
    public const CREDITED_CLICKS_SQL = 'SELECT pc.click_id FROM 202_clicks_publisher pc';

    public function __construct(private Connection $conn)
    {
    }

    /**
     * The report filter "clicks credited to publisher $publisherUserId", over
     * the click id $clickId names (an alias and column, as `2st.click_id`).
     */
    public static function creditedClicksCondition(string $clickId, int $publisherUserId): string
    {
        if (preg_match('/\A[A-Za-z0-9_]+\.click_id\z/', $clickId) !== 1) {
            throw new \InvalidArgumentException("PublisherCredit: '$clickId' is not a click id column");
        }

        return $clickId . ' IN (' . self::CREDITED_CLICKS_SQL . ' WHERE pc.publisher_user_id = ' . $publisherUserId . ')';
    }

    /**
     * The publisher id a click request names, or null when it names none a publisher can have.
     *
     * @param array<array-key, mixed> $query
     */
    public static function requested(array $query): ?string
    {
        $value = $query[self::PARAMETER] ?? null;
        if (!is_string($value) || preg_match(self::PUBLIC_ID, $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * The publisher $publicId names, when $ownerUserId's link may credit it.
     *
     * @return array{user_id: int, public_id: string}|null
     */
    public function forClick(int $ownerUserId, ?string $publicId): ?array
    {
        if ($ownerUserId < 1 || $publicId === null || preg_match(self::PUBLIC_ID, $publicId) !== 1) {
            return null;
        }
        $stmt = $this->conn->prepareRead(
            'SELECT p.user_id, p.user_public_publisher_id, p.install_hash FROM 202_users AS p'
                . ' WHERE p.user_public_publisher_id = ? AND p.user_active = 1 AND p.user_deleted = 0'
                . ' AND EXISTS (SELECT 1 FROM 202_user_role AS ur WHERE ur.user_id = p.user_id AND ur.role_id = ?)'
                . ' LIMIT 2'
        );
        $this->conn->bind($stmt, 'si', [$publicId, self::PUBLISHER_ROLE_ID]);
        $rows = $this->conn->fetchAll($stmt);
        // The column is not unique: an id two users hold names neither.
        if (count($rows) !== 1) {
            return null;
        }
        $row = $rows[0];
        $publisherId = self::int($row, 'user_id');
        // The column's collation ignores case; the id is compared exactly.
        if ($publisherId === $ownerUserId || !hash_equals(self::text($row, 'user_public_publisher_id'), $publicId)) {
            return null;
        }
        $installHash = $this->grantingInstall($ownerUserId);
        if ($installHash === null || !InstallHash::matches($installHash, self::text($row, 'install_hash'))) {
            return null;
        }

        return ['user_id' => $publisherId, 'public_id' => $publicId];
    }

    /**
     * Get Links: the publishers $ownerUserId may put on a link, by name.
     *
     * @return list<array{user_id: int, name: string, public_id: string}>
     */
    public function grantable(int $ownerUserId): array
    {
        $installHash = $this->grantingInstall($ownerUserId);
        if ($installHash === null) {
            return [];
        }
        $stmt = $this->conn->prepareRead(
            'SELECT p.user_id, p.user_fname, p.user_lname, p.user_name, p.user_public_publisher_id'
                . ' FROM 202_users AS p'
                . ' WHERE p.install_hash = ? AND p.user_id <> ? AND p.user_active = 1 AND p.user_deleted = 0'
                . ' AND EXISTS (SELECT 1 FROM 202_user_role AS ur WHERE ur.user_id = p.user_id AND ur.role_id = ?)'
                . ' ORDER BY p.user_fname, p.user_lname, p.user_name, p.user_id'
        );
        $this->conn->bind($stmt, 'sii', [$installHash, $ownerUserId, self::PUBLISHER_ROLE_ID]);
        $counts = [];
        $publishers = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $publicId = self::text($row, 'user_public_publisher_id');
            if (preg_match(self::PUBLIC_ID, $publicId) !== 1) {
                continue;
            }
            $counts[strtolower($publicId)] = ($counts[strtolower($publicId)] ?? 0) + 1;
            $name = trim(self::text($row, 'user_fname') . ' ' . self::text($row, 'user_lname'));
            $publishers[] = [
                'user_id' => self::int($row, 'user_id'),
                'name' => $name !== '' ? $name : self::text($row, 'user_name'),
                'public_id' => $publicId,
            ];
        }

        // As forClick(): an id two users hold credits neither, so neither is offered.
        return array_values(array_filter(
            $publishers,
            static fn (array $p): bool => $counts[strtolower($p['public_id'])] === 1
        ));
    }

    /**
     * Get Links: the publisher with user id $userId, when $ownerUserId may put it on a link.
     *
     * @return array{user_id: int, name: string, public_id: string}|null
     */
    public function grantableById(int $ownerUserId, int $userId): ?array
    {
        foreach ($this->grantable($ownerUserId) as $publisher) {
            if ($publisher['user_id'] === $userId) {
                return $publisher;
            }
        }

        return null;
    }

    /** The public id of the publisher a recorded click is credited to, or null. */
    public function publicIdOfClick(int $clickId): ?string
    {
        if ($clickId < 1) {
            return null;
        }
        $stmt = $this->conn->prepareRead('SELECT publisher_user_id FROM 202_clicks_publisher WHERE click_id = ?');
        $this->conn->bind($stmt, 'i', [$clickId]);
        $credit = $this->conn->fetchOne($stmt);
        if ($credit === null) {
            return null;
        }
        $stmt = $this->conn->prepareRead('SELECT user_public_publisher_id FROM 202_users WHERE user_id = ?');
        $this->conn->bind($stmt, 'i', [self::int($credit, 'publisher_user_id')]);
        $publisher = $this->conn->fetchOne($stmt);
        $publicId = $publisher === null ? '' : self::text($publisher, 'user_public_publisher_id');

        return $publicId !== '' ? $publicId : null;
    }

    /**
     * The install of an active account that may grant publishers, or null when
     * it may not. A blank hash names no install: it matches nothing (InstallHash).
     */
    private function grantingInstall(int $ownerUserId): ?string
    {
        if ($ownerUserId < 1) {
            return null;
        }
        $stmt = $this->conn->prepareRead(
            'SELECT o.install_hash FROM 202_users AS o'
                . ' WHERE o.user_id = ? AND o.user_active = 1 AND o.user_deleted = 0'
                . ' AND EXISTS (SELECT 1 FROM 202_user_role AS ur'
                . ' INNER JOIN 202_role_permission AS rp ON rp.role_id = ur.role_id'
                . ' INNER JOIN 202_permissions AS pm ON pm.permission_id = rp.permission_id'
                . ' WHERE ur.user_id = o.user_id AND pm.permission_description = ?)'
        );
        $this->conn->bind($stmt, 'is', [$ownerUserId, self::GRANT_PERMISSION]);
        $row = $this->conn->fetchOne($stmt);

        $installHash = $row === null ? '' : self::text($row, 'install_hash');

        return $installHash !== '' ? $installHash : null;
    }

    /** @param array<string, mixed> $row */
    private static function text(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
    }
}
