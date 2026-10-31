<?php

declare(strict_types=1);

namespace Prosper202\Click\Filter;

use Prosper202\Database\Connection;

/**
 * An account's click filter settings and the list entry a click's address
 * falls in, as the click path reads them: once per click, uncached (a
 * primary-key read and one index probe; a cache would need every writer to
 * reach it, and a stale "always count" counts a click farm), and never
 * failing the click. Any error reading them (the tables not there yet while
 * an upgrade waits, a lost connection, a stored value this code cannot read)
 * answers the defaults with no entry, which filter as before the filter had
 * settings, and is logged once per request.
 */
final class ClickPathFilterRules
{
    private static bool $logged = false;

    private function __construct()
    {
    }

    /** @return array{ClickFilterSettings, ?ClickFilterEntry} */
    public static function forClick(Connection $conn, int $userId, string $arrived): array
    {
        try {
            $store = new MysqlClickFilterStore($conn);
            $settings = $store->settings($userId);
            $packed = AddressRange::packedForMatch($arrived);

            return [$settings, $packed === null ? null : $store->match($userId, $packed)];
        } catch (\Throwable $e) {
            if (!self::$logged) {
                self::$logged = true;
                error_log('Prosper202 click filter: account ' . $userId . '\'s settings could not be read, so the'
                    . ' click is filtered by the defaults: ' . $e->getMessage());
            }

            return [ClickFilterSettings::defaults(), null];
        }
    }
}
