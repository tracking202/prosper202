<?php

declare(strict_types=1);

namespace Prosper202\Report;

use Prosper202\Database\Connection;

/**
 * The account's time zone (202_users.user_timezone), as the reports read
 * it: UTC when it is unset or not a zone. One rule for the API controllers
 * (api/v3/Support/AccountTimezone), for the readers that run without one,
 * such as the attribution export cron, and for the pages (AUTH::
 * accountTimezone() and AUTH::set_timezone() take it from here).
 *
 * A zone is a name PHP lists (DateTimeZone::listIdentifiers(), with the
 * backward-compatible names an older list may have stored), spelled exactly
 * as listed. That is what every writer accepts (Personal settings, PUT
 * /users/{id}). It was two rules: the pages took only a listed name, and
 * this took anything `new DateTimeZone()` accepts -- an offset such as
 * `+05:30`, `GMT+5`, `Z`, a name in another case -- so one stored value
 * counted the pages' days in UTC and the API's at that fixed offset. An
 * offset is not a zone (CLAUDE.md #29): it is one moment of a zone's
 * history, and applied to a range it is wrong on the far side of every
 * daylight-saving change. A stored value that is not a zone is UTC on both
 * now, as it always was on the pages; the update and administration
 * endpoints, which delete or rewrite by day, refuse it instead.
 */
final class AccountZone
{
    private function __construct()
    {
    }

    public static function normalize(?string $stored): string
    {
        $timezone = trim((string) $stored);

        return self::isZone($timezone) ? $timezone : 'UTC';
    }

    /** True when $name is a zone PHP lists by exactly that name. */
    public static function isZone(string $name): bool
    {
        static $zones = null;
        $zones ??= array_flip(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC));

        return isset($zones[$name]);
    }

    /** The account's zone; a read that fails throws (it is not an account in UTC). */
    public static function read(Connection $conn, int $userId): string
    {
        $stmt = $conn->prepareRead('SELECT user_timezone FROM 202_users WHERE user_id = ? LIMIT 1');
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);

        return self::normalize(isset($row['user_timezone']) ? (string) $row['user_timezone'] : null);
    }
}
