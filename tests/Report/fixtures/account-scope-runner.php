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
 *                   a ReportBasicForm::DETAIL_LEVEL_* constant's name
 *   visitors        the Visitors list's query(): its count, and the click
 *                   ids its statement lists
 *   lists           the filter menus: ReportPrefsStore::lists() and
 *                   p202_overview_filter_lists()
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

/** connect.php inside a function: see report-read-failure-runner.php. */
function boot_app(string $root): void
{
    $prev = error_reporting(0);
    ob_start();
    require_once $root . '/202-config/connect.php';
    require_once $root . '/202-config/class-dataengine.php';
    require_once $root . '/202-config/ReportSummaryForm.class.php';
    require_once $root . '/202-config/functions-ui-overview.php';
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
boot_app($root);

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
            $result[] = ['title' => (string) $child->getTitle(), 'clicks' => (int) $child->getClicks()];
        }
    } elseif ($reader === 'visitors') {
        $query = query('SELECT 2c.click_id FROM 202_dataengine 2c ', '2c');
        $listed = $db->query($query['click_sql']);
        if (!$listed instanceof mysqli_result) {
            throw new RuntimeException('visitors: ' . $db->error);
        }
        $result = ['rows' => $query['rows'], 'listed' => array_map('intval', array_column($listed->fetch_all(MYSQLI_ASSOC), 'click_id'))];
    } elseif ($reader === 'lists') {
        $result = [
            'store' => (new \Tracking202\Report\ReportPrefsStore($db))->lists($userId),
            'overview' => p202_overview_filter_lists(
                new \Prosper202\Database\Connection($db),
                $userId,
                ['ppc_network_id', 'ppc_account_id', 'aff_network_id', 'aff_campaign_id', 'landing_page_id', 'text_ad_id']
            ),
        ];
    } else {
        throw new InvalidArgumentException("no reader named '$reader'");
    }
} catch (Throwable $e) {
    echo 'THREW ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(0);
}
echo 'RETURNED ', json_encode($result, JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
