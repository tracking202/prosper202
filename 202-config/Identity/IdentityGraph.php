<?php

declare(strict_types=1);

namespace Prosper202\Identity;

use Prosper202\Database\Connection;

/**
 * The identity graph: which clicks belong to one person.
 *
 * Every click that carries first-party signals (IdentitySignal) records
 * them as observations — the evidence, which the journey view uses to say
 * why two clicks are linked — and each distinct signal maps to a visitor
 * key. A click whose signals map to different visitor keys MERGES them,
 * union-find style: the lower key becomes an alias of the higher, every key
 * that pointed at the lower one is re-pointed (path compression, so any key
 * is at most one hop from its canonical key), and the merge is recorded
 * with the click that caused it. The click itself is stored in
 * 202_clicks_visitor with the canonical key.
 *
 * Guard against over-merging: a signal that has joined more than
 * QUARANTINE_AFTER visitor keys (a shared kiosk, `cust=test` in QA, a
 * leaked cookie) is quarantined. It is still observed, but it no longer
 * links anything, and the report shows it.
 *
 * Merges are recorded with requeued_at NULL; the attribution worker re-queues
 * the conversions a merge can change (plan §6.2, PR 9).
 *
 * Every method runs inside the caller's transaction. Locks are taken in one
 * order — signal rows sorted by (type, hash), then visitor rows sorted by
 * key — so two clicks merging overlapping keys serialise instead of
 * deadlocking into a half-merged graph.
 */
final class IdentityGraph
{
    public const QUARANTINE_AFTER = 20;

    private IdentityKeys $keys;

    public function __construct(private Connection $conn, ?IdentityKeys $keys = null)
    {
        $this->keys = $keys ?? new IdentityKeys($conn);
    }

    /**
     * Link a click to the person its signals name.
     *
     * Safe to call again for the same click with more signals (a signed
     * customer id arriving with a conversion): the click's existing key takes
     * part in the resolution, so the new signal merges into it.
     *
     * @param list<IdentitySignal> $signals
     * @return int|null The click's canonical visitor key; null when it carried no signal.
     */
    public function attachClick(int $userId, int $clickId, int $clickTime, array $signals): ?int
    {
        if ($signals === []) {
            return null;
        }
        $hashKey = $this->keys->forUser($userId)['hash'];
        $now = time();

        // Hash, and fix the lock order.
        $hashed = [];
        foreach ($signals as $signal) {
            $hash = self::hash($hashKey, $signal);
            $hashed[$signal->type->value . ':' . $hash] = ['type' => $signal->type->value, 'hash' => $hash];
        }
        ksort($hashed);

        foreach ($hashed as $h) {
            $obs = $this->conn->prepareWrite(
                'INSERT IGNORE INTO 202_identity_observations (click_id, signal_type, signal_hash, observed_at) VALUES (?, ?, ?, ?)'
            );
            $this->conn->bind($obs, 'issi', [$clickId, $h['type'], $h['hash'], $now]);
            $this->conn->executeUpdate($obs);
        }

        // Which keys do the signals already name?
        $known = [];      // signal id => row
        $candidates = [];
        foreach ($hashed as $id => $h) {
            $stmt = $this->conn->prepareWrite(
                'SELECT visitor_key, merges, quarantined_at FROM 202_identity_signals
                 WHERE user_id = ? AND signal_type = ? AND signal_hash = ? FOR UPDATE'
            );
            $this->conn->bind($stmt, 'iss', [$userId, $h['type'], $h['hash']]);
            $row = $this->conn->fetchOne($stmt);
            if ($row === null) {
                continue;
            }
            $known[$id] = $row;
            if ($row['quarantined_at'] === null) {
                $candidates[] = (int) $row['visitor_key'];
            }
        }
        $existing = $this->clickKey($clickId);
        if ($existing !== null) {
            $candidates[] = $existing;
        }

        $canonical = $this->lockAndResolve($userId, $candidates);
        if ($canonical === []) {
            $target = $this->newVisitor($userId, $now);
        } else {
            $target = max($canonical);
            $linker = null;
            foreach ($hashed as $id => $h) {
                if (isset($known[$id]) && $known[$id]['quarantined_at'] === null) {
                    $linker = $h;
                    break;
                }
            }
            foreach ($canonical as $key) {
                if ($key !== $target) {
                    $this->merge($userId, $key, $target, $clickId, $linker ?? reset($hashed), $now);
                }
            }
            $joined = count($canonical) - 1;
            if ($joined > 0) {
                foreach ($hashed as $id => $h) {
                    if (!isset($known[$id]) || $known[$id]['quarantined_at'] !== null) {
                        continue;
                    }
                    $merges = (int) $known[$id]['merges'] + $joined;
                    $stmt = $this->conn->prepareWrite(
                        'UPDATE 202_identity_signals SET merges = ?, quarantined_at = ?
                         WHERE user_id = ? AND signal_type = ? AND signal_hash = ?'
                    );
                    $this->conn->bind($stmt, 'iiiss', [
                        $merges,
                        $merges > self::QUARANTINE_AFTER ? $now : null,
                        $userId, $h['type'], $h['hash'],
                    ]);
                    $this->conn->executeUpdate($stmt);
                }
            }
        }

        // Signals seen for the first time now name this person.
        foreach ($hashed as $id => $h) {
            if (isset($known[$id])) {
                continue;
            }
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_identity_signals (user_id, signal_type, signal_hash, visitor_key, merges, quarantined_at, created_at)
                 VALUES (?, ?, ?, ?, 0, NULL, ?)'
            );
            $this->conn->bind($stmt, 'issii', [$userId, $h['type'], $h['hash'], $target, $now]);
            $this->conn->executeUpdate($stmt);
        }

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_clicks_visitor (click_id, user_id, visitor_key, click_time) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE visitor_key = VALUES(visitor_key)'
        );
        $this->conn->bind($stmt, 'iiii', [$clickId, $userId, $target, $clickTime]);
        $this->conn->executeUpdate($stmt);

        return $target;
    }

    /**
     * Every visitor key of the person a key belongs to, whether the key is
     * canonical or an alias: what a journey query matches
     * 202_clicks_visitor.visitor_key against. Empty when the account has no
     * visitor row for the key.
     *
     * The one reading of the alias structure outside this class's writers:
     * merge() compresses paths, so a key is at most one hop from its
     * canonical key and every alias points straight at it. The attribution
     * worker calls this inside its transaction, so it reads the primary.
     *
     * @return list<int>
     */
    public function keysOfPerson(int $userId, int $key): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT alias_of FROM 202_identity_visitors WHERE visitor_key = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$key, $userId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return [];
        }

        return $this->keysOf($userId, $row['alias_of'] !== null ? (int) $row['alias_of'] : $key);
    }

    /**
     * Every key that is, or is an alias of, the given canonical key.
     *
     * @return list<int>
     */
    public function keysOf(int $userId, int $canonicalKey): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT visitor_key FROM 202_identity_visitors WHERE user_id = ? AND (visitor_key = ? OR alias_of = ?) ORDER BY visitor_key'
        );
        $this->conn->bind($stmt, 'iii', [$userId, $canonicalKey, $canonicalKey]);

        return array_map(static fn (array $r): int => (int) $r['visitor_key'], $this->conn->fetchAll($stmt));
    }

    /** The canonical key a click belongs to, or null. */
    public function visitorOfClick(int $clickId): ?int
    {
        $key = $this->clickKey($clickId);
        if ($key === null) {
            return null;
        }
        $stmt = $this->conn->prepareWrite('SELECT alias_of FROM 202_identity_visitors WHERE visitor_key = ? LIMIT 1');
        $this->conn->bind($stmt, 'i', [$key]);
        $row = $this->conn->fetchOne($stmt);

        return $row !== null && $row['alias_of'] !== null ? (int) $row['alias_of'] : $key;
    }

    /** The stored hash of a signal for an account (what the graph keys on). */
    public static function hash(string $hashKeyHex, IdentitySignal $signal): string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $hashKeyHex) !== 1) {
            throw new \InvalidArgumentException('the hashing key is not 32 bytes of hex');
        }
        $key = (string) hex2bin($hashKeyHex);

        // The type is inside the MAC so the same string used as two kinds
        // of signal hashes to two different values.
        return hash_hmac('sha256', $signal->type->value . "\0" . $signal->value, $key);
    }

    private function clickKey(int $clickId): ?int
    {
        $stmt = $this->conn->prepareWrite('SELECT visitor_key FROM 202_clicks_visitor WHERE click_id = ? LIMIT 1 FOR UPDATE');
        $this->conn->bind($stmt, 'i', [$clickId]);
        $row = $this->conn->fetchOne($stmt);

        return $row !== null ? (int) $row['visitor_key'] : null;
    }

    /**
     * Lock the visitor rows the candidate keys name and return the distinct
     * canonical keys among them. Resolution repeats until the set of locked
     * rows covers every canonical key, so a merge that committed between the
     * signal read and the lock is followed rather than undone.
     *
     * @param list<int> $candidates
     * @return list<int>
     */
    private function lockAndResolve(int $userId, array $candidates): array
    {
        $locked = [];
        $pending = array_values(array_unique($candidates));
        $canonical = [];
        for ($round = 0; $pending !== [] && $round < 4; $round++) {
            sort($pending);
            $next = [];
            foreach ($pending as $key) {
                if (isset($locked[$key])) {
                    continue;
                }
                $stmt = $this->conn->prepareWrite(
                    'SELECT alias_of FROM 202_identity_visitors WHERE visitor_key = ? AND user_id = ? LIMIT 1 FOR UPDATE'
                );
                $this->conn->bind($stmt, 'ii', [$key, $userId]);
                $row = $this->conn->fetchOne($stmt);
                if ($row === null) {
                    throw new \RuntimeException('visitor key ' . $key . ' is named by a signal or click but does not exist for user ' . $userId);
                }
                $locked[$key] = true;
                if ($row['alias_of'] === null) {
                    $canonical[$key] = true;
                } else {
                    $next[] = (int) $row['alias_of'];
                }
            }
            $pending = array_values(array_unique($next));
        }
        if ($pending !== []) {
            throw new \RuntimeException('visitor keys for user ' . $userId . ' alias in a chain longer than path compression allows');
        }
        $keys = array_keys($canonical);
        sort($keys);

        return $keys;
    }

    private function newVisitor(int $userId, int $now): int
    {
        $stmt = $this->conn->prepareWrite('INSERT INTO 202_identity_visitors (user_id, alias_of, created_at) VALUES (?, NULL, ?)');
        $this->conn->bind($stmt, 'ii', [$userId, $now]);
        $key = $this->conn->executeInsert($stmt);
        if ($key <= 0) {
            throw new \RuntimeException('a new visitor key for user ' . $userId . ' was not created');
        }

        return $key;
    }

    /**
     * @param array{type: string, hash: string} $linker The signal that joined the two keys.
     */
    private function merge(int $userId, int $from, int $into, int $clickId, array $linker, int $now): void
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_identity_visitors SET alias_of = ? WHERE user_id = ? AND (visitor_key = ? OR alias_of = ?)'
        );
        $this->conn->bind($stmt, 'iiii', [$into, $userId, $from, $from]);
        $this->conn->executeUpdate($stmt);

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_identity_merges (user_id, from_key, into_key, click_id, signal_type, signal_hash, merged_at, requeued_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL)'
        );
        $this->conn->bind($stmt, 'iiiissi', [$userId, $from, $into, $clickId, $linker['type'], $linker['hash'], $now]);
        $this->conn->executeUpdate($stmt);
    }
}
