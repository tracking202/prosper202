<?php

declare(strict_types=1);

namespace Prosper202\Update;

/** Whether this install came from the paid 1.9.56 release, whose upgrade has notes of its own. */
final class PaidOrigin
{
    /** The notes, from the install root, and the page that shows them before sign-in. */
    public const string NOTES_FILE = 'documentation/upgrading/from-paid-1.9.56.md';
    public const string NOTES_PAGE = '202-config/paid-1956-notes.php';

    /**
     * True when the database stores 1.9.56, as the paid release left it (a
     * free install stores it only while its 1.9.56 step is unfinished, so
     * the link is worded as a question), or holds the table only the paid
     * release made (202_clicks_purlink, which the upgrade leaves in place;
     * not every paid install has it).
     *
     * @throws \RuntimeException when the table could not be asked for
     */
    public static function isPaid1956(\mysqli $db, string $storedVersion): bool
    {
        if ($storedVersion === '1.9.56') {
            return true;
        }
        $result = $db->query("SHOW TABLES LIKE '202\\_clicks\\_purlink'");
        if (!$result instanceof \mysqli_result) {
            throw new \RuntimeException('could not read whether 202_clicks_purlink exists [errno ' . $db->errno . ']');
        }
        $exists = $result->num_rows > 0;
        $result->free();

        return $exists;
    }
}
