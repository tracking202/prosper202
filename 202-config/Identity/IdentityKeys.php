<?php

declare(strict_types=1);

namespace Prosper202\Identity;

use Prosper202\Database\Connection;

/**
 * The two secrets each Prosper202 account holds for the identity graph.
 *
 * - The hashing key turns a signal into what is stored: HMAC-SHA256 over the
 *   signal, so a raw cookie value or customer id is never written and the
 *   same person hashes the same way on every path.
 * - The linking key is what the operator's own server signs customer ids
 *   with (cust_sig = HMAC-SHA256(linking key, canonical id)); an id without
 *   a valid signature links nothing, because on a public pixel anyone can
 *   send any cust.
 *
 * Both are minted by one idempotent function the first time an account
 * needs them, so a fresh install, an upgraded one and a user created later
 * all get keys on the same path. Rotating the hashing key starts the
 * account's graph afresh (old hashes no longer match); rotating the linking
 * key invalidates every signature the operator computed with the old one.
 */
final class IdentityKeys
{
    /** @var array<int, array{hash: string, link: string}> */
    private array $cache = [];

    public function __construct(private Connection $conn)
    {
    }

    /**
     * @return array{hash: string, link: string} Hex-encoded 32-byte keys.
     */
    public function forUser(int $userId): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('identity keys belong to an account; user id ' . $userId . ' is not one');
        }
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        $keys = $this->read($userId);
        if ($keys === null) {
            $mint = $this->conn->prepareWrite(
                'INSERT IGNORE INTO 202_identity_keys (user_id, hash_key, link_key, created_at) VALUES (?, ?, ?, ?)'
            );
            $this->conn->bind($mint, 'issi', [$userId, bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), time()]);
            $this->conn->executeUpdate($mint);
            // Read back rather than trust the values just generated: a
            // concurrent first click may have won the INSERT IGNORE.
            $keys = $this->read($userId);
            if ($keys === null) {
                throw new \RuntimeException('identity keys for user ' . $userId . ' could not be minted');
            }
        }

        return $this->cache[$userId] = $keys;
    }

    /** Replace the linking key; returns the new one (hex). */
    public function rotateLinkKey(int $userId): string
    {
        $this->forUser($userId);
        $new = bin2hex(random_bytes(32));
        $stmt = $this->conn->prepareWrite('UPDATE 202_identity_keys SET link_key = ?, rotated_at = ? WHERE user_id = ?');
        $this->conn->bind($stmt, 'sii', [$new, time(), $userId]);
        $this->conn->executeUpdate($stmt);
        unset($this->cache[$userId]);

        return $new;
    }

    /** @return array{hash: string, link: string}|null */
    private function read(int $userId): ?array
    {
        $stmt = $this->conn->prepareWrite('SELECT hash_key, link_key FROM 202_identity_keys WHERE user_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'i', [$userId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }
        $hash = (string) $row['hash_key'];
        $link = (string) $row['link_key'];
        // A key that is not 32 bytes of hex would still produce hashes, just
        // weaker ones; an unreadable secret is refused, named (CLAUDE.md #11).
        if (preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1 || preg_match('/^[0-9a-f]{64}$/D', $link) !== 1) {
            throw new \RuntimeException('identity keys for user ' . $userId . ' are not 32-byte hex values');
        }

        return ['hash' => $hash, 'link' => $link];
    }
}
