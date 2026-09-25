<?php

declare(strict_types=1);

/**
 * The Overview, Visitors and Spy pages on the v2 shell.
 *
 * Each of those pages is the same shape: a page header, one GET filter form
 * (p202_report_filter_bar()), and a panel an AJAX fragment under
 * tracking202/ajax/ draws the report into. The fragments read the filters
 * from 202_users_pref, as every classic report does, so the page applies the
 * query string to that row before the fragment is asked for anything
 * (functions-report-prefs.php says why and how). What is here:
 *
 *   p202_overview_page_state()     read the stored filters, apply the URL's,
 *                                  and say what the form shows
 *   p202_overview_filter_lists()   the option lists the filter bar needs
 *   p202_overview_page()           render header, filters and report panel
 *   p202_overview_metrics_table()  the twelve-metric report table
 *   p202_overview_pagination()     a fragment's page links
 *   p202_overview_number()         a formatted figure back to a number, for
 *                                  sorting
 *
 * The markup is the kit's (202-account/ui-kit.php), parts included, and
 * tests/Api/V3/NoLegacyBootstrapClassesTest checks this file and every
 * fragment these pages load. The behaviour is 202-js/p202-overview.js's.
 */

require_once __DIR__ . '/functions-report-prefs.php';

/** The filters every click report applies, in the order the bar shows them. */
const P202_OVERVIEW_CLICK_FILTERS = [
    'ppc_network_id', 'aff_campaign_id', 'user_pref_show',
    'ppc_account_id', 'aff_network_id', 'landing_page_id', 'text_ad_id', 'method_of_promotion',
    'country_id', 'region_id', 'isp_id', 'device_id', 'browser_id', 'platform_id',
    'subid', 'ip', 'keyword', 'referer',
];

/**
 * The filters a publisher account does not see: the classic calendar hid the
 * traffic-source and offer sections from a publisher, whose reports are
 * scoped to their own clicks by the data engine instead.
 */
const P202_OVERVIEW_PUBLISHER_HIDDEN = [
    'ppc_network_id', 'ppc_account_id', 'aff_network_id', 'aff_campaign_id',
    'text_ad_id', 'landing_page_id', 'method_of_promotion',
];

/**
 * ReportBasicForm's "no grouping" and "traffic source" ids. Spelled out so a
 * page that offers no grouping need not load the 5,500-line report class
 * (whose file also lifts the request's time limit as it loads);
 * tests/Api/V3/OverviewPagesTest pins them to the class's constants.
 */
const P202_OVERVIEW_GROUP_NONE = '0';
const P202_OVERVIEW_GROUP_TRAFFIC_SOURCE = '1';

/**
 * What "Reset" puts back: the schema's own defaults for 202_users_pref.
 *
 * @return array<string, string>
 */
function p202_overview_defaults(): array
{
    return [
        'range' => 'today',
        'user_pref_show' => 'all',
        'user_cpc_or_cpv' => 'cpc',
        'user_pref_limit' => '50',
        'user_pref_breakdown' => 'day',
        'group_1' => P202_OVERVIEW_GROUP_TRAFFIC_SOURCE,
        'group_2' => P202_OVERVIEW_GROUP_NONE,
        'group_3' => P202_OVERVIEW_GROUP_NONE,
        'group_4' => P202_OVERVIEW_GROUP_NONE,
    ];
}

/**
 * The grouping ids Group Overview offers, id => label, in the classic order.
 *
 * @return array<string, string>
 */
function p202_overview_groupings(): array
{
    require_once __DIR__ . '/ReportSummaryForm.class.php';
    $options = [];
    foreach (ReportSummaryForm::getDetailArray() as $id) {
        $options[(string) $id] = (string) ReportBasicForm::translateDetailLevelById($id);
    }
    return $options;
}

/**
 * Read the stored filters, apply the query string's, and work out what the
 * form shows.
 *
 * A request that names none of the page's filters is a first visit: the
 * stored ones stand, as the per-user default (UI standard, rule 8). One that
 * names any is applied — whole, or not at all when a value is refused, in
 * which case the refusals come back and nothing is written. `defaults` fills
 * a stored value that is empty and would leave the report blank (Group
 * Overview with no grouping); that write is the app deciding, and the page
 * says so.
 *
 * The write happens on a GET and carries no session token, deliberately:
 * what it changes is which report the user sees next, the same columns the
 * classic calendar's tokenless POST to set_user_prefs.php writes, and the
 * link that changed them is on the address bar, where the filter bar shows
 * every value it applied. Nothing else in the row is reachable from here
 * (p202_report_prefs_save() refuses any other column).
 *
 * @param array{names: list<string>, defaults?: array<string, string>} $spec
 * @param array<string, mixed> $query
 * @return array{
 *     prefs: array<string, mixed>,
 *     values: array<string, string>,
 *     errors: array<string, string>,
 *     applied: bool,
 *     decided: list<string>,
 *     range: string,
 *     from: string,
 *     to: string,
 * }
 */
function p202_overview_page_state(\Prosper202\Database\Connection $conn, int $userId, array $spec, array $query): array
{
    $names = $spec['names'];
    $prefs = p202_report_prefs_load($conn, $userId);

    $groups = in_array('group_1', $names, true) ? array_keys(p202_overview_groupings()) : [];
    $asked = false;
    foreach ($names as $name) {
        if (array_key_exists($name, $query)) {
            $asked = true;
            break;
        }
    }

    $read = ['columns' => [], 'errors' => [], 'values' => []];
    if ($asked) {
        $read = p202_report_prefs_from_query($query, $names, $groups, P202_OVERVIEW_GROUP_NONE);
    }

    // The app's own defaults for a value that is empty and must not be.
    $decided = [];
    $fields = p202_report_pref_fields();
    foreach ($spec['defaults'] ?? [] as $name => $default) {
        $column = $fields[$name]['column'];
        $current = $read['columns'][$column] ?? ($prefs[$column] ?? null);
        if ($read['errors'] === [] && ($current === null || $current === '' || $current === '0')) {
            $read['columns'][$column] = $default;
            $decided[] = $name;
        }
    }

    if ($read['errors'] === [] && $read['columns'] !== []) {
        p202_report_prefs_save($conn, $userId, $read['columns']);
        $prefs = p202_report_prefs_load($conn, $userId);
    }

    $values = p202_report_prefs_values($prefs);
    if ($read['errors'] !== []) {
        // Refused: the form shows what was sent, so it can be corrected,
        // and the report is not drawn under a form it does not match.
        foreach ($read['values'] as $name => $value) {
            if ($name !== 'range' && $name !== 'from' && $name !== 'to') {
                $values[$name] = $value;
            }
        }
    }

    // The window, as the picker shows it. A preset shows the days it
    // resolves to; a refused custom window shows no dates rather than
    // handing the date inputs a value they cannot hold.
    $predefined = (string) ($prefs['user_pref_time_predefined'] ?? '');
    $range = $predefined === '' ? P202_RANGE_CUSTOM : $predefined;
    if ($range !== P202_RANGE_CUSTOM && !isset(p202_report_ranges()[$range])) {
        $range = P202_RANGE_CUSTOM;
    }
    $time = grab_timeframe();
    $from = date('Y-m-d', (int) $time['from']);
    $to = date('Y-m-d', (int) $time['to']);
    if (isset($read['errors']['range'])) {
        $sentRange = (string) ($read['values']['range'] ?? '');
        $range = isset(p202_report_ranges()[$sentRange]) ? $sentRange : P202_RANGE_CUSTOM;
        $sentFrom = (string) ($read['values']['from'] ?? '');
        $sentTo = (string) ($read['values']['to'] ?? '');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $sentFrom) === 1 ? $sentFrom : '';
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $sentTo) === 1 ? $sentTo : '';
    }

    return [
        'prefs' => $prefs,
        'values' => $values,
        'errors' => $read['errors'],
        'applied' => $asked && $read['errors'] === [],
        'decided' => $decided,
        'range' => $range,
        'from' => $from,
        'to' => $to,
    ];
}

/**
 * The option lists the filter bar needs for `$names`, value => label, or
 * group => [value => label] where the classic calendar had two dependent
 * menus (a campaign under its category, an account under its traffic
 * source): one menu with <optgroup>s says the same without a second request.
 *
 * Each list is the classic calendar's own query, scoped to the user and to
 * what is not deleted. A failed query throws (Connection's QueryException);
 * an empty menu would read as "you have none".
 *
 * @param list<string> $names
 * @return array<string, array<string|int, string|array<string|int, string>>>
 */
function p202_overview_filter_lists(\Prosper202\Database\Connection $conn, int $userId, array $names): array
{
    $rows = static function (string $sql, bool $scoped = true) use ($conn, $userId): array {
        $stmt = $conn->prepareRead($sql);
        if ($scoped) {
            $conn->bind($stmt, 'i', [$userId]);
        }
        return array_map('array_values', $conn->fetchAll($stmt));
    };
    $flat = static function (array $all): array {
        $list = [];
        foreach ($all as [$value, $label]) {
            $list[(string) $value] = (string) $label;
        }
        return $list;
    };
    $grouped = static function (array $all): array {
        $list = [];
        foreach ($all as [$value, $label, $group]) {
            $list[(string) $group][(string) $value] = (string) $label;
        }
        return $list;
    };

    $queries = [
        'ppc_network_id' => static fn () => [P202_REPORT_NO_TRAFFIC_SOURCE => '[No traffic source]'] + $flat($rows(
            'SELECT ppc_network_id, ppc_network_name FROM 202_ppc_networks WHERE user_id = ? AND ppc_network_deleted = 0 ORDER BY ppc_network_name'
        )),
        'ppc_account_id' => static fn () => $grouped($rows(
            'SELECT a.ppc_account_id, a.ppc_account_name, n.ppc_network_name FROM 202_ppc_accounts AS a'
            . ' JOIN 202_ppc_networks AS n ON (n.ppc_network_id = a.ppc_network_id)'
            . ' WHERE a.user_id = ? AND a.ppc_account_deleted = 0 AND n.ppc_network_deleted = 0'
            . ' ORDER BY n.ppc_network_name, a.ppc_account_name'
        )),
        'aff_network_id' => static fn () => $flat($rows(
            'SELECT aff_network_id, aff_network_name FROM 202_aff_networks WHERE user_id = ? AND aff_network_deleted = 0 ORDER BY aff_network_name'
        )),
        'aff_campaign_id' => static fn () => $grouped($rows(
            'SELECT c.aff_campaign_id, c.aff_campaign_name, n.aff_network_name FROM 202_aff_campaigns AS c'
            . ' JOIN 202_aff_networks AS n ON (n.aff_network_id = c.aff_network_id)'
            . ' WHERE c.user_id = ? AND c.aff_campaign_deleted = 0 AND n.aff_network_deleted = 0'
            . ' ORDER BY n.aff_network_name, c.aff_campaign_name'
        )),
        'landing_page_id' => static fn () => $flat($rows(
            'SELECT landing_page_id, landing_page_nickname FROM 202_landing_pages WHERE user_id = ? AND landing_page_deleted = 0 ORDER BY landing_page_nickname'
        )),
        'text_ad_id' => static fn () => $flat($rows(
            'SELECT text_ad_id, text_ad_name FROM 202_text_ads WHERE user_id = ? AND text_ad_deleted = 0 ORDER BY text_ad_name'
        )),
        // Install-wide lookups, as the classic menus read them: one entry
        // per name.
        'country_id' => static fn () => $flat($rows('SELECT MIN(country_id), country_name FROM 202_locations_country GROUP BY country_name ORDER BY country_name', false)),
        'region_id' => static fn () => $flat($rows('SELECT MIN(region_id), region_name FROM 202_locations_region GROUP BY region_name ORDER BY region_name', false)),
        'isp_id' => static fn () => $flat($rows('SELECT MIN(isp_id), isp_name FROM 202_locations_isp GROUP BY isp_name ORDER BY isp_name', false)),
        'device_id' => static fn () => $flat($rows('SELECT type_id, type_name FROM 202_device_types ORDER BY type_name', false)),
        'browser_id' => static fn () => $flat($rows('SELECT MIN(browser_id), browser_name FROM 202_browsers GROUP BY browser_name ORDER BY browser_name', false)),
        'platform_id' => static fn () => $flat($rows('SELECT MIN(platform_id), platform_name FROM 202_platforms GROUP BY platform_name ORDER BY platform_name', false)),
    ];

    $lists = [];
    foreach ($names as $name) {
        if (isset($queries[$name])) {
            $lists[$name] = $queries[$name]();
        }
    }
    return $lists;
}

/**
 * Render a report page's body: header, filters, and the panel its fragment
 * is drawn into. Called between template_top() and template_bottom().
 *
 * @param array{
 *     id: string,
 *     title: string,
 *     desc: string,
 *     icon: string,
 *     action: string,
 *     fragment: string,
 *     panel: string,
 *     names: list<string>,
 *     common?: list<string>,
 *     lists: array<string, mixed>,
 *     state: array<string, mixed>,
 *     base: string,
 *     range?: bool,
 *     note?: string,
 *     aside?: string,
 *     header_action?: string,
 *     spy?: bool,
 *     before_panel?: string,
 *     offset?: int,
 * } $page
 *   id           a short key, unique per page: the filter form's id prefix,
 *                the Advanced disclosure's remember key, the panel's id
 *   action       the page's own URL (the form's action, Reset's base)
 *   fragment     the AJAX fragment's URL
 *   names        the filters this page offers, in bar order; `range` is the
 *                window and is not listed here
 *   common       names shown in the first row though the catalog files them
 *                under Advanced (the grouping on a grouped page)
 *   header_action TRUSTED HTML for the page header's action slot
 *   before_panel TRUSTED HTML between the filters and the report panel
 */
function p202_overview_page(array $page): string
{
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $state = $page['state'];
    $values = $state['values'];
    $errors = $state['errors'];
    $names = $page['names'];
    $withRange = $page['range'] ?? true;

    // The classic reports' own names and specs for everything the catalog
    // knows; the groupings are this family's own.
    $catalogNames = array_values(array_filter($names, static fn (string $n): bool => !str_starts_with($n, 'group_')));
    $filters = p202_report_filters($values, $page['lists'], $catalogNames);
    $groupings = in_array('group_1', $names, true) ? p202_overview_groupings() : [];
    $none = P202_OVERVIEW_GROUP_NONE;
    $groupLabels = ['group_1' => 'Group by', 'group_2' => 'Then by', 'group_3' => 'Third grouping', 'group_4' => 'Fourth grouping'];
    foreach (['group_1', 'group_2', 'group_3', 'group_4'] as $index => $group) {
        if (!in_array($group, $names, true)) {
            continue;
        }
        $filters[] = [
            'name' => $group,
            'label' => $groupLabels[$group],
            'type' => 'select',
            'any' => null,
            'options' => $index === 0 ? $groupings : [$none => 'Nothing more'] + $groupings,
            'value' => (string) ($values[$group] ?? ''),
            'default' => $index === 0 ? '' : $none,
            'advanced' => $index >= 2,
        ];
    }
    $common = $page['common'] ?? [];
    foreach ($filters as $i => $filter) {
        if (in_array($filter['name'], $common, true)) {
            $filters[$i]['advanced'] = false;
        }
        if (isset($errors[$filter['name']])) {
            $filters[$i]['error'] = $errors[$filter['name']];
        }
    }
    // The first row is the common case; its order is the page's.
    usort($filters, static fn (array $a, array $b): int => array_search($a['name'], $names, true) <=> array_search($b['name'], $names, true));

    // Reset puts every filter this page offers back to its default, and
    // says so in the URL like any other set of filters.
    $defaults = p202_overview_defaults();
    $reset = [];
    if ($withRange) {
        $reset['range'] = $defaults['range'];
    }
    foreach ($names as $name) {
        $reset[$name] = $defaults[$name] ?? '';
    }

    $bar = [
        'action' => $page['action'],
        'id' => $page['id'] . '-filters',
        'filters' => $filters,
        'reset' => $page['action'] . '?' . http_build_query($reset),
        'note' => $page['note'] ?? 'The filters you apply open by default here and on the other reports.',
        'aside' => $page['aside'] ?? '',
        'remember' => 'overview-filters',
    ];
    if ($withRange) {
        $bar['range'] = [
            'range' => $state['range'],
            'from' => $state['from'],
            'to' => $state['to'],
            'error' => $errors['range'] ?? '',
        ];
    }

    $html = '<div class="p202-page-header">'
        . '<div class="p202-page-header__icon"><i class="bi ' . $e($page['icon']) . '"></i></div>'
        . '<div class="p202-page-header__text">'
        . '<h1 class="p202-page-header__title">' . $e($page['title']) . '</h1>'
        . '<p class="p202-page-header__desc">' . $e($page['desc']) . '</p>'
        . '</div>'
        . (($page['header_action'] ?? '') !== '' ? '<div class="p202-page-header__actions">' . $page['header_action'] . '</div>' : '')
        . '</div>';

    $html .= p202_report_filter_bar($bar);

    foreach ($state['decided'] as $name) {
        if (str_starts_with($name, 'group_')) {
            $label = $groupings[(string) ($values[$name] ?? '')] ?? '';
            $html .= '<p class="mb-3"><span class="p202-decided"><i class="bi bi-check2-circle"></i> Grouped by ' . $e($label)
                . ' until you choose another grouping. <a href="#' . $e($page['id'] . '-filters-' . $name) . '">change</a></span></p>';
        }
    }

    $html .= $page['before_panel'] ?? '';

    $html .= '<section class="p202-panel" aria-labelledby="' . $e($page['id']) . '-title">'
        . '<div class="p202-panel__head"><h2 class="p202-panel__title" id="' . $e($page['id']) . '-title">' . $e($page['panel']) . '</h2>'
        . (!empty($page['spy']) ? '<span class="p202-pill p202-pill--good" data-p202-spy-status>live · last 24 hours</span>' : '')
        . '</div>'
        . '<div class="p202-panel__body">';
    if ($errors !== []) {
        $html .= '<div class="p202-empty">'
            . '<i class="bi bi-funnel p202-empty__icon"></i>'
            . '<strong class="p202-empty__title">These filters were not applied</strong>'
            . '<div>Correct the field marked above and apply again. The report is not drawn under filters it does not match.</div>'
            . '</div>';
    } else {
        $html .= '<div id="' . $e($page['id']) . '-report" data-p202-report="' . $e($page['fragment']) . '"'
            . (!empty($page['spy']) ? ' data-p202-spy' : '')
            . ' data-p202-offset="' . (int) ($page['offset'] ?? 0) . '" aria-live="polite" aria-busy="true">'
            . '<span class="p202-skeleton mb-2" style="width: 40%;" aria-hidden="true"></span>'
            . '<span class="p202-skeleton mb-2" style="width: 85%;" aria-hidden="true"></span>'
            . '<span class="p202-skeleton" style="width: 70%;" aria-hidden="true"></span>'
            . '<span class="visually-hidden">Loading the report</span>'
            . '</div>';
    }
    $html .= '</div></section>';

    $html .= '<script src="' . $e(rtrim($page['base'], '/') . '/202-js/p202-overview.js') . '?v=' . $e(p202_overview_script_version()) . '" defer></script>';
    return $html;
}

/** A cache-busting version for p202-overview.js: its bytes, not a hand-kept number. */
function p202_overview_script_version(): string
{
    $hash = @md5_file(dirname(__DIR__) . '/202-js/p202-overview.js');
    return $hash === false ? '0' : substr($hash, 0, 12);
}

/**
 * A formatted figure as a number: "$1,304.00" is 1304, "($2.40)" is -2.4,
 * "8,233%" is 8233, "?" (a masked figure) is null.
 */
function p202_overview_number(string $formatted): ?float
{
    $text = trim(html_entity_decode(strip_tags($formatted), ENT_QUOTES, 'UTF-8'));
    $negative = str_starts_with($text, '(') && str_ends_with($text, ')');
    $digits = preg_replace('/[^0-9.\-]/', '', $text);
    if ($digits === null || $digits === '' || $digits === '-' || !is_numeric($digits)) {
        return null;
    }
    $number = (float) $digits;
    return $negative ? -abs($number) : $number;
}

/**
 * The twelve metrics every overview report shows, as p202_data_table()
 * columns and rows.
 *
 * `$data` is what DataEngine::getReportData() returns: the rows, then a
 * totals row whose keys carry a `total_` prefix. Its values are already
 * escaped by HtmlReportFormatter, so they are passed through as HTML. A user
 * without access to campaign data sees "?" for the volume and money figures,
 * as DisplayData renders them.
 *
 * @param list<array<string, string>>|array<int|string, array<string, string>> $data
 * @param array{label: string, key: callable(array<string, string>): string, sortable?: bool, masked?: bool, id?: string, caption?: string, empty?: array<string, string>, totals?: bool} $options
 *   key     the first cell's TRUSTED HTML for a row
 */
function p202_overview_metrics_table(array $data, array $options): string
{
    $rows = array_values($data);
    $totalsRow = null;
    if ($rows !== [] && ($options['totals'] ?? true)) {
        $totalsRow = array_pop($rows);
    }
    $masked = !empty($options['masked']);

    $metrics = [
        'clicks' => 'Clicks', 'click_out' => 'Click throughs', 'ctr' => 'CTR', 'leads' => 'Leads',
        'su_ratio' => 'Avg S/U', 'payout' => 'Avg payout', 'epc' => 'Avg EPC', 'cpc' => 'Avg CPC',
        'income' => 'Income', 'cost' => 'Cost', 'net' => 'Net', 'roi' => 'ROI',
    ];
    $hidden = ['clicks', 'click_out', 'leads', 'income', 'cost', 'net'];

    $columns = [['key' => 'label', 'label' => $options['label']]];
    foreach ($metrics as $key => $label) {
        $columns[] = ['key' => $key, 'label' => $label, 'num' => true];
    }

    $cell = static function (array $row, string $key, string $prefix) use ($masked, $hidden): array {
        if ($masked && in_array($key, $hidden, true)) {
            return ['text' => '?'];
        }
        $value = (string) ($row[$prefix . $key] ?? '');
        $html = $key === 'cost' && $value !== '' ? '(' . $value . ')' : $value;
        if ($key === 'net' || $key === 'roi') {
            $number = p202_overview_number($value) ?? 0.0;
            $tone = $number > 0 ? 'text-success' : ($number < 0 ? 'text-danger' : '');
            if ($tone !== '') {
                $html = '<span class="' . $tone . '">' . $html . '</span>';
            }
        }
        $sort = p202_overview_number($value);
        return ['html' => $html, 'sort' => $sort];
    };

    $tableRows = [];
    foreach ($rows as $row) {
        $out = ['label' => ['html' => ($options['key'])($row)]];
        foreach (array_keys($metrics) as $key) {
            $out[$key] = $cell($row, $key, '');
        }
        $tableRows[] = $out;
    }

    $totals = null;
    if ($totalsRow !== null && $tableRows !== []) {
        $totals = ['label' => 'Totals for report'];
        foreach (array_keys($metrics) as $key) {
            $totals[$key] = $cell($totalsRow, $key, 'total_');
        }
    }

    return p202_data_table($columns, $tableRows, array_filter([
        'id' => $options['id'] ?? '',
        'caption' => $options['caption'] ?? '',
        'sortable' => $options['sortable'] ?? true,
        'totals' => $totals,
        'empty' => $options['empty'] ?? null,
    ], static fn ($v): bool => $v !== null && $v !== ''));
}

/**
 * A fragment's page links, as the kit's pagination: the neighbouring ten
 * pages either side, and previous/next. Each link carries its zero-based
 * offset for p202-overview.js, which reloads the fragment; the href is the
 * same offset in the page's own query string, so a page can be linked to.
 */
function p202_overview_pagination(int $pages, int $offset, string $label = 'Report pages'): string
{
    if ($pages <= 1) {
        return '';
    }
    $offset = max(0, min($offset, $pages - 1));
    $item = static function (int $target, string $text, string $aria, bool $active = false, bool $disabled = false): string {
        $classes = 'page-item' . ($active ? ' active' : '') . ($disabled ? ' disabled' : '');
        if ($disabled) {
            return '<li class="' . $classes . '"><span class="page-link" aria-hidden="true">' . $text . '</span></li>';
        }
        return '<li class="' . $classes . '"' . ($active ? ' aria-current="page"' : '') . '>'
            . '<a class="page-link" href="?offset=' . $target . '" data-p202-offset="' . $target . '"' . ($aria !== '' ? ' aria-label="' . $aria . '"' : '') . '>' . $text . '</a></li>';
    };

    $html = '<nav class="mt-3" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"><ul class="pagination pagination-sm flex-wrap mb-0">';
    $html .= $item(max(0, $offset - 1), '&lsaquo;', 'Previous page', false, $offset === 0);
    for ($i = max(0, $offset - 10); $i < min($pages, $offset + 11); $i++) {
        $html .= $item($i, (string) ($i + 1), '', $i === $offset);
    }
    $html .= $item(min($pages - 1, $offset + 1), '&rsaquo;', 'Next page', false, $offset >= $pages - 1);
    return $html . '</ul></nav>';
}

/**
 * The empty state a click report shows when nothing matched: one sentence and
 * the first step, which for a report is a tracking link.
 *
 * @return array{icon: string, title: string, body: string, action: string, href: string}
 */
function p202_overview_empty(string $base, string $title = 'No clicks in this range'): array
{
    return [
        'icon' => 'bi-inbox',
        'title' => $title,
        'body' => 'Widen the date range or clear a filter above. If no clicks have arrived yet, send traffic through a tracking link.',
        'action' => 'Get tracking links',
        'href' => rtrim($base, '/') . '/tracking202/setup/get_trackers.php',
    ];
}

/**
 * Serve a report page: apply the URL's filters, then render the page on the
 * v2 shell. The page file says what it is; this does the rest, the same way
 * for every page in the family.
 *
 * A failure to read or write the filters is said on the page, in the shell,
 * rather than as a blank 500: the report cannot be drawn, and the reader
 * needs to know it was not an empty one.
 *
 * @param array<string, mixed> $page  as p202_overview_page(), without
 *   `state`, `lists`, `base` and `offset`, plus `shell` (the template_top()
 *   options, which must be the v2 shell's), `defaults` for
 *   p202_overview_page_state() and `page_title` for <title>
 */
function p202_overview_run(array $page): void
{
    // The page names its shell, so a grep for the v2 opt-in finds every page
    // of this family, and NoLegacyBootstrapClassesTest selects it.
    if (($page['shell'] ?? null) !== ['ui' => 'v2']) {
        throw new InvalidArgumentException("p202_overview_run(): a page of this family passes 'shell' => ['ui' => 'v2']");
    }
    $base = get_absolute_url();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $conn = new \Prosper202\Database\Connection(DB::getInstance()->getConnection());

    // A publisher's reports are scoped to their own clicks; the classic
    // calendar hid the traffic-source and offer filters from them.
    if (!empty($_SESSION['publisher'])) {
        $page['names'] = array_values(array_diff($page['names'], P202_OVERVIEW_PUBLISHER_HIDDEN));
    }
    $stateNames = $page['names'];
    if ($page['range'] ?? true) {
        array_unshift($stateNames, 'range');
    }

    try {
        $page['state'] = p202_overview_page_state($conn, $userId, ['names' => $stateNames, 'defaults' => $page['defaults'] ?? []], $_GET);
        $page['lists'] = p202_overview_filter_lists($conn, $userId, $page['names']);
    } catch (RuntimeException $error) {
        error_log('Report page ' . (string) ($page['id'] ?? '?') . ': ' . $error->getMessage());
        template_top((string) ($page['page_title'] ?? $page['title']), $page['shell']);
        echo p202_flash('bad', 'The report filters could not be read or saved, so this report cannot be drawn. Reload the page; if it keeps happening, the database is refusing the request and the server log says why.');
        template_bottom();
        return;
    }

    $page['base'] = $base;
    $page['offset'] = max(0, (int) ($_GET['offset'] ?? 0));
    template_top((string) ($page['page_title'] ?? $page['title']), $page['shell']);
    echo p202_overview_page($page);
    template_bottom();
}
