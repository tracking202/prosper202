<?php

declare(strict_types=1);

// Analyze › Publishers, downloaded: the rows the page lists, tab-separated,
// as the other Analyze downloads are.
$rootPath = dirname(__DIR__, 2);
include_once $rootPath . '/202-config/connect.php';
require_once __DIR__ . '/PublishersReport.php';

AUTH::require_user();
AUTH::require_permissions('access_to_campaign_data');
AUTH::set_timezone($_SESSION['user_timezone']);

// The window the page drew (the link carries its view), not whatever the stored one says by now.
require_once $rootPath . '/202-config/functions-report-prefs.php';
p202_report_view_begin();
$time = grab_timeframe();

try {
    $report = \Tracking202\Analyze\PublishersReport::read(
        $db,
        (int) ($_SESSION['user_own_id'] ?? 0),
        (int) $time['from'],
        (int) $time['to']
    );
} catch (\Throwable $failure) {
    error_log('Analyze publishers download failed: ' . $failure->getMessage());
    http_response_code(500);
    die('The report could not be read. Try again in a moment; if it keeps happening, the server log says why.');
}

header('Content-type: application/octet-stream');
header('Content-Disposition: attachment; filename=T202_publishers_' . time() . '.xls');
header('Pragma: no-cache');
header('Expires: -1');

echo implode("\n", \Tracking202\Analyze\PublishersReport::exportLines($report)) . "\n";
