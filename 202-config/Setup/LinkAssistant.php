<?php

declare(strict_types=1);

namespace Prosper202\Setup;

/**
 * The Link Assistant on Setup › Campaigns: it recognises an affiliate
 * network's link and suggests where that network takes a sub id, carrying
 * [[subid]]. The page only ever suggests; the user applies it with a click.
 *
 * The same rules run in the browser (202-js/p202-link-assistant.js), which
 * reads networks() from the page; LinkAssistantTest holds the two to the same
 * answers. Patterns must therefore mean the same in PCRE and JavaScript:
 * plain classes, groups and quantifiers only.
 *
 * A network's place is where its sub id goes:
 * - query:   ?param=value in the query string;
 * - pair:    /param:value as a path segment (Partnerize);
 * - segment: a path segment after the part of the path `match` matched.
 *
 * `match` is read from the link's start up to its query: a pattern found
 * only inside a parameter value or the fragment is another site's name.
 */
final class LinkAssistant
{
    public const TOKEN = '[[subid]]';

    /**
     * First match wins, so a network matched by its host comes before the
     * path shapes several platforms share.
     *
     * @var list<array{id: string, name: string, match: string, param: string, place: string}>
     */
    private const NETWORKS = [
        ['id' => 'clickbank', 'name' => 'ClickBank', 'param' => 'tid', 'place' => 'query',
            'match' => 'hop\.clickbank\.net'],
        ['id' => 'paykickstart', 'name' => 'PayKickstart', 'param' => 'tid', 'place' => 'query',
            'match' => 'paykstrt\.com/[0-9]+/[0-9]+'],
        ['id' => 'jvzoo', 'name' => 'JVZoo', 'param' => 'tid', 'place' => 'query',
            'match' => '(jvzoo|jvz[1-9])\.com/c/[0-9]+/[0-9]+'],
        ['id' => 'warriorplus', 'name' => 'WarriorPlus', 'param' => 'sid', 'place' => 'segment',
            'match' => 'warriorplus\.com/o2/[av]/\w+/\w+'],
        ['id' => 'zaxaa', 'name' => 'Zaxaa', 'param' => 'sid', 'place' => 'segment',
            'match' => 'zaxaa\.com/o/[0-9]+/[0-1](/|\?|$)'],
        ['id' => 'rakuten', 'name' => 'Rakuten Advertising', 'param' => 'u1', 'place' => 'query',
            'match' => 'click\.linksynergy\.com/(fs-bin/click|deeplink)\?id='],
        ['id' => 'shareasale', 'name' => 'ShareASale', 'param' => 'afftrack', 'place' => 'query',
            'match' => 'shareasale\.com/r\.cfm\?'],
        ['id' => 'awin', 'name' => 'Awin', 'param' => 'clickref', 'place' => 'query',
            'match' => 'awin1\.com/cread\.php\?'],
        ['id' => 'avantlink', 'name' => 'AvantLink', 'param' => 'ctc', 'place' => 'query',
            'match' => 'avantlink\.com/click\.php\?'],
        ['id' => 'linkconnector', 'name' => 'LinkConnector', 'param' => 'atid', 'place' => 'query',
            'match' => 'linkconnector\.com/ta\.php\?lc='],
        ['id' => 'ebay', 'name' => 'eBay Partner Network', 'param' => 'customid', 'place' => 'query',
            'match' => '//(www\.|m\.)?ebay\.[a-z.]+/[^#]*[?&]campid=[0-9]+'],
        ['id' => 'partnerize', 'name' => 'Partnerize', 'param' => 'pubref', 'place' => 'pair',
            'match' => 'prf\.hn/click/camref:\w+'],
        ['id' => 'flexoffers', 'name' => 'FlexOffers', 'param' => 'fobs', 'place' => 'query',
            'match' => '/a\.ashx\?foid='],
        ['id' => 'maxbounty', 'name' => 'MaxBounty', 'param' => 's2', 'place' => 'query',
            'match' => 'lnk\.asp\?o=[0-9]+&c=[0-9]+&a=[0-9]+'],
        ['id' => 'tune', 'name' => 'TUNE (HasOffers)', 'param' => 'aff_sub', 'place' => 'query',
            'match' => 'aff_c\?offer_id=[0-9]+&aff_id=[0-9]+'],
        ['id' => 'cake', 'name' => 'CAKE', 'param' => 's2', 'place' => 'query',
            'match' => '\?a=[0-9]+&c=[0-9]+'],
        ['id' => 'linktrust', 'name' => 'LinkTrust', 'param' => 'sid', 'place' => 'query',
            'match' => '\?cid=[0-9]+&afid=[0-9]+'],
        ['id' => 'affise', 'name' => 'Affise', 'param' => 'sub1', 'place' => 'query',
            'match' => '/click\?pid=\w+&offer_id=\w+'],
        ['id' => 'postaffiliatepro', 'name' => 'Post Affiliate Pro', 'param' => 'data1', 'place' => 'query',
            'match' => '/\?a_aid=\w+&a_bid=\w+'],
        ['id' => 'idevaffiliate', 'name' => 'iDevAffiliate', 'param' => 'tid1', 'place' => 'query',
            'match' => '\?idev_id=\w+'],
        ['id' => 'cj', 'name' => 'CJ Affiliate', 'param' => 'sid', 'place' => 'query',
            'match' => '/click-[0-9]+-[0-9]+'],
        ['id' => 'pepperjam', 'name' => 'Pepperjam', 'param' => 'sid', 'place' => 'query',
            'match' => '/t/[0-9]+-[0-9]+-[0-9]+-[0-9]+'],
        ['id' => 'impact', 'name' => 'impact.com', 'param' => 'subId1', 'place' => 'query',
            'match' => '/c/[0-9]+/[0-9]+/[0-9]+'],
    ];

    private function __construct()
    {
    }

    /**
     * The table the page hands its script.
     *
     * @return list<array{id: string, name: string, match: string, param: string, place: string}>
     */
    public static function networks(): array
    {
        return self::NETWORKS;
    }

    /**
     * What the assistant suggests for $url, or null: an unknown link, or one
     * that already carries [[subid]] (the user placed it).
     *
     * action is add (the parameter is missing), fill (it is there, empty) or
     * replace (it holds `previous`, which the suggestion would overwrite).
     *
     * @return array{network: string, name: string, param: string, action: string, previous: ?string, url: string}|null
     */
    public static function suggest(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || stripos($url, self::TOKEN) !== false) {
            return null;
        }
        foreach (self::NETWORKS as $network) {
            if (!self::isLinkOf($url, $network['match'])) {
                continue;
            }
            $placed = match ($network['place']) {
                'pair' => self::placePair($url, $network['param']),
                'segment' => self::placeSegment($url, $network['match']),
                default => self::placeQuery($url, $network['param']),
            };
            if ($placed === null) {
                return null;
            }
            [$suggested, $action, $previous] = $placed;

            return [
                'network' => $network['id'],
                'name' => $network['name'],
                'param' => $network['param'],
                'action' => $action,
                'previous' => $previous,
                'url' => $suggested,
            ];
        }

        return null;
    }

    /**
     * The pattern must start in the link's own host or path (a `\?...`
     * pattern at the query's start), not in a parameter value or fragment
     * that names another site.
     */
    private static function isLinkOf(string $url, string $pattern): bool
    {
        [$path, $query] = self::split($url);
        if (preg_match(self::regex($pattern), $path . $query, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return false;
        }

        return $m[0][1] <= strlen($path);
    }

    public static function regex(string $pattern): string
    {
        return '~' . $pattern . '~i';
    }

    /**
     * The URL cut at its query and fragment: [before ?, ?query or '', #fragment or ''].
     *
     * @return array{string, string, string}
     */
    private static function split(string $url): array
    {
        $hash = strpos($url, '#');
        $fragment = $hash === false ? '' : substr($url, $hash);
        $rest = $hash === false ? $url : substr($url, 0, $hash);
        $mark = strpos($rest, '?');

        return $mark === false ? [$rest, '', $fragment] : [substr($rest, 0, $mark), substr($rest, $mark), $fragment];
    }

    /** @return array{string, string, ?string} */
    private static function placeQuery(string $url, string $param): array
    {
        [$path, $query, $fragment] = self::split($url);
        $found = preg_match('~([?&])(' . preg_quote($param, '~') . ')=([^&]*)~i', $query, $m, PREG_OFFSET_CAPTURE);
        if ($found === 1) {
            $value = $m[3][0];
            $start = $m[3][1];
            $query = substr($query, 0, $start) . self::TOKEN . substr($query, $start + strlen($value));

            return [$path . $query . $fragment, $value === '' ? 'fill' : 'replace', $value === '' ? null : $value];
        }
        $joiner = $query === '' ? '?' : (str_ends_with($query, '?') || str_ends_with($query, '&') ? '' : '&');

        return [$path . $query . $joiner . $param . '=' . self::TOKEN . $fragment, 'add', null];
    }

    /** @return array{string, string, ?string} */
    private static function placePair(string $url, string $param): array
    {
        [$path, $query, $fragment] = self::split($url);
        $found = preg_match('~/(' . preg_quote($param, '~') . '):([^/]*)~i', $path, $m, PREG_OFFSET_CAPTURE);
        if ($found === 1) {
            $value = $m[2][0];
            $start = $m[2][1];
            $path = substr($path, 0, $start) . self::TOKEN . substr($path, $start + strlen($value));

            return [$path . $query . $fragment, $value === '' ? 'fill' : 'replace', $value === '' ? null : $value];
        }
        // Partnerize reads everything after /destination: as the landing URL.
        $destination = stripos($path, '/destination:');
        $head = $destination === false ? rtrim($path, '/') : substr($path, 0, $destination);
        $tail = $destination === false ? '' : substr($path, $destination);

        return [$head . '/' . $param . ':' . self::TOKEN . $tail . $query . $fragment, 'add', null];
    }

    /** @return array{string, string, ?string}|null */
    private static function placeSegment(string $url, string $match): ?array
    {
        [$path, $query, $fragment] = self::split($url);
        if (preg_match(self::regex($match), $path, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $end = $m[0][1] + strlen($m[0][0]);
        $value = trim(substr($path, $end), '/');
        $suggested = rtrim(substr($path, 0, $end), '/') . '/' . self::TOKEN . $query . $fragment;

        return [$suggested, $value === '' ? 'add' : 'replace', $value === '' ? null : $value];
    }
}
