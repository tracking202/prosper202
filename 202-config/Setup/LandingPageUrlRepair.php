<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use Prosper202\Database\Connection;
use Prosper202\Http\HttpUrlPrefix;

/**
 * Landing pages saved through the API before it held their URL to
 * http:// or https:// can hold a bare host ('lp.example.com/x',
 * '//lp.example.com/x'). The 1.9.80 upgrade gives each https://, as a save
 * now does (HttpUrlPrefix::complete()), so nobody edits them by hand. A URL
 * that is no bare host, or that would not fit its column, is left as it is.
 */
final class LandingPageUrlRepair
{
    private const COLUMNS = ['landing_page_url', 'leave_behind_page_url'];
    private const MAX_LENGTH = 255;

    private function __construct()
    {
    }

    /** @return int the URLs completed */
    public static function run(Connection $conn): int
    {
        $completed = 0;
        foreach (self::COLUMNS as $column) {
            $stmt = $conn->prepareWrite(
                "SELECT landing_page_id, user_id, $column AS url FROM 202_landing_pages"
                . " WHERE $column IS NOT NULL AND $column <> '' AND $column NOT LIKE 'http://%' AND $column NOT LIKE 'https://%'"
            );
            foreach ($conn->fetchAll($stmt) as $row) {
                $url = HttpUrlPrefix::complete((string) $row['url']);
                if ($url === (string) $row['url'] || mb_strlen($url) > self::MAX_LENGTH) {
                    continue;
                }
                $update = $conn->prepareWrite(
                    "UPDATE 202_landing_pages SET $column = ? WHERE landing_page_id = ? AND user_id = ? AND $column = ?"
                );
                $conn->bind($update, 'siis', [$url, (int) $row['landing_page_id'], (int) $row['user_id'], (string) $row['url']]);
                $completed += $conn->executeUpdate($update);
            }
        }

        return $completed;
    }
}
