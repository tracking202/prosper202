<?php

declare(strict_types=1);

namespace Prosper202\Http;

use Prosper202\Database\Connection;

/**
 * The privacy setting (user_pref_privacy) that governs an account's
 * visitors, for code that does not run connect2.php's bootstrap — the app
 * intakes.
 *
 * The click path reads one row: the first account's (user 1), the install's
 * setting, whichever account's tracker was clicked. Every account can set
 * its own on Personal settings, though, so an account that holds back
 * would be overruled by an install that does not. Here the strictest of the
 * two governs: the install's, which is what the click path applies, and the
 * owning account's own. Masking more is the safe direction; that the click
 * path still reads only the install's is recorded where it is read.
 *
 * Read as the value it is, or as 'all' — hold back — when it cannot be read
 * (CLAUDE.md #11: a security value that cannot be parsed never resolves to
 * the most permissive reading): a failed query, or a stored value that is
 * not disabled, eu or all, logged by user id so the row can be found. An
 * account with no preferences row has set nothing: the column's default,
 * 'disabled'.
 */
final class PrivacySetting
{
    /** The account whose setting the click path applies (connect2.php). */
    public const INSTALL_USER_ID = 1;

    /** Weakest first. */
    public const SETTINGS = ['disabled', 'eu', 'all'];

    private function __construct()
    {
    }

    public static function forAccount(Connection $conn, ?int $ownerId): string
    {
        $ids = [self::INSTALL_USER_ID];
        if ($ownerId !== null && $ownerId > 0 && $ownerId !== self::INSTALL_USER_ID) {
            $ids[] = $ownerId;
        }
        try {
            $stmt = $conn->prepareRead(
                'SELECT user_id, user_pref_privacy FROM 202_users_pref WHERE user_id IN ('
                . implode(', ', array_fill(0, count($ids), '?')) . ')'
            );
            $conn->bind($stmt, str_repeat('i', count($ids)), $ids);
            $rows = $conn->fetchAll($stmt);
        } catch (\Throwable $e) {
            error_log('p202 privacy: user_pref_privacy could not be read (' . $e->getMessage() . '); holding back');

            return 'all';
        }

        $rank = array_flip(self::SETTINGS);
        $strictest = self::SETTINGS[0];
        foreach ($rows as $row) {
            $value = $row['user_pref_privacy'] ?? null;
            if (!is_string($value) || !isset($rank[$value])) {
                error_log('p202 privacy: user ' . (string) ($row['user_id'] ?? '?') . '\'s user_pref_privacy is '
                    . var_export($value, true) . ', not one of ' . implode(', ', self::SETTINGS) . '; holding back');

                return 'all';
            }
            if ($rank[$value] > $rank[$strictest]) {
                $strictest = $value;
            }
        }

        return $strictest;
    }
}
