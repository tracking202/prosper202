<?php

declare(strict_types=1);

namespace Tracking202\Report;

/**
 * What a report's query string asks for, read and checked before anything is
 * stored or queried.
 *
 * The classic reports keep their filters in `202_users_pref`: the calendar
 * posts them to tracking202/ajax/set_user_prefs.php, and every report (and
 * every *_download.php) reads them back from there. A v2 report takes the same
 * filters from its URL instead (UI standard, rule 8: the URL is the report),
 * under the same names the calendar posts, and hands the checked values to
 * ReportPrefsStore, which writes them to the same columns — so the stored
 * default, the downloads and the classic pages that still read it all see
 * what the URL said.
 *
 * Pure: no database, no session, no globals. The one thing it cannot decide
 * alone — which region or ISP a typed name means — it passes through for the
 * store to resolve.
 */
final class ReportFilterInput
{
    /**
     * URL name => the 202_users_pref column it is stored in. These are the
     * names display_calendar() renders and set_user_prefs.php reads.
     */
    public const COLUMNS = [
        'ppc_network_id' => 'user_pref_ppc_network_id',
        'ppc_account_id' => 'user_pref_ppc_account_id',
        'aff_network_id' => 'user_pref_aff_network_id',
        'aff_campaign_id' => 'user_pref_aff_campaign_id',
        'text_ad_id' => 'user_pref_text_ad_id',
        'method_of_promotion' => 'user_pref_method_of_promotion',
        'landing_page_id' => 'user_pref_landing_page_id',
        'country_id' => 'user_pref_country_id',
        'region_id' => 'user_pref_region_id',
        'isp_id' => 'user_pref_isp_id',
        'device_id' => 'user_pref_device_id',
        'browser_id' => 'user_pref_browser_id',
        'platform_id' => 'user_pref_platform_id',
        'ip' => 'user_pref_ip',
        'referer' => 'user_pref_referer',
        'keyword' => 'user_pref_keyword',
        'user_pref_limit' => 'user_pref_limit',
        'user_pref_show' => 'user_pref_show',
        'user_cpc_or_cpv' => 'user_cpc_or_cpv',
    ];

    /** Filters whose value is a row id; '' means "not filtering". */
    public const ID_FIELDS = [
        'ppc_network_id', 'ppc_account_id', 'aff_network_id', 'aff_campaign_id',
        'text_ad_id', 'landing_page_id', 'country_id', 'region_id', 'isp_id',
        'device_id', 'browser_id', 'platform_id',
    ];

    /** Free-text filters, and the width of the column each is stored in. */
    public const TEXT_FIELDS = ['ip' => 100, 'referer' => 100, 'keyword' => 100];

    /**
     * The typed-name stand-ins for two id filters whose lists are too long
     * for any menu. The form submits the name; ReportPrefsStore resolves it
     * to the id the column holds. `region_id` / `isp_id` in a hand-made URL
     * still work and win over nothing: the name is only read when the id is
     * absent.
     */
    public const NAMED_FIELDS = ['region' => 'region_id', 'isp' => 'isp_id'];

    /** Display settings: allowed values, and the value that means "default". */
    public const CHOICES = [
        'user_pref_show' => [['all', 'real', 'filtered', 'filtered_bot', 'leads'], 'all'],
        'user_pref_limit' => [['10', '25', '50', '75', '100', '150', '200'], '50'],
        'user_cpc_or_cpv' => [['cpc', 'cpv'], 'cpc'],
        'method_of_promotion' => [['directlink', 'landingpage'], ''],
    ];

    /** "No traffic source": 0 already means "all" in that column. */
    public const NO_TRAFFIC_SOURCE = '16777215';

    public const RANGE_CUSTOM = 'custom';

    /** The classic calendar's presets, which grab_timeframe() resolves. */
    public const RANGES = ['today', 'yesterday', 'last7', 'last14', 'last30', 'thismonth', 'lastmonth', 'thisyear', 'lastyear', 'alltime'];

    /**
     * @param array<string, string> $values  URL name => checked value, for
     *   every offered field ('' = not filtering, or the default choice)
     * @param array{range: string, from: ?array{int,int,int}, to: ?array{int,int,int}}|null $window
     *   null when the URL did not name a window (the stored one stands)
     * @param array<string, string> $errors  URL name (or 'range') => sentence
     * @param array<string, string> $names   'region' / 'isp' => the typed name
     *   to resolve, when the URL gave a name rather than an id
     */
    private function __construct(
        public readonly bool $speaks,
        public readonly array $values,
        public readonly ?array $window,
        public readonly array $errors,
        public readonly array $names,
        public readonly int $page,
        public readonly string $order,
    ) {
    }

    /**
     * Read a query string.
     *
     * A URL that names any report field SPEAKS for every field this page
     * offers: one that is absent is not filtering. That is what makes a link
     * mean the same report to whoever opens it, rather than the link's filters
     * laid over the reader's own. A URL that names none (the page opened from
     * the menu) does not speak, and the stored per-user default stands.
     *
     * The window is the exception: it has no "off" value, so a URL that names
     * filters but no range keeps the stored window.
     *
     * @param array<string, mixed> $query   typically $_GET
     * @param list<string> $offered         the URL names this page offers
     * @param list<string> $orderTokens     the sort keys the report accepts
     */
    public static function fromQuery(array $query, array $offered, array $orderTokens = []): self
    {
        $read = static function (string $name) use ($query): ?string {
            if (!array_key_exists($name, $query)) {
                return null;
            }
            $value = $query[$name];
            // An array (name[]=x) is not a value any control submits.
            return is_scalar($value) ? trim((string) $value) : "\0";
        };

        $namesInUrl = array_merge($offered, ['range', 'from', 'to']);
        foreach (self::NAMED_FIELDS as $named => $idField) {
            if (in_array($idField, $offered, true)) {
                $namesInUrl[] = $named;
            }
        }
        $speaks = false;
        foreach ($namesInUrl as $name) {
            if (array_key_exists($name, $query)) {
                $speaks = true;
                break;
            }
        }

        $values = [];
        $errors = [];
        $names = [];
        foreach ($offered as $field) {
            if (!isset(self::COLUMNS[$field])) {
                throw new \InvalidArgumentException("ReportFilterInput: '$field' is not a stored report filter");
            }
            $raw = $read($field);
            $namedAlias = array_search($field, self::NAMED_FIELDS, true);
            if (($raw === null || $raw === '') && $namedAlias !== false) {
                $typed = $read((string) $namedAlias);
                if ($typed === "\0") {
                    $errors[$field] = 'That is not a name.';
                } elseif ($typed !== null && $typed !== '') {
                    $names[(string) $namedAlias] = $typed;
                }
                $values[$field] = '';
                continue;
            }
            [$value, $error] = self::check($field, $raw ?? '');
            $values[$field] = $value;
            if ($error !== null) {
                $errors[$field] = $error;
            }
        }

        // A custom window, however it was asked for: both dates, and the
        // first no later than the second. One reader, so the two ways of
        // asking cannot check different things.
        $custom = static function () use ($read, &$errors): ?array {
            $from = self::parseDate((string) $read('from'));
            $to = self::parseDate((string) $read('to'));
            if ($from === null || $to === null) {
                $errors['range'] = 'Choose a start and an end date for a custom range.';
                return null;
            }
            if (sprintf('%04d%02d%02d', ...$from) > sprintf('%04d%02d%02d', ...$to)) {
                $errors['range'] = 'The start date is after the end date.';
                return null;
            }
            return ['range' => self::RANGE_CUSTOM, 'from' => $from, 'to' => $to];
        };

        $window = null;
        $range = $read('range');
        if ($range !== null) {
            if ($range === self::RANGE_CUSTOM) {
                $window = $custom();
            } elseif (in_array($range, self::RANGES, true)) {
                $window = ['range' => $range, 'from' => null, 'to' => null];
            } else {
                $errors['range'] = 'That is not one of the ranges on the list.';
            }
        } elseif ($read('from') !== null || $read('to') !== null) {
            // Dates with no range say "custom" without saying it; read them
            // as that rather than dropping a window someone typed.
            $window = $custom();
        }

        $page = 1;
        $rawPage = $read('page');
        if ($rawPage !== null && $rawPage !== '') {
            if (preg_match('/^[1-9]\d{0,6}$/', $rawPage) !== 1) {
                $errors['page'] = 'That is not a page number.';
            } else {
                $page = (int) $rawPage;
            }
        }

        $order = '';
        $rawOrder = $read('order');
        if ($rawOrder !== null && $rawOrder !== '') {
            if (!in_array($rawOrder, $orderTokens, true)) {
                $errors['order'] = 'That is not a column the report can be sorted by.';
            } else {
                $order = $rawOrder;
            }
        }

        return new self($speaks, $values, $window, $errors, $names, $page, $order);
    }

    /**
     * One field's value, checked. Returns the value to store and, when it is
     * refused, the sentence to show under the field.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function check(string $field, string $raw): array
    {
        if ($raw === "\0") {
            return ['', 'That is not a single value.'];
        }
        if (in_array($field, self::ID_FIELDS, true)) {
            if ($raw === '' || $raw === '0') {
                return ['', null];
            }
            if (preg_match('/^[1-9]\d{0,9}$/', $raw) !== 1) {
                return ['', 'That is not one of the choices on the list.'];
            }
            return [$raw, null];
        }
        if (isset(self::CHOICES[$field])) {
            [$allowed, $default] = self::CHOICES[$field];
            if ($raw === '') {
                return [$default, null];
            }
            if (!in_array($raw, $allowed, true)) {
                return [$default, 'That is not one of the choices on the list.'];
            }
            return [$raw, null];
        }
        if (isset(self::TEXT_FIELDS[$field])) {
            $width = self::TEXT_FIELDS[$field];
            if (mb_strlen($raw, 'UTF-8') > $width) {
                return ['', "Keep this under $width characters; the saved filter holds no more."];
            }
            if ($field === 'ip' && $raw !== '' && filter_var($raw, FILTER_VALIDATE_IP) === false) {
                return ['', "$raw is not an IP address."];
            }
            return [$raw, null];
        }
        throw new \InvalidArgumentException("ReportFilterInput: no rule for '$field'");
    }

    /**
     * A calendar date in either shape a report has ever submitted: ISO
     * `YYYY-MM-DD`, which `<input type="date">` sends whatever the reader's
     * locale shows, or the classic calendar's `mm/dd/yyyy` (and the two-digit
     * `mm/dd/yy` its preset buttons wrote). Anything else, or a day that does
     * not exist, is null — the caller says so rather than guessing.
     *
     * The one date reader for reports: p202_report_parse_date() (the classic
     * writer's name for it) delegates here. Spaces around the slashes are
     * accepted, as the classic reader's trim-and-explode did.
     *
     * A two-digit year means 20yy, as the classic path's mktime() read it
     * (0–69 → 2000–2069, 70–99 → 1970–1999).
     *
     * @return array{int, int, int}|null [year, month, day]
     */
    public static function parseDate(string $value): ?array
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/D', $value, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})\s*/\s*(\d{1,2})\s*/\s*(\d{2}|\d{4})$#D', $value, $m) === 1) {
            [$month, $day] = [(int) $m[1], (int) $m[2]];
            $year = (int) $m[3];
            if (strlen($m[3]) === 2) {
                $year += $year < 70 ? 2000 : 1900;
            }
        } else {
            return null;
        }
        if ($year < 1970 || !checkdate($month, $day, $year)) {
            return null;
        }
        return [$year, $month, $day];
    }
}
