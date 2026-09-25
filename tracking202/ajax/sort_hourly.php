<?php

declare(strict_types=1);

/**
 * Overview › Day Parting: the report per hour of the day, added up over the
 * window.
 *
 * Drawn into tracking202/overview/day-parting.php on the v2 shell; the
 * filters are the user's report preferences, which that page has just
 * applied from its URL.
 */

include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -17) . '/202-config/class-dataengine.php');
require_once(substr(__DIR__, 0, -17) . '/202-config/functions-ui-overview.php');

AUTH::require_user();

// Draw the view the page rendered, not whatever the stored filters say by
// now (ReportView); a request that carries none reads the stored ones.
$reportView = p202_report_view_begin();

//set the timezone for the user, for entering their dates.
AUTH::set_timezone($_SESSION['user_timezone']);

$prefs = p202_report_prefs_load(new \Prosper202\Database\Connection($db), (int) $_SESSION['user_id']);
$cpv = ($prefs['user_cpc_or_cpv'] ?? '') === 'cpv';

//grab the users date range preferences
$time = grab_timeframe();

$de = new DataEngine();
$data = $de->getReportData('hourly', (string) $time['from'], (string) $time['to'], $cpv);

// Every row is on the page (this report is not paginated), so sorting in the
// browser is honest.
echo p202_overview_metrics_table($data, [
    'id' => 'day-parting-table',
    'label' => 'Hour',
    'caption' => 'Your figures per hour of the day',
    'key' => static fn (array $row): string => (string) ($row['click_time_from_disp'] ?? ''),
    'masked' => isset($userObj) && !$userObj->hasPermission('access_to_campaign_data') && empty($_SESSION['publisher']),
    'empty' => p202_overview_empty(get_absolute_url()),
]);
