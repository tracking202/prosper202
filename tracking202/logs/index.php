<?php

/**
 * Conversion Logs: every conversion the account recorded, newest first, with
 * the filters and columns of the 1.9.56 page of the same address. Rows come
 * from the conversion ledger (Prosper202\Conversion\ConversionLog).
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
$knownRange = in_array($form['range'], \Tracking202\Report\ReportWindow::PRESETS, true)
    || $form['range'] === ConversionLog::CUSTOM_RANGE;
$range = $knownRange ? $form['range'] : ConversionLog::DEFAULT_RANGE;
// The picker refuses a date it cannot show; a typed one that is not a day is the range error.
$day = static fn (string $v): string => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) === 1 ? $v : '';

template_top('Conversion Logs');

echo '<div class="p202-page-header">'
    . '<div class="p202-page-header__icon"><i class="bi bi-journal-text"></i></div>'
    . '<div class="p202-page-header__text">'
    . '<h1 class="p202-page-header__title">Conversion Logs</h1>'
    . '<p class="p202-page-header__desc">Every conversion recorded on your account, newest first, including'
    . ' deleted ones and reversals. Open a click from Visitors to see why each of its conversions counts or not.</p>'
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
        . '<div>Correct the field marked above and apply again.</div></div></div></section>';
    template_bottom();
    exit;
}

if ($readFailed || $result === null) {
    echo '</div><div class="p202-panel__body">'
        . p202_flash('bad', 'The conversion log could not be read. Try again; if it keeps failing,'
            . ' check the database error log.')
        . '</div></section>';
    template_bottom();
    exit;
}

$total = $result['total'];
echo '<span class="p202-panel__sub">' . $e(number_format($total)) . ' conversion' . ($total === 1 ? '' : 's')
    . '</span>'
    . '</div><div class="p202-panel__body">';

$masked = \Prosper202\Report\CampaignDataMask::hidden();
$rows = [];
foreach ($result['rows'] as $row) {
    $type = $e($row['type']);
    if ($row['reverses_conv_id'] !== null) {
        $type .= '<div class="form-text">' . $e('Reverses conversion ' . $row['reverses_conv_id']) . '</div>';
    }
    $agent = $row['user_agent'] !== '' ? '<code class="small text-break">' . $e($row['user_agent']) . '</code>' : '—';
    $rows[] = [
        'click' => (string) $row['click_id'],
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

// The page links carry the filters, so page 2 is page 2 of the same list.
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
$pages = (int) ceil($total / ConversionLog::PER_PAGE);
echo p202_overview_pagination($pages, $read['page'], 'Conversion log pages', $view);

echo '</div></section>';

template_bottom();
