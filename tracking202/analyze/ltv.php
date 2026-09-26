<?php

declare(strict_types=1);

/**
 * Analyze › Customer LTV, on the v2 shell.
 *
 * The page is the section's frame: its header, the acquisition window, and
 * #m-content, into which 202-js/ltv.js loads the view the URL names (report,
 * customer, company, companies, products, subscriptions, settings) from the
 * partials under tracking202/ajax/. The window is the one report filter this
 * section has; it is read from the URL and stored exactly as the Analyze
 * reports store theirs (ReportFilterInput, ReportPrefsStore), because the
 * partials and ltv_download.php read it back through grab_timeframe().
 */

use Tracking202\Analyze\AnalyzeReportController;
use Tracking202\Report\ReportFilterInput;
use Tracking202\Report\ReportPrefsStore;

$rootPath = dirname(__DIR__, 2);
include_once $rootPath . '/202-config/connect.php';
require_once __DIR__ . '/AnalyzeReportController.php';

AUTH::require_user();
AUTH::set_timezone($_SESSION['user_timezone']);

$store = new ReportPrefsStore($db);
$userId = (int) $_SESSION['user_id'];
$input = ReportFilterInput::fromQuery($_GET, []);
// This page reads the window and nothing else. A `page` or `order` that
// does not parse (a stray one on a pasted link) is not the window's
// problem: it neither holds the range back nor is blamed for it, as in
// AnalyzeReportController::handleRequest().
$errors = array_diff_key($input->errors, ['page' => true, 'order' => true]);
if ($input->speaks && $errors === []) {
    $errors = $store->save($userId, [], $input->window);
}

$time = grab_timeframe();
$window = AnalyzeReportController::windowOf($time);

// What this page shows is its view: the partials ltv.js loads and the
// download each draw it (ltv.js sends it with every request under
// tracking202/ajax/, and each installs it with p202_report_view_begin()),
// so a second tab storing another window meanwhile changes neither
// (ReportView), exactly as for the other Analyze reports.
require_once $rootPath . '/202-config/functions-report-prefs.php';
$view = p202_report_view_query([], [], $window);
p202_report_view_from_request([\Prosper202\DataEngine\ReportView::PARAM => $view], $userId);
$rangeSpec = ['range' => $window['range'], 'from' => $window['from'], 'to' => $window['to']];
if (isset($errors['range'])) {
    $rangeSpec['error'] = $errors['range'];
}

$base = rtrim(get_absolute_url(), '/');
$self = $base . '/tracking202/analyze/ltv.php';
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

template_top('Customer Lifetime Value', ['ui' => 'v2']);
?>

<div class="p202-page-header">
    <div class="p202-page-header__icon"><i class="bi bi-person-lines-fill"></i></div>
    <div class="p202-page-header__text">
        <h1 class="p202-page-header__title">Customer Lifetime Value</h1>
        <p class="p202-page-header__desc">Revenue per customer across their lifetime — repeat purchases, subscriptions and refunds included.</p>
    </div>
</div>

<?php if ($errors !== []) {
    echo p202_flash('bad', 'The range was not applied: ' . implode(' ', $errors) . ' The report still shows your previous range.');
} ?>

<?php echo p202_report_filter_bar([
    'action' => $self,
    'id' => 'ltv-range',
    'range' => $rangeSpec,
    'filters' => [],
    'note' => 'The range selects customers by when they were first seen. It is shared with your other reports.',
]); ?>

<div id="m-content" data-ltv-page="<?php echo $e($self); ?>" data-ltv-ajax="<?php echo $e($base . '/tracking202/ajax/'); ?>" data-ltv-view="<?php echo $e($view); ?>" aria-busy="true" aria-live="polite">
    <div class="p202-skeleton mb-3" style="height: 5.5rem"></div>
    <div class="p202-skeleton" style="height: 18rem"></div>
</div>

<script src="<?php echo $e($base . '/202-js/ltv.js'); ?>" defer></script>

<?php template_bottom();
