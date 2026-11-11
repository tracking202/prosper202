<?php

/**
 * Analyze › Publishers: what each publisher's links did. The clicks a
 * publisher link credited (Prosper202\Click\PublisherCredit), by publisher,
 * under each by link (its t202id, stored with a credit since 1.9.80; older
 * clicks are one "link not recorded" group) and campaign, with the clicks
 * on no publisher link last so the totals are the account's.
 *
 * The figures are GET /reports/groups?by=publisher,publisher_link,campaign's, from the same
 * controller, over the window the other Analyze reports share (read and
 * stored as ltv.php does it).
 */

declare(strict_types=1);

use Prosper202\Click\PublisherCredit;
use Prosper202\Database\Connection;
use Tracking202\Analyze\AnalyzeReportController;
use Tracking202\Analyze\PublishersReport;
use Tracking202\Report\ReportFilterInput;
use Tracking202\Report\ReportPrefsStore;

$rootPath = dirname(__DIR__, 2);
include_once $rootPath . '/202-config/connect.php';
require_once __DIR__ . '/AnalyzeReportController.php';
require_once __DIR__ . '/PublishersReport.php';

AUTH::require_user();
AUTH::require_permissions('access_to_campaign_data');
AUTH::set_timezone($_SESSION['user_timezone']);

$store = new ReportPrefsStore($db);
$userId = (int) $_SESSION['user_id'];
$input = ReportFilterInput::fromQuery($_GET, []);
// Only the window is read here; a stray `page` or `order` neither holds it back nor is blamed for it.
$errors = array_diff_key($input->errors, ['page' => true, 'order' => true]);
if ($input->speaks && $errors === []) {
    $errors = $store->save($userId, [], $input->window);
}

$time = grab_timeframe();
$window = AnalyzeReportController::windowOf($time);
$rangeSpec = ['range' => $window['range'], 'from' => $window['from'], 'to' => $window['to']];
if (isset($errors['range'])) {
    $rangeSpec['error'] = $errors['range'];
}

$base = rtrim(get_absolute_url(), '/');
$self = $base . '/tracking202/analyze/publishers.php';
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// Whose clicks: the signed-in account's, as the other reports read them (DataScope).
$accountId = (int) ($_SESSION['user_own_id'] ?? 0);
$report = null;
$grantable = [];
try {
    $report = PublishersReport::read($db, $accountId, (int) $time['from'], (int) $time['to']);
    $grantable = (new PublisherCredit(new Connection($db)))->grantable($accountId);
} catch (\Throwable $failure) {
    error_log('Analyze publishers report failed: ' . $failure->getMessage());
    $report = null;
}
$publishers = $report['publishers'] ?? [];
$uncredited = $report['uncredited'] ?? null;
$unrecordedLinks = $report['unrecorded_links'] ?? false;
$currency = $report['currency'] ?? 'USD';

// The download exports this page's window: the link carries it (ReportView).
require_once $rootPath . '/202-config/functions-report-prefs.php';
$download = '<a class="btn btn-secondary btn-sm" href="'
    . $e(p202_report_view_url($base . '/tracking202/analyze/publishers_download.php', p202_report_view_query([], [], $window)))
    . '"><i class="bi bi-file-earmark-spreadsheet"></i> Download to Excel</a>';

$metricLabels = ['Clicks', 'Click throughs', 'Leads', 'Conv. rate', 'Income', 'Cost', 'Net', 'ROI'];
/** @param array<string, mixed> $row */
$metricCells = static function (array $row) use ($e, $currency): string {
    $net = (float) $row['total_net'];
    $tone = $net > 0 ? 'text-success-emphasis' : ($net < 0 ? 'text-danger-emphasis' : '');
    $toned = static fn (string $text): string => $tone === '' ? $text : '<span class="' . $tone . '">' . $text . '</span>';
    $cells = [
        number_format((int) $row['total_clicks']),
        number_format((int) $row['total_click_throughs']),
        number_format((int) $row['total_leads']),
        number_format((float) $row['conv_rate'], 2) . '%',
        $e(dollar_format((float) $row['total_income'], $currency)),
        $e(dollar_format((float) $row['total_cost'], $currency)),
        $toned($e(dollar_format($net, $currency))),
        $toned(number_format((float) $row['roi'], 0) . '%'),
    ];

    return '<td class="num">' . implode('</td><td class="num">', $cells) . '</td>';
};
$empty = static function (string $icon, string $title, string $body, string $action = '', string $href = '') use ($e): string {
    return '<div class="p202-empty">'
        . '<i class="bi ' . $e($icon) . ' p202-empty__icon"></i>'
        . '<strong class="p202-empty__title">' . $e($title) . '</strong>'
        . '<div>' . $e($body) . '</div>'
        . ($action === '' ? '' : '<div class="p202-empty__action"><a class="btn btn-primary btn-sm" href="' . $e($href) . '">' . $e($action) . '</a></div>')
        . '</div>';
};

template_top('Analyze Your Publishers');
?>

<div class="p202-page-header">
    <div class="p202-page-header__icon"><i class="bi bi-people"></i></div>
    <div class="p202-page-header__text">
        <h1 class="p202-page-header__title">Publishers</h1>
        <p class="p202-page-header__desc">What each publisher's links did: clicks, leads and profit by publisher, and by link and campaign under each.</p>
    </div>
</div>

<?php if ($errors !== []) {
    echo p202_flash('bad', 'The range was not applied: ' . implode(' ', $errors) . ' The report still shows your previous range.');
} ?>

<?php echo p202_report_filter_bar([
    'action' => $self,
    'id' => 'publisher-range',
    'range' => $rangeSpec,
    'filters' => [],
    'aside' => $publishers === [] ? '' : $download,
    'note' => 'A click counts for the publisher whose link it came on. The range is shared with your other reports.',
]); ?>

<?php if ($report === null) { ?>
    <?php echo p202_flash('bad', 'The report could not be read. Try again in a moment; if it keeps happening, the server log says why.'); ?>
    <?php echo $empty('bi-exclamation-triangle', 'The report could not be read', 'This is not a statement that there were no clicks.'); ?>
<?php } elseif ($publishers === [] && $grantable === []) { ?>
    <?php echo $empty('bi-people', 'No publishers yet', 'A publisher is a user with the Publisher role. Add one, then make them a link in Get Links: every click on it is counted here.', 'Add a publisher', $base . '/202-account/user-management.php'); ?>
<?php } elseif ($publishers === []) { ?>
    <?php if ($window['range'] !== 'alltime') { ?>
        <?php echo $empty('bi-inbox', 'No clicks on a publisher link in this range', 'Nothing came in on a publisher\'s link between these dates.', 'Show all time', $self . '?range=alltime'); ?>
    <?php } else { ?>
        <?php echo $empty('bi-inbox', 'No clicks on a publisher link yet', 'Choose a publisher in Get Links to make their link. Every click on it is counted here.', 'Get a publisher link', $base . '/tracking202/setup/get_trackers.php'); ?>
    <?php } ?>
<?php } else { ?>
    <div class="p202-table-wrap">
        <table class="table table-hover p202-table" id="publisher-table">
            <caption class="visually-hidden">Publishers, each with their links and the campaign each link sent clicks to</caption>
            <thead>
                <tr>
                    <th scope="col">Publisher, link and campaign</th>
                    <?php foreach ($metricLabels as $label) { ?>
                        <th scope="col" class="num"><?php echo $e($label); ?></th>
                    <?php } ?>
                </tr>
            </thead>
            <?php foreach ($publishers as $publisher) { ?>
                <tbody>
                    <tr class="p202-table__group">
                        <th scope="rowgroup" colspan="9"><?php echo $e($publisher['name']); ?></th>
                    </tr>
                    <?php foreach ($publisher['rows'] as $row) { ?>
                        <tr>
                            <td>
                                <?php echo $row['link'] === null ? '<span class="text-secondary">Link not recorded</span>' : 'Link ' . $e($row['link']); ?>
                                <span class="text-secondary">&middot; <?php echo $row['campaign'] === null ? 'No campaign' : $e($row['campaign']); ?></span>
                            </td>
                            <?php echo $metricCells($row); ?>
                        </tr>
                    <?php } ?>
                    <tr class="fw-semibold">
                        <td>All of <?php echo $e($publisher['name']); ?></td>
                        <?php echo $metricCells($publisher); ?>
                    </tr>
                </tbody>
            <?php } ?>
            <tbody>
                <?php if ($uncredited !== null) { ?>
                    <tr>
                        <td><span class="text-secondary">Clicks on no publisher link</span></td>
                        <?php echo $metricCells($uncredited); ?>
                    </tr>
                <?php } ?>
                <tr class="p202-table__totals">
                    <td>Totals for report</td>
                    <?php echo $metricCells($report['totals']); ?>
                </tr>
            </tbody>
        </table>
    </div>
    <p class="form-text">A link is named by the number after <code>t202id=</code> in it.<?php echo $unrecordedLinks ? ' Clicks from before this version did not record their link, so they are listed as &ldquo;Link not recorded&rdquo; under their campaign.' : ''; ?></p>
<?php } ?>

<?php template_bottom();
