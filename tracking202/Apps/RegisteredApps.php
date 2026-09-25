<?php

declare(strict_types=1);

namespace Tracking202\Apps;

use Api\V3\Controllers\AppRegistrationsController;

/**
 * The account's registered apps, read the same way by every page that shows
 * them.
 *
 * Setup's "Your apps" panel and Analyze's App filter each read this list, and
 * they were reading it differently: Setup asked for 200 and Analyze for 500,
 * so an account with 300 apps saw all of them in one place and two thirds of
 * them in the other. Neither said it had cut the list — the panel just ended,
 * and the filter just did not offer the app you were looking for, which reads
 * as "that app is not registered" rather than "there are more".
 *
 * So the ceiling, the ordering and the fact of truncation live here together:
 * the three things the two pages have to agree about.
 */
final class RegisteredApps
{
    /**
     * The API's own per-page ceiling (api/v3/Controller.php clamps `limit` to
     * 500). Asking for more is silently reduced to this, so naming a larger
     * number here would not read more rows — it would only make the code look
     * as though it had.
     */
    public const MAX = 500;

    /**
     * `total` is null exactly when the list may be cut and the count is not
     * known — so a caller cannot render an exact figure the reader would
     * believe. Reporting count() there produced "500 of 500 apps", which
     * states as fact the one thing this could not establish.
     *
     * @return array{apps: list<array<string, mixed>>, total: int|null, truncated: bool}
     */
    public static function read(AppRegistrationsController $apps): array
    {
        // Both platforms: Setup registers and configures either, and the
        // Analyze report covers either (PR 11). Each row carries its
        // `platform`, which is how the pages label and route it.
        $result = $apps->list(['limit' => self::MAX]);
        $rows = array_values($result['data'] ?? []);

        // The API orders by primary key; both pages read better by name.
        usort($rows, static fn(array $a, array $b): int => strcasecmp(
            (string)($a['app_name'] ?? ''),
            (string)($b['app_name'] ?? '')
        ));

        // `total` is the unpaginated count, and api/v3/Controller::list()
        // always sends it. If it ever goes missing, the row count alone still
        // settles one of the two cases: a page short of the ceiling is the
        // whole answer, because a fuller one would have filled it. A page
        // exactly at the ceiling is the case we cannot settle, and there the
        // honest reading is "there may be more" — answering "nothing was cut"
        // would be the fail-open one (error pattern #11), silent in exactly
        // the situation it exists to report.
        $count = count($rows);
        $total = isset($result['pagination']['total'])
            ? (int)$result['pagination']['total']
            : null;

        return [
            'apps' => $rows,
            'total' => $total,
            'truncated' => $total === null ? $count >= self::MAX : $total > $count,
        ];
    }
}
