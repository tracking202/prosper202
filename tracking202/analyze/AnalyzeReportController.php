<?php

declare(strict_types=1);

namespace Tracking202\Analyze;

use DataEngine;
use Tracking202\Report\Json\FlatReportPayloadBuilder;
use Tracking202\Report\Json\ReportDispatchRequest;
use Tracking202\Report\ReportFilterInput;
use Tracking202\Report\ReportPrefsStore;

/**
 * The Analyze report pages on the v2 shell: keywords, text ads, referers,
 * IPs, countries, regions, cities, ISPs, landing pages, devices, browsers,
 * platforms and custom variables. One controller and one template
 * (templates/report.php) for all thirteen; a page file only names its report.
 *
 * What a page does, in order:
 *
 *  1. Reads its filters from the URL (ReportFilterInput). A URL that names
 *     any of them is the whole report: that is what makes it a link someone
 *     can send.
 *  2. Stores them in the user's report preferences (ReportPrefsStore), which
 *     is where DataEngine, grab_timeframe() and every *_download.php read
 *     them — so the download next to the table exports what the table shows,
 *     and the classic pages that share the row carry the same filters. A URL
 *     that names none leaves the stored ones standing: the first visit shows
 *     the report the user last looked at (UI standard, rule 8).
 *  3. Runs the same DataEngine query the classic AJAX fragment ran, and
 *     renders it server-side in the component layer. There is no second
 *     request: the classic page loaded its table from
 *     tracking202/ajax/sort_<report>.php, or from report_dispatch.php through
 *     202-js/tracking-report.js; both jobs are this one render now.
 *
 * Nothing the URL asks for is applied if any of it is refused: a bad date or
 * a region that cannot be stored would otherwise leave half a request in
 * force, and the page says so in the refused field's own sentence.
 */
final class AnalyzeReportController
{
    /**
     * Report type (DataEngine's name) => how its page reads.
     *
     * @var array<string, array{title: string, heading: string, description: string, icon: string, download: string, feature: string, page: string}>
     */
    public const REPORTS = [
        'keyword' => ['title' => 'Analyze Your Keywords', 'heading' => 'Keywords', 'icon' => 'bi-key', 'download' => 'keywords_download.php', 'feature' => 'Keyword', 'page' => 'keywords.php',
            'description' => 'Clicks, leads and profit for each keyword your traffic sources passed.'],
        'textad' => ['title' => 'Analyze Your Text Advertisements', 'heading' => 'Text Ads', 'icon' => 'bi-card-text', 'download' => 'text_ads_download.php', 'feature' => 'Text ad', 'page' => 'text_ads.php',
            'description' => 'How each of your text ads performed, from click to profit.'],
        'referer' => ['title' => 'Analyze Incoming Referers', 'heading' => 'Referers', 'icon' => 'bi-box-arrow-in-right', 'download' => 'referers_download.php', 'feature' => 'Referer', 'page' => 'referers.php',
            'description' => 'The sites your visitors came from, and what their clicks earned.'],
        'ip' => ['title' => 'Analyze Incoming IP Addresses', 'heading' => 'IP Addresses', 'icon' => 'bi-hdd-network', 'download' => 'ips_download.php', 'feature' => 'IP', 'page' => 'ips.php',
            'description' => 'Clicks, leads and profit for each visitor IP address.'],
        'country' => ['title' => 'Analyze Incoming Countries', 'heading' => 'Countries', 'icon' => 'bi-globe2', 'download' => 'countries_download.php', 'feature' => 'Country', 'page' => 'countries.php',
            'description' => 'Where your visitors are, by country, and what their clicks earned.'],
        'region' => ['title' => 'Analyze Incoming Regions', 'heading' => 'Regions', 'icon' => 'bi-map', 'download' => 'regions_download.php', 'feature' => 'Region', 'page' => 'regions.php',
            'description' => 'Where your visitors are, by state or region, and what their clicks earned.'],
        'city' => ['title' => 'Analyze Incoming Cities', 'heading' => 'Cities', 'icon' => 'bi-geo-alt', 'download' => 'cities_download.php', 'feature' => 'City', 'page' => 'cities.php',
            'description' => 'Where your visitors are, by city, and what their clicks earned.'],
        'isp' => ['title' => 'Analyze Incoming ISP/Carrier', 'heading' => 'ISPs and Carriers', 'icon' => 'bi-broadcast', 'download' => 'isps_download.php', 'feature' => 'ISP/Carrier', 'page' => 'isp.php',
            'description' => 'The networks your visitors connect through, and what their clicks earned.'],
        'landingpage' => ['title' => 'Analyze Landing Pages', 'heading' => 'Landing Pages', 'icon' => 'bi-window', 'download' => 'landing_pages_download.php', 'feature' => 'Landing page', 'page' => 'landing_pages.php',
            'description' => 'How each landing page converted, direct links included.'],
        'device' => ['title' => 'Analyze Devices', 'heading' => 'Devices', 'icon' => 'bi-phone', 'download' => 'device_download.php', 'feature' => 'Device', 'page' => 'devices.php',
            'description' => 'The devices your visitors used, and what their clicks earned.'],
        'browser' => ['title' => 'Analyze Browsers', 'heading' => 'Browsers', 'icon' => 'bi-browser-chrome', 'download' => 'browser_download.php', 'feature' => 'Browser', 'page' => 'browsers.php',
            'description' => 'The browsers your visitors used, and what their clicks earned.'],
        'platform' => ['title' => 'Analyze Platforms', 'heading' => 'Platforms', 'icon' => 'bi-laptop', 'download' => 'platform_download.php', 'feature' => 'Platform', 'page' => 'platforms.php',
            'description' => 'The operating systems your visitors used, and what their clicks earned.'],
        'variable' => ['title' => 'Analyze Your Custom Variables', 'heading' => 'Custom Variables', 'icon' => 'bi-braces', 'download' => 'variables_download.php', 'feature' => 'Variable', 'page' => 'variables.php',
            'description' => 'Clicks, leads and profit for each value of the custom variables your traffic sources pass.'],
    ];

    /**
     * The filters these reports offer, in the bar's order. Everything the
     * classic calendar posted for them, except the subid box: set_user_prefs
     * never stored it, so it never filtered anything, and a field that does
     * nothing does not come across.
     */
    public const FILTERS = [
        'ppc_network_id', 'aff_campaign_id', 'user_pref_show',
        'ppc_account_id', 'aff_network_id', 'landing_page_id', 'text_ad_id', 'method_of_promotion',
        'country_id', 'region_id', 'isp_id', 'device_id', 'browser_id', 'platform_id',
        'ip', 'keyword', 'referer',
        'user_pref_limit', 'user_cpc_or_cpv',
    ];

    /**
     * What a publisher does not see, as the classic calendar hid it. The
     * calendar still posted them, empty, so every save cleared them; this
     * does the same, so a filter set before the account became a publisher
     * cannot go on narrowing a report that no longer shows it.
     */
    public const PUBLISHER_HIDDEN = [
        'ppc_network_id', 'aff_campaign_id', 'ppc_account_id', 'aff_network_id',
        'landing_page_id', 'text_ad_id', 'method_of_promotion',
    ];

    /** The metric columns, in the classic table's order, with their sort keys. */
    public const METRICS = [
        'clicks' => ['Clicks', 'clicks'],
        'clickOut' => ['Click throughs', 'click_throughs'],
        'ctr' => ['CTR', 'ctr'],
        'leads' => ['Leads', 'leads'],
        'avgSu' => ['Avg S/U', 'su_ratio'],
        'avgPayout' => ['Avg payout', 'payout'],
        'avgEpc' => ['Avg EPC', 'epc'],
        'avgCpc' => ['Avg CPC', 'cpc'],
        'income' => ['Income', 'income'],
        'cost' => ['Cost', 'cost'],
        'net' => ['Net', 'net'],
        'roi' => ['ROI', 'roi'],
    ];

    /** Without a sort in the URL, DataEngine orders by leads, most first. */
    public const DEFAULT_SORT = ['key' => 'leads', 'dir' => 'descending'];

    private string $type;
    private int $userId;
    private ReportPrefsStore $store;
    /** @var list<array{kind: string, text: string}> */
    private array $flashes = [];

    public function __construct(string $type)
    {
        if (!isset(self::REPORTS[$type])) {
            throw new \InvalidArgumentException("AnalyzeReportController: '$type' is not an Analyze report");
        }
        \AUTH::require_user();
        \AUTH::set_timezone($_SESSION['user_timezone']);
        require_once dirname(__DIR__, 2) . '/202-config/functions-report-prefs.php';
        global $db;

        $this->type = $type;
        $this->userId = (int) $_SESSION['user_id'];
        $this->store = new ReportPrefsStore($db);
    }

    public function handleRequest(): void
    {
        $publisher = !empty($_SESSION['publisher']);
        $offered = $publisher ? array_values(array_diff(self::FILTERS, self::PUBLISHER_HIDDEN)) : self::FILTERS;
        $orderTokens = ReportDispatchRequest::supportedOrderTokens();
        $input = ReportFilterInput::fromQuery($_GET, $offered, $orderTokens);

        $errors = $input->errors;
        $submitted = null;
        if ($input->speaks) {
            $values = $input->values;
            $errors += $this->resolveNames($input, $values);
            // A page or sort that cannot be read is dropped with a word; it
            // is not a filter, so it does not hold the filters back.
            $blocking = array_diff_key($errors, ['page' => true, 'order' => true]);
            if ($blocking === []) {
                if ($publisher) {
                    $values += array_fill_keys(self::PUBLISHER_HIDDEN, '');
                }
                $blocking = $this->store->save($this->userId, $values, $input->window);
                $errors += $blocking;
            }
            if ($blocking !== []) {
                $submitted = ['values' => $input->values, 'names' => $input->names, 'window' => $input->window];
                $this->flash('bad', 'Nothing you asked for was applied, because of the field marked below. The report still shows your previous filters.');
            }
        }
        if (isset($errors['page'])) {
            $this->flash('warn', 'That page number could not be read, so this is the first page.');
        }
        if (isset($errors['order'])) {
            $this->flash('warn', 'That sort could not be read, so the report is in its usual order: most leads first.');
        }

        $prefs = $this->store->load($this->userId);
        $values = ReportPrefsStore::valuesFromRow($prefs, $offered);
        $time = grab_timeframe();

        // What this page shows is its view: the rest of this request draws
        // it, and the download carries it, so a second tab writing the
        // stored filters meanwhile changes neither (ReportView).
        $view = p202_report_view_query($offered, $values, self::windowOf($time));
        p202_report_view_from_request([\Prosper202\DataEngine\ReportView::PARAM => $view], $this->userId);

        $page = isset($errors['page']) ? 1 : $input->page;
        $order = isset($errors['order']) ? '' : $input->order;

        $analyze = [
            'type' => $this->type,
            'report' => self::REPORTS[$this->type],
            'self' => rtrim(get_absolute_url(), '/') . '/tracking202/analyze/' . self::REPORTS[$this->type]['page'],
            'downloadUrl' => p202_report_view_url(rtrim(get_absolute_url(), '/') . '/tracking202/analyze/' . self::REPORTS[$this->type]['download'], $view),
            'offered' => $offered,
            'values' => $values,
            'time' => $time,
            'window' => self::windowOf($time),
            'page' => $page,
            'order' => $order,
            'errors' => $submitted === null ? [] : $errors,
            'submitted' => $submitted,
            'names' => $this->shownNames($offered, $submitted === null ? $values : $submitted['values'], $submitted['names'] ?? []),
            'lists' => $this->store->lists($this->userId),
            'suggestions' => [
                'region' => in_array('region_id', $offered, true) ? $this->store->suggestions('region', $this->dataUserId(), (int) $time['from'], (int) $time['to']) : [],
                'isp' => in_array('isp_id', $offered, true) ? $this->store->suggestions('isp', $this->dataUserId(), (int) $time['from'], (int) $time['to']) : [],
            ],
        ];
        $analyze += $this->runReport($time, $page, $order);
        $analyze['flashes'] = $this->flashes;

        require __DIR__ . '/templates/report.php';
    }

    /**
     * The stored window as the range picker shows it.
     *
     * @param array<string, mixed> $time  grab_timeframe()
     * @return array{range: string, from: string, to: string}
     */
    public static function windowOf(array $time): array
    {
        $range = (string) ($time['user_pref_time_predefined'] ?? '');
        if (!in_array($range, ReportFilterInput::RANGES, true)) {
            $range = ReportFilterInput::RANGE_CUSTOM;
        }
        $day = static fn (mixed $ts): string => (int) $ts > 0 ? date('Y-m-d', (int) $ts) : '';
        return ['range' => $range, 'from' => $day($time['from'] ?? 0), 'to' => $day($time['to'] ?? 0)];
    }

    /**
     * Turn typed region and ISP names into the ids the columns hold.
     *
     * @param array<string, string> $values  updated in place
     * @return array<string, string>  field => sentence, for names that did not resolve
     */
    private function resolveNames(ReportFilterInput $input, array &$values): array
    {
        if ($input->names === []) {
            return [];
        }
        $window = $input->window;
        if ($window !== null && $window['range'] === ReportFilterInput::RANGE_CUSTOM) {
            [$fy, $fm, $fd] = $window['from'];
            [$ty, $tm, $td] = $window['to'];
            $from = (int) mktime(0, 0, 0, $fm, $fd, $fy);
            $to = (int) mktime(23, 59, 59, $tm, $td, $ty);
        } else {
            // A preset, or the stored window: the suggestions came from the
            // window on screen, so resolve against that one.
            $time = grab_timeframe();
            $from = (int) $time['from'];
            $to = (int) $time['to'];
        }
        $errors = [];
        foreach ($input->names as $kind => $typed) {
            $field = ReportFilterInput::NAMED_FIELDS[$kind];
            $resolved = $this->store->resolveName($kind, $typed, $this->dataUserId(), $from, $to);
            if ($resolved['error'] !== null) {
                $errors[$field] = $resolved['error'];
            } else {
                $values[$field] = (string) $resolved['id'];
            }
        }
        return $errors;
    }

    /**
     * What the region and ISP fields show: the name typed, when the bar is
     * handing a refused request back; otherwise the name of the id in force
     * (or submitted), so the field reads as the menu it replaces would have.
     *
     * @param list<string> $offered
     * @param array<string, string> $values
     * @param array<string, string> $typed
     * @return array<string, string>
     */
    private function shownNames(array $offered, array $values, array $typed): array
    {
        $names = [];
        foreach (ReportFilterInput::NAMED_FIELDS as $kind => $field) {
            if (!in_array($field, $offered, true)) {
                $names[$kind] = '';
                continue;
            }
            $names[$kind] = $typed[$kind] ?? $this->store->nameOf($kind, (string) ($values[$field] ?? ''));
        }
        return $names;
    }

    /**
     * Whose clicks a report counts, as DataEngine decides it: an account that
     * may see every campaign sees every user's; anyone else sees their own.
     */
    private function dataUserId(): ?int
    {
        if (isset($_SESSION['publisher']) && $_SESSION['publisher'] == false) {
            return null;
        }
        return (int) ($_SESSION['user_own_id'] ?? 0);
    }

    /**
     * @param array<string, mixed> $time
     * @return array<string, mixed>
     */
    private function runReport(array $time, int $page, string $order): array
    {
        global $db;
        $prefsRow = $this->store->load($this->userId);
        $cpv = ($prefsRow['user_cpc_or_cpv'] ?? '') === 'cpv';

        // DataEngine reads the page and the sort from $_POST, the way the
        // classic fragment received them; this request is a GET, so they are
        // handed over here exactly as report_dispatch.php hands them over.
        $_POST = ['offset' => (string) ($page - 1)];
        if ($order !== '') {
            $_POST['order'] = $order;
        }

        try {
            $engine = new DataEngine();
            $data = $engine->getReportData(
                $this->type,
                $db->real_escape_string((string) $time['from']),
                $db->real_escape_string((string) $time['to']),
                $cpv
            );
            if (!is_array($data)) {
                throw new \RuntimeException('DataEngine returned no report for ' . $this->type);
            }
            if ($this->type === 'variable') {
                return ['result' => self::variableReport($data)];
            }
            $request = ReportDispatchRequest::fromArray([
                'reportType' => $this->type,
                'offset' => $page - 1,
                'order' => $order,
                'includeDependentFilters' => false,
            ]);
            return ['result' => FlatReportPayloadBuilder::build($this->type, $data, $engine->foundRows(), $request, $prefsRow)];
        } catch (\Throwable $e) {
            error_log('Analyze ' . $this->type . ' report failed: ' . $e->getMessage());
            $this->flash('bad', 'The report could not be read. Try again in a moment; if it keeps happening, the server log says why.');
            return ['result' => null];
        }
    }

    /**
     * The custom-variable report, flattened to what the table shows: one
     * group per traffic source and variable, its values, and the totals.
     *
     * DataEngine's shape for this report is the one DisplayData walked: the
     * totals first and last, and between them one entry per traffic source
     * keyed by its id, holding its variables keyed by variable id, each with
     * its values. The first totals entry is skipped exactly as DisplayData
     * skipped it — it is the same totals, and has no variables.
     *
     * @param array<int|string, mixed> $data
     * @return array{groups: list<array{source: string, variable: string, values: list<array<string, string>>}>, totals: array<string, string>|null}
     */
    public static function variableReport(array $data): array
    {
        $rows = array_values($data);
        $count = count($rows);
        $groups = [];
        $totals = null;
        foreach ($rows as $i => $row) {
            if ($i === $count - 1) {
                $totals = is_array($row) ? $row : null;
                break;
            }
            if (!is_array($row) || empty($row['variables']) || !is_array($row['variables'])) {
                continue;
            }
            foreach ($row['variables'] as $variable) {
                $groups[] = [
                    'source' => (string) ($row[0]['ppc_network_name'] ?? ''),
                    'variable' => (string) ($variable[0]['variable_name'] ?? ''),
                    'values' => array_values(array_filter((array) ($variable['values'] ?? []), 'is_array')),
                ];
            }
        }
        return ['groups' => $groups, 'totals' => $totals];
    }

    private function flash(string $kind, string $text): void
    {
        $this->flashes[] = ['kind' => $kind, 'text' => $text];
    }
}
