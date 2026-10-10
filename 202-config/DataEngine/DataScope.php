<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

/**
 * Whose clicks the report pages read.
 *
 * Every account's, for a session marked as one that may see every campaign
 * ($_SESSION['publisher'] set and false — nothing in the app sets it); the
 * signed-in account's own otherwise, which is every session there is. The
 * engine, Analyze, the Overview's lists and the reports' suggestions all read
 * it this way; the Visitors list and Spy (query() in functions-tracking202.php)
 * read the absent key the other way round, as "may see everything", so every
 * account's visitors were listed to every signed-in user.
 */
final class DataScope
{
    private function __construct()
    {
    }

    /** The account whose clicks to read, or null for every account's. */
    public static function userId(): ?int
    {
        if (isset($_SESSION['publisher']) && $_SESSION['publisher'] == false) {
            return null;
        }

        return (int) ($_SESSION['user_own_id'] ?? 0);
    }
}
