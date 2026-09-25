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
 * (functions-report-prefs.php says why and how), and hands the fragment,
 * its polls and the download the view it rendered, so a second tab writing
 * the row meanwhile does not change what this one draws (ReportView). What
 * is here:
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
const P202_OVERVIEW_GROUP_NONE = P202_REPORT_GROUP_NONE;
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
 *     view: string,
 * }
 *   view  the page's filters and window as a query string, for its
 *         fragment, poll and download to draw under (ReportView); '' when
 *         the filters were refused
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
        // The rest of this request draws what it just wrote, whatever
        // another tab writes meanwhile (ReportView).
        \Prosper202\DataEngine\ReportView::install($userId, $read['columns']);
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

    // What this page shows, for the requests it makes later: the report
    // fragment, the poll, the download. Each draws this view rather than
    // whatever the stored row says by then (ReportView).
    $view = '';
    if ($read['errors'] === []) {
        $filterNames = array_values(array_filter($names, static fn (string $n): bool => $n !== 'range'));
        $window = in_array('range', $names, true) ? ['range' => $range, 'from' => $from, 'to' => $to] : null;
        $view = p202_report_view_query($filterNames, $values, $window, $groups);
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
        'view' => $view,
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
 * Regions, ISPs, browsers and platforms are install-wide lookups that grow
 * with every click any account on the install receives — the classic
 * calendar's GROUP BY over them put every ISP on earth into one <select>.
 * Those four list what `$seen` says this account's clicks carried in the
 * report's window instead, busiest first and at most `limit` of them
 * (p202_overview_seen_list()), which is where Analyze's suggestions for the
 * same filters come from. Countries (a few hundred) and device types (four)
 * stay whole.
 *
 * @param list<string> $names
 * @param array{data_user_id: ?int, from: int, to: int, values?: array<string, string>, limit?: int}|null $seen
 *   whose clicks, in which window; `values` are the filters' current values,
 *   each kept in its list with its name even when the window has no click
 *   carrying it. Null lists nothing for the four (a test, or a page that
 *   offers none of them).
 * @return array<string, array<string|int, string|array<string|int, string>>>
 */
function p202_overview_filter_lists(\Prosper202\Database\Connection $conn, int $userId, array $names, ?array $seen = null): array
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
        // Install-wide and bounded, as the classic menus read them: one
        // entry per name.
        'country_id' => static fn () => $flat($rows('SELECT MIN(country_id), country_name FROM 202_locations_country GROUP BY country_name ORDER BY country_name', false)),
        'device_id' => static fn () => $flat($rows('SELECT type_id, type_name FROM 202_device_types ORDER BY type_name', false)),
    ];
    // Install-wide and unbounded: what this account's clicks carried.
    foreach (array_keys(P202_OVERVIEW_SEEN_LISTS) as $name) {
        $queries[$name] = static fn () => $seen === null ? [] : p202_overview_seen_list(
            $conn,
            $name,
            $seen['data_user_id'],
            $seen['from'],
            $seen['to'],
            (string) ($seen['values'][$name] ?? ''),
            $seen['limit'] ?? P202_OVERVIEW_SEEN_LIMIT
        );
    }

    $lists = [];
    foreach ($names as $name) {
        if (isset($queries[$name])) {
            $lists[$name] = $queries[$name]();
        }
    }
    return $lists;
}

/**
 * The filters whose lists are the values this account's clicks carried:
 * name => [the 202_dataengine column, the lookup it names (a parenthesised
 * join where it is two tables, so it nests under another JOIN on MySQL as
 * well as MariaDB), its id column, how an entry is labelled]. A region
 * carries its country code, because region names repeat across countries.
 */
const P202_OVERVIEW_SEEN_LISTS = [
    'region_id' => ['region_id', '(202_locations_region AS l LEFT JOIN 202_locations_country AS c ON (c.country_id = l.main_country_id))', 'l.region_id', "CONCAT(l.region_name, IF(c.country_code IS NULL OR c.country_code = '', '', CONCAT(' (', UPPER(c.country_code), ')')))"],
    'isp_id' => ['isp_id', '202_locations_isp AS l', 'l.isp_id', 'l.isp_name'],
    'browser_id' => ['browser_id', '202_browsers AS l', 'l.browser_id', 'l.browser_name'],
    'platform_id' => ['platform_id', '202_platforms AS l', 'l.platform_id', 'l.platform_name'],
];

/** How many entries a seen list holds at most: Analyze's suggestion cap. */
const P202_OVERVIEW_SEEN_LIMIT = 300;

/**
 * Whose clicks a report's lists are drawn from: every account's for a user
 * who sees every campaign, this account's otherwise — the rule DataEngine
 * scopes the report itself by (AnalyzeReportController::dataUserId()).
 */
function p202_overview_data_user_id(): ?int
{
    if (isset($_SESSION['publisher']) && $_SESSION['publisher'] == false) {
        return null;
    }
    return (int) ($_SESSION['user_own_id'] ?? 0);
}

/**
 * One filter's list, id => label: the ids of `$name` that the clicks in the
 * window carried, the busiest `$limit` of them, in label order. The id is the
 * one the clicks carry, which is the one the report's filter matches; the
 * classic menu listed the lowest id per name, which a click need not have.
 *
 * `$current`, when set and not among them, is added with its own label: the
 * filter in force is never shown as a bare number, and a window with no
 * clicks still shows what the report is filtered by.
 */
function p202_overview_seen_list(\Prosper202\Database\Connection $conn, string $name, ?int $dataUserId, int $from, int $to, string $current = '', int $limit = P202_OVERVIEW_SEEN_LIMIT): array
{
    if (!isset(P202_OVERVIEW_SEEN_LISTS[$name])) {
        throw new InvalidArgumentException("p202_overview_seen_list(): '$name' is not a filter listed from the clicks");
    }
    [$column, $lookup, $idColumn, $label] = P202_OVERVIEW_SEEN_LISTS[$name];
    $scope = $dataUserId === null ? 'd.user_id != 0' : 'd.user_id = ?';
    $sql = "SELECT d.$column AS id, MIN($label) AS label FROM 202_dataengine AS d"
        . " JOIN $lookup ON ($idColumn = d.$column)"
        . " WHERE $scope AND d.click_time >= ? AND d.click_time <= ?"
        . " GROUP BY d.$column ORDER BY SUM(d.clicks) DESC, d.$column LIMIT " . max(1, $limit);
    $params = $dataUserId === null ? [$from, $to] : [$dataUserId, $from, $to];
    $stmt = $conn->prepareRead($sql);
    $conn->bind($stmt, str_repeat('i', count($params)), $params);
    $list = [];
    foreach ($conn->fetchAll($stmt) as $row) {
        $list[(string) $row['id']] = (string) $row['label'];
    }

    if ($current !== '' && !isset($list[$current]) && preg_match('/^[1-9]\d{0,18}$/', $current) === 1) {
        $stmt = $conn->prepareRead("SELECT $label AS label FROM $lookup WHERE $idColumn = ?");
        $conn->bind($stmt, 'i', [(int) $current]);
        $row = $conn->fetchOne($stmt);
        if ($row !== null) {
            $list[$current] = (string) $row['label'];
        }
    }

    uasort($list, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
    return $list;
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
 *     download?: string,
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
 *   download     the page's download URL: a "Download to Excel" button that
 *                exports the page's view (ReportView)
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

    // A download exports this page's view, not whatever the stored filters
    // say by the time it is clicked.
    $aside = $page['aside'] ?? '';
    if (($page['download'] ?? '') !== '' && $errors === []) {
        $aside .= '<a class="btn btn-secondary btn-sm" href="' . $e(p202_report_view_url((string) $page['download'], (string) ($state['view'] ?? ''))) . '">'
            . '<i class="bi bi-file-earmark-spreadsheet"></i> Download to Excel</a>';
    }

    $bar = [
        'action' => $page['action'],
        'id' => $page['id'] . '-filters',
        'filters' => $filters,
        'reset' => $page['action'] . '?' . http_build_query($reset),
        'note' => $page['note'] ?? 'The filters you apply open by default here and on the other reports.',
        'aside' => $aside,
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
        $html .= '<div id="' . $e($page['id']) . '-report" data-p202-report="' . $e(p202_report_view_url($page['fragment'], (string) ($state['view'] ?? ''))) . '"'
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
        // The lists of what the clicks carried are drawn from the report's
        // own window (the page's view is installed, so this is it), or from
        // the last day for Spy, whose window that is.
        $time = ($page['range'] ?? true) ? grab_timeframe() : ['from' => time() - 86400, 'to' => time()];
        $page['lists'] = p202_overview_filter_lists($conn, $userId, $page['names'], [
            'data_user_id' => p202_overview_data_user_id(),
            'from' => (int) $time['from'],
            'to' => (int) $time['to'],
            'values' => $page['state']['values'],
        ]);
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
