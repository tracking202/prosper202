<?php

declare(strict_types=1);

/**
 * Markup helpers for the LTV AJAX partials, which render inside Analyze ›
 * Customer LTV on the v2 shell (tracking202/analyze/ltv.php).
 *
 * Each helper returns a component of the Prosper202 standard, parts included,
 * as 202-account/ui-kit.php renders it (error pattern #19): tabs, KPI tiles,
 * panels, pills, filter pills, empty states, flashes and a pager. The partials
 * used to carry a private stylesheet of `.ltv-*` classes with fixed light
 * colours; those are gone, so the section follows the theme like every other
 * v2 page. Element ids, form field names and JS handlers stay in the partials,
 * so every backend contract is unchanged.
 *
 * All helpers RETURN strings; partials echo them. Dynamic text is escaped
 * here unless a parameter is documented as trusted HTML ($actionsHtml,
 * $hintHtml, $messageHtml).
 */

require_once __DIR__ . '/ltv_helpers.php';

/**
 * Kept so a partial that still asks for the old scoped stylesheet gets
 * nothing rather than a fatal: the component layer styles everything now.
 */
function p202_ltv_ui_styles(): string
{
    return '';
}

/**
 * Section tabs shown on every top-level LTV view: the kit's .p202-tabs.
 * Real links (to the view's URL), so a middle-click or a copied link works;
 * a plain click is routed in place by ltvNav().
 *
 * Each link carries the window the view is drawn for, as ltvNav() puts it on
 * the address bar (range, then from/to for a custom one): without it a copied
 * or middle-clicked tab opened ltv.php with no window, which shows the stored
 * one — whatever another page stored since, not the one on screen.
 *
 * @param array<string, string>|null $window  range/from/to as query values;
 *   null reads the window this request is drawn for (p202_ltv_window_query())
 */
function p202_ltv_tabs(string $active, ?array $window = null): string
{
    $tabs = [
        'report' => ['bi-graph-up', 'Report'],
        'subscriptions' => ['bi-arrow-repeat', 'Subscriptions'],
        'products' => ['bi-cart', 'Products'],
        'companies' => ['bi-building', 'Companies'],
        'settings' => ['bi-gear', 'Settings'],
    ];
    $window ??= p202_ltv_window_query();
    $base = get_absolute_url() . 'tracking202/analyze/ltv.php';
    $html = '<nav class="nav p202-tabs" aria-label="Customer LTV views">';
    foreach ($tabs as $view => [$icon, $label]) {
        $current = $view === $active;
        $query = http_build_query($window + ($view === 'report' ? [] : ['view' => $view]));
        $html .= '<a class="nav-link' . ($current ? ' active' : '') . '"'
            . ($current ? ' aria-current="page"' : '')
            . ' href="' . p202_ltv_esc($base . ($query === '' ? '' : '?' . $query)) . '"'
            . ' onclick="ltvNav(\'' . $view . '\'); return false;">'
            . '<i class="bi ' . $icon . ' me-1"></i>' . p202_ltv_esc($label) . '</a>';
    }

    return $html . '</nav>';
}

/**
 * The window an LTV view is drawn for, as the query values ltv.php reads: a
 * preset's name, or `custom` with its two days. Every LTV partial reads its
 * window through grab_timeframe(), so this is the one on screen.
 *
 * @param array<string, mixed>|null $time  grab_timeframe(); read when null
 * @return array<string, string>
 */
function p202_ltv_window_query(?array $time = null): array
{
    $time ??= grab_timeframe();
    $range = (string) ($time['user_pref_time_predefined'] ?? '');
    if (in_array($range, \Tracking202\Report\ReportFilterInput::RANGES, true)) {
        return ['range' => $range];
    }
    $from = (int) ($time['from'] ?? 0);
    $to = (int) ($time['to'] ?? 0);
    if ($from <= 0 || $to <= 0) {
        return [];
    }
    return ['range' => \Tracking202\Report\ReportFilterInput::RANGE_CUSTOM, 'from' => date('Y-m-d', $from), 'to' => date('Y-m-d', $to)];
}

/**
 * KPI tile. $tone: '' | 'good' | 'bad'. Wrap a set in
 * <div class="p202-tiles">.
 */
function p202_ltv_stat(string $label, string $value, string $sub = '', string $tone = ''): string
{
    $toneClass = ['good' => ' is-good', 'bad' => ' is-bad'][$tone] ?? '';

    return '<div class="p202-tile' . $toneClass . '">'
        . '<div class="p202-tile__label">' . p202_ltv_esc($label) . '</div>'
        . '<div class="p202-tile__value">' . p202_ltv_esc($value) . '</div>'
        . ($sub !== '' ? '<div class="p202-tile__sub">' . p202_ltv_esc($sub) . '</div>' : '')
        . '</div>';
}

/**
 * Panel opener. $actionsHtml is trusted markup built by the partial
 * (links/buttons with escaped content), placed in the panel's aside.
 */
function p202_ltv_card_open(string $title = '', string $sub = '', string $actionsHtml = ''): string
{
    $html = '<section class="p202-panel mb-3">';
    if ($title !== '' || $sub !== '' || $actionsHtml !== '') {
        $html .= '<div class="p202-panel__head">'
            . ($title !== '' ? '<h2 class="p202-panel__title">' . p202_ltv_esc($title) . '</h2>' : '')
            . ($sub !== '' ? '<span class="p202-panel__sub">' . p202_ltv_esc($sub) . '</span>' : '')
            . ($actionsHtml !== '' ? '<div class="p202-panel__aside">' . $actionsHtml . '</div>' : '')
            . '</div>';
    }

    return $html;
}

function p202_ltv_card_close(): string
{
    return '</section>';
}

/**
 * Pill. $tone: green | blue | red | amber | gray — the component's good,
 * accent, bad, warn and neutral.
 */
function p202_ltv_pill(string $text, string $tone = 'gray'): string
{
    $modifier = [
        'green' => ' p202-pill--good',
        'blue' => ' p202-pill--accent',
        'red' => ' p202-pill--bad',
        'amber' => ' p202-pill--warn',
    ][$tone] ?? '';

    return '<span class="p202-pill' . $modifier . '">' . p202_ltv_esc($text) . '</span>';
}

/**
 * Status → pill with a sensible tone for every lifecycle string used across
 * subscriptions, webhooks, deliveries, integrations and customers.
 */
function p202_ltv_status_pill(string $status): string
{
    $tone = match ($status) {
        'active', 'delivered' => 'green',
        'trialing', 'pending' => 'blue',
        'past_due', 'dead', 'failed' => 'red',
        'paused' => 'amber',
        default => 'gray', // canceled, anonymized, unknown
    };

    return p202_ltv_pill(str_replace('_', ' ', $status), $tone);
}

/**
 * Empty state with its parts ($icon is a Bootstrap Icons class like
 * 'bi-people'). $hintHtml is trusted markup.
 */
function p202_ltv_empty(string $icon, string $title, string $hintHtml = ''): string
{
    return '<div class="p202-empty"><i class="bi ' . p202_ltv_esc($icon) . ' p202-empty__icon"></i>'
        . '<strong class="p202-empty__title">' . p202_ltv_esc($title) . '</strong>'
        . ($hintHtml !== '' ? '<div>' . $hintHtml . '</div>' : '')
        . '</div>';
}

/**
 * Flash message. $tone: success | error | info | warn. $messageHtml is
 * trusted markup — callers escape their dynamic parts.
 */
function p202_ltv_flash(string $tone, string $messageHtml): string
{
    [$variant, $icon] = [
        'success' => ['alert-success', 'bi-check-circle'],
        'error' => ['alert-danger', 'bi-x-circle'],
        'warn' => ['alert-warning', 'bi-exclamation-triangle'],
    ][$tone] ?? ['alert-info', 'bi-info-circle'];

    return '<div class="alert ' . $variant . ' p202-flash" role="status"><i class="bi ' . $icon . '"></i>'
        . '<div class="p202-flash__body">' . $messageHtml . '</div></div>';
}

/**
 * Panel-footer pager: "Showing X–Y of Z" plus Previous/Next buttons calling
 * $jsFn(newOffset). Renders nothing when everything fits on one page and
 * we're on it.
 */
function p202_ltv_pager(int $offset, int $limit, int $total, string $jsFn): string
{
    if ($total <= $limit && $offset === 0) {
        return '';
    }
    $from = $total > 0 ? min($offset + 1, $total) : 0;
    $to = min($offset + $limit, $total);
    $html = '<div class="p202-panel__body"><div class="p202-table-toolbar mb-0">'
        . '<span class="text-secondary small">Showing ' . number_format($from) . '&ndash;' . number_format($to)
        . ' of ' . number_format($total) . '</span><div class="p202-table-toolbar__aside">';
    if ($offset > 0) {
        $html .= '<button type="button" class="btn btn-secondary btn-sm" onclick="' . $jsFn . '(' . max(0, $offset - $limit) . ');">'
            . '<i class="bi bi-chevron-left"></i> Previous</button>';
    }
    if ($offset + $limit < $total) {
        $html .= '<button type="button" class="btn btn-secondary btn-sm" onclick="' . $jsFn . '(' . ($offset + $limit) . ');">'
            . 'Next <i class="bi bi-chevron-right"></i></button>';
    }

    return $html . '</div></div></div>';
}

/**
 * A row of switch pills bound to a hidden input, so existing JS that reads
 * $('#id').val() keeps working unchanged. Each pill is an <a>, as the kit's
 * groupings are, with the current one accented; choosing one stores its value
 * and runs $jsOnSelect (e.g. 'ltvLoad(0)').
 *
 * @param array<string|int, string> $options
 */
function p202_ltv_chips(string $inputId, array $options, string $current, string $jsOnSelect): string
{
    $esc = p202_ltv_esc(...);
    $html = '<input type="hidden" id="' . $esc($inputId) . '" value="' . $esc($current) . '">'
        . '<div class="p202-toolbar" role="group" data-ltv-chips="' . $esc($inputId) . '">';
    foreach ($options as $value => $label) {
        $value = (string) $value;
        $selected = $value === $current;
        $html .= '<a href="#" role="button" class="p202-pill' . ($selected ? ' p202-pill--accent' : '') . '"'
            . ($selected ? ' aria-pressed="true"' : ' aria-pressed="false"')
            . ' data-value="' . $esc($value) . '"'
            . ' onclick="document.getElementById(\'' . $esc($inputId) . '\').value = this.getAttribute(\'data-value\'); ' . $jsOnSelect . ' return false;">'
            . $esc($label) . '</a>';
    }

    return $html . '</div>';
}
