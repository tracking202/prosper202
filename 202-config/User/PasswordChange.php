<?php

declare(strict_types=1);

namespace Prosper202\User;

use Prosper202\Database\Connection;
use RuntimeException;

/**
 * A new password for a user, from Personal settings (the user's own) or the
 * reset link (202-pass-reset.php): the password and the end of every
 * remember-me key of the user, in one transaction.
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

    /**
     * Store $hash as the user's password and delete every remember-me key of
     * theirs; with $consumesResetKey, also clear the reset key that allowed it.
     * The caller holds no transaction; nothing is kept unless all of it is.
     *
     * @throws RuntimeException when nothing was stored (a QueryException for a
     *   failed statement, RuntimeException for a user who is not there)
     */
    public static function store(Connection $conn, int $userId, string $hash, bool $consumesResetKey): void
    {
        $sql = $consumesResetKey
            ? 'UPDATE 202_users SET user_pass = ?, user_pass_key = NULL, user_pass_time = 0 WHERE user_id = ?'
            : 'UPDATE 202_users SET user_pass = ? WHERE user_id = ?';
        $conn->transaction(static function () use ($conn, $sql, $userId, $hash): void {
            $stmt = $conn->prepareWrite($sql);
            $conn->bind($stmt, 'si', [$hash, $userId]);
            if ($conn->executeUpdate($stmt) !== 1) {
                // A new hash always differs from the stored one: no row changed
                // is no user, and a password stored nowhere is not a change.
                throw new RuntimeException('Storing the password of user ' . $userId . ' changed no row');
            }
            RememberMeKeys::revokeAll($conn, $userId);
        });
    }
}
