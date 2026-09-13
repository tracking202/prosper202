<?php

declare(strict_types=1);

namespace Tracking202\Analyze;

use Api\V3\Controllers\AttributionAppsController;
use Api\V3\Controllers\AttributionPostbacksController;
use Api\V3\Attribution\SignatureState;
use Api\V3\Controllers\UsersController;
use Api\V3\Exception\ValidationException;
use Api\V3\HttpException;

/**
 * Analyze › Mobile Apps: what the SKAdNetwork and AdAttributionKit postbacks
 * add up to.
 *
 * Three readings of the same rows, as tabs:
 *
 *  - Report, the grouped totals, in whichever dimension answers the question
 *    (day, app, ad network, source, country, version, protocol, conversion
 *    type). This is /attribution/report, rendered.
 *  - Postbacks, the individual rows behind those totals, for the moment a
 *    total looks wrong and the question becomes "which ones".
 *  - Verify, a scratch pad for checking that a captured postback's signature
 *    is genuine. Nothing is stored; it exists so an operator can answer "is
 *    this real" without trusting the pipeline that would store it.
 *
 * Every read goes through the v3 controllers in-process, so this page and the
 * REST API answer with the same numbers and the same sentences.
 *
 * State lives in the query string rather than the session: a report someone
 * is looking at has a URL they can send to somebody else, and the back button
 * does what it looks like it does.
 */
class MobileAppsReportController
{
    /** Reading the report. Managing apps is a different permission. */
    private const VIEW_PERMISSION = 'view_attribution_reports';

    public const VIEWS = ['report', 'postbacks', 'verify'];

    /**
     * How the report can be grouped, in the order the pills are shown.
     *
     * Keys are the API's own group_by values; an unknown one from the query
     * string falls back to 'day' rather than erroring, because a mistyped URL
     * should show a report rather than a stack trace. The label is what the
     * first column is called once grouped that way.
     */
    public const GROUPINGS = [
        'day'             => 'Day',
        'app'             => 'App',
        'ad-network'      => 'Ad network',
        'source'          => 'Source',
        'country'         => 'Country',
        'protocol'        => 'Protocol',
        'version'         => 'Version',
        'conversion-type' => 'Type',
    ];

    /**
     * The response field each grouping's first column comes out of.
     *
     * One table, because the rendered table and the CSV both need it, and a
     * grouping whose key is spelled out in only one of them is a column that
     * reads correctly in one place and wrongly in the other. Day, app and
     * source are rendered specially (a formatted date, a name beside its id,
     * two fields joined) and appear here so the key sets can be compared:
     * tests/Analyze asserts this covers exactly GROUPINGS.
     */
    public const GROUP_KEYS = [
        'day'             => 'date',
        'app'             => 'app_id',
        'ad-network'      => 'ad_network_id',
        'source'          => 'source_identifier',
        'country'         => 'country_code',
        'protocol'        => 'protocol',
        'version'         => 'version',
        'conversion-type' => 'conversion_type',
    ];

    /**
     * The ranges the toolbar offers, under the names the rest of the app
     * already uses for them (the calendar in functions-tracking202.php), so
     * somebody moving between a click report and this one reads the same
     * words. Last 90 Days is the one addition: a conversion window runs to
     * 35 days and postbacks trickle in behind it, so the long look is a
     * question this report gets asked and the others do not.
     */
    public const RANGES = [
        'today'     => 'Today',
        'yesterday' => 'Yesterday',
        'last7'     => 'Last 7 Days',
        'last14'    => 'Last 14 Days',
        'last30'    => 'Last 30 Days',
        'last90'    => 'Last 90 Days',
        'thismonth' => 'This Month',
        'lastmonth' => 'Last Month',
    ];

    /**
     * Not a preset but the absence of one: the value that makes the from/to
     * inputs live. Named here because the template renders it and the
     * behaviour layer keys on it.
     */
    public const CUSTOM_RANGE = 'custom';

    private const DEFAULT_RANGE = 'last30';

    /** A day at a time is what someone opening a report wants first. */
    private const DEFAULT_GROUPING = 'day';

    /**
     * How many group rows one report may hold, and how many postbacks a page
     * of the Postbacks tab holds. The report says so when it hit the ceiling
     * (meta.groups_truncated); the list pages instead.
     */
    private const MAX_GROUPS = 200;
    private const PER_PAGE = 50;

    /**
     * A ceiling on the page number, so a hand-edited one cannot overflow
     * (page - 1) * PER_PAGE into a float — which the API answers with a 422
     * about a bad offset rather than with an empty page.
     */
    private const MAX_PAGE = 1000000;

    /**
     * How many apps the filter menu lists — the API's own ceiling, so the
     * menu is complete for any account that is not extraordinary.
     */
    private const MAX_APPS = 500;

    private int $userId;
    private AttributionPostbacksController $postbacks;
    private AttributionAppsController $apps;
    private UsersController $users;

    /** @var list<array{kind: string, text: string}> */
    private array $flashes = [];

    public function __construct()
    {
        \AUTH::require_user();
        global $db, $userObj;

        if (!$userObj->hasPermission(self::VIEW_PERMISSION)) {
            header('location: ' . get_absolute_url() . 'tracking202/');
            exit;
        }

        $this->userId = (int)$_SESSION['user_own_id'];
        $this->postbacks = new AttributionPostbacksController($db, $this->userId);
        $this->apps = new AttributionAppsController($db, $this->userId);
        $this->users = new UsersController($db);
    }

    public function handleRequest(): void
    {
        $view = (string)($_GET['view'] ?? 'report');
        if (!in_array($view, self::VIEWS, true)) {
            $view = 'report';
        }

        $mobileReport = [
            'view' => $view,
            'self' => rtrim(get_absolute_url(), '/') . '/tracking202/analyze/mobile_apps.php',
            'apps' => $this->listApps(),
            'filters' => $this->readFilters(),
            'groupings' => self::GROUPINGS,
            'ranges' => self::RANGES,
            'customRange' => self::CUSTOM_RANGE,
            // Revenue on this page is money; UsersController owns the account's
            // currency so this page and Setup > Mobile Apps cannot disagree.
            'currency' => $this->users->accountCurrency($this->userId),
        ];

        if ($view === 'report') {
            $mobileReport += $this->buildReport($mobileReport['filters']);
            if (($_GET['download'] ?? '') === 'csv') {
                // Only when the report is really there: a download built from
                // a failed read would be an empty file that reads as "no
                // postbacks" once it is open in a spreadsheet, with the error
                // left behind on a page nobody is looking at any more.
                if ($mobileReport['report'] !== null) {
                    $this->sendCsv($mobileReport);
                }
            }
        } elseif ($view === 'postbacks') {
            $mobileReport += $this->buildPostbacks($mobileReport['filters']);
        } else {
            $mobileReport += $this->buildVerify();
        }

        $mobileReport['flashes'] = $this->flashes;
        require __DIR__ . '/templates/mobile_apps.php';
    }

    // ─── Reading what was asked for ──────────────────────────────────

    /**
     * The filters, as the page understands them.
     *
     * Everything here is bounded or dropped before it reaches the API: a
     * query string is user input, and the API answers a bad value with a 422
     * that would replace the whole report with an error. For a report the
     * better behaviour is to show the report it can and say what it ignored,
     * which is what dropping an unusable filter does.
     *
     * @return array<string, mixed>
     */
    private function readFilters(): array
    {
        $window = self::resolveWindow(
            isset($_GET['range']) ? (string)$_GET['range'] : null,
            trim((string)($_GET['from'] ?? '')),
            trim((string)($_GET['to'] ?? '')),
            time()
        );
        foreach ($window['notes'] as $note) {
            $this->flash('warn', $note);
        }
        [$range, $timeFrom, $timeTo] = [$window['range'], $window['from'], $window['to']];

        $groupBy = (string)($_GET['group_by'] ?? self::DEFAULT_GROUPING);
        if (!isset(self::GROUPINGS[$groupBy])) {
            $groupBy = self::DEFAULT_GROUPING;
        }

        // Round-tripped rather than pattern-matched: '0012' and a number too
        // big for an integer both match /^\d+$/ and then reach the API as a
        // different app id than the one typed, or as a 422 that would replace
        // the whole report with an error message.
        $appId = trim((string)($_GET['app_id'] ?? ''));
        if ($appId !== '' && ($appId !== (string)(int)$appId || (int)$appId <= 0)) {
            $this->flash('warn', 'The app filter was ignored: an App Store id is a whole number.');
            $appId = '';
        }

        // The states the column really holds, from the enum that defines them,
        // so a state added there is not silently rejected here.
        $signature = trim((string)($_GET['signature'] ?? ''));
        if ($signature !== '' && !in_array($signature, SignatureState::values(), true)) {
            $this->flash('warn', 'The signature filter was ignored: it must be one of '
                . implode(', ', SignatureState::values()) . '.');
            $signature = '';
        }

        return [
            'range' => $range,
            // Shown in the date inputs, and they are UTC days by the same
            // argument the window is.
            'from' => gmdate('Y-m-d', $timeFrom),
            'to' => gmdate('Y-m-d', $timeTo),
            'time_from' => $timeFrom,
            'time_to' => $timeTo,
            'group_by' => $groupBy,
            'app_id' => $appId,
            'signature' => $signature,
        ];
    }

    /**
     * The window a set of query-string values means.
     *
     * Static, and given its own `now`, because everything it decides is a
     * question with a fixed answer at a fixed instant — which is the only way
     * "Last 30 Days" can be tested. It returns the notes it would like said
     * out loud rather than saying them, so the same function serves the page
     * and a test.
     *
     * Two rules live here:
     *
     *  - When the form sent a range, the range decides. The date inputs are
     *    always in the markup and always hold a window (the one being shown),
     *    so letting a non-empty date mean "custom" would make choosing a
     *    preset a no-op for anyone whose browser submitted those inputs. The
     *    form disables them off custom so they are not submitted at all; this
     *    is the same rule, stated where it is enforced. A hand-written URL
     *    carrying only dates is still read as custom.
     *  - Days are whole UTC days, because that is how the report groups them
     *    (FLOOR(received_at / 86400)). A window anchored to the viewer's
     *    midnight would put the first and last day's postbacks into day
     *    groups the report does not show.
     *
     * @param string|null $range the submitted range, or null when none was
     * @return array{range: string, from: int, to: int, notes: list<string>}
     */
    public static function resolveWindow(?string $range, string $from, string $to, int $now): array
    {
        $day = 86400;
        $today = (int)(floor($now / $day) * $day);
        $notes = [];

        if ($range === null) {
            $range = ($from !== '' || $to !== '') ? self::CUSTOM_RANGE : self::DEFAULT_RANGE;
        } elseif ($range !== self::CUSTOM_RANGE && !isset(self::RANGES[$range])) {
            $range = self::DEFAULT_RANGE;
        }

        if ($range !== self::CUSTOM_RANGE) {
            // Month 0 is December of the year before, which is what gmmktime()
            // does with it; verified rather than assumed.
            $monthStart = static fn (int $monthsBack): int => (int)gmmktime(
                0,
                0,
                0,
                (int)gmdate('n', $now) - $monthsBack,
                1,
                (int)gmdate('Y', $now)
            );
            [$start, $end] = match ($range) {
                'today' => [$today, $today + $day - 1],
                'yesterday' => [$today - $day, $today - 1],
                'last7' => [$today - 6 * $day, $today + $day - 1],
                'last14' => [$today - 13 * $day, $today + $day - 1],
                'last90' => [$today - 89 * $day, $today + $day - 1],
                'thismonth' => [$monthStart(0), $today + $day - 1],
                'lastmonth' => [$monthStart(1), $monthStart(0) - 1],
                // last30, and the value DEFAULT_RANGE names. An unknown value
                // cannot reach here — it was replaced above.
                default => [$today - 29 * $day, $today + $day - 1],
            };

            return ['range' => $range, 'from' => $start, 'to' => $end, 'notes' => $notes];
        }

        $start = self::parseUtcDay($from);
        $end = self::parseUtcDay($to);
        if (($from !== '' && $start === null) || ($to !== '' && $end === null)) {
            $notes[] = 'A date was not in YYYY-MM-DD form, so it was ignored.';
        }

        // A missing or unreadable end is today; a missing or unreadable start
        // is thirty days before the end, which is the default window.
        if ($end === null) {
            $end = $today;
        }
        if ($start === null) {
            $start = $end - 29 * $day;
        }
        // Swapped rather than refused: it is obvious what was meant, and an
        // error here costs the whole report.
        if ($start > $end) {
            [$start, $end] = [$end, $start];
            $notes[] = 'The dates were the wrong way round, so they were swapped.';
        }

        return ['range' => self::CUSTOM_RANGE, 'from' => $start, 'to' => $end + $day - 1, 'notes' => $notes];
    }

    /** Midnight UTC for a YYYY-MM-DD string, or null when it is not one. */
    private static function parseUtcDay(string $date): ?int
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return null;
        }
        $stamp = gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
        // gmmktime() rolls an impossible date forward: 2026-02-31 comes back
        // as 3 March, so a typo would be answered with a different month's
        // report and nothing said about it. The round trip is the proof the
        // date exists.
        if ($stamp === false || gmdate('Y-m-d', $stamp) !== $date) {
            return null;
        }

        return $stamp;
    }

    // ─── The three views ─────────────────────────────────────────────

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function buildReport(array $filters): array
    {
        $params = [
            'group_by' => $filters['group_by'],
            'time_from' => $filters['time_from'],
            'time_to' => $filters['time_to'],
            'limit' => self::MAX_GROUPS,
        ];
        if ($filters['app_id'] !== '') {
            $params['app_id'] = $filters['app_id'];
        }
        if ($filters['signature'] !== '') {
            $params['signature'] = $filters['signature'];
        }

        try {
            $answer = $this->postbacks->report($params);
        } catch (HttpException $e) {
            // The report is the page; without it there is nothing to show,
            // so say what happened rather than rendering an empty table that
            // reads as "no postbacks" (error pattern #11).
            $this->flash('bad', $e->getMessage());

            return ['report' => null, 'totals' => null, 'events' => []];
        }

        $groups = $answer['data']['groups'] ?? [];

        return [
            'report' => [
                'groups' => $groups,
                'truncated' => (bool)($answer['meta']['groups_truncated'] ?? false),
                'trusted' => (string)($answer['meta']['trusted'] ?? 'verified-only'),
            ],
            'totals' => $this->totals($groups),
            'events' => $this->eventTotals($groups),
        ];
    }

    /**
     * Column totals for the tiles and the table's totals row.
     *
     * Summed here rather than asked of the API because the API answers per
     * group; the same postback cannot appear in two groups of one report, so
     * a sum over groups is a sum over distinct postbacks.
     *
     * @param list<array<string, mixed>> $groups
     * @return array<string, float|int>
     */
    private function totals(array $groups): array
    {
        $counters = [
            'postbacks', 'installs', 'redownloads', 'reengagements', 'losses',
            'signature_valid_count', 'signature_invalid_count',
            'signature_unverified_count', 'signature_development_count',
        ];
        $totals = array_fill_keys($counters, 0) + ['revenue' => 0.0];

        foreach ($groups as $group) {
            foreach ($counters as $key) {
                $totals[$key] += (int)($group[$key] ?? 0);
            }
            // Revenue is not a group column: it is the sum of the group's
            // decoded events, the same way the table's revenue cell is.
            foreach ((array)($group['events'] ?? []) as $event) {
                $totals['revenue'] += (float)($event['revenue'] ?? 0);
            }
        }
        // Five places, which is what the revenue column stores.
        $totals['revenue'] = round($totals['revenue'], 5);

        return $totals;
    }

    /**
     * Decoded events across the whole report, biggest first.
     *
     * @param list<array<string, mixed>> $groups
     * @return list<array{name: string, count: int, revenue: float}>
     */
    private function eventTotals(array $groups): array
    {
        $events = [];
        foreach ($groups as $group) {
            foreach ((array)($group['events'] ?? []) as $name => $event) {
                $key = (string)$name;
                $events[$key] ??= ['name' => $key, 'count' => 0, 'revenue' => 0.0];
                $events[$key]['count'] += (int)($event['count'] ?? 0);
                $events[$key]['revenue'] += (float)($event['revenue'] ?? 0);
            }
        }
        usort($events, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_values($events);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function buildPostbacks(array $filters): array
    {
        $page = min(max(1, (int)($_GET['page'] ?? 1)), self::MAX_PAGE);

        $params = [
            'time_from' => $filters['time_from'],
            'time_to' => $filters['time_to'],
            'limit' => self::PER_PAGE,
            'offset' => ($page - 1) * self::PER_PAGE,
        ];
        if ($filters['app_id'] !== '') {
            $params['app_id'] = $filters['app_id'];
        }
        if ($filters['signature'] !== '') {
            $params['signature'] = $filters['signature'];
        }

        try {
            $answer = $this->postbacks->list($params);
        } catch (HttpException $e) {
            $this->flash('bad', $e->getMessage());

            // null, not [], so the template can say the rows could not be
            // read rather than that there are none.
            return ['postbacks' => null, 'pagination' => null];
        }

        $total = (int)($answer['pagination']['total'] ?? 0);

        return [
            'postbacks' => $answer['data'] ?? [],
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'pages' => max(1, (int)ceil($total / self::PER_PAGE)),
                'per_page' => self::PER_PAGE,
            ],
        ];
    }

    /**
     * The Verify tab: nothing until something is pasted.
     *
     * @return array<string, mixed>
     */
    private function buildVerify(): array
    {
        $payload = (string)($_POST['payload'] ?? '');
        $nothing = ['verify' => null, 'verifyPayload' => $payload, 'verifyVersion' => null];
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || trim($payload) === '') {
            return $nothing;
        }

        // Malformed JSON is told to the user, never coerced into an empty
        // object that would then be "verified" and answered with a confident
        // no (error pattern #4).
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            $this->flash('bad', 'That is not a JSON object: ' . json_last_error_msg() . '.');

            return $nothing;
        }

        try {
            $answer = $this->postbacks->verify($decoded);
        } catch (ValidationException | HttpException $e) {
            $this->flash('bad', $e->getMessage());

            return $nothing;
        }

        return [
            'verify' => $answer['data'] ?? $answer,
            'verifyPayload' => $payload,
            // The verifier answers about the signature, not about the
            // postback, so the version it could not check is only knowable
            // here — and it is the first thing to say when the answer is
            // that nothing could be checked.
            'verifyVersion' => is_scalar($decoded['version'] ?? null) ? (string)$decoded['version'] : null,
        ];
    }

    // ─── Bits the template needs ─────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function listApps(): array
    {
        try {
            $rows = $this->apps->list(['limit' => self::MAX_APPS])['data'] ?? [];
        } catch (HttpException) {
            return [];
        }
        usort($rows, static fn(array $a, array $b): int => strcasecmp(
            (string)($a['app_name'] ?? ''),
            (string)($b['app_name'] ?? '')
        ));

        return $rows;
    }

    /**
     * The report as a CSV, and then nothing else.
     *
     * Same numbers as the table above it, built from the same array, so the
     * two cannot drift. Every field goes through fputcsv rather than a join:
     * an app name or an ad-network id may contain a comma or a quote, and a
     * hand-rolled join turns one of those into a row with the wrong number of
     * columns.
     *
     * @param array<string, mixed> $mobileReport
     */
    private function sendCsv(array $mobileReport): void
    {
        $filters = $mobileReport['filters'];
        $label = self::GROUPINGS[$filters['group_by']] ?? 'Group';

        $rows = [[
            $label, 'Postbacks', 'Installs', 'Re-downloads', 'Re-engagements', 'Losses',
            'Revenue', 'Signature verified', 'Signature invalid', 'Signature unverifiable',
            'Signature development',
        ]];
        foreach ($mobileReport['report']['groups'] as $group) {
            $revenue = 0.0;
            foreach ((array)($group['events'] ?? []) as $event) {
                $revenue += (float)($event['revenue'] ?? 0);
            }
            $rows[] = [
                $this->csvGroupLabel($group, $filters['group_by']),
                (int)($group['postbacks'] ?? 0),
                (int)($group['installs'] ?? 0),
                (int)($group['redownloads'] ?? 0),
                (int)($group['reengagements'] ?? 0),
                (int)($group['losses'] ?? 0),
                // The bare number, not dollar_format's rendering: a
                // spreadsheet should get something it can add up.
                round($revenue, 5),
                (int)($group['signature_valid_count'] ?? 0),
                (int)($group['signature_invalid_count'] ?? 0),
                (int)($group['signature_unverified_count'] ?? 0),
                (int)($group['signature_development_count'] ?? 0),
            ];
        }

        // Built in memory first — MAX_GROUPS rows at the very most — because
        // once a Content-Disposition header is out, a write that fails leaves
        // a short file the reader has no way to know is short. Returning
        // instead renders the page with the message on it.
        $body = self::csvBody($rows);
        if ($body === null) {
            $this->flash('bad', 'The CSV could not be built, so nothing was downloaded.');

            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        // A filename reaches a header, so it must not be able to carry a
        // newline into one. Everything in it is generated above, but a header
        // is the wrong place to rely on that.
        $name = preg_replace(
            '/[^A-Za-z0-9._-]+/',
            '-',
            'mobile-apps-' . $filters['group_by'] . '-' . $filters['from'] . '-to-' . $filters['to'] . '.csv'
        ) ?? 'mobile-apps.csv';
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }

    /**
     * Rows as CSV text, or null if any part of the encoding failed.
     *
     * fputcsv is what quotes a field containing a comma, a quote or a newline;
     * a join would turn one app name into a row with the wrong number of
     * columns. Its return value is checked for the reason every return value
     * here is: a short write is indistinguishable from a short report.
     *
     * @param list<list<string|int|float>> $rows
     */
    private static function csvBody(array $rows): ?string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return null;
        }

        foreach ($rows as $row) {
            if (fputcsv($handle, $row) === false) {
                fclose($handle);

                return null;
            }
        }

        rewind($handle);
        $body = stream_get_contents($handle);
        fclose($handle);

        return $body === false ? null : $body;
    }

    /** The first column's value for one group, as plain text. */
    private function csvGroupLabel(array $group, string $groupBy): string
    {
        return match ($groupBy) {
            'app' => trim((string)($group['app_name'] ?? '')) !== ''
                ? $group['app_name'] . ' (' . (int)($group['app_id'] ?? 0) . ')'
                : (string)(int)($group['app_id'] ?? 0),
            // A source identifier of '0' is a real one, so the empties are
            // named rather than left to array_filter's falsiness.
            'source' => implode(' / ', array_filter([
                (string)($group['source_identifier'] ?? ''),
                ($group['campaign_id'] ?? null) === null ? '' : (string)$group['campaign_id'],
            ], static fn (string $part): bool => $part !== '')),
            default => (string)($group[self::GROUP_KEYS[$groupBy] ?? ''] ?? ''),
        };
    }

    private function flash(string $kind, string $text): void
    {
        $this->flashes[] = ['kind' => $kind, 'text' => $text];
    }
}
