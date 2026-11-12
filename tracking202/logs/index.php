<?php

/**
 * Conversion Logs: every conversion the account recorded, newest first, with
 * the filters and columns of the 1.9.56 page of the same address. Rows come
 * from the conversion ledger (Prosper202\Conversion\ConversionLog). Each subid
 * opens its click, each row links the requests the Inbound Log holds for it,
 * and ?download=csv is the list on screen, every page of it, as a file.
 */

declare(strict_types=1);

include_once dirname(__DIR__, 2) . '/202-config/connect.php';

AUTH::require_user();
// The 1.9.56 page's gate: the log shows visitors' addresses and user agents.
AUTH::require_permissions('access_to_setup_section');
AUTH::set_timezone($_SESSION['user_timezone']);

require_once dirname(__DIR__, 2) . '/202-config/functions-ui-overview.php';

use Prosper202\Conversion\ConversionLog;
use Prosper202\Conversion\Ledger\ConversionSource;

$base = get_absolute_url();
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$userId = (int) $_SESSION['user_own_id'];
$log = new ConversionLog(new \Prosper202\Database\Connection($db));

$read = ConversionLog::readQuery($_GET, date_default_timezone_get(), time());
$form = $read['form'];
$errors = $read['errors'];

// Read before the page is drawn, so a failed read is said in the panel.
$campaigns = [];
$result = null;
$readFailed = false;
try {
    $campaigns = $log->campaigns($userId);
    if ($errors === []) {
        $result = $log->page($userId, $read['filters'], $read['page']);
    }
} catch (\Prosper202\Database\Exceptions\QueryException $failed) {
    error_log('Conversion Logs: ' . $failed->getMessage());
    $readFailed = true;
}

$masked = \Prosper202\Report\CampaignDataMask::hidden();
$knownRange = in_array($form['range'], \Tracking202\Report\ReportWindow::PRESETS, true)
    || $form['range'] === ConversionLog::CUSTOM_RANGE;
$range = $knownRange ? $form['range'] : ConversionLog::DEFAULT_RANGE;
// The filters as a query, so page 2 and the download are of the same list.
$view = http_build_query(array_filter([
    'range' => $range,
    'from' => $range === ConversionLog::CUSTOM_RANGE ? $form['from'] : '',
    'to' => $range === ConversionLog::CUSTOM_RANGE ? $form['to'] : '',
    'campaign_id' => $form['campaign_id'],
    'click_id' => $form['click_id'],
    'type' => $form['type'],
    'deleted' => $form['deleted'],
    'time_basis' => $form['time_basis'] !== 'conversion' ? $form['time_basis'] : '',
], static fn (string $v): bool => $v !== ''));

// Download CSV: the filtered list, whole. A filter that was refused, or a
// list that could not be read, is said on the page instead of in a file.
if (($_GET['download'] ?? null) === 'csv' && $errors === [] && !$readFailed) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="conversion-logs-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        throw new \RuntimeException('Conversion Logs: the download could not be opened');
    }
    // A long list is read in batches and streamed; the request's time limit is the list's length, not a fault.
    set_time_limit(0);
    // An install may hold a download to fewer rows in 202-config.php.
    $csvMax = defined('P202_CONVERSION_LOGS_CSV_MAX') && is_int(P202_CONVERSION_LOGS_CSV_MAX) && P202_CONVERSION_LOGS_CSV_MAX > 0
        ? min(P202_CONVERSION_LOGS_CSV_MAX, ConversionLog::EXPORT_MAX) : ConversionLog::EXPORT_MAX;
    \Prosper202\Conversion\ConversionLogCsv::write($out, $log, $userId, $read['filters'], $masked, $csvMax);
    exit;
}

$types = [];
foreach (ConversionSource::cases() as $source) {
    $types[$source->value] = $source->label();
}
$filters = [
    [
        'name' => 'campaign_id', 'label' => 'Campaign', 'type' => 'select', 'any' => 'All campaigns',
        'options' => $campaigns, 'value' => $form['campaign_id'], 'error' => $errors['campaign_id'] ?? '',
    ],
    [
        'name' => 'click_id', 'label' => 'Subid', 'type' => 'search', 'placeholder' => 'Click id',
        'value' => $form['click_id'], 'error' => $errors['click_id'] ?? '',
    ],
    [
        'name' => 'type', 'label' => 'Type', 'type' => 'select', 'any' => 'All types', 'options' => $types,
        'value' => $form['type'], 'error' => $errors['type'] ?? '', 'advanced' => true,
    ],
    [
        'name' => 'deleted', 'label' => 'Deleted', 'type' => 'select', 'any' => 'Live and deleted',
        'options' => ConversionLog::DELETED, 'value' => $form['deleted'], 'error' => $errors['deleted'] ?? '',
        'advanced' => true,
    ],
    [
        'name' => 'time_basis', 'label' => 'Date range applies to', 'type' => 'select', 'any' => null,
        'default' => 'conversion', 'options' => ConversionLog::TIME_BASES, 'value' => $form['time_basis'],
        'error' => $errors['time_basis'] ?? '', 'advanced' => true,
    ],
];
// The picker refuses a date it cannot show; a typed one that is not a day is the range error.
$day = static fn (string $v): string => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) === 1 ? $v : '';

template_top('Conversion Logs');

echo '<div class="p202-page-header">'
    . '<div class="p202-page-header__icon"><i class="bi bi-journal-text"></i></div>'
    . '<div class="p202-page-header__text">'
    . '<h1 class="p202-page-header__title">Conversion Logs</h1>'
    . '<p class="p202-page-header__desc">Every conversion recorded on your account, newest first, including'
    . ' deleted ones and reversals. Open a subid to see why each of its conversions counts or not.</p>'
    . '</div></div>';

echo p202_report_filter_bar([
    'action' => $base . 'tracking202/logs/',
    'id' => 'logs-filters',
    'range' => [
        'range' => $range, 'from' => $day($form['from']), 'to' => $day($form['to']),
        'custom' => ConversionLog::CUSTOM_RANGE, 'error' => $errors['range'] ?? '',
    ],
    'filters' => $filters,
    'reset' => $base . 'tracking202/logs/',
]);

echo '<section class="p202-panel" aria-labelledby="logs-title"><div class="p202-panel__head">'
    . '<h2 class="p202-panel__title" id="logs-title">Conversions</h2>';

if ($errors !== []) {
    echo '</div><div class="p202-panel__body"><div class="p202-empty"><i class="bi bi-funnel p202-empty__icon"></i>'
        . '<strong class="p202-empty__title">These filters were not applied</strong>'
        . '<div>' . $e(isset($errors['offset']) && count($errors) === 1
            ? $errors['offset'] . ' Apply the filters again to start from the first page.'
            : 'Correct the field marked above and apply again.') . '</div></div></div></section>';
    template_bottom();
    exit;
}

if ($readFailed || $result === null) {
    echo '</div><div class="p202-panel__body">'
        . p202_flash('bad', 'The conversions could not be read just now, so none are shown; nothing was changed.'
            . ' Reload the page. If it keeps failing, the server\'s error log has the reason (a database upgrade may be waiting).')
        . '</div></section>';
    template_bottom();
    exit;
}

$total = $result['total'];
echo '<span class="p202-panel__sub">' . $e(number_format($total)) . ' conversion' . ($total === 1 ? '' : 's')
    . '</span>'
    . ($total > 0 ? '<div class="p202-panel__aside"><a class="btn btn-secondary btn-sm" id="conversion-logs-csv" href="'
        . $e($base . 'tracking202/logs/?' . ($view !== '' ? $view . '&' : '') . 'download=csv')
        . '"><i class="bi bi-file-earmark-spreadsheet"></i> Download CSV</a></div>' : '')
    . '</div><div class="p202-panel__body">';

$inboundLog = $base . 'tracking202/setup/inbound_log.php?click_id=';
$rows = [];
foreach ($result['rows'] as $row) {
    $type = $e($row['type']);
    if ($row['reverses_conv_id'] !== null) {
        $type .= '<div class="form-text">' . $e('Reverses conversion ' . $row['reverses_conv_id']) . '</div>';
    }
    $agent = $row['user_agent'] !== '' ? '<code class="small text-break">' . $e($row['user_agent']) . '</code>' : '—';
    $rows[] = [
        'click' => ['html' => p202_click_breakdown_button($base, $row['click_id'])
            . ' <a class="small text-nowrap" href="' . $e($inboundLog . $row['click_id']) . '">its inbound requests</a>'],
        'campaign' => $row['campaign_name'] ?? '—',
        'click_time' => $row['click_time'] > 0 ? date('m/d/y g:ia', $row['click_time']) : '—',
        'conv_time' => date('m/d/y g:ia', $row['conv_time']),
        'elapsed' => $row['elapsed'] ?? '—',
        'ip' => $row['ip'] !== '' ? $row['ip'] : '—',
        'type' => ['html' => $type],
        'transaction' => $row['transaction_id'] ?? '—',
        'amount' => $masked ? '?' : (string) dollar_format((float) $row['amount']),
        'deleted' => ['html' => $row['deleted'] ? '<span class="p202-pill p202-pill--bad">deleted</span>' : 'no'],
        'user_agent' => ['html' => $agent],
    ];
}

echo p202_data_table([
    ['key' => 'click', 'label' => 'Subid'],
    ['key' => 'campaign', 'label' => 'Campaign'],
    ['key' => 'click_time', 'label' => 'Click time'],
    ['key' => 'conv_time', 'label' => 'Conversion time'],
    ['key' => 'elapsed', 'label' => 'Elapsed'],
    ['key' => 'ip', 'label' => 'IP address'],
    ['key' => 'type', 'label' => 'Type'],
    ['key' => 'transaction', 'label' => 'Transaction ID'],
    ['key' => 'amount', 'label' => 'Payout', 'num' => true],
    ['key' => 'deleted', 'label' => 'Deleted'],
    ['key' => 'user_agent', 'label' => 'User agent'],
], $rows, [
    'id' => 'conversion-logs-table',
    'caption' => 'Conversions recorded on your account, newest first',
    'empty' => [
        'icon' => 'bi-receipt', 'title' => 'No conversions match',
        'body' => 'Widen the date range or clear a filter above.',
    ],
]);

$pages = (int) ceil($total / ConversionLog::PER_PAGE);
echo p202_overview_pagination($pages, $result['page'], 'Conversion log pages', $view);

echo '</div></section>';
echo p202_click_breakdown_modal($base);

template_bottom();
