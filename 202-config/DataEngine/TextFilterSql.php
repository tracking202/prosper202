<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

use Api\V3\Exception\ValidationException;
use Api\V3\Support\ReportFilter;

/**
 * The report pages' keyword, referer and IP filters (202_users_pref
 * user_pref_keyword, user_pref_referer, user_pref_ip) as WHERE terms over a
 * click source, built by the API's ReportFilter so a filter means the same
 * thing on every page and in GET /reports/* and /clicks:
 *
 *  - keyword and referer are "contains", case-insensitive, over the keyword
 *    and the whole referring URL, with % and _ matched literally;
 *  - ip is one address, exactly, every 202_ips row stored for it, an IPv6
 *    address however it is written.
 *
 * The three readers each had their own copy, and each was wrong somewhere:
 * every one pasted the text into LIKE (`50%` matched "500 off"); the
 * Analyze pages resolved the referer to an id list with GROUP_CONCAT, which
 * the server cuts at group_concat_max_len without an error, and the address
 * to the first of its 202_ips rows; Visitors matched the referer's domain
 * only and the address as a substring (10.0.0.1 matched 10.0.0.12); the
 * Group Overview matched the referer only when it was the whole URL, joined
 * 202_clicks_site on the referer rather than the click (each click counted
 * once per click sharing its referer), and inner-joined 202_ips_v6, so an
 * IPv4 filter matched nothing.
 *
 * Every term is a subquery, so no list is built and nothing can be cut.
 * ReportFilter's binds are inlined as literals because these readers build
 * SQL text: an integer as itself, UTF-8 text quoted through the
 * connection's escaper, anything else (the packed 16 bytes of an IPv6
 * address) as a hex literal.
 *
 * A stored value ReportFilter refuses (an IP that is not one, text that is
 * not UTF-8) matches nothing: never every click (CLAUDE.md #11). The filter
 * forms refuse such a value before it is stored.
 */
final class TextFilterSql
{
    /** 202_users_pref column => ReportFilter parameter. */
    public const PREFERENCES = [
        'user_pref_keyword' => 'keyword',
        'user_pref_referer' => 'referer',
        'user_pref_ip' => 'ip',
    ];

    private function __construct()
    {
    }

    /**
     * The terms the row's three text filters add, each " AND (...)"; '' when
     * none is set.
     *
     * @param array<string, mixed> $userRow a 202_users_pref row (as ReportView draws it)
     * @param string $alias the click source's alias; it must carry keyword_id,
     *   ip_id and click_referer_site_url_id (202_dataengine does)
     * @param callable(string): string $escape the connection's real_escape_string
     */
    public static function where(array $userRow, string $alias, callable $escape): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $alias) !== 1) {
            throw new \InvalidArgumentException("TextFilterSql: '$alias' is not a table alias");
        }
        $columns = [
            'keyword_id' => "$alias.keyword_id",
            'ip_id' => "$alias.ip_id",
            'click_referer_site_url_id' => "$alias.click_referer_site_url_id",
        ];

        $sql = '';
        foreach (self::PREFERENCES as $column => $param) {
            $value = $userRow[$column] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }
            try {
                [$terms, $binds, $types] = ReportFilter::where(
                    [$param => is_scalar($value) ? (string) $value : $value],
                    static fn (): string => 'UTC',
                    $columns
                );
            } catch (ValidationException) {
                $sql .= ' AND 0=1';
                continue;
            }
            foreach ($terms as $term) {
                $sql .= ' AND (' . self::inline($term, $binds, $types, $escape) . ')';
            }
        }

        return $sql;
    }

    /**
     * $term with each `?` replaced, in order, by its bind as a literal; the
     * binds a term does not use are left for the next. ReportFilter's terms
     * hold no `?` but their placeholders, which the count check below holds.
     *
     * @param list<int|string> $binds consumed from the front
     * @param callable(string): string $escape
     */
    private static function inline(string $term, array &$binds, string &$types, callable $escape): string
    {
        $needed = substr_count($term, '?');
        if ($needed > count($binds) || strlen($types) !== count($binds)) {
            throw new \LogicException('TextFilterSql: a filter term and its binds do not line up');
        }
        $parts = explode('?', $term);
        $out = array_shift($parts);
        foreach ($parts as $part) {
            $value = array_shift($binds);
            $type = $types[0];
            $types = substr($types, 1);
            $out .= self::literal($value, $type, $escape) . $part;
        }

        return $out;
    }

    /** @param callable(string): string $escape */
    private static function literal(int|string $value, string $type, callable $escape): string
    {
        if ($type === 'i') {
            return (string) (int) $value;
        }
        $text = (string) $value;
        if (mb_check_encoding($text, 'UTF-8') && !str_contains($text, "\0")) {
            return "'" . $escape($text) . "'";
        }

        return "X'" . bin2hex($text) . "'";
    }
}
