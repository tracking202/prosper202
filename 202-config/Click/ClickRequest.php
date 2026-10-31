<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * The values a click takes from its request, read one way for the four click
 * scripts (dl.php, rtr.php, record_simple.php, record_adv.php). Each read its
 * own copy: a list (c1[]=x) was stored as "Array", the keyword chains
 * disagreed on an empty value ('0' was no keyword on the landing pages and
 * '' won over t202kw on dl.php), and only record_simple.php made the keyword
 * valid UTF-8, which a strict server refuses a value without, losing the
 * click.
 */
final class ClickRequest
{
    /** Where a bidded keyword is read, the first that names one. */
    private const BIDDED = ['OVKEY', 't202kw', 'target_passthrough', 'keyword'];

    /** Where a searched keyword is read after the referring page's q. */
    private const SEARCHED = [
        'OVRAW', 'target_passthrough', 'keyword', 'search_word', 'query', 'encquery', 'terms', 'rdata',
        'qs', 'wd', 'text', 'szukaj', 'qt', 'k', 'words', 't202kw',
    ];

    private function __construct()
    {
    }

    /**
     * $key's value as text the click can store: '' when it is absent or a
     * list, and a byte that is no UTF-8 is '?'.
     *
     * @param array<array-key, mixed> $query
     */
    public static function text(array $query, string $key): string
    {
        $value = $query[$key] ?? null;

        return is_string($value) ? mb_scrub($value, 'UTF-8') : '';
    }

    /**
     * The ad platforms' click ids (ClickRecord::AD_CLICK_IDS) as text(), by
     * name, uncut: a token or the writer cuts each to its column.
     *
     * @param array<array-key, mixed> $query
     * @return array<string, string>
     */
    public static function adClickIds(array $query): array
    {
        $ids = [];
        foreach (array_keys(ClickRecord::AD_CLICK_IDS) as $name) {
            $ids[$name] = self::text($query, $name);
        }

        return $ids;
    }

    /**
     * $key's value as sent, or '' when it is absent or a list: for a URL the
     * click parses or keeps (a landing page's referer) and for an id, where
     * text()'s ? for a byte that is no UTF-8 could stand where a URL's query
     * begins.
     *
     * @param array<array-key, mixed> $query
     */
    public static function string(array $query, string $key): string
    {
        $value = $query[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * The keyword the account's preference names: the first of its sources
     * that holds one ('bidded' reads the link's, anything else the referring
     * search's, as the column's default 'searched'). A `t202var_<name>`
     * keyword is the value of the parameter <name>, and no keyword when that
     * is absent or a list: the placeholder itself is no keyword.
     *
     * @param array<array-key, mixed> $get the request's query
     * @param array<array-key, mixed> $refererQuery the query of the page the visitor came from
     */
    public static function keyword(array $get, array $refererQuery, ?string $preference): string
    {
        if ($preference === 'bidded') {
            $keyword = self::first($get, self::BIDDED);
        } else {
            $keyword = self::text($refererQuery, 'q');
            if ($keyword === '') {
                $keyword = self::first($get, self::SEARCHED);
            }
        }
        if (str_starts_with($keyword, 't202var_')) {
            $keyword = self::text($get, substr($keyword, strlen('t202var_')));
        }

        return str_replace('%20', ' ', $keyword);
    }

    /**
     * @param array<array-key, mixed> $query
     * @param list<string> $keys
     */
    private static function first(array $query, array $keys): string
    {
        foreach ($keys as $key) {
            $value = self::text($query, $key);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
