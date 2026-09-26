<?php

declare(strict_types=1);

namespace Tracking202\Analyze;

use Api\V3\Controllers\AppNotificationsController;
use Api\V3\Controllers\AppRegistrationsController;
use Api\V3\Controllers\AppPostbacksController;
use Api\V3\Controllers\AppReportController;
use Api\V3\Controllers\GoalsController;
use Api\V3\Apps\Apple\SignatureState;
use Api\V3\Controllers\UsersController;
use Api\V3\Exception\ValidationException;
use Api\V3\HttpException;
use Tracking202\Apps\RegisteredApps;

/**
 * Analyze › Mobile Apps: what the apps' signals add up to, on both
 * platforms (plan §5.6, PR 11).
 *
 * Five readings, as tabs:
 *
 *  - Report, the grouped totals — /apps/report, rendered. Both platforms
 *    together by day, app or platform; iOS alone by Apple's dimensions (ad
 *    network, source, country, version, protocol, conversion type); Android
 *    alone by campaign, match state, Play Integrity state or goal.
 *  - Funnel, an app's goals in order with the installs that reached each.
 *  - Postbacks, the individual iOS rows behind the totals, for the moment a
 *    total looks wrong and the question becomes "which ones".
 *  - Postbacks sent, the traffic-source postbacks the app installs' goals
 *    queued, and whether each went out (the notification outbox).
 *  - Verify, a scratch pad for checking that a captured postback's signature
 *    is genuine. Nothing is stored; it exists so an operator can answer "is
 *    this real" without trusting the pipeline that would store it.
 *
 * Every read goes through the v3 controllers in-process, so this page and the
 * REST API answer with the same numbers and the same sentences.
 *
 * State lives in the query string rather than the session — and never in
 * 202_users_pref, the stored filters another tab writes (which is what
 * DataEngine\ReportView exists to override for the click reports): a report
 * someone is looking at has a URL they can send to somebody else, every
 * request the page makes carries its own filters, and the back button does
 * what it looks like it does. tests/Report/ReportViewReadersTest checks this
 * page reads no stored filter.
 */
class MobileAppsReportController
{
    /** Reading the report. Managing apps is a different permission. */
    private const VIEW_PERMISSION = 'view_attribution_reports';

    public const VIEWS = ['report', 'funnel', 'postbacks', 'notifications', 'verify'];

    /** The platform switch, in the order it is shown. */
    public const PLATFORMS = ['all' => 'Both', 'ios' => 'iOS', 'android' => 'Android'];

    /**
     * How the iOS report can be grouped, in the order the pills are shown.
     *
     * Keys are the API's own group_by values; an unknown one from the query
     * string falls back to 'day' and says so. The label is what the first
     * column is called once grouped that way.
     */
    public const GROUPINGS = [
        'day'             => 'Day',
        'registration'    => 'App',
        'ad-network'      => 'Ad network',
        'source'          => 'Source',
        'country'         => 'Country',
        'protocol'        => 'Protocol',
        'version'         => 'Version',
        'conversion-type' => 'Type',
    ];

    /** How the Android report can be grouped. */
    public const ANDROID_GROUPINGS = [
        'day'             => 'Day',
        'registration'    => 'App',
        'campaign'        => 'Campaign',
        'match-state'     => 'Match state',
        'integrity-state' => 'Play Integrity',
        'goal'            => 'Goal',
    ];

    /** How both platforms together can be grouped: the dimensions they share. */
    public const SHARED_GROUPINGS = [
        'day'          => 'Day',
        'registration' => 'App',
        'platform'     => 'Platform',
    ];

    /**
     * The groupings a platform's report offers.
     *
     * @return array<string, string>
     */
    public static function groupingsFor(string $platform): array
    {
        return match ($platform) {
            'ios' => self::GROUPINGS,
            'android' => self::ANDROID_GROUPINGS,
            default => self::SHARED_GROUPINGS,
        };
    }

    /**
     * The response field each iOS grouping's first column comes out of.
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
        return AppPostbacksController::groupKeys();
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

    /** The Android trust classes a report can be recomputed over. */
    public const TRUST_CLASSES = ['trusted', 'refuted', 'unvouched'];

    /**
     * How many group rows one report may hold (per platform), and how many
     * rows a page of the Postbacks and Postbacks sent tabs holds. The report
     * says so when it hit the ceiling (meta.groups_truncated); the lists
     * page instead.
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
    private \mysqli $db;
    private AppPostbacksController $postbacks;
    private AppRegistrationsController $apps;
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
        $this->db = $db;
        $this->postbacks = new AppPostbacksController($db, $this->userId);
        $this->apps = new AppRegistrationsController($db, $this->userId);
        $this->users = new UsersController($db);
    }

    public function handleRequest(): void
    {
        $view = (string)($_GET['view'] ?? 'report');
        if (!in_array($view, self::VIEWS, true)) {
            $view = 'report';
        }

        // Verify renders no amount, so it does not pay for the currency
        // statement. It does read the apps: the tabs every view shows depend
        // on whether the account has an Android app, and a tab that came and
        // went with the view would be a menu that moves under the pointer.
        $needsApps = $view !== 'verify';
        $apps = $this->listApps();
        $mobileReport = [
            'view' => $view,
            'self' => rtrim(get_absolute_url(), '/') . '/tracking202/analyze/mobile_apps.php',
            'filters' => $this->readFilters($apps, $view),
            'apps' => $apps,
            'platforms' => self::PLATFORMS,
            'ranges' => self::RANGES,
            'customRange' => self::CUSTOM_RANGE,
            // Revenue on this page is money; UsersController owns the account's
            // currency so this page and Setup > Mobile Apps cannot disagree.
            'currency' => $needsApps ? $this->users->accountCurrency($this->userId) : self::DEFAULT_CURRENCY_FALLBACK,
        ];
        $mobileReport['groupings'] = self::groupingsFor($mobileReport['filters']['platform']);

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
        } elseif ($view === 'funnel') {
            $mobileReport += $this->buildFunnel($mobileReport['filters'], $apps);
        } elseif ($view === 'postbacks') {
            $mobileReport += $this->buildPostbacks($mobileReport['filters']);
        } elseif ($view === 'notifications') {
            $mobileReport += $this->buildNotifications($mobileReport['filters']);
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
     * @param list<array<string, mixed>> $apps
     * @return array<string, mixed>
     */
    private function readFilters(array $apps, string $view): array
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

        // Round-tripped rather than pattern-matched: '0012' and a number too
        // big for an integer both match /^\d+$/ and then reach the API as a
        // different registration than the one typed, or as a 422 that would
        // replace the whole report with an error message.
        $registrationId = trim((string)($_GET['registration_id'] ?? ''));
        if ($registrationId !== '' && ($registrationId !== (string)(int)$registrationId || (int)$registrationId <= 0)) {
            $this->flashFilterDropped('The app filter was ignored: an app is chosen by its registration number, a whole number.');
            $registrationId = '';
        }

        $platform = self::resolvePlatform(
            isset($_GET['platform']) ? (string)$_GET['platform'] : null,
            $apps,
            $registrationId
        );
        foreach ($platform['notes'] as $note) {
            $this->flashFilterDropped($note);
        }
        $platform = $platform['platform'];

        $groupings = self::groupingsFor($platform);
        $groupBy = (string)($_GET['group_by'] ?? self::DEFAULT_GROUPING);
        if (!isset($groupings[$groupBy])) {
            if ($groupBy !== self::DEFAULT_GROUPING) {
                $this->flashFilterDropped('That grouping is not one the ' . self::PLATFORMS[$platform] . ' report offers, so it is grouped by '
                    . $groupings[self::DEFAULT_GROUPING] . '.');
            }
            $groupBy = self::DEFAULT_GROUPING;
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
        if ($signature !== '' && !self::signatureApplies($view, $platform)) {
            $this->flashFilterDropped('The signature filter was ignored: it filters Apple\'s postbacks, so it applies to the Postbacks tab and the iOS report only.');
            $signature = '';
        }
        $trusted = strtolower(trim((string)($_GET['trusted'] ?? '')));
        if ($trusted !== '' && (!in_array($trusted, self::TRUST_CLASSES, true) || $platform !== 'android')) {
            $this->flashFilterDropped($platform !== 'android'
                ? 'The trust filter was ignored: it filters Android installs, so it applies to the Android report only.'
                : 'The trust filter was ignored: it must be one of ' . implode(', ', self::TRUST_CLASSES) . '.');
            $trusted = '';
        }
        $status = strtolower(trim((string)($_GET['status'] ?? '')));
        if ($status !== '' && !in_array($status, AppNotificationsController::STATUSES, true)) {
            $this->flashFilterDropped('The status filter was ignored: it must be one of ' . implode(', ', AppNotificationsController::STATUSES) . '.');
            $status = '';
        }

        return [
            'range' => $range,
            // Shown in the date inputs, and they are UTC days by the same
            // argument the window is.
            'from' => gmdate('Y-m-d', $timeFrom),
            'to' => gmdate('Y-m-d', $timeTo),
            'time_from' => $timeFrom,
            'time_to' => $timeTo,
            'platform' => $platform,
            'group_by' => $groupBy,
            'registration_id' => $registrationId,
            'signature' => $signature,
            'trusted' => $trusted,
            'status' => $status,
        ];
    }

    /**
     * Whether the signature filter means anything on a view.
     *
     * It filters Apple's postbacks by their signature state. The Postbacks
     * tab lists only Apple's postbacks, whatever platform the page is set to
     * (the setting is shared by every tab, and an account with apps on both
     * platforms defaults to both), so the filter applies there always; the
     * report applies it only when it is the iOS report. Anywhere else it is
     * dropped and said to be (readFilters()), never silently. The form, the
     * links and readFilters() all ask this one question, so a filter the
     * form offers is never one the page then throws away.
     */
    public static function signatureApplies(string $view, string $platform): bool
    {
        return $view === 'postbacks' || ($view === 'report' && $platform === 'ios');
    }

    /**
     * Which platform's report to show.
     *
     * The URL decides when it says. When it does not, the app decides
     * (UI standard, rule 3): an app filter means that app's platform, and
     * an account with apps on one platform only sees that platform's report
     * — the one with every dimension it can use — rather than a combined
     * report whose second half is always empty. Anything else is both.
     *
     * @param list<array<string, mixed>> $apps
     * @return array{platform: string, notes: list<string>}
     */
    public static function resolvePlatform(?string $asked, array $apps, string $registrationId): array
    {
        $asked = $asked === null ? null : strtolower(trim($asked));
        if ($asked !== null && $asked !== '') {
            if (isset(self::PLATFORMS[$asked])) {
                return ['platform' => $asked, 'notes' => []];
            }
            return ['platform' => self::defaultPlatform($apps, $registrationId), 'notes' => ['That platform is not one this report offers, so it shows '
                . self::PLATFORMS[self::defaultPlatform($apps, $registrationId)] . '.']];
        }

        return ['platform' => self::defaultPlatform($apps, $registrationId), 'notes' => []];
    }

    /** @param list<array<string, mixed>> $apps */
    private static function defaultPlatform(array $apps, string $registrationId): string
    {
        $platforms = [];
        foreach ($apps as $app) {
            $platform = (string)($app['platform'] ?? '');
            if ($registrationId !== '' && (string)($app['registration_id'] ?? '') === $registrationId && isset(self::PLATFORMS[$platform])) {
                return $platform;
            }
            $platforms[$platform] = true;
        }
        $platforms = array_keys($platforms);

        return count($platforms) === 1 && isset(self::PLATFORMS[$platforms[0]]) ? $platforms[0] : 'all';
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

    // ─── The views ───────────────────────────────────────────────────

    /**
     * The report's filters, as /apps/report takes them: the window, the
     * app, and the one trust filter the platform has.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function reportParams(array $filters): array
    {
        $params = [
            'platform' => $filters['platform'],
            'time_from' => $filters['time_from'],
            'time_to' => $filters['time_to'],
        ];
        foreach (['registration_id', 'signature', 'trusted'] as $key) {
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
        $params = $this->reportParams($filters) + [
            'group_by' => $filters['group_by'],
            'limit' => self::MAX_GROUPS,
        ];

        try {
            $answer = (new AppReportController($this->db, $this->userId))->report($params);
        } catch (HttpException $e) {
            // The report is the page; without it there is nothing to show,
            // so say what happened rather than rendering an empty table that
            // reads as "no postbacks" (error pattern #11).
            $this->flash('bad', $e->getMessage());

            return ['report' => null, 'totals' => null, 'events' => []];
        }

        // The API's ungrouped totals, never a sum over the groups: a sum is
        // wrong by a replayed postback that landed in two of them, and wrong
        // by every group the truncation dropped.
        $totals = $answer['data']['totals'] ?? null;
        if (!is_array($totals)) {
            $this->flash('bad', 'The report came back without its totals, so the figures above the table are not shown.');
            $totals = null;
        }
        $json = static fn (mixed $v): mixed => json_decode((string)json_encode($v), true);

        return [
            'report' => [
                'groups' => $json($answer['data']['groups'] ?? []),
                'truncated' => (bool)($answer['meta']['groups_truncated'] ?? false),
                'trusted' => (string)($answer['meta']['trusted'] ?? 'trusted-only'),
                'notes' => (string)($answer['meta']['notes'] ?? ''),
            ],
            'totals' => $totals === null ? null : $json($totals),
            'events' => $totals === null ? [] : self::eventList($filters['platform'] === 'all'
                ? []
                : (array)$json($totals['events'] ?? [])),
        ];
    }

    /**
     * One group's decoded revenue — the shared `revenue` field, which is
     * `decoded_revenue` on an iOS row and the payable outcomes on an Android
     * one. The events are the fallback for a response that predates it.
     *
     * @param array<string, mixed> $group
     */
    public static function groupRevenue(array $group): float
    {
        foreach (['revenue', 'decoded_revenue'] as $key) {
            if (isset($group[$key]) && is_numeric($group[$key])) {
                return (float)$group[$key];
            }
        }

        $revenue = 0.0;
        foreach ((array)($group['events'] ?? []) as $event) {
            // Coalesce INSIDE the cast. `(float)$e['revenue'] ?? 0` indexes
            // first, so a legacy event without the key emits "Undefined array
            // key" and the ?? never fires — dead code plus a warning an
            // error handler can turn into an aborted report.
            $revenue += (float)(((array)$event)['revenue'] ?? 0);
        }

        return $revenue;
    }

    /**
     * The totals' per-goal events, biggest first.
     *
     * @param array<string, mixed> $events name => {count, revenue}
     * @return list<array{name: string, count: int, revenue: float}>
     */
    private static function eventList(array $events): array
    {
        $out = [];
        foreach ($events as $name => $event) {
            $out[] = ['name' => (string)$name, 'count' => (int)($event['count'] ?? 0), 'revenue' => (float)($event['revenue'] ?? 0)];
        }
        usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * An app's funnel: its goals in order, each with the installs that
     * reached it in the window (trusted, the unvouched beside them), as a
     * share of the first step and of the step it waits for.
     *
     * The app is the one the filter names; with none, the account's only
     * Android app when it has exactly one (the app decides what it can).
     * An iOS app has no funnel to read — Apple's postback carries one value,
     * the highest step the device reached, not each step — so its view says
     * that and shows the decoded goals instead.
     *
     * @param array<string, mixed> $filters
     * @param list<array<string, mixed>> $apps
     * @return array<string, mixed>
     */
    private function buildFunnel(array $filters, array $apps): array
    {
        $android = array_values(array_filter($apps, static fn (array $a): bool => (string)($a['platform'] ?? '') === 'android'));
        $chosen = null;
        foreach ($apps as $app) {
            if ($filters['registration_id'] !== '' && (string)$app['registration_id'] === $filters['registration_id']) {
                $chosen = $app;
            }
        }
        if ($chosen === null && $filters['registration_id'] === '' && count($android) === 1) {
            $chosen = $android[0];
        }
        $out = ['funnel' => null, 'funnelApp' => $chosen, 'funnelApps' => $android, 'funnelEvents' => null];
        if ($chosen === null) {
            return $out;
        }
        $window = ['time_from' => $filters['time_from'], 'time_to' => $filters['time_to'], 'registration_id' => (string)$chosen['registration_id']];
        $report = new AppReportController($this->db, $this->userId);

        if ((string)$chosen['platform'] !== 'android') {
            try {
                $answer = $report->report($window + ['platform' => 'ios', 'group_by' => 'platform']);
                $out['funnelEvents'] = self::eventList((array)json_decode((string)json_encode($answer['data']['totals']['events'] ?? []), true));
            } catch (HttpException $e) {
                $this->flash('bad', $e->getMessage());
            }
            return $out;
        }

        try {
            $answer = $report->report($window + ['platform' => 'android', 'group_by' => 'goal', 'limit' => 500]);
            $goals = (new GoalsController($this->db, $this->userId))->list([
                'registration_id' => (string)$chosen['registration_id'], 'limit' => '500',
            ])['data'];
        } catch (HttpException $e) {
            $this->flash('bad', $e->getMessage());
            return $out;
        }
        $reached = [];
        foreach ($answer['data']['groups'] as $group) {
            $reached[(int)$group['goal_id']] = $group;
        }
        $out['funnel'] = self::funnelSteps($goals, $reached);

        return $out;
    }

    /**
     * The funnel's rows: every current goal of the app in `after` order —
     * the install goal, then each goal after the ones it waits for, ties by
     * id — with what the report says reached it (zeros for a goal nothing
     * reached, which is a step of the funnel all the same).
     *
     * @param list<array<string, mixed>> $goals the app's goals (GoalsController::list())
     * @param array<int, array<string, mixed>> $reached goal id => the report's goal row
     * @return list<array<string, mixed>>
     */
    public static function funnelSteps(array $goals, array $reached): array
    {
        $byId = [];
        foreach ($goals as $goal) {
            if (($goal['archived_at'] ?? null) === null) {
                $byId[(int)$goal['goal_id']] = $goal;
            }
        }
        ksort($byId);
        $depth = [];
        $depthOf = static function (int $id, array $seen) use (&$depthOf, &$depth, $byId): int {
            if (isset($depth[$id])) {
                return $depth[$id];
            }
            if (isset($seen[$id])) {
                return 0; // a cycle (which the API refuses to create) cannot hang the page
            }
            $seen[$id] = true;
            $d = ($byId[$id]['builtin'] ?? null) === 'install' ? 0 : 1;
            foreach ((array)($byId[$id]['definition']['after'] ?? []) as $after) {
                if (isset($byId[(int)$after])) {
                    $d = max($d, $depthOf((int)$after, $seen) + 1);
                }
            }
            return $depth[$id] = $d;
        };
        $order = [];
        foreach (array_keys($byId) as $id) {
            $order[] = [$depthOf($id, []), $id];
        }
        sort($order);

        $count = static fn (int $id): int => (int)($reached[$id]['installs'] ?? 0);
        $installStep = null;
        foreach ($byId as $id => $goal) {
            if (($goal['builtin'] ?? null) === 'install') {
                $installStep = $id;
                break;
            }
        }

        $steps = [];
        $first = null;
        foreach ($order as [$level, $id]) {
            $row = $reached[$id] ?? [];
            $installs = $count($id);
            $first ??= $installs;
            // The step before is the one this goal waits for, not the row
            // above it: two goals at one depth are siblings, not a sequence.
            // `after` means every named goal first, so the smallest of them
            // bounds who could reach this one; a goal that waits for nothing
            // follows the install.
            $waitsFor = array_values(array_filter(array_map('intval', (array)($byId[$id]['definition']['after'] ?? [])), static fn (int $a): bool => isset($byId[$a])));
            if ($waitsFor === [] && $installStep !== null && $installStep !== $id) {
                $waitsFor = [$installStep];
            }
            $previous = $waitsFor === [] ? null : min(array_map($count, $waitsFor));
            $steps[] = [
                'goal_id' => $id,
                'name' => (string)$byId[$id]['name'],
                'level' => $level,
                'builtin' => $byId[$id]['builtin'] ?? null,
                'after' => array_values(array_map('intval', (array)($byId[$id]['definition']['after'] ?? []))),
                'installs' => $installs,
                'unvouched' => (int)($row['unvouched_count'] ?? 0),
                'goals_reached' => (int)($row['goals_reached'] ?? 0),
                'revenue' => (float)($row['revenue'] ?? 0),
                // A share of nothing is not zero percent, it is no share.
                'of_first' => $first > 0 ? (float)$installs / $first : null,
                'of_previous' => $previous !== null && $previous > 0 ? (float)$installs / $previous : null,
            ];
        }

        return $steps;
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
        $base = [
            'time_from' => $filters['time_from'],
            'time_to' => $filters['time_to'],
            'limit' => self::PER_PAGE,
        ];
        foreach (['registration_id', 'signature'] as $key) {
            if ($filters[$key] !== '') {
                $base[$key] = $filters[$key];
            }
        }

        try {
            $answer = $this->postbacks->list($base + ['offset' => ($asked - 1) * self::PER_PAGE]);
            // A missing total resolves to what the rows already prove, never
            // to zero. Zero is the EMPTY answer, so an absent total would
            // have rendered the rows under "No postbacks in this range",
            // hidden the pager, and clamped every page back to the first.
            // offset + rows-in-hand is a floor: exact on the last page, and
            // never smaller than what is on screen.
            $total = isset($answer['pagination']['total'])
                ? (int)$answer['pagination']['total']
                : ($asked - 1) * self::PER_PAGE + count($answer['data'] ?? []);
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
     * The traffic-source postbacks app installs' goals queued in the window,
     * newest first, and the summary by status.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function buildNotifications(array $filters): array
    {
        $asked = min(max(1, (int)($_GET['page'] ?? 1)), self::MAX_PAGE);
        $params = ['time_from' => $filters['time_from'], 'time_to' => $filters['time_to'], 'limit' => self::PER_PAGE];
        foreach (['registration_id', 'status'] as $key) {
            if ($filters[$key] !== '') {
                $params[$key] = $filters[$key];
            }
        }
        $read = new AppNotificationsController($this->db, $this->userId);
        try {
            $answer = $read->list($params + ['offset' => ($asked - 1) * self::PER_PAGE]);
            $total = (int)$answer['pagination']['total'];
            $pages = max(1, (int)ceil($total / self::PER_PAGE));
            $page = min($asked, $pages);
            if ($page !== $asked) {
                $this->flash('warn', 'There is no page ' . $asked . ' of these postbacks, so this is the last one.');
                $answer = $read->list($params + ['offset' => ($page - 1) * self::PER_PAGE]);
            }
        } catch (HttpException $e) {
            $this->flash('bad', $e->getMessage());

            return ['notifications' => null, 'summary' => null, 'pagination' => null];
        }

        return [
            'notifications' => $answer['data'],
            'summary' => $answer['meta']['summary'],
            'pagination' => ['rows' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => self::PER_PAGE],
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
     * The registered apps, both platforms, for the filter menu, the funnel's
     * app menu and the Postbacks tab's app column.
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
            // has no free-text entry, but registration_id in the address does work —
            // the template renders an option for a value it was not given,
            // labelled "not in your list" — so name the escape hatch that
            // exists rather than a control that does not.
            $of = $registered['total'] === null ? '' : ' of ' . $registered['total'];
            $this->flash('warn', 'The App filter lists the first ' . count($registered['apps']) . $of
                . ' registered apps, by name. To report on one that is not listed,'
                . " add registration_id=<the app's number on Setup › Mobile Apps> to this page's address.");
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
        $label = $mobileReport['groupings'][$filters['group_by']] ?? 'Group';

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

        $columns = self::csvColumns($filters['platform']);
        $rows = [array_merge([$label], array_keys($columns))];
        foreach ($mobileReport['report']['groups'] as $group) {
            $row = [self::csvGroupLabel($group, $filters['group_by'])];
            foreach ($columns as $field) {
                // The bare number, not dollar_format's rendering: a
                // spreadsheet should get something it can add up.
                $row[] = $field === 'revenue' ? round(self::groupRevenue($group), 5)
                    : ($field === 'platform' ? (string)($group['platform'] ?? '') : (int)($group[$field] ?? 0));
            }
            $rows[] = $row;
        }
        if ($mobileReport['report']['truncated']) {
            // The file leaves the page behind, so the qualification has to
            // travel with it. Without this the partial total is carried into
            // whatever the reader reconciles it against.
            $rows[] = [];
            $rows[] = ['More groups matched than are listed here. Narrow the range or filter by app to see the rest.'];
        }

        // Built in memory first — MAX_GROUPS rows per platform at the very
        // most — because once a Content-Disposition header is out, a write
        // that fails leaves a short file the reader has no way to know is
        // short. Returning instead renders the page with the message on it.
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
            'mobile-apps-' . $filters['platform'] . '-' . $filters['group_by'] . '-' . $filters['from'] . '-to-' . $filters['to'] . '.csv'
        ) ?? 'mobile-apps.csv';
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }

    /**
     * A platform's CSV columns, header => the group field it reads.
     *
     * @return array<string, string>
     */
    public static function csvColumns(string $platform): array
    {
        return match ($platform) {
            'ios' => [
                'Postbacks' => 'postbacks', 'Installs' => 'installs', 'Re-downloads' => 'redownloads', 'Re-engagements' => 'reengagements',
                'Losses' => 'losses', 'Revenue' => 'revenue', 'Decoded' => 'decoded', 'Ambiguous encoding' => 'ambiguous_encoding',
                'Trusted' => 'trusted_count', 'Refuted' => 'refuted_count', 'Unvouched' => 'unvouched_count', 'Development-signed' => 'test_count',
            ],
            'android' => [
                'Received' => 'received', 'Installs' => 'installs', 'Organic' => 'organic', 'Pending' => 'pending',
                'Refuted' => 'refuted_count', 'Unvouched' => 'unvouched_count', 'Test' => 'test_count',
                'Goals reached' => 'goals_reached', 'Revenue' => 'revenue',
            ],
            default => [
                'Platform' => 'platform', 'Installs' => 'installs', 'Goals reached' => 'goals_reached', 'Revenue' => 'revenue',
                'Trusted' => 'trusted_count', 'Refuted' => 'refuted_count', 'Unvouched' => 'unvouched_count', 'Test' => 'test_count',
            ],
        };
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
    private static function csvGroupLabel(array $group, string $groupBy): string
    {
        $parts = self::groupLabelParts($group, $groupBy);
        if ($parts === []) {
            return '';
        }
        if (in_array($groupBy, ['registration', 'campaign'], true) && count($parts) > 1) {
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
        if ($groupBy === 'registration') {
            // The name, qualified by the app's own key (its App Store id or
            // package), which is what an operator recognises; a
            // registration since deleted keeps only its number.
            $name = trim((string)($group['app_name'] ?? ''));
            $key = trim((string)($group['app_key'] ?? ''));
            if ($key === '') {
                $id = $group['registration_id'] ?? null;
                return $id === null ? [] : ['registration ' . (int)$id];
            }

            return $name === '' ? [$key] : [$name, $key];
        }

        if ($groupBy === 'source') {
            // A source identifier of '0' is a real one, so the empties are
            // named rather than left to array_filter's falsiness.
            return array_values(array_filter([
                (string)($group['source_identifier'] ?? ''),
                ($group['campaign_id'] ?? null) === null ? '' : (string)$group['campaign_id'],
            ], static fn (string $part): bool => $part !== ''));
        }

        if ($groupBy === 'campaign') {
            $id = $group['aff_campaign_id'] ?? null;
            if ($id === null) {
                return [];
            }
            $name = trim((string)($group['aff_campaign_name'] ?? ''));

            return $name === '' ? ['campaign ' . (int)$id] : [$name, 'campaign ' . (int)$id];
        }

        $field = match ($groupBy) {
            'platform' => 'platform',
            'match-state' => 'match_state',
            'integrity-state' => 'integrity_state',
            'goal' => 'goal_name',
            'day' => 'date',
            default => self::groupKeys()[$groupBy] ?? '',
        };
        $value = (string)($group[$field] ?? '');
        if ($groupBy === 'platform') {
            $value = self::PLATFORMS[$value] ?? $value;
        } elseif ($groupBy === 'match-state' || $groupBy === 'integrity-state') {
            $value = str_replace('_', ' ', $value);
        }

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
