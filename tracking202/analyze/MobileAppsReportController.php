<?php

declare(strict_types=1);

namespace Tracking202\Analyze;

use Api\V3\Controllers\AttributionAppsController;
use Api\V3\Controllers\AttributionPostbacksController;
use Api\V3\Attribution\SignatureState;
use Api\V3\Controllers\UsersController;
use Api\V3\Exception\ValidationException;
use Api\V3\HttpException;
use Tracking202\Attribution\RegisteredApps;

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
     * Asked of the API rather than restated here. It is one list — the SQL
     * that names the key and the two renderers that read it — and a local
     * copy would go on resolving to '' (an empty first column, in both the
     * table and the CSV, with nothing erroring) after a rename upstream.
     *
     * @return array<string, string>
     */
    public static function groupKeys(): array
    {
        return AttributionPostbacksController::groupKeys();
    }

    /**
     * The ranges the toolbar offers, under the names the rest of the app
     * already uses for them, so somebody moving between a click report and
     * this one reads the same words. Last 90 Days is the one addition: a
     * conversion window runs to 35 days and postbacks trickle in behind it,
     * so the long look is a question this report gets asked and the others
     * are not.
     *
     * The windows match too, which is the part that was wrong when this
     * shipped: grab_timeframe()'s 'last7' is `-7 days 00:00:00` through
     * today, i.e. EIGHT whole days, and 'This Month' runs to the last day of
     * the month. Naming the presets after the calendar's while resolving
     * them a day shorter gave one label two meanings, and an operator
     * reconciling clicks against installs would have read the gap as missing
     * postbacks. tests/Analyze executes both and fails if they diverge.
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
     * The "last N days" presets, as the calendar counts them: N days back
     * from midnight, through the end of today, so the window spans N + 1
     * whole days. One table rather than an arm each — four arms of the same
     * expression re-derived the offset four times, and the arm that also
     * served "anything unknown" meant a preset added without one reported
     * the default window under its own name.
     */
    private const RANGE_DAYS = [
        'last7'  => 7,
        'last14' => 14,
        'last30' => 30,
        'last90' => 90,
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
     * Stands in for the account currency on the views that render no money
     * and so never read it. How many apps the filter menu lists is
     * RegisteredApps::MAX now, shared with Setup so the two pages cannot
     * disagree about which apps exist.
     */
    private const DEFAULT_CURRENCY_FALLBACK = UsersController::DEFAULT_CURRENCY;

    /** Set when readFilters() could not use a filter the URL carried. */
    private bool $filterDropped = false;

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

        // Verify renders neither the app menu nor an amount, so it pays for
        // neither: listApps() is a 500-row read and a sort, and the currency
        // is another statement. Filters are read first either way, because
        // everything below reports on them.
        $needsApps = $view !== 'verify';
        $mobileReport = [
            'view' => $view,
            'self' => rtrim(get_absolute_url(), '/') . '/tracking202/analyze/mobile_apps.php',
            'filters' => $this->readFilters(),
            'apps' => $needsApps ? $this->listApps() : [],
            'groupings' => self::GROUPINGS,
            'groupKeys' => self::groupKeys(),
            'ranges' => self::RANGES,
            'customRange' => self::CUSTOM_RANGE,
            // Revenue on this page is money; UsersController owns the account's
            // currency so this page and Setup > Mobile Apps cannot disagree.
            'currency' => $needsApps ? $this->users->accountCurrency($this->userId) : self::DEFAULT_CURRENCY_FALLBACK,
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
        [$range, $timeFrom, $timeTo] = [$window['range'], $window['from'], $window['to']];

        // Unknown values are replaced, and SAID — the two filters below
        // already did, and a range or grouping silently swapped for the
        // default answers a question nobody asked while looking exactly like
        // the answer to the one they did (error pattern #4).
        foreach ($window['notes'] as $note) {
            $this->flashFilterDropped($note);
        }

        $groupBy = (string)($_GET['group_by'] ?? self::DEFAULT_GROUPING);
        if (!isset(self::GROUPINGS[$groupBy])) {
            if ($groupBy !== self::DEFAULT_GROUPING) {
                $this->flashFilterDropped('That grouping is not one this report offers, so it is grouped by '
                    . self::GROUPINGS[self::DEFAULT_GROUPING] . '.');
            }
            $groupBy = self::DEFAULT_GROUPING;
        }

        // Round-tripped rather than pattern-matched: '0012' and a number too
        // big for an integer both match /^\d+$/ and then reach the API as a
        // different app id than the one typed, or as a 422 that would replace
        // the whole report with an error message.
        $appId = trim((string)($_GET['app_id'] ?? ''));
        if ($appId !== '' && ($appId !== (string)(int)$appId || (int)$appId <= 0)) {
            $this->flashFilterDropped('The app filter was ignored: an App Store id is a whole number.');
            $appId = '';
        }

        // The states the column really holds, from the enum that defines
        // them, so a state added there is not silently rejected here — and
        // folded the way the API folds it (strtolower + trim, see
        // buildFilters), because a URL copied out of the documentation or a
        // support ticket must not mean one thing to the REST caller and
        // another to this page.
        $signature = strtolower(trim((string)($_GET['signature'] ?? '')));
        if ($signature !== '' && !in_array($signature, SignatureState::values(), true)) {
            $this->flashFilterDropped('The signature filter was ignored: it must be one of '
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
        $endOfToday = $today + $day - 1;
        $notes = [];

        if ($range === null) {
            $range = ($from !== '' || $to !== '') ? self::CUSTOM_RANGE : self::DEFAULT_RANGE;
        } elseif ($range !== self::CUSTOM_RANGE && !isset(self::RANGES[$range])) {
            if ($range !== '') {
                $notes[] = 'That range is not one this report offers, so it shows '
                    . self::RANGES[self::DEFAULT_RANGE] . '.';
            }
            $range = self::DEFAULT_RANGE;
        }

        if ($range !== self::CUSTOM_RANGE) {
            if (isset(self::RANGE_DAYS[$range])) {
                return [
                    'range' => $range,
                    'from' => $today - self::RANGE_DAYS[$range] * $day,
                    'to' => $endOfToday,
                    'notes' => $notes,
                ];
            }

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
            // No default arm: every name in RANGES is answered here or in
            // RANGE_DAYS above, and a preset added to one without the other
            // must fail loudly rather than quietly report the default window
            // under its own name. readFilters() has already replaced anything
            // that is not in RANGES, so this cannot be reached from a request.
            [$start, $end] = match ($range) {
                'today' => [$today, $endOfToday],
                'yesterday' => [$today - $day, $today - 1],
                // To the end of the month, as the click calendar's own
                // "This Month" does. Days with no postbacks cost nothing.
                'thismonth' => [$monthStart(0), $monthStart(-1) - 1],
                'lastmonth' => [$monthStart(1), $monthStart(0) - 1],
            };

            return ['range' => $range, 'from' => $start, 'to' => $end, 'notes' => $notes];
        }

        // A date that is present and unreadable is refused, not quietly
        // replaced: only an ABSENT one falls back. The two cases produced the
        // same window and the same sentence before, so a typo was answered
        // with a different month's report under a message about formatting.
        [$start, $startWhy] = self::parseUtcDay($from);
        [$end, $endWhy] = self::parseUtcDay($to);
        foreach ([[$from, $startWhy], [$to, $endWhy]] as [$typed, $why]) {
            if ($typed !== '' && $why !== null) {
                $notes[] = $why;
            }
        }

        // A missing end is today; a missing start is the default window
        // before the end.
        if ($end === null) {
            $end = $today;
        }
        if ($start === null) {
            $start = $end - self::RANGE_DAYS[self::DEFAULT_RANGE] * $day;
        }
        // Swapped rather than refused: it is obvious what was meant, and an
        // error here costs the whole report.
        if ($start > $end) {
            [$start, $end] = [$end, $start];
            $notes[] = 'The dates were the wrong way round, so they were swapped.';
        }

        return ['range' => self::CUSTOM_RANGE, 'from' => $start, 'to' => $end + $day - 1, 'notes' => $notes];
    }

    /**
     * Midnight UTC for a YYYY-MM-DD string, with the reason when it is not
     * one — the two ways to fail need different sentences, and answering a
     * date that does not exist with a complaint about formatting sends the
     * reader hunting a problem they do not have.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private static function parseUtcDay(string $date): array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return [null, 'A date was not in YYYY-MM-DD form, so it was ignored.'];
        }
        $stamp = gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
        // gmmktime() rolls an impossible date forward: 2026-02-31 comes back
        // as 3 March, so a typo would be answered with a different month's
        // report and nothing said about it. The round trip is the proof the
        // date exists.
        if ($stamp === false || gmdate('Y-m-d', $stamp) !== $date) {
            return [null, 'There is no such date as ' . $date . ', so it was ignored.'];
        }

        return [$stamp, null];
    }

    // ─── The three views ─────────────────────────────────────────────

    /**
     * The filters both reads send on, so a filter wired into one of them and
     * not the other cannot become a control that narrows the Report tab and
     * is ignored by the Postbacks tab.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function apiFilters(array $filters): array
    {
        $params = [];
        foreach (['app_id', 'signature'] as $key) {
            if ($filters[$key] !== '') {
                $params[$key] = $filters[$key];
            }
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function buildReport(array $filters): array
    {
        $params = $this->apiFilters($filters) + [
            'group_by' => $filters['group_by'],
            'time_from' => $filters['time_from'],
            'time_to' => $filters['time_to'],
            'limit' => self::MAX_GROUPS,
        ];

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
        $truncated = (bool)($answer['meta']['groups_truncated'] ?? false);

        // The API's ungrouped totals, never a sum over the groups: a sum is
        // wrong by a replayed postback that landed in two of them, and wrong
        // by every group the truncation dropped.
        $totals = $answer['data']['totals'] ?? null;
        if (!is_array($totals)) {
            $this->flash(
                'bad',
                'The report came back without its totals, so the figures above the table are not shown.'
            );
            $totals = null;
        } else {
            // Revenue is the one metric that is not a distinct count: the
            // decode picks one copy per postback across the whole window, so
            // a group holds its own decoded events and summing them is
            // summing distinct postbacks. It is still only the groups that
            // survived, which is why a truncated report says so.
            $totals['revenue'] = round(array_sum(array_map(
                static fn (array $group): float => self::groupRevenue($group),
                $groups
            )), 5);
        }

        return [
            'report' => [
                'groups' => $groups,
                'truncated' => $truncated,
                'trusted' => (string)($answer['meta']['trusted'] ?? 'verified-only'),
                'notes' => (string)($answer['meta']['notes'] ?? ''),
            ],
            'totals' => $totals,
            'events' => $this->eventTotals($groups),
        ];
    }

    /**
     * One group's decoded revenue.
     *
     * The API publishes it per group as decoded_revenue; the events are the
     * fallback for a response that predates it, and are what the per-event
     * panel adds up anyway. Written once because the tile, the table cell and
     * the CSV column all need it and three copies could round differently.
     *
     * @param array<string, mixed> $group
     */
    public static function groupRevenue(array $group): float
    {
        if (isset($group['decoded_revenue']) && is_numeric($group['decoded_revenue'])) {
            return (float)$group['decoded_revenue'];
        }

        $revenue = 0.0;
        foreach ((array)($group['events'] ?? []) as $event) {
            $revenue += (float)((array)$event)['revenue'] ?? 0;
        }

        return $revenue;
    }

    /**
     * Decoded events across the groups the report kept, biggest first.
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
        // MAX_PAGE only stops (page - 1) * PER_PAGE overflowing into a float.
        // The real ceiling is the last page there is, and it is not known
        // until the count comes back — so ask, clamp, and ask again only if
        // the clamp moved. Without it a hand-edited ?page=1000000 becomes
        // OFFSET 49999950, which the server walks row by row to return
        // nothing, and the empty answer renders as "No postbacks in this
        // range" — a sentence about the range that is false.
        $asked = min(max(1, (int)($_GET['page'] ?? 1)), self::MAX_PAGE);
        $base = $this->apiFilters($filters) + [
            'time_from' => $filters['time_from'],
            'time_to' => $filters['time_to'],
            'limit' => self::PER_PAGE,
        ];

        try {
            $answer = $this->postbacks->list($base + ['offset' => ($asked - 1) * self::PER_PAGE]);
            $total = (int)($answer['pagination']['total'] ?? 0);
            $pages = max(1, (int)ceil($total / self::PER_PAGE));
            $page = min($asked, $pages);
            if ($page !== $asked) {
                $this->flash('warn', 'There is no page ' . $asked . ' of these postbacks, so this is the last one.');
                $answer = $this->postbacks->list($base + ['offset' => ($page - 1) * self::PER_PAGE]);
            }
        } catch (HttpException $e) {
            $this->flash('bad', $e->getMessage());

            // null, not [], so the template can say the rows could not be
            // read rather than that there are none.
            return ['postbacks' => null, 'pagination' => null];
        }

        return [
            'postbacks' => $answer['data'] ?? [],
            'pagination' => [
                // Rows, not distinct postbacks: list() counts what it returns,
                // while the Report tab counts one per replayed postback. The
                // template says "rows" so the two tabs cannot look like they
                // disagree about the same number.
                'rows' => $total,
                'page' => $page,
                'pages' => $pages,
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
        // no (error pattern #4). Two ways to not be an object, two sentences:
        // json_last_error_msg() reads "No error" for `null`, `123` or a bare
        // string, because those parse fine — it is only the truth on the
        // branch where the parse actually failed.
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            $this->flash('bad', json_last_error() === JSON_ERROR_NONE
                ? 'That is valid JSON but not an object; paste the whole postback, braces included.'
                : 'That is not JSON: ' . json_last_error_msg() . '.');

            return $nothing;
        }

        try {
            $answer = $this->postbacks->verify($decoded);
        } catch (ValidationException $e) {
            // The API's per-field sentences say WHICH field and why; the
            // message alone is "Invalid postback", which tells an operator
            // nothing they can act on. The sibling setup page shows them too.
            $this->flash('bad', trim($e->getMessage() . ' ' . implode(' ', array_map(
                static fn (string $field, string $why): string => $field . ': ' . $why,
                array_keys($e->getFieldErrors()),
                array_values(array_map('strval', $e->getFieldErrors()))
            ))));

            return $nothing;
        } catch (HttpException $e) {
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

    /**
     * The registered apps, for the filter menu and the Postbacks tab's app
     * column.
     *
     * RegisteredApps carries the ceiling and the ordering, so this menu and
     * Setup's panel cannot disagree about which apps exist — a filter missing
     * an app the other page lists reads as "that app is not registered".
     *
     * A failed read is said out loud, for the reason buildReport() says its
     * own (error pattern #11): an empty menu is indistinguishable from an
     * account with no apps, and if a filter IS applied the menu then shows
     * "All apps" over a report that is still narrowed to one.
     *
     * @return list<array<string, mixed>>
     */
    private function listApps(): array
    {
        try {
            $registered = RegisteredApps::read($this->apps);
        } catch (HttpException $e) {
            $this->flash('bad', 'The list of registered apps could not be read, so the App filter is empty: '
                . $e->getMessage());

            return [];
        }

        if ($registered['truncated']) {
            // A menu cannot offer what it does not list, so an app past the
            // ceiling reads as unregistered rather than unlisted. The filter
            // has no free-text entry, but app_id in the address does work —
            // the template renders an option for a value it was not given,
            // labelled "not in your list" — so name the escape hatch that
            // exists rather than a control that does not.
            $of = $registered['total'] === null ? '' : ' of ' . $registered['total'];
            $this->flash('warn', 'The App filter lists the first ' . count($registered['apps']) . $of
                . ' registered apps, by name. To report on one that is not listed,'
                . " add app_id=<App Store id> to this page's address.");
        }

        return $registered['apps'];
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

        // A download cannot carry a flash, so a report built from filters the
        // page could not use would cover a wider set of rows than the URL
        // asked for, look complete, and say nothing. Refuse that, and let the
        // page render with the reasons on it; the link is still there once
        // they are read.
        //
        // Only a DROPPED FILTER, not any flash: the App-filter truncation
        // notice is about the menu, not about this report, and gating on the
        // whole list meant an account with more than 500 registered apps
        // could never download a CSV that was in fact exact.
        if ($this->filterDropped) {
            $this->flash('bad', 'Nothing was downloaded: the report is not the one the link asked for. '
                . 'Check the messages above, then use Download to CSV again.');

            return;
        }

        $rows = [[
            $label, 'Postbacks', 'Installs', 'Re-downloads', 'Re-engagements', 'Losses',
            'Revenue', 'Signature verified', 'Signature invalid', 'Signature unverifiable',
            'Signature development',
        ]];
        foreach ($mobileReport['report']['groups'] as $group) {
            $rows[] = [
                $this->csvGroupLabel($group, $filters['group_by']),
                (int)($group['postbacks'] ?? 0),
                (int)($group['installs'] ?? 0),
                (int)($group['redownloads'] ?? 0),
                (int)($group['reengagements'] ?? 0),
                (int)($group['losses'] ?? 0),
                // The bare number, not dollar_format's rendering: a
                // spreadsheet should get something it can add up.
                round(self::groupRevenue($group), 5),
                (int)($group['signature_valid_count'] ?? 0),
                (int)($group['signature_invalid_count'] ?? 0),
                (int)($group['signature_unverified_count'] ?? 0),
                (int)($group['signature_development_count'] ?? 0),
            ];
        }
        if ($mobileReport['report']['truncated']) {
            // The file leaves the page behind, so the qualification has to
            // travel with it. Without this the partial total is carried into
            // whatever the reader reconciles it against.
            $rows[] = [];
            $rows[] = ['More groups matched than are listed here. Narrow the range or filter by app to see the rest.'];
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

        // 202-config/template.php opens an output buffer at include time and
        // anything already in it — a PHP warning, which a non-production
        // install displays — would be flushed in front of the CSV at
        // shutdown, past a Content-Length that counts only the CSV. Discard
        // it: this response is a file, not a page.
        while (ob_get_level() > 0) {
            ob_end_clean();
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
     * fputcsv is what quotes a field containing a comma, a quote or a
     * newline; a join would turn one app name into a row with the wrong
     * number of columns. Its return value is checked for the reason every
     * return value here is: a short write is indistinguishable from a short
     * report.
     *
     * `escape: ''` is not optional. PHP's default escape character is a
     * backslash, which is NOT part of RFC 4180: a field containing `\"`
     * comes out as `"ad\",BOOM"`, and every reader that does not share
     * PHP's private convention — Excel, LibreOffice, str_getcsv with the
     * same argument — sees the quote as closing the field and reads one row
     * as four. ad_network_id and transaction_id are free-form strings from a
     * public receiver, so that is reachable input, not a hypothetical. It
     * also silences PHP 8.4's deprecation notice, which would otherwise be
     * emitted from here — before sendCsv() sends a single header.
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
            if (fputcsv($handle, array_map([self::class, 'csvCell'], $row), ',', '"', '') === false) {
                fclose($handle);

                return null;
            }
        }

        rewind($handle);
        $body = stream_get_contents($handle);
        fclose($handle);

        return $body === false ? null : $body;
    }

    /**
     * One cell, safe to open in a spreadsheet.
     *
     * A leading =, +, - or @ makes Excel, LibreOffice and Sheets treat the
     * cell as a formula, and the first column of this file is an ad network
     * id, a source identifier or a country code — strings an unauthenticated
     * device postback puts there verbatim. Anyone who knows a victim's App
     * Store id (it is on the App Store) can therefore choose text in their
     * download. Prefixing an apostrophe is the interoperable neutraliser:
     * spreadsheets read it as "the rest is literal" and drop it on display.
     * The JSON API has no such sink, which is why this is the page's problem
     * rather than the receiver's.
     */
    private static function csvCell(string|int|float $value): string|int|float
    {
        if (!is_string($value) || $value === '' || strpos('=+-@', $value[0]) === false) {
            return $value;
        }

        return "'" . $value;
    }

    /**
     * The first column's value for one group, as plain text.
     *
     * The values come from groupLabelParts, which the rendered table reads
     * too; only the punctuation is the file's own. An app is "Name (id)"
     * because the id qualifies the name, while a source's two parts are
     * peers and are joined.
     */
    private function csvGroupLabel(array $group, string $groupBy): string
    {
        $parts = self::groupLabelParts($group, $groupBy);
        if ($parts === []) {
            return '';
        }
        if ($groupBy === 'app' && count($parts) > 1) {
            return $parts[0] . ' (' . $parts[1] . ')';
        }

        return implode(' / ', $parts);
    }

    /**
     * What the first column says about one group, in pieces, as plain text.
     *
     * One implementation because three read it — the rendered table, the
     * Postbacks tab's own app and source cells, and the CSV — and they had
     * already drifted: the CSV joined a source with ' / ' where the table
     * used ' · ', and printed nothing where the table said "not given". A
     * download that reads differently from the table it is a download of is
     * the exact drift one implementation exists to prevent, as groupKeys()
     * does for the field the value comes out of. The caller joins and
     * decorates; the empty list means "the report has no value here".
     *
     * @param array<string, mixed> $group
     * @return list<string>
     */
    public static function groupLabelParts(array $group, string $groupBy): array
    {
        if ($groupBy === 'app') {
            $name = trim((string)($group['app_name'] ?? ''));
            $id = (string)(int)($group['app_id'] ?? 0);

            return $name === '' ? [$id] : [$name, $id];
        }

        if ($groupBy === 'source') {
            // A source identifier of '0' is a real one, so the empties are
            // named rather than left to array_filter's falsiness.
            return array_values(array_filter([
                (string)($group['source_identifier'] ?? ''),
                ($group['campaign_id'] ?? null) === null ? '' : (string)$group['campaign_id'],
            ], static fn (string $part): bool => $part !== ''));
        }

        $value = (string)($group[self::groupKeys()[$groupBy] ?? ''] ?? '');

        return $value === '' ? [] : [$value];
    }

    private function flash(string $kind, string $text): void
    {
        $this->flashes[] = ['kind' => $kind, 'text' => $text];
    }

    /**
     * A flash that also records that the report is NOT the one the URL asked
     * for, because a filter in it could not be used.
     *
     * Separate from the flash list because sendCsv() needs the distinction
     * and the list cannot carry it. The gate there used to read "any flash at
     * all", on the reasoning that every warning came from readFilters(); the
     * apps-truncation notice broke that premise a wave later and silently
     * made the CSV undownloadable for exactly the accounts big enough to
     * trigger it. A premise about where warnings come from does not survive
     * the next warning.
     */
    private function flashFilterDropped(string $text): void
    {
        $this->filterDropped = true;
        $this->flash('warn', $text);
    }
}
