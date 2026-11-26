<?php

declare(strict_types=1);

namespace Prosper202\User;

use Prosper202\Database\Connection;
use RuntimeException;

/**
 * A new password for a user, from Personal settings (the user's own) or the
 * reset link (202-pass-reset.php): the password, the end of every remember-me
 * key of the user and of every session they have open (SessionGeneration),
 * in one transaction. The reset link's key is consumed by the same write, so a
 * link sets one password however many times it is submitted.
 *
 * The password changed and the keys stayed, so a stolen remember-me cookie
 * went on signing in after its owner changed the password to lock the thief
 * out. Every writer of a new password deletes the keys
 * (RememberMeKeysRevokedTest); a rehash at sign-in stores the same password
 * and does not.
 */
final class PasswordChange
{
    private function __construct()
    {
    }

    /** How long a reset key allows a reset after it was issued (202-lost-pass.php). */
    public const RESET_KEY_SECONDS = 3 * 86400;

    /**
     * Store $hash as the user's password, delete every remember-me key of
     * theirs and end every session they have open. With $resetKey, the write
     * also consumes that reset key, and only while the key is still the
     * user's and unexpired at $now: two submits of one link both pass the
     * page's read, and the row lock lets the second write see the first one's
     * commit, so it finds the key gone and changes nothing (ResetKeyNotUsable).
     * The caller holds no transaction; nothing is kept unless all of it is.
     * Answers the user's new session generation, which the browser that made
     * the change takes to keep its own session
     * (AUTH::keepSessionAfterPasswordChange()).
     *
     * @throws ResetKeyNotUsable when $resetKey no longer allows the reset
     * @throws RuntimeException when nothing was stored (a QueryException for a
     *   failed statement, RuntimeException for a user who is not there)
     */
    public static function store(
        Connection $conn,
        int $userId,
        string $hash,
        ?string $resetKey = null,
        ?int $now = null
    ): int {
        if ($resetKey === '') {
            throw new ResetKeyNotUsable('An empty reset key allows no reset');
        }

        return $conn->transaction(static function () use ($conn, $userId, $hash, $resetKey, $now): int {
            if ($resetKey === null) {
                $stmt = $conn->prepareWrite('UPDATE 202_users SET user_pass = ? WHERE user_id = ?');
                $conn->bind($stmt, 'si', [$hash, $userId]);
            } else {
                // By the primary key, as every other writer of the row locks
                // it: through the key's index, two submits each took a lock
                // the other needed, and the second was a deadlock (answered
                // "could not save") rather than a used link.
                $stmt = $conn->prepareWrite('UPDATE 202_users FORCE INDEX (PRIMARY)'
                    . ' SET user_pass = ?, user_pass_key = NULL, user_pass_time = 0'
                    . ' WHERE user_id = ? AND user_pass_key = ? AND user_pass_time >= ?');
                $conn->bind($stmt, 'sisi', [$hash, $userId, $resetKey, ($now ?? time()) - self::RESET_KEY_SECONDS]);
            }
            if ($conn->executeUpdate($stmt) !== 1) {
                if ($resetKey !== null) {
                    throw new ResetKeyNotUsable('The reset key of user ' . $userId
                        . ' was used, replaced or expired before the password was stored');
                }
                // A new hash always differs from the stored one: no row changed
                // is no user, and a password stored nowhere is not a change.
                throw new RuntimeException('Storing the password of user ' . $userId . ' changed no row');
            }
            RememberMeKeys::revokeAll($conn, $userId);

            return SessionGeneration::bump($conn, $userId);
        });
    }
}
