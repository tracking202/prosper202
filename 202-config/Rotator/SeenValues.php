<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

use Prosper202\Database\Connection;

/**
 * The values an account's own clicks carried, for the rule types that
 * compare what a link holds (c1-c4, the utm values, the keyword) and the
 * referer: what Setup › Redirectors suggests as a value is typed, so a rule
 * is written with a value the account has received rather than a typo that
 * matches nobody.
 *
 * Read as Analyze's seen filters are (p202_overview_seen_list_sql()): the
 * account's report rows in the window grouped by the id first, then the
 * lookup, which is install-wide and far larger than one account's values.
 */
final class SeenValues
{
    /** How far back the clicks are read. */
    public const WINDOW_DAYS = 30;

    /** type => [the 202_dataengine column, the lookup joined as l, its id column, the value]. */
    private const LOOKUPS = [
        'c1' => ['c1_id', '202_tracking_c1 AS l', 'l.c1_id', 'l.c1'],
        'c2' => ['c2_id', '202_tracking_c2 AS l', 'l.c2_id', 'l.c2'],
        'c3' => ['c3_id', '202_tracking_c3 AS l', 'l.c3_id', 'l.c3'],
        'c4' => ['c4_id', '202_tracking_c4 AS l', 'l.c4_id', 'l.c4'],
        't202kw' => ['keyword_id', '202_keywords AS l', 'l.keyword_id', 'l.keyword'],
        'utm_source' => ['utm_source_id', '202_utm_source AS l', 'l.utm_source_id', 'l.utm_source'],
        'utm_medium' => ['utm_medium_id', '202_utm_medium AS l', 'l.utm_medium_id', 'l.utm_medium'],
        'utm_campaign' => ['utm_campaign_id', '202_utm_campaign AS l', 'l.utm_campaign_id', 'l.utm_campaign'],
        'utm_term' => ['utm_term_id', '202_utm_term AS l', 'l.utm_term_id', 'l.utm_term'],
        'utm_content' => ['utm_content_id', '202_utm_content AS l', 'l.utm_content_id', 'l.utm_content'],
        // A referer rule finds its value anywhere in the referer: the host is what tells referers apart.
        'referer' => ['click_referer_site_url_id',
            '(202_site_urls AS su INNER JOIN 202_site_domains AS l ON (l.site_domain_id = su.site_domain_id))',
            'su.site_url_id', 'l.site_domain_host'],
    ];

    private function __construct()
    {
    }

    public static function suggests(string $type): bool
    {
        return isset(self::LOOKUPS[$type]);
    }

    /** The statement matching() runs: the account, the window's start, the pattern. */
    public static function sql(string $type, int $limit): string
    {
        if (!isset(self::LOOKUPS[$type])) {
            throw new \InvalidArgumentException("SeenValues: '$type' is not a rule type suggested from the clicks");
        }
        [$column, $lookup, $idColumn, $value] = self::LOOKUPS[$type];

        // A value holding a comma cannot be a rule's: the rule's value is a comma-separated list.
        return "SELECT $value AS value, SUM(seen.clicks) AS clicks FROM ("
            . "SELECT d.$column AS id, SUM(d.clicks) AS clicks FROM 202_dataengine AS d"
            . " WHERE d.user_id = ? AND d.click_time >= ? AND d.$column > 0"
            . " GROUP BY d.$column) AS seen"
            . " STRAIGHT_JOIN $lookup ON ($idColumn = seen.id)"
            . " WHERE $value LIKE ? ESCAPE '|' AND $value != '' AND $value NOT LIKE '%,%'"
            . " GROUP BY $value ORDER BY clicks DESC, value LIMIT " . max(1, $limit);
    }

    /**
     * The values of this type the account's clicks carried in the window
     * that hold what was typed, the busiest first.
     *
     * @return list<string>
     */
    public static function matching(
        Connection $conn,
        int $userId,
        string $type,
        string $typed,
        int $now,
        int $limit = 20
    ): array {
        $typed = trim($typed);
        if ($typed === '') {
            return [];
        }
        $stmt = $conn->prepareRead(self::sql($type, $limit));
        $conn->bind($stmt, 'iis', [
            $userId,
            $now - self::WINDOW_DAYS * 86400,
            '%' . strtr($typed, ['|' => '||', '%' => '|%', '_' => '|_']) . '%',
        ]);

        return array_map(static fn (array $row): string => (string) $row['value'], $conn->fetchAll($stmt));
    }
}
