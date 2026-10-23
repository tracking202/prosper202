<?php

declare(strict_types=1);

/**
 * One Analyze report: keywords, text ads, referers, IPs, countries, regions,
 * cities, ISPs, landing pages, devices, browsers, platforms or custom
 * variables. Rendered by AnalyzeReportController on the v2 shell, so no
 * Bootstrap 3 or Flat UI class belongs here
 * (tests/Api/V3/NoLegacyBootstrapClassesTest scans this file).
 *
 * The filters are one GET form and every link on the page carries the whole
 * report in its query string, so what someone is looking at is a URL they
 * can send.
 *
 * @var array<string, mixed> $analyze  built by AnalyzeReportController
 */

use Tracking202\Analyze\AnalyzeReportController as Report;
use Tracking202\Report\ReportFilterInput as Input;

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
// DataEngine's formatter hands every value over HTML-escaped (it was written
// for markup); decode once so p202_data_table() escapes it exactly once.
$plain = static fn (mixed $v): string => html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML401, 'UTF-8');

$info = $analyze['report'];
$type = (string) $analyze['type'];
$self = (string) $analyze['self'];
$offered = $analyze['offered'];
$values = $analyze['values'];
$window = $analyze['window'];
$result = $analyze['result'];
$order = (string) $analyze['order'];
$ranges = p202_report_ranges();

/**
 * A link to this report with some of it changed and the rest kept. Only what
 * differs from "not filtering" is written, and the window always is, which
 * is what makes the link speak for every filter (ReportFilterInput).
 */
$link = static function (array $changes = []) use ($self, $values, $window, $order, $offered): string {
    $pick = static fn (string $key, mixed $current): mixed
        => array_key_exists($key, $changes) ? $changes[$key] : $current;
    $range = (string) $pick('range', $window['range']);
    $query = ['range' => $range];
    if ($range === Input::RANGE_CUSTOM) {
        $query['from'] = $pick('from', $window['from']);
        $query['to'] = $pick('to', $window['to']);
    }
    foreach ($offered as $field) {
        $value = (string) $pick($field, $values[$field] ?? '');
        $default = isset(Input::CHOICES[$field]) ? Input::CHOICES[$field][1] : '';
        if ($value !== '' && $value !== $default) {
            $query[$field] = $value;
        }
    }
    $query['order'] = $pick('order', $order);
    $query['page'] = $changes['page'] ?? null;
    $query = array_filter($query, static fn ($v): bool => $v !== null && $v !== '' && $v !== 1);
    return $self . '?' . http_build_query($query);
};

// ── The filter bar ──────────────────────────────────────────────────────
// After a refusal the bar shows what was submitted, with the reason under
// the field, so it can be corrected; otherwise what is in force.
$submitted = $analyze['submitted'];
$errors = $analyze['errors'];
$shown = $submitted === null ? $values : $submitted['values'];
$names = $analyze['names'];

$lists = $analyze['lists'] + ['region_id' => [], 'isp_id' => []];
$specs = p202_report_filters($shown, $lists, $offered);
foreach ($specs as $index => $spec) {
    $field = $spec['name'];
    $named = array_search($field, Input::NAMED_FIELDS, true);
    if ($named !== false) {
        // Install-wide and unbounded: a typed name with suggestions from
        // this account's own clicks, never a menu of every region on earth.
        $specs[$index] = [
            'name' => (string) $named,
            'label' => $spec['label'],
            'type' => 'suggest',
            'value' => $names[$named] ?? '',
            'advanced' => true,
            'placeholder' => 'Start typing',
            'suggestions' => $analyze['suggestions'][$named] ?? [],
            'hint' => $named === 'region' ? 'Suggestions are the regions your clicks in this range came from.' : 'Suggestions are the networks your clicks in this range came through.',
        ];
    }
    if (isset($errors[$field])) {
        $specs[$index]['error'] = $errors[$field];
    }
}

$range = $window;
if ($submitted !== null && $submitted['window'] !== null) {
    $w = $submitted['window'];
    $range = [
        'range' => $w['range'],
        'from' => $w['from'] === null ? $window['from'] : vsprintf('%04d-%02d-%02d', $w['from']),
        'to' => $w['to'] === null ? $window['to'] : vsprintf('%04d-%02d-%02d', $w['to']),
    ];
}
$rangeSpec = ['range' => $range['range'], 'from' => $range['from'], 'to' => $range['to']];
if (isset($errors['range'])) {
    $rangeSpec['error'] = $errors['range'];
}

$clicksLabel = [
    'all' => 'all clicks', 'real' => 'real clicks', 'filtered' => 'filtered-out clicks',
    'filtered_bot' => 'filtered-out bot clicks', 'leads' => 'converted clicks',
][$values['user_pref_show'] ?? 'all'] ?? 'all clicks';
$rangeLabel = $window['range'] === Input::RANGE_CUSTOM
    ? $window['from'] . ' to ' . $window['to']
    : ($ranges[$window['range']] ?? $window['range']);
$note = $rangeLabel . ' · ' . $clicksLabel . ' · ' . ($values['user_pref_limit'] ?? '50') . ' rows a page · '
    . strtoupper((string) ($values['user_cpc_or_cpv'] ?? 'cpc')) . ' costs. What you apply here becomes your default on every report.';

$hidden = [];
if ($order !== '') {
    $hidden['order'] = $order;
}

$download = '<a class="btn btn-secondary btn-sm" href="' . $e($analyze['downloadUrl']) . '"><i class="bi bi-file-earmark-spreadsheet"></i> Download to Excel</a>';

// ── Cells ───────────────────────────────────────────────────────────────

/** The value a metric sorts by: "$1,304.00" by 1304, "(12.50)" by -12.5. */
$sortValue = static function (string $display, bool $isCost): ?float {
    $display = trim($display);
    if ($display === '' || $display === '?') {
        return null;
    }
    $negative = !$isCost && (str_starts_with($display, '(') || str_contains($display, '-'));
    $digits = preg_replace('/[^0-9.]/', '', $display);
    if ($digits === '' || $digits === null || !is_numeric($digits)) {
        return null;
    }
    return $negative ? -(float) $digits : (float) $digits;
};

$metricCell = static function (string $id, array $cell) use ($e, $plain, $sortValue): array {
    $display = $plain($cell['display'] ?? '');
    $tone = (string) ($cell['tone'] ?? 'default');
    $sort = $sortValue($display, $id === 'cost');
    if (($id === 'net' || $id === 'roi') && ($tone === 'primary' || $tone === 'important')) {
        $class = $tone === 'primary' ? 'text-success-emphasis' : 'text-danger-emphasis';
        return ['html' => '<span class="' . $class . '">' . $e($display) . '</span>', 'sort' => $sort];
    }
    return ['text' => $display, 'sort' => $sort];
};

$featureCell = static function (array $feature) use ($e, $plain): array {
    $text = $plain($feature['text'] ?? '');
    $title = $plain($feature['title'] ?? $text);
    switch ($feature['variant'] ?? 'plain_text') {
        case 'truncated_text':
            $width = (int) ($feature['maxWidthPx'] ?? 250);
            return ['html' => '<span class="d-inline-block text-truncate align-bottom" style="max-width: ' . $width . 'px" title="' . $e($title) . '">' . $e($text) . '</span>', 'sort' => $text];
        case 'flagged_location':
            return ['html' => '<img src="' . $e($feature['flagUrl'] ?? '') . '" alt="" width="16" height="11" class="me-1 align-baseline">' . $e($text), 'sort' => $text];
        default:
            return ['text' => $text, 'sort' => $text];
    }
};

$empty = static function (string $icon, string $title, string $body, string $action = '', string $href = '') use ($e): string {
    return '<div class="p202-empty">'
        . '<i class="bi ' . $e($icon) . ' p202-empty__icon"></i>'
        . '<strong class="p202-empty__title">' . $e($title) . '</strong>'
        . '<div>' . $e($body) . '</div>'
        . ($action === '' ? '' : '<div class="p202-empty__action"><a class="btn btn-primary btn-sm" href="' . $e($href) . '">' . $e($action) . '</a></div>')
        . '</div>';
};

$anyFilter = false;
foreach ($offered as $field) {
    $default = isset(Input::CHOICES[$field]) ? Input::CHOICES[$field][1] : '';
    if (!in_array($field, ['user_pref_limit', 'user_cpc_or_cpv'], true) && ($values[$field] ?? '') !== '' && ($values[$field] ?? '') !== $default) {
        $anyFilter = true;
    }
}
$resetUrl = $link(array_fill_keys($offered, '') + ['order' => '']);
$noRows = static function () use ($empty, $anyFilter, $resetUrl, $window, $link): string {
    if ($anyFilter) {
        return $empty('bi-funnel', 'No clicks match these filters', 'The range has clicks the filters leave out. Clearing them keeps the range.', 'Clear the filters', $resetUrl);
    }
    if ($window['range'] !== 'alltime') {
        return $empty('bi-inbox', 'No clicks in this range', 'Nothing was tracked between these dates.', 'Show all time', $link(['range' => 'alltime', 'page' => null]));
    }
    return $empty('bi-inbox', 'No clicks yet', 'Once a tracking link receives traffic, this report fills in.');
};

template_top((string) $info['title']);
?>

<div class="p202-page-header">
    <div class="p202-page-header__icon"><i class="bi <?php echo $e($info['icon']); ?>"></i></div>
    <div class="p202-page-header__text">
        <h1 class="p202-page-header__title"><?php echo $e($info['heading']); ?></h1>
        <p class="p202-page-header__desc"><?php echo $e($info['description']); ?></p>
    </div>
</div>

<?php foreach ($analyze['flashes'] as $flash) {
    echo p202_flash($flash['kind'], $flash['text']);
} ?>

<?php echo p202_report_filter_bar([
    'action' => $self,
    'id' => 'report-filters',
    'hidden' => $hidden,
    'range' => $rangeSpec,
    'filters' => $specs,
    'reset' => $resetUrl,
    'note' => $note,
    'aside' => $download,
    'remember' => 'analyze-report-advanced',
]); ?>

<?php if ($result === null) { ?>
    <?php echo $empty('bi-exclamation-triangle', 'The report could not be read', 'The message above says why. This is not a statement that there were no clicks.'); ?>

<?php } elseif ($type === 'variable') { ?>
    <?php $groups = $result['groups'];
    $totals = $result['totals']; ?>
    <?php if ($groups === []) { ?>
        <?php echo $noRows(); ?>
    <?php } else { ?>
        <?php
        $metricKeys = ['clicks', 'click_out', 'ctr', 'leads', 'su_ratio', 'payout', 'epc', 'cpc', 'income', 'cost', 'net', 'roi'];
        $metricLabels = array_column(Report::METRICS, 0);
        $tone = static function (string $key, string $display) use ($e): string {
            if ($key !== 'net' && $key !== 'roi') {
                return $e($display);
            }
            $n = trim($display);
            if ($n === '' || preg_replace('/[^1-9]/', '', $n) === '') {
                return $e($display);
            }
            $class = str_starts_with($n, '(') || str_starts_with($n, '-') ? 'text-danger-emphasis' : 'text-success-emphasis';
            return '<span class="' . $class . '">' . $e($display) . '</span>';
        };
        ?>
        <div class="p202-table-wrap">
            <table class="table table-hover p202-table" id="stats-table">
                <caption class="visually-hidden">Custom variables by traffic source, with every value each one took</caption>
                <thead>
                    <tr>
                        <th scope="col"><?php echo $e($info['feature']); ?></th>
                        <?php foreach ($metricLabels as $label) { ?>
                            <th scope="col" class="num"><?php echo $e($label); ?></th>
                        <?php } ?>
                    </tr>
                </thead>
                <?php foreach ($groups as $group) { ?>
                    <tbody>
                        <tr class="p202-table__group">
                            <th scope="colgroup" colspan="13"><?php echo $e($plain($group['source'])); ?> <span class="text-secondary">· <?php echo $e($plain($group['variable'])); ?></span></th>
                        </tr>
                        <?php foreach ($group['values'] as $row) { ?>
                            <tr>
                                <td><?php echo $e($plain($row['variable_value'] ?? '')); ?></td>
                                <?php foreach ($metricKeys as $key) { ?>
                                    <td class="num"><?php echo $tone($key, $plain($row[$key] ?? '')); ?></td>
                                <?php } ?>
                            </tr>
                        <?php } ?>
                    </tbody>
                <?php } ?>
                <?php if ($totals !== null) { ?>
                    <tbody>
                        <tr class="p202-table__totals">
                            <td>Totals for report</td>
                            <?php foreach ($metricKeys as $key) { ?>
                                <td class="num"><?php echo $tone($key, $plain($totals['total_' . $key] ?? '')); ?></td>
                            <?php } ?>
                        </tr>
                    </tbody>
                <?php } ?>
            </table>
        </div>
    <?php } ?>

<?php } else { ?>
    <?php
    $pagination = $result['pagination'];
    $pages = (int) $pagination['pageCount'];
    $current = (int) $pagination['currentOffset'] + 1;
    $restricted = (bool) ($result['access']['campaignDataRestricted'] ?? false);

    // A table that is all on this page sorts in the browser, any column; one
    // that runs to more pages sorts on the server, by link, because sorting
    // one page of rows would reorder that page and nothing else.
    $clientSort = $pages <= 1;
    $sorted = Report::DEFAULT_SORT;
    if ($order !== '' && preg_match('/^sort_breakdown_(\w+) (asc|desc)$/', $order, $m) === 1) {
        foreach (Report::METRICS as $key => [$label, $token]) {
            if ($token === $m[1]) {
                $sorted = ['key' => $key, 'dir' => $m[2] === 'asc' ? 'ascending' : 'descending'];
            }
        }
    }

    $columns = [['key' => 'feature', 'label' => $info['feature'], 'sort' => 'text']];
    foreach (Report::METRICS as $key => [$label, $token]) {
        $column = ['key' => $key, 'label' => $label, 'num' => true];
        if (!$clientSort) {
            // Most first on the first click; the other way on the next.
            $dir = $sorted['key'] === $key && $sorted['dir'] === 'descending' ? 'asc' : 'desc';
            $column['href'] = $link(['order' => 'sort_breakdown_' . $token . ' ' . $dir, 'page' => null]);
        }
        $columns[] = $column;
    }

    $rows = [];
    foreach ($result['rows'] as $row) {
        $cells = ['feature' => $featureCell($row['feature'])];
        foreach (array_keys(Report::METRICS) as $key) {
            $cells[$key] = $metricCell($key, $row['metrics'][$key] ?? []);
        }
        $rows[] = $cells;
    }
    $totals = ['feature' => $pages > 1 ? 'Totals for this page' : 'Totals for report'];
    foreach (array_keys(Report::METRICS) as $key) {
        $totals[$key] = $metricCell($key, $result['totals']['metrics'][$key] ?? []);
    }
    ?>

    <?php if ($rows === [] && !empty($pagination['outOfRange'])) { ?>
        <?php echo $empty('bi-inbox', 'This page is past the end of the report', 'The report has ' . $pages . ' ' . ($pages === 1 ? 'page' : 'pages') . ' at this many rows a page.', 'Go to the last page', $link(['page' => $pages])); ?>
    <?php } elseif ($rows === []) { ?>
        <?php echo $noRows(); ?>
    <?php } else { ?>
        <?php if ($restricted) { ?>
            <p class="text-secondary small"><i class="bi bi-lock"></i> Your account cannot see campaign figures, so clicks, leads and money show as “?”.</p>
        <?php } ?>
        <?php echo p202_data_table($columns, $rows, [
            'id' => 'stats-table',
            'caption' => $info['heading'] . ', ' . ($clientSort ? 'sortable by any column' : 'sorted on the server by the column links'),
            'sortable' => $clientSort,
            'sorted' => $sorted,
            'totals' => $totals,
        ]); ?>

        <?php if ($pages > 1) { ?>
            <div class="p202-table-toolbar mt-3">
                <nav aria-label="Report pages">
                    <ul class="pagination pagination-sm mb-0">
                        <?php $pageWindow = range(max(1, $current - 2), min($pages, $current + 2)); ?>
                        <li class="page-item<?php echo $current <= 1 ? ' disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $e($link(['page' => max(1, $current - 1)])); ?>" aria-label="Previous page"<?php echo $current <= 1 ? ' tabindex="-1" aria-disabled="true"' : ''; ?>>&lsaquo;</a>
                        </li>
                        <?php foreach ($pageWindow as $n) { ?>
                            <li class="page-item<?php echo $n === $current ? ' active' : ''; ?>"<?php echo $n === $current ? ' aria-current="page"' : ''; ?>>
                                <a class="page-link" href="<?php echo $e($link(['page' => $n])); ?>"><?php echo (int) $n; ?></a>
                            </li>
                        <?php } ?>
                        <li class="page-item<?php echo $current >= $pages ? ' disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $e($link(['page' => min($pages, $current + 1)])); ?>" aria-label="Next page"<?php echo $current >= $pages ? ' tabindex="-1" aria-disabled="true"' : ''; ?>>&rsaquo;</a>
                        </li>
                    </ul>
                </nav>
                <span class="text-secondary small">Page <?php echo $current; ?> of <?php echo $pages; ?> · <?php echo number_format((int) $pagination['totalRows']); ?> rows</span>
            </div>
        <?php } ?>
    <?php } ?>
<?php } ?>

<?php template_bottom();
