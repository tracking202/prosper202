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
        $stored = self::stored($db);
        if (isset($stored['error'])) {
            return 'cannot read the schema version from 202_version (' . $stored['error'] . '); open 202-config/upgrade.php on the site to repair it.';
        }
        if ($stored['version'] === $codeVersion) {
            return null;
        }
        if (self::isVersion($stored['version']) && version_compare($stored['version'], $codeVersion, '>')) {
            return 'the database\'s schema is version ' . $stored['version'] . ', newer than this code (version '
                . $codeVersion . '): these files are older than the database. Nothing was run. Deploy the files of'
                . ' version ' . $stored['version'] . ' or later; an upgrade cannot help.';
        }

        return 'the database needs an upgrade: its schema is version ' . ($stored['version'] !== '' ? $stored['version'] : '(none recorded)')
            . ' and this code is version ' . $codeVersion
            . '. Nothing was run. Open 202-config/upgrade.php on the site (signed in) to upgrade it; cron jobs run again once it has.';
    }

    /**
     * What an answer that has already failed says while the files are
     * deployed and the upgrade has not run, when a statement can fail on the
     * older schema. Null unless the schema is older than this code, by
     * version_compare(): when it is current, newer (files rolled back, which
     * no upgrade mends), or its version cannot be read or is not a version,
     * the answer claims no cause, as it did. It never throws, being asked
     * only on an error path.
     */
    public static function pendingUpgrade(\mysqli $db, string $codeVersion): ?string
    {
        try {
            $stored = self::stored($db);
        } catch (\Throwable) {
            return null;
        }
        if (
            isset($stored['error']) || !self::isVersion($stored['version'])
            || !version_compare($stored['version'], $codeVersion, '<')
        ) {
            return null;
        }

        return 'the database needs an upgrade: its schema is version ' . $stored['version']
            . ' and this code is version ' . $codeVersion
            . '. Open 202-config/upgrade.php on the site (signed in) to upgrade it.';
    }

    /** Dotted numbers, as 202_version holds them: version_compare() orders anything else by its own rules. */
    private static function isVersion(string $version): bool
    {
        return preg_match('/\A\d+(\.\d+)*\z/', $version) === 1;
    }

    /** @return array{version: string}|array{error: string} */
    private static function stored(\mysqli $db): array
    {
        try {
            $result = $db->query('SELECT version FROM 202_version');
        } catch (\mysqli_sql_exception $e) {
            return ['error' => $e->getMessage()];
        }
        if (!$result instanceof \mysqli_result) {
            return ['error' => $db->error];
        }
        $row = $result->fetch_assoc();
        $result->free();

        return ['version' => is_array($row) ? (string) ($row['version'] ?? '') : ''];
    }
}
