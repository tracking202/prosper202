<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\ValidationException;

/**
 * The window and filters a report reads from its query string, checked
 * before any of it reaches SQL, and turned into WHERE fragments — over the
 * 202_dataengine alias `de` by default, or over any source a column map
 * describes (see "Over another table" below).
 *
 * The filters are the Analyze pages' (tracking202/analyze, through
 * ReportFilterInput and DataEngine's UserPrefFilters), with their meaning:
 *
 *  - the id filters narrow to one row of their table; '' or 0 is "not
 *    filtering", as the pages' menus send it. ppc_network_id also takes
 *    `none`, the clicks with no traffic source (no account, or an account
 *    without one), and the pages' own spelling of it, 16777215 (the
 *    column's maximum, since 0 already meant "all"). As an id that matched
 *    no row, so the API answered nothing where the page showed the clicks;
 *  - device_type is a 202_device_types id, applied as the pages apply their
 *    device menu: to every device model of that type;
 *  - keyword and referer are "contains", case-insensitive (the columns'
 *    collation), over the keyword and the referring URL, with % and _ in
 *    the text matched literally (the pages used to paste the text into
 *    LIKE, so `50%` matched every keyword containing "50");
 *  - ip is one address, exactly; an IPv6 address matches however it is
 *    written (it is compared packed, as it is stored);
 *  - show is the pages' "show" menu: all, real (not filtered), filtered,
 *    filtered_bot, leads (clicks that converted);
 *  - method_of_promotion is directlink (no landing page) or landingpage.
 *
 * The pages' keyword, referer and IP filters are now these very terms
 * (Prosper202\DataEngine\TextFilterSql builds them through this class).
 * They used to resolve the referer to an id list with GROUP_CONCAT, which
 * is cut at group_concat_max_len (1024 bytes by default on MySQL 8;
 * MariaDB's default is 1 MB), so on MySQL a common word matched a truncated
 * list, the last id possibly cut mid-number, and the IP to the first of
 * several stored rows for one address (202_ips has no unique key on it).
 * Both are subqueries here, over every matching row.
 *
 * A parameter the report does not know, a list where one value goes, and a
 * value outside a filter's form are each a 422 naming the parameter
 * (CLAUDE.md #4): a typo like `campain_id=7` used to be ignored, and the
 * answer covered every campaign while reading as the one asked for.
 *
 * Over another table. The fragments name columns through a map, logical
 * column => SQL expression, whose default is the 202_dataengine column under
 * `de` (COLUMNS lists the logical names). A source that keeps a column
 * elsewhere maps it — 202_clicks `c` with 202_clicks_advance `ca` maps
 * `click_time` to `c.click_time` and `keyword_id` to `ca.keyword_id` — and a
 * source that has no such column maps it to null: params() then leaves out
 * every filter that needs it, so rejectUnknown() refuses it by name, and
 * apply() refuses it too if it is handed one anyway. where() is the one-call
 * form: the query string in, WHERE terms and binds out.
 */
final class ReportFilter
{
    /**
     * The logical columns the fragments read, with the parameters that need
     * each. A column map keyed by these names re-targets the fragments.
     */
    public const COLUMNS = [
        'click_time'                => ['time_from', 'time_to', 'period'],
        'aff_campaign_id'           => ['aff_campaign_id'],
        'aff_network_id'            => ['aff_network_id'],
        'ppc_account_id'            => ['ppc_account_id'],
        'ppc_network_id'            => ['ppc_network_id'],
        'landing_page_id'           => ['landing_page_id', 'method_of_promotion'],
        'country_id'                => ['country_id'],
        'text_ad_id'                => ['text_ad_id'],
        'region_id'                 => ['region_id'],
        'isp_id'                    => ['isp_id'],
        'browser_id'                => ['browser_id'],
        'platform_id'               => ['platform_id'],
        'device_id'                 => ['device_type'],
        'click_filtered'            => ['show'],
        'click_bot'                 => ['show'],
        'click_lead'                => ['show'],
        'keyword_id'                => ['keyword'],
        'ip_id'                     => ['ip'],
        'click_referer_site_url_id' => ['referer'],
    ];

    /** The window: TimeBound's forms, and its named periods. */
    public const WINDOW = ['time_from', 'time_to', 'period'];

    /**
     * Id filters: parameter => 202_dataengine column, in the order they are
     * applied (the first six are the ones the API always had, in their order).
     */
    public const ID_FILTERS = [
        'aff_campaign_id' => 'aff_campaign_id',
        'aff_network_id'  => 'aff_network_id',
        'ppc_account_id'  => 'ppc_account_id',
        'ppc_network_id'  => 'ppc_network_id',
        'landing_page_id' => 'landing_page_id',
        'country_id'      => 'country_id',
        'text_ad_id'      => 'text_ad_id',
        'region_id'       => 'region_id',
        'isp_id'          => 'isp_id',
        'browser_id'      => 'browser_id',
        'platform_id'     => 'platform_id',
    ];

    /** show => [column, the condition on it] (UserPrefFilters::showFilter's). */
    public const SHOW = [
        'all'          => null,
        'real'         => ['click_filtered', '= 0'],
        'filtered'     => ['click_filtered', '= 1'],
        'filtered_bot' => ['click_bot', '= 1'],
        'leads'        => ['click_lead', '!= 0'],
    ];

    /** method_of_promotion => [column, the condition on it]. */
    public const METHODS_OF_PROMOTION = [
        'directlink'  => ['landing_page_id', '= 0'],
        'landingpage' => ['landing_page_id', '!= 0'],
    ];

    /** The pages' ppc_network_id for "no traffic source" (UserPrefFilters). */
    public const NO_TRAFFIC_SOURCE = '16777215';

    /** Longest keyword / referer text accepted. */
    public const MAX_TEXT = 255;

    private function __construct()
    {
    }

    /**
     * Every filter parameter (the window excluded), in the order they apply.
     *
     * @return list<string>
     */
    public static function filterNames(): array
    {
        return [
            ...array_keys(self::ID_FILTERS),
            'device_type', 'method_of_promotion', 'show', 'keyword', 'ip', 'referer',
        ];
    }

    /**
     * The window and every filter: what every report endpoint accepts. With a
     * column map, the ones its source can serve (a parameter whose column is
     * mapped to null is left out).
     *
     * @param array<string, ?string> $columns
     * @return list<string>
     */
    public static function params(array $columns = []): array
    {
        $unserved = [];
        foreach (self::COLUMNS as $column => $needs) {
            if (array_key_exists($column, $columns) && $columns[$column] === null) {
                $unserved = [...$unserved, ...$needs];
            }
        }

        return array_values(array_diff([...self::WINDOW, ...self::filterNames()], $unserved));
    }

    /**
     * The one-call form: the WHERE terms and binds the window and the filters
     * in $params add, over the columns $columns maps (the dataengine's `de`
     * by default). $params must have been through rejectUnknown().
     *
     * @param array<string, mixed> $params
     * @param callable(): string $timezone
     * @param array<string, ?string> $columns
     * @return array{0: list<string>, 1: list<int|string>, 2: string} WHERE terms, binds, bind types
     */
    public static function where(array $params, callable $timezone, array $columns = []): array
    {
        $where = [];
        $binds = [];
        $types = '';
        self::apply($params, $timezone, $where, $binds, $types, $columns);

        return [$where, $binds, $types];
    }

    /**
     * Refuse a parameter outside $known, naming every one; and refuse a list
     * (`name[]=x`) for any parameter, since none takes more than one value.
     *
     * @param array<array-key, mixed> $params
     * @param list<string> $known
     */
    public static function rejectUnknown(array $params, array $known): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($params)), $known));
        if ($unknown !== []) {
            throw new ValidationException(
                'Unknown parameter' . (count($unknown) > 1 ? 's' : '') . ': ' . implode(', ', $unknown),
                array_fill_keys($unknown, 'Not a parameter of this report. Valid: ' . implode(', ', $known))
            );
        }
        foreach ($params as $name => $value) {
            if (!is_string($value) && !is_int($value)) {
                throw new ValidationException('Invalid ' . $name, [(string) $name => 'Takes one value, not a list']);
            }
        }
    }

    /**
     * Append the window and the filters to a WHERE list, with their binds.
     *
     * @param array<string, mixed> $params already through rejectUnknown()
     * @param callable(): string $timezone the account's timezone, asked only when a bound needs it
     * @param list<string> $where
     * @param list<int|string> $binds
     * @param array<string, ?string> $columns logical column => SQL expression (see COLUMNS)
     */
    public static function apply(
        array $params,
        callable $timezone,
        array &$where,
        array &$binds,
        string &$types,
        array $columns = []
    ): void {
        foreach (array_keys($columns) as $column) {
            if (!isset(self::COLUMNS[$column])) {
                throw new \InvalidArgumentException("ReportFilter: no column named $column");
            }
        }
        // A parameter whose column this source lacks is refused, never
        // dropped: dropping it would answer for every click.
        $given = array_keys(array_filter($params, static fn (mixed $v): bool => $v !== '' && $v !== null));
        $unserved = array_values(array_diff(
            array_intersect($given, [...self::WINDOW, ...self::filterNames()]),
            self::params($columns)
        ));
        if ($unserved !== []) {
            throw new ValidationException(
                'This report cannot filter by ' . implode(', ', $unserved),
                array_fill_keys($unserved, 'Not available on this report')
            );
        }
        $col = static fn (string $name): string => $columns[$name] ?? 'de.' . $name;

        // Read once, so a date bound and a calendar period cost one lookup.
        $zone = null;
        $tz = static function () use (&$zone, $timezone): string {
            return $zone ??= $timezone();
        };

        [$from, $to] = TimeBound::window($params, $tz);
        if ($from !== null) {
            $where[] = $col('click_time') . ' >= ?';
            $binds[] = $from;
            $types .= 'i';
        }
        if ($to !== null) {
            $where[] = $col('click_time') . ' <= ?';
            $binds[] = $to;
            $types .= 'i';
        }
        $period = $params['period'] ?? '';
        if ($period !== '') {
            [$from, $to] = TimeBound::period($period, $tz);
            if ($from !== null) {
                $where[] = $col('click_time') . ' >= ?';
                $binds[] = $from;
                $types .= 'i';
            }
            if ($to !== null) {
                $where[] = $col('click_time') . ' <= ?';
                $binds[] = $to;
                $types .= 'i';
            }
        }

        foreach (self::ID_FILTERS as $param => $column) {
            if ($param === 'ppc_network_id' && self::isNoTrafficSource($params[$param] ?? null)) {
                $where[] = $col($column) . ' IS NULL';
                continue;
            }
            $id = self::id($params, $param);
            if ($id !== null) {
                $where[] = $col($column) . ' = ?';
                $binds[] = $id;
                $types .= 'i';
            }
        }

        $deviceType = self::id($params, 'device_type');
        if ($deviceType !== null) {
            $where[] = $col('device_id')
                . ' IN (SELECT dm.device_id FROM 202_device_models dm WHERE dm.device_type = ?)';
            $binds[] = $deviceType;
            $types .= 'i';
        }

        $method = self::choice($params, 'method_of_promotion', self::METHODS_OF_PROMOTION);
        if ($method !== null) {
            [$column, $condition] = self::METHODS_OF_PROMOTION[$method];
            $where[] = $col($column) . ' ' . $condition;
        }

        $show = self::choice($params, 'show', self::SHOW);
        if ($show !== null && self::SHOW[$show] !== null) {
            [$column, $condition] = self::SHOW[$show];
            $where[] = $col($column) . ' ' . $condition;
        }

        $keyword = self::text($params, 'keyword');
        if ($keyword !== null) {
            $where[] = $col('keyword_id')
                . " IN (SELECT k.keyword_id FROM 202_keywords k WHERE k.keyword LIKE ? ESCAPE '!')";
            $binds[] = self::contains($keyword);
            $types .= 's';
        }

        $ip = self::ip($params);
        if ($ip !== null) {
            if ($ip['v6']) {
                // An IPv6 address is a 202_ips row whose ip_address holds the
                // id of its 202_ips_v6 row (MysqlLocationRepository::insertIp).
                // Only an all-digit ip_address is such a reference: compared
                // with a number, the text '2.0.1.1' reads as 2 (SELECT
                // 2 = '2.0.1.1' is 1 on MariaDB 10.11), so it is never left
                // to the server to decide.
                $where[] = $col('ip_id') . ' IN (SELECT i.ip_id FROM 202_ips i INNER JOIN 202_ips_v6 i6'
                    . " ON (i.ip_address REGEXP '^[0-9]+$' AND i6.ip_id = CAST(i.ip_address AS UNSIGNED))"
                    . ' WHERE i6.ip_address = ?)';
            } else {
                $where[] = $col('ip_id') . ' IN (SELECT i.ip_id FROM 202_ips i WHERE i.ip_address = ?)';
            }
            $binds[] = $ip['value'];
            $types .= 's';
        }

        $referer = self::text($params, 'referer');
        if ($referer !== null) {
            $where[] = $col('click_referer_site_url_id')
                . " IN (SELECT su.site_url_id FROM 202_site_urls su WHERE su.site_url_address LIKE ? ESCAPE '!')";
            $binds[] = self::contains($referer);
            $types .= 's';
        }
    }

    /** `none`, or the pages' 16777215: the clicks with no traffic source. */
    private static function isNoTrafficSource(mixed $value): bool
    {
        return $value === 'none' || $value === self::NO_TRAFFIC_SOURCE || $value === (int) self::NO_TRAFFIC_SOURCE;
    }

    /**
     * An id filter's value: null when absent, '' or 0 (not filtering).
     *
     * @param array<string, mixed> $params
     */
    private static function id(array $params, string $name): ?int
    {
        $value = $params[$name] ?? null;
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if ((!is_string($value) && !is_int($value)) || preg_match('/^[1-9][0-9]{0,17}$/D', (string) $value) !== 1) {
            $none = $name === 'ppc_network_id' ? ', or none for the clicks with no traffic source' : '';
            throw new ValidationException('Invalid ' . $name, [
                $name => 'A positive whole number (an id), or 0 for no filter' . $none . '; got ' . self::shown($value),
            ]);
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $choices
     */
    private static function choice(array $params, string $name, array $choices): ?string
    {
        $value = $params[$name] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !array_key_exists($value, $choices)) {
            throw new ValidationException('Invalid ' . $name, [
                $name => 'One of: ' . implode(', ', array_keys($choices)) . '; got ' . self::shown($value),
            ]);
        }

        return $value;
    }

    /**
     * A contains filter's text, trimmed; null when absent or blank.
     *
     * @param array<string, mixed> $params
     */
    private static function text(array $params, string $name): ?string
    {
        $value = $params[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new ValidationException('Invalid ' . $name, [$name => 'Text (UTF-8) to search for']);
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value, 'UTF-8') > self::MAX_TEXT) {
            throw new ValidationException('Invalid ' . $name, [$name => 'At most ' . self::MAX_TEXT . ' characters']);
        }

        return $value;
    }

    /**
     * The ip filter: the address as stored — dotted text for IPv4, the
     * packed 16 bytes for IPv6 — or null when absent or blank.
     *
     * @param array<string, mixed> $params
     * @return array{v6: bool, value: string}|null
     */
    private static function ip(array $params): ?array
    {
        $value = $params['ip'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $text = is_string($value) ? trim($value) : '';
        if (filter_var($text, FILTER_VALIDATE_IP) === false) {
            throw new ValidationException('Invalid ip', [
                'ip' => 'One IPv4 or IPv6 address, e.g. 203.0.113.7 or 2001:db8::1; got ' . self::shown($value),
            ]);
        }
        $packed = inet_pton($text);
        if ($packed === false) {
            throw new ValidationException('Invalid ip', [
                'ip' => 'One IPv4 or IPv6 address, e.g. 203.0.113.7 or 2001:db8::1; got ' . self::shown($value),
            ]);
        }
        if (filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return ['v6' => false, 'value' => (string) inet_ntop($packed)];
        }

        return ['v6' => true, 'value' => $packed];
    }

    /** A LIKE pattern that matches $text literally, anywhere. */
    private static function contains(string $text): string
    {
        return '%' . strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    /** A rejected value, quoted and cut short, for the message. */
    private static function shown(mixed $value): string
    {
        if (!is_scalar($value)) {
            return 'a list';
        }
        $text = (string) $value;
        if (!mb_check_encoding($text, 'UTF-8')) {
            // Echoed into a JSON body, invalid UTF-8 would fail the encode.
            return 'a value that is not UTF-8';
        }

        return '"' . (mb_strlen($text, 'UTF-8') > 40 ? mb_substr($text, 0, 40, 'UTF-8') . '…' : $text) . '"';
    }
}
