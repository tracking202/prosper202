<?php

declare(strict_types=1);

/**
 * Boots the app against the scratch database as one signed-in account and
 * runs one legacy report reader, in a process of its own, for
 * LegacyReportAccountScopeIntegrationTest (the boot is
 * report-read-failure-runner.php's).
 *
 * argv: <reader>
 *   engine:<type>   DataEngine::getReportData(<type>) over the window
 *   group:<level>   Group Overview's query (ReportSummaryForm) at one level,
 *                   a ReportBasicForm::DETAIL_LEVEL_* constant's name: each
 *                   row's id, title and clicks
 *   visitors        the Visitors list's query(): its count, and the click
 *                   ids its statement lists
 *   lists           the filter menus: ReportPrefsStore::lists() and
 *                   p202_overview_filter_lists()
 *   api-v1:<report> / api-v2:<report>
 *                   the legacy API's reportQuery() for text_ads,
 *                   landing_pages, countries, cities or carriers, and v1's
 *                   getDataForWP() (get_data_for_wp); `refused` is the
 *                   keywords report grouped by a column no table has, a
 *                   statement the server refuses; `cid=<id>` is runReports()
 *                   for text_ads on that campaign, `report?<query>` is
 *                   runReports() for text_ads with the query's parameters as
 *                   sent (a refusal prints the API's JSON and ends, with no
 *                   RETURNED line), v1's `wp?<query>` is the WordPress
 *                   plugin's call its `type` names (get_data_for_wp,
 *                   wp_create_lp, wp_update_lp) with the query as $_GET,
 *                   `auth?fail=<none|key|user>&apikey=<key>` the key check
 *                   each version's endpoint makes first (v1's getStats(), a
 *                   text_ads report, v2's getAuth()) on a connection whose
 *                   fetch of the key's row, or of the account's time zone,
 *                   answers false,
 *                   and v2's `categories` its listCategories(), each handed
 *                   the user id as its endpoint hands it (v1 a string, v2
 *                   an int)
 *   dirty-hours     the cron job's DataEngine::processDirtyHours(), then
 *                   the 202_dirty_hours rows it left (RollupRefreshIntegrationTest)
 *   click-upgrade   the cron job's DataEngine::processClickUpgrade(), and
 *                   whether curl was loaded, which sends it the other way
 *                   (ClickUpgradeIntegrationTest runs it without)
 * env:  P202_TEST_DB_HOST/PORT/USER/PASS/NAME, P202_TEST_REPORT_USER,
 *       P202_TEST_FROM, P202_TEST_TO (the window, unix seconds)
 * Prints "RETURNED <json>", or "THREW <class>: <message>".
 */

[, $reader] = $argv + [null, ''];

$root = dirname(__DIR__, 3);
$dbName = (string) getenv('P202_TEST_DB_NAME');
$userId = (int) getenv('P202_TEST_REPORT_USER');
$from = (int) getenv('P202_TEST_FROM');
$to = (int) getenv('P202_TEST_TO');
if ($dbName === '' || $userId <= 0 || $from <= 0 || $to <= 0) {
    fwrite(STDERR, "P202_TEST_DB_NAME, P202_TEST_REPORT_USER, P202_TEST_FROM and P202_TEST_TO are required\n");
    exit(2);
}
$host = (string) (getenv('P202_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string) (getenv('P202_TEST_DB_PORT') ?: '');

/**
 * connect.php inside a function: see report-read-failure-runner.php. The
 * legacy API boots as its endpoints do, on connect2.php.
 */
function boot_app(string $root, bool $legacyApi): void
{
    $prev = error_reporting(0);
    ob_start();
    if ($legacyApi) {
        require_once $root . '/202-config/connect2.php';
    } else {
        require_once $root . '/202-config/connect.php';
        require_once $root . '/202-config/class-dataengine.php';
        require_once $root . '/202-config/ReportSummaryForm.class.php';
        require_once $root . '/202-config/functions-ui-overview.php';
    }
    ob_end_clean();
    error_reporting($prev);
    $config = ['dbname', 'dbhost', 'dbhostro', 'dbuser', 'dbpass', 'mchost', 'root'];
    foreach (get_defined_vars() as $name => $value) {
        if (!in_array($name, $config, true)) {
            $GLOBALS[$name] = $value;
        }
    }
}

$GLOBALS['dbname'] = $dbName;
$GLOBALS['dbhost'] = $port !== '' ? $host . ':' . $port : $host;
$GLOBALS['dbhostro'] = $GLOBALS['dbhost'];
$GLOBALS['dbuser'] = (string) (getenv('P202_TEST_DB_USER') ?: 'root');
$GLOBALS['dbpass'] = (string) (getenv('P202_TEST_DB_PASS') ?: '');
$GLOBALS['mchost'] = '';
$_SERVER += ['SERVER_NAME' => 'localhost', 'REQUEST_URI' => '/account-scope', 'SCRIPT_URL' => '/account-scope', 'REMOTE_ADDR' => '127.0.0.1'];
boot_app($root, str_starts_with($reader, 'api-'));

// A connection whose fetch from the statements naming $failOn answers false,
// as a failed one does. Evaluated, as the integration suites declare their
// stand-ins.
eval(<<<'PHP'
    final class FailingFetchMysqli extends mysqli
    {
        public ?string $failOn = null;

        public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
        {
            if ($this->failOn === null || !str_contains($query, $this->failOn)) {
                return parent::query($query, $result_mode);
            }
            if (!$this->real_query($query)) {
                return false;
            }

            return new class ($this, MYSQLI_STORE_RESULT) extends mysqli_result {
                public function fetch_assoc(): array|null|false
                {
                    return false;
                }
            };
        }
    }
    PHP);

$db = DB::getInstance()->getConnection();
$at = $db->query('SELECT DATABASE()');
$current = $at instanceof mysqli_result ? (string) ($at->fetch_row()[0] ?? '') : '';
if ($current !== $dbName) {
    fwrite(STDERR, "the app connected to '$current', not the scratch database '$dbName'\n");
    exit(2);
}

$_SESSION['user_id'] = $userId;
$_SESSION['user_own_id'] = $userId;
$_SESSION['user_timezone'] = 'UTC';
$_POST = [];

/** The user's preferences row, as the pages hand it to ReportSummaryForm. */
$prefs = static function () use ($db, $userId): array {
    $result = $db->query('SELECT * FROM 202_users_pref WHERE user_id = ' . $userId);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('preferences: ' . $db->error);
    }
    return $result->fetch_assoc() ?? [];
};

try {
    if (str_starts_with($reader, 'engine:')) {
        $result = (new DataEngine())->getReportData(substr($reader, 7), $from, $to, false);
    } elseif (str_starts_with($reader, 'group:')) {
        $form = new ReportSummaryForm();
        $form->setDetails([constant('ReportBasicForm::' . substr($reader, 6))]);
        $form->setDetailsSort([ReportBasicForm::SORT_NAME]);
        $form->setDisplayType([ReportBasicForm::DISPLAY_TYPE_TABLE]);
        $form->setStartTime($from);
        $form->setEndTime($to);
        $sql = $form->getQuery((string) $userId, $prefs());
        $rows = $db->query($sql);
        if (!$rows instanceof mysqli_result) {
            throw new RuntimeException('group overview: ' . $db->error);
        }
        foreach ($rows->fetch_all(MYSQLI_ASSOC) as $row) {
            $form->addReportData($row);
        }
        $result = [];
        foreach ($form->getReportData()->getChildArrayBySort() as $child) {
            $result[] = [
                'id' => (string) $child->getId(),
                'title' => (string) $child->getTitle(),
                'clicks' => (int) $child->getClicks(),
            ];
        }
    } elseif ($reader === 'visitors') {
        $query = query('SELECT 2c.click_id FROM 202_dataengine 2c ', '2c');
        $listed = $db->query($query['click_sql']);
        if (!$listed instanceof mysqli_result) {
            throw new RuntimeException('visitors: ' . $db->error);
        }
        $result = ['rows' => $query['rows'], 'listed' => array_map('intval', array_column($listed->fetch_all(MYSQLI_ASSOC), 'click_id'))];
    } elseif ($reader === 'dirty-hours') {
        (new DataEngine())->processDirtyHours();
        $left = $db->query('SELECT COUNT(*) FROM 202_dirty_hours WHERE user_id = ' . $userId);
        if (!$left instanceof mysqli_result) {
            throw new RuntimeException('dirty hours: ' . $db->error);
        }
        $result = ['left' => (int) ($left->fetch_row()[0] ?? -1)];
    } elseif ($reader === 'click-upgrade') {
        $curl = function_exists('curl_version');
        if (!$curl) {
            (new DataEngine())->processClickUpgrade();
        }
        $result = ['curl' => $curl];
    } elseif ($reader === 'lists') {
        $result = [
            'store' => (new \Tracking202\Report\ReportPrefsStore($db))->lists($userId),
            'overview' => p202_overview_filter_lists(
                new \Prosper202\Database\Connection($db),
                $userId,
                ['ppc_network_id', 'ppc_account_id', 'aff_network_id', 'aff_campaign_id', 'landing_page_id', 'text_ad_id']
            ),
        ];
    } elseif (preg_match('/^api-(v1|v2):(\w+|cid=\d+|(?:report|wp|auth)\?[^\s]*)$/D', $reader, $m) === 1) {
        // The legacy API's functions, as GET /api/<version>/reports/ calls
        // them, through the version's own functions.php (the report helpers
        // are api/legacy/functions.php's, which it loads). Loaded as they
        // ship, strict types and all: this runner
        // used to strip the declare, because every report request was a
        // TypeError under it (real_escape_string() handed the window's
        // timestamps and the unasked cid/c1-c4), and so it could not see
        // that the endpoint answered 500.
        require $root . '/api/' . $m[1] . '/functions.php';
        $reports = [
            'text_ads' => ['text_ads', 'text_ad_id', 'text_ad_name'],
            'landing_pages' => ['landing_pages', 'landing_page_id', 'landing_page'],
            'countries' => ['locations_country', 'country_id', 'country_name'],
            'cities' => ['locations_city', 'city_id', 'city_name'],
            'carriers' => ['locations_isp', 'isp_id', 'isp_name'],
            'refused' => ['keywords', 'no_such_column', 'keyword'],
        ];
        if (isset($reports[$m[2]])) {
            $result = reportQuery($db, ...[...$reports[$m[2]], (string) $userId, $from, $to]);
        } elseif ($m[2] === 'get_data_for_wp') {
            $result = getDataForWP($db, (string) $userId);
        } elseif ($m[2] === 'categories') {
            ob_start();
            listCategories($db, $userId);
            $result = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
        } elseif (str_starts_with($m[2], 'report?')) {
            parse_str(substr($m[2], 7), $sent);
            $result = runReports($db, [
                'type' => 'text_ads',
                'date_from' => gmdate('m/d/Y', $from),
                'date_to' => gmdate('m/d/Y', $to),
            ] + $sent, $m[1] === 'v1' ? (string) $userId : $userId, 'UTC', $m[1]);
        } elseif (str_starts_with($m[2], 'wp?') && $m[1] === 'v1') {
            parse_str(substr($m[2], 3), $_GET);
            $result = match ($_GET['type'] ?? '') {
                'get_data_for_wp' => getDataForWP($db, (string) $userId),
                'wp_create_lp' => wpCreateLp($db, (string) $userId),
                'wp_update_lp' => wpUpdateLp($db, (string) $userId),
            };
        } elseif (str_starts_with($m[2], 'auth?')) {
            parse_str(substr($m[2], 5), $sent);
            $failing = new FailingFetchMysqli(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                $dbName,
                (int) ($port !== '' ? $port : 3306)
            );
            // The session the bootstrap set up on its own connection: under
            // MySQL 8.0's default sql_mode (ONLY_FULL_GROUP_BY) the report's
            // GROUP BY is refused.
            $mode = $db->query('SELECT @@SESSION.sql_mode');
            $mode = $mode instanceof mysqli_result ? $mode->fetch_row()[0] ?? null : null;
            $set = is_string($mode) ? "SET SESSION sql_mode = '" . $failing->real_escape_string($mode) . "'" : null;
            if ($set === null || !$failing->query($set)) {
                fwrite(STDERR, "could not give the failing connection the app's sql_mode\n");
                exit(2);
            }
            $failing->failOn = match ($sent['fail'] ?? 'none') {
                'key' => '202_api_keys',
                'user' => '`user_timezone`',
                default => null,
            };
            $result = $m[1] === 'v1'
                ? getStats($failing, [
                    'apikey' => (string) ($sent['apikey'] ?? ''),
                    'type' => 'text_ads',
                    'date_from' => gmdate('m/d/Y', $from),
                    'date_to' => gmdate('m/d/Y', $to),
                ])
                : getAuth($failing, ['apikey' => (string) ($sent['apikey'] ?? '')]);
        } elseif (str_starts_with($m[2], 'cid=')) {
            $result = runReports($db, [
                'type' => 'text_ads',
                'cid' => substr($m[2], 4),
                'date_from' => gmdate('m/d/Y', $from),
                'date_to' => gmdate('m/d/Y', $to),
            ], $m[1] === 'v1' ? (string) $userId : $userId, 'UTC', $m[1]);
        } else {
            throw new InvalidArgumentException("no legacy API reader named '$m[2]'");
        }
    } else {
        throw new InvalidArgumentException("no reader named '$reader'");
    }
} catch (Throwable $e) {
    echo 'THREW ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(0);
}
echo 'RETURNED ', json_encode($result, JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
