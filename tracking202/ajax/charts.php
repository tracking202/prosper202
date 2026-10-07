<?php

declare(strict_types=1);

include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -17) . '/202-config/class-dataengine.php');

AUTH::require_user();

require_once(substr(__DIR__, 0, -17) . '/202-config/functions-report-prefs.php');

use Prosper202\Report\OverviewChart;

/*
 * The Overview chart's two writes (account_overview.php draws the chart and
 * p202-overview.js posts here):
 *
 *   chart_time_range=hours|days     store the resolution, and answer the
 *                                   chart drawn at it as JSON
 *   levels[i][id], types[i][type]   store the chart's lines, a campaign (0
 *                                   for all) and a figure each
 *
 * Each answers as the Overview does. A role that is not shown campaign data
 * is not drawn a chart (the Overview leaves it out): 403. An account with no
 * chart row (one the API or the Administration page created) is given the
 * installer's default chart (OverviewChart), where the writes were UPDATEs
 * of nothing: the resolution answered 404 and the builder's lines were
 * dropped under a 200. A value the page never sends is refused, naming it:
 * 422. And a chart whose query failed answers 500 with what the Overview
 * says in its place; the exception went uncaught.
 *
 * Errors are JSON, {"error": "..."}, which p202-overview.js shows.
 */

// Draw the view the page rendered, not whatever the stored filters say by
// now (ReportView); a request that carries none reads the stored ones.
$reportView = p202_report_view_begin();

$answer = static function (int $status, array $body, int $flags = 0): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, $flags | JSON_THROW_ON_ERROR);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    $answer(405, ['error' => 'The chart is changed with a POST from the Overview.']);
}

// validate CSRF token before any state change
if (!AUTH::check_csrf_token()) {
    $answer(403, ['error' => 'Invalid or expired form token. Reload the page and try again.']);
}

$canSee = isset($userObj) && $userObj->hasPermission('access_to_campaign_data');
if (!$canSee) {
    $answer(403, ['error' => 'Your role is not shown campaign data, so it has no chart.']);
}

AUTH::set_timezone($_SESSION['user_timezone']);
$userId = (int) $_SESSION['user_id'];
$conn = new \Prosper202\Database\Connection($db);

if (array_key_exists('chart_time_range', $_POST)) {
    $range = $_POST['chart_time_range'];
    if (!is_string($range) || !in_array($range, OverviewChart::RANGES, true)) {
        $answer(422, ['error' => 'chart_time_range must be one of: ' . implode(', ', OverviewChart::RANGES) . '.']);
    }
    try {
        OverviewChart::saveRange($conn, $userId, $range);
    } catch (\Throwable $e) {
        error_log('charts.php: the chart resolution was not saved: ' . $e->getMessage());
        $answer(500, ['error' => 'The chart resolution was not saved; the server log says why.']);
    }

    try {
        $stmt = $conn->prepareRead('SELECT `data` FROM `202_charts` WHERE `user_id` = ? LIMIT 1');
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);
    } catch (\Throwable $e) {
        error_log('charts.php: the chart lines could not be read: ' . $e->getMessage());
        $answer(500, ['error' => 'The chart could not be read; the server log says why.'
            . ' This is not a statement that there were no clicks.']);
    }
    $lines = unserialize((string) ($row['data'] ?? ''), ['allowed_classes' => false]);
    if (!is_array($lines) || $lines === []) {
        // As the Overview draws a chart it cannot read: all campaigns' clicks.
        $lines = [['campaign_id' => '0', 'value_type' => 'clicks']];
    }

    $time = grab_timeframe();
    $from = (int) $time['from'];
    $to = (int) $time['to'];
    $rangeOutputFormat = $range === 'hours' ? 'M d h:iA' : 'M d';
    $rangePeriod = returnRanges(new DateTime('@' . $from), new DateTime('@' . $to), $range);
    try {
        $chart = (new DataEngine())->getChart($from, $to, $lines, $range, $rangeOutputFormat, $rangePeriod);
    } catch (\RuntimeException) {
        $answer(500, ['error' => 'The chart could not be read; the server log says why.'
            . ' This is not a statement that there were no clicks.']);
    }

    $categories = [];
    foreach ($rangePeriod as $point) {
        $categories[] = $point->format($rangeOutputFormat);
    }
    $answer(200, [
        'json' => $chart,
        'categories' => $categories,
        'title' => 'From ' . date('d/m/Y', $from) . ' to ' . date('d/m/Y', $to),
    ], JSON_NUMERIC_CHECK);
}

try {
    $lines = OverviewChart::lines($_POST['levels'] ?? null, $_POST['types'] ?? null);
} catch (\InvalidArgumentException $refused) {
    $answer(422, ['error' => $refused->getMessage()]);
}
try {
    OverviewChart::saveLines($conn, $userId, $lines);
} catch (\Throwable $e) {
    error_log('charts.php: the chart lines were not saved: ' . $e->getMessage());
    $answer(500, ['error' => 'The chart was not saved; the server log says why.']);
}
$answer(200, ['saved' => count($lines)]);
