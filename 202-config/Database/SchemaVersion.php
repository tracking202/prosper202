<?php

declare(strict_types=1);

namespace Prosper202\Database;

/**
 * Whether the database's schema is the version this code is, for the entry
 * points that do not bootstrap through 202-config/connect.php (which checks
 * the same thing with upgrade_needed()). A version that cannot be read is
 * not "current": the caller refuses to run rather than work against a
 * schema it cannot name (CLAUDE.md error pattern #11).
 */
final class SchemaVersion
{
    /**
     * Null when the schema is current; otherwise the sentence saying why
     * not and what to do.
     */
    public static function mismatch(\mysqli $db, string $codeVersion): ?string
    {
        try {
            $result = $db->query('SELECT version FROM 202_version');
        } catch (\mysqli_sql_exception $e) {
            return 'cannot read the schema version from 202_version (' . $e->getMessage() . '); open 202-config/upgrade.php on the site to repair it.';
        }
        if (!$result instanceof \mysqli_result) {
            return 'cannot read the schema version from 202_version (' . $db->error . '); open 202-config/upgrade.php on the site to repair it.';
        }
        $row = $result->fetch_assoc();
        $result->free();
        $stored = is_array($row) ? (string) ($row['version'] ?? '') : '';
        if ($stored === $codeVersion) {
            return null;
        }

        return 'the database needs an upgrade: its schema is version ' . ($stored !== '' ? $stored : '(none recorded)')
            . ' and this code is version ' . $codeVersion
            . '. Nothing was run. Open 202-config/upgrade.php on the site (signed in) to upgrade it; cron jobs run again once it has.';
    }
}
