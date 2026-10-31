<?php

declare(strict_types=1);

namespace Prosper202\Database;

use Prosper202\Database\Schema\TableRegistry;

/**
 * Which install a database is: 32 random bytes, minted when the install's
 * schema is made and kept in 202_deployment_secrets. A database dropped and
 * created again under the same name is a new install with a new id, which is
 * what the database's host and name cannot say: the API state store named
 * its directory by those alone, so a reinstalled database read the previous
 * install's state as its own (its Idempotency-Key records replayed and
 * created nothing, its staged changes were listed and applied here).
 *
 * Minted by one idempotent statement, from the installer
 * (INSTALL::install_databases()), from the upgrade (the rungs that reconcile
 * 202_deployment_secrets), and by of() on a database that has none yet --
 * the files of a release are deployed before its upgrade runs. An id is
 * never replaced. A read that fails, or finds a value that is not an id,
 * throws: nothing answers for an id it does not have (CLAUDE.md #11).
 */
final class InstallId
{
    public const SECRET_NAME = 'install_id';

    private function __construct()
    {
    }

    /** The statement that mints the id if it is absent. Contains a fresh random id. */
    public static function mintStatement(): string
    {
        return 'INSERT INTO `' . TableRegistry::DEPLOYMENT_SECRETS . '`'
            . ' (secret_name, secret_value, created_at) VALUES ('
            . "'" . self::SECRET_NAME . "', '" . bin2hex(random_bytes(32)) . "', " . time() . ')'
            . ' ON DUPLICATE KEY UPDATE secret_name = secret_name';
    }

    /** Mint the id on this connection if it is absent; throws when it cannot be minted or read back. */
    public static function ensure(\mysqli $db): void
    {
        self::of($db);
    }

    /**
     * The install's id, 64 lower-case hex characters, minted first when the
     * database has none.
     *
     * Not minted inside an open transaction: a rollback would take the id
     * with it, and every state record written under it would name an
     * install that, read again, has another id.
     *
     * @throws \RuntimeException when it cannot be read or minted
     */
    public static function of(\mysqli $db): string
    {
        $id = self::read($db);
        if ($id !== null) {
            return $id;
        }
        if ((new Connection($db))->inTransaction()) {
            throw new \RuntimeException(
                'This database has no install id yet, and one minted inside the open transaction would go with'
                . ' its rollback: run the upgrade (202-config/upgrade.php), which mints it.'
            );
        }
        try {
            $minted = $db->query(self::mintStatement());
        } catch (\mysqli_sql_exception $e) {
            throw new \RuntimeException('Could not mint the install id', 0, $e);
        }
        if ($minted === false) {
            throw new \RuntimeException('Could not mint the install id: ' . $db->error);
        }

        return self::read($db)
            ?? throw new \RuntimeException('The install id was minted but cannot be read back');
    }

    /**
     * The stored id, or null when there is none.
     *
     * A locking read: a plain one inside a caller's transaction would fix
     * the snapshot that transaction's later plain reads answer from, before
     * any lock it takes (CLAUDE.md #36). Outside a transaction its shared
     * lock ends with the statement.
     */
    private static function read(\mysqli $db): ?string
    {
        try {
            $result = $db->query('SELECT secret_value FROM `' . TableRegistry::DEPLOYMENT_SECRETS . '`'
                . " WHERE secret_name = '" . self::SECRET_NAME . "' LOCK IN SHARE MODE");
        } catch (\mysqli_sql_exception $e) {
            throw new \RuntimeException('The install id could not be read', 0, $e);
        }
        if (!$result instanceof \mysqli_result) {
            throw new \RuntimeException('The install id could not be read: ' . $db->error);
        }
        $row = $result->fetch_assoc();
        $result->free();
        if ($row === false) {
            throw new \RuntimeException('The install id could not be read: the fetch failed');
        }
        if ($row === null) {
            return null;
        }
        $hex = $row['secret_value'] ?? null;
        if (!is_string($hex) || preg_match('/^[0-9a-f]{64}$/D', $hex) !== 1) {
            throw new \RuntimeException('The stored install id is not 64 lower-case hex characters');
        }

        return $hex;
    }
}
