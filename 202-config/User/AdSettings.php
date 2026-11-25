<?php

declare(strict_types=1);

namespace Prosper202\User;

use Prosper202\Database\Connection;

/**
 * A user's Personal Settings choice of where Prosper202 shows its ads, read
 * from the row each time. The home page read a session copy that only a save
 * of Personal Settings wrote, so after every sign-in Special offers was
 * hidden from everyone, Show All Ads included.
 */
final class AdSettings
{
    /** The column's default, and the answer for a user with no preferences row. */
    public const DEFAULT = 'show_all';

    /**
     * The stored choice. A read that fails throws: '' or the default would
     * show or hide the panel as though the user had chosen it.
     */
    public static function forUser(Connection $conn, int $userId): string
    {
        $stmt = $conn->prepareRead('SELECT user_pref_ad_settings FROM 202_users_pref WHERE user_id = ? LIMIT 1');
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            return self::DEFAULT;
        }

        return (string) ($row['user_pref_ad_settings'] ?? self::DEFAULT);
    }

    /** Whether the home page shows its Special offers panel. */
    public static function showsHomeOffers(string $setting): bool
    {
        return $setting !== 'hide_all';
    }
}
