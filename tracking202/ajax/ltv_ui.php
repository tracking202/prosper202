<?php

declare(strict_types=1);

/**
 * Shared UI kit for the LTV AJAX partials: one scoped stylesheet plus small
 * HTML helpers (section tabs, stat tiles, cards, pills, chips, empty states,
 * flash messages, pagers) so all seven views share a single design language.
 *
 * Everything is namespaced under .ltv- classes so nothing leaks into the
 * surrounding Bootstrap/Flat-UI page, and the helpers only produce markup —
 * element ids, form field names and JS handlers stay in the partials, which
 * keeps every backend contract untouched.
 *
 * All helpers RETURN strings; partials echo them. Dynamic text is escaped
 * here unless a parameter is documented as trusted HTML ($actionsHtml).
 */

require_once __DIR__ . '/ltv_helpers.php';

/**
 * The scoped stylesheet, emitted once per response (partials and the merge
 * modal can both ask for it safely).
 */
function p202_ltv_ui_styles(): string
{
    static $emitted = false;
    if ($emitted) {
        return '';
    }
    $emitted = true;

    return <<<'CSS'
<style>
/* ---------- LTV design tokens (scoped, self-contained) ---------- */
.ltv-tabs { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; border-bottom: 1px solid #e7e8ea; margin: 2px 0 16px; }
.ltv-tab { display: inline-flex; align-items: center; gap: 7px; padding: 8px 13px; margin-bottom: -1px; font-size: 13px; color: #6b7280; text-decoration: none; border-bottom: 2px solid transparent; border-radius: 6px 6px 0 0; }
.ltv-tab:hover, .ltv-tab:focus { color: #1f2328; background: #f6f7f8; text-decoration: none; }
.ltv-tab.active { color: #2f6fdd; border-bottom-color: #2f6fdd; font-weight: 600; }
.ltv-tab .fa { font-size: 12px; opacity: .75; }

.ltv-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(148px, 1fr)); gap: 10px; margin-bottom: 16px; }
.ltv-stat { background: #fff; border: 1px solid #e7e8ea; border-radius: 10px; padding: 12px 14px; box-shadow: 0 1px 2px rgba(16,24,40,.04); min-width: 0; }
.ltv-stat-label { font-size: 11px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: #8a919b; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ltv-stat-value { font-size: 20px; font-weight: 600; color: #1f2328; font-variant-numeric: tabular-nums; line-height: 1.2; white-space: nowrap; }
.ltv-stat-sub { font-size: 11px; color: #8a919b; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ltv-stat.good .ltv-stat-value { color: #217a41; }
.ltv-stat.bad .ltv-stat-value { color: #c9372c; }

.ltv-card { background: #fff; border: 1px solid #e7e8ea; border-radius: 10px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(16,24,40,.04); min-width: 0; }
.ltv-card-head { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; padding: 13px 16px 2px; }
.ltv-card-title { font-size: 13.5px; font-weight: 600; color: #1f2328; }
.ltv-card-sub { font-size: 12px; color: #8a919b; }
.ltv-card-actions { margin-left: auto; font-size: 12px; white-space: nowrap; display: flex; gap: 12px; align-items: center; }
.ltv-card-body { padding: 10px 16px 14px; }
.ltv-card-body + .ltv-card-body { border-top: 1px solid #f0f1f2; }

.ltv-table-wrap { overflow-x: auto; padding: 6px 8px 8px; }
table.ltv-table { width: 100%; border-collapse: collapse; font-size: 13px; margin: 0; }
.ltv-table th { font-size: 11px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: #8a919b; text-align: left; padding: 8px 10px; border-bottom: 1px solid #e7e8ea; white-space: nowrap; }
.ltv-table th[role=columnheader]:not(.no-sort):after { border-color: #9aa1ab transparent; }
.ltv-table td { padding: 8px 10px; border-bottom: 1px solid #f0f1f2; color: #1f2328; vertical-align: middle; }
.ltv-table tbody tr:last-child td { border-bottom: 0; }
.ltv-table th.num, .ltv-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
.ltv-table-hover tbody tr:hover td { background: #f7f8f9; }
.ltv-row-link { cursor: pointer; }
.ltv-dim { color: #9ca3af; }
.ltv-strong { font-weight: 600; }
.ltv-neg { color: #c9372c; }
.ltv-pos { color: #217a41; }

.ltv-pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; line-height: 1.6; white-space: nowrap; }
.ltv-pill-green { background: #e7f5ec; color: #217a41; }
.ltv-pill-blue { background: #eaf2fc; color: #2f6fdd; }
.ltv-pill-red { background: #fdecec; color: #c9372c; }
.ltv-pill-amber { background: #fdf3e2; color: #b54708; }
.ltv-pill-gray { background: #f1f2f4; color: #6b7280; }

.ltv-chips { display: inline-flex; flex-wrap: wrap; gap: 6px; vertical-align: middle; }
.ltv-chip { display: inline-block; padding: 4px 12px; border: 1px solid #e0e2e5; border-radius: 999px; background: #fff; font-size: 12px; color: #5f6670; cursor: pointer; user-select: none; }
.ltv-chip:hover { border-color: #c9cdd3; color: #1f2328; background: #fafbfc; }
.ltv-chip.active { background: #eaf2fc; border-color: #bcd4f6; color: #2f6fdd; font-weight: 600; }

.ltv-btn { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border: 1px solid #dcdfe3; border-radius: 7px; background: #fff; color: #374151; font-size: 12.5px; font-weight: 500; cursor: pointer; line-height: 1.5; text-decoration: none; transition: background .12s, border-color .12s; }
.ltv-btn:hover, .ltv-btn:focus { background: #f6f7f8; border-color: #c9cdd3; color: #1f2328; text-decoration: none; outline: none; }
.ltv-btn .fa { font-size: 12px; opacity: .8; }
.ltv-btn-primary { background: #2f6fdd; border-color: #2f6fdd; color: #fff; }
.ltv-btn-primary:hover, .ltv-btn-primary:focus { background: #2861c4; border-color: #2861c4; color: #fff; }
.ltv-btn-danger { color: #c9372c; }
.ltv-btn-danger:hover, .ltv-btn-danger:focus { background: #fdecec; border-color: #f2c4c0; color: #b02a20; }
.ltv-btn-xs { padding: 2px 9px; font-size: 11.5px; border-radius: 6px; gap: 4px; }
.ltv-btn[disabled] { opacity: .45; cursor: default; pointer-events: none; }

.ltv-input, .ltv-select, select.ltv-select { -webkit-appearance: none; appearance: none; border: 1px solid #dcdfe3; border-radius: 7px; padding: 5px 10px; font-size: 13px; color: #1f2328; background-color: #fff; line-height: 1.5; box-shadow: none; }
select.ltv-select { padding-right: 24px; background-image: url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%236b7280' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 9px center; }
.ltv-input:focus, .ltv-select:focus { outline: none; border-color: #7fa8e8; box-shadow: 0 0 0 3px rgba(47,111,221,.12); }
.ltv-input::placeholder { color: #9ca3af; }
.ltv-input-sm { padding: 3px 8px; font-size: 12px; border-radius: 6px; }
.ltv-toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding: 8px 16px 4px; }
.ltv-toolbar .ltv-grow { flex: 1 1 220px; min-width: 160px; }

.ltv-empty { text-align: center; padding: 34px 20px; color: #8a919b; }
.ltv-empty .fa { font-size: 22px; color: #c4c9cf; display: block; margin-bottom: 9px; }
.ltv-empty-title { font-size: 13px; font-weight: 600; color: #5f6670; margin-bottom: 3px; }
.ltv-empty-hint { font-size: 12px; max-width: 560px; margin: 0 auto; line-height: 1.55; }
.ltv-empty-hint code { font-size: 11px; }

.ltv-flash { display: flex; gap: 10px; align-items: flex-start; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; border: 1px solid; line-height: 1.5; }
.ltv-flash .fa { margin-top: 2px; flex-shrink: 0; }
.ltv-flash-success { background: #f2faf5; border-color: #cdeeda; color: #1d6f3c; }
.ltv-flash-error { background: #fdf3f2; border-color: #f5d3d0; color: #b02a20; }
.ltv-flash-info { background: #f2f7fd; border-color: #d3e3f8; color: #2a5fb8; }
.ltv-flash-warn { background: #fdf8ee; border-color: #f3e3bd; color: #946300; }

.ltv-pager { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 16px; border-top: 1px solid #f0f1f2; font-size: 12px; color: #8a919b; flex-wrap: wrap; }
.ltv-pager-btns { display: flex; gap: 6px; }

.ltv-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
@media (max-width: 900px) { .ltv-cols { grid-template-columns: 1fr; } }
.ltv-cols > .ltv-card { margin-bottom: 0; }

.ltv-page-head { display: flex; align-items: center; gap: 13px; margin-bottom: 16px; flex-wrap: wrap; }
.ltv-avatar { width: 44px; height: 44px; border-radius: 50%; background: #eaf2fc; color: #2f6fdd; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 600; flex-shrink: 0; }
.ltv-page-title { font-size: 17px; font-weight: 600; color: #1f2328; line-height: 1.3; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ltv-page-sub { font-size: 12px; color: #8a919b; margin-top: 1px; }
.ltv-page-actions { margin-left: auto; display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.ltv-back { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: #6b7280; margin-bottom: 10px; }
.ltv-back:hover { color: #1f2328; text-decoration: none; }

.ltv-def { display: flex; gap: 12px; padding: 7px 0; border-bottom: 1px solid #f0f1f2; font-size: 13px; }
.ltv-def:last-child { border-bottom: 0; }
.ltv-def-label { flex: 0 0 34%; color: #8a919b; }
.ltv-def-label small { display: block; font-size: 11px; color: #aab0b8; text-transform: none; letter-spacing: 0; line-height: 1.45; margin-top: 2px; font-weight: 400; }
.ltv-def-value { flex: 1; min-width: 0; color: #1f2328; word-break: break-word; }
.ltv-def-value .ltv-input, .ltv-def-value .ltv-select { width: 100%; max-width: 340px; }

.ltv-mt { margin-top: 16px; }
.ltv-note { font-size: 12px; color: #8a919b; line-height: 1.55; }
.ltv-note code, .ltv-def-label code { font-size: 11px; }
</style>
CSS;
}

/**
 * Section tabs shown on every top-level LTV view.
 */
function p202_ltv_tabs(string $active): string
{
    $tabs = [
        'report' => ['fa-line-chart', 'Report'],
        'subscriptions' => ['fa-refresh', 'Subscriptions'],
        'products' => ['fa-shopping-cart', 'Products'],
        'companies' => ['fa-building-o', 'Companies'],
        'settings' => ['fa-cog', 'Settings'],
    ];
    $html = '<div class="ltv-tabs">';
    foreach ($tabs as $view => [$icon, $label]) {
        $html .= '<a href="#" class="ltv-tab' . ($view === $active ? ' active' : '') . '"'
            . ' onclick="ltvNav(\'' . $view . '\'); return false;">'
            . '<i class="fa ' . $icon . '"></i>' . p202_ltv_esc($label) . '</a>';
    }

    return $html . '</div>';
}

/**
 * KPI tile. $tone: '' | 'good' | 'bad'.
 */
function p202_ltv_stat(string $label, string $value, string $sub = '', string $tone = ''): string
{
    return '<div class="ltv-stat' . ($tone !== '' ? ' ' . p202_ltv_esc($tone) : '') . '">'
        . '<div class="ltv-stat-label">' . p202_ltv_esc($label) . '</div>'
        . '<div class="ltv-stat-value">' . p202_ltv_esc($value) . '</div>'
        . ($sub !== '' ? '<div class="ltv-stat-sub">' . p202_ltv_esc($sub) . '</div>' : '')
        . '</div>';
}

/**
 * Card opener. $actionsHtml is trusted markup built by the partial
 * (links/buttons with escaped content).
 */
function p202_ltv_card_open(string $title = '', string $sub = '', string $actionsHtml = ''): string
{
    $html = '<div class="ltv-card">';
    if ($title !== '' || $sub !== '' || $actionsHtml !== '') {
        $html .= '<div class="ltv-card-head">'
            . ($title !== '' ? '<span class="ltv-card-title">' . p202_ltv_esc($title) . '</span>' : '')
            . ($sub !== '' ? '<span class="ltv-card-sub">' . p202_ltv_esc($sub) . '</span>' : '')
            . ($actionsHtml !== '' ? '<span class="ltv-card-actions">' . $actionsHtml . '</span>' : '')
            . '</div>';
    }

    return $html;
}

function p202_ltv_card_close(): string
{
    return '</div>';
}

/**
 * Pill. $tone: green | blue | red | amber | gray.
 */
function p202_ltv_pill(string $text, string $tone = 'gray'): string
{
    return '<span class="ltv-pill ltv-pill-' . p202_ltv_esc($tone) . '">' . p202_ltv_esc($text) . '</span>';
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
 * Friendly empty state ($icon is a font-awesome class like 'fa-users').
 */
function p202_ltv_empty(string $icon, string $title, string $hintHtml = ''): string
{
    return '<div class="ltv-empty"><i class="fa ' . p202_ltv_esc($icon) . '"></i>'
        . '<div class="ltv-empty-title">' . p202_ltv_esc($title) . '</div>'
        . ($hintHtml !== '' ? '<div class="ltv-empty-hint">' . $hintHtml . '</div>' : '')
        . '</div>';
}

/**
 * Flash message. $tone: success | error | info | warn. $messageHtml is
 * trusted markup — callers escape their dynamic parts.
 */
function p202_ltv_flash(string $tone, string $messageHtml): string
{
    $icon = match ($tone) {
        'success' => 'fa-check-circle',
        'error' => 'fa-exclamation-circle',
        'warn' => 'fa-exclamation-triangle',
        default => 'fa-info-circle',
    };

    return '<div class="ltv-flash ltv-flash-' . p202_ltv_esc($tone) . '">'
        . '<i class="fa ' . $icon . '"></i><div>' . $messageHtml . '</div></div>';
}

/**
 * Card-footer pager: "Showing X–Y of Z" plus Previous/Next buttons calling
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
    $html = '<div class="ltv-pager"><span>Showing '
        . number_format($from) . '&ndash;' . number_format($to)
        . ' of ' . number_format($total) . '</span><span class="ltv-pager-btns">';
    if ($offset > 0) {
        $html .= '<button type="button" class="ltv-btn ltv-btn-xs" onclick="' . $jsFn . '(' . max(0, $offset - $limit) . ');">'
            . '<i class="fa fa-angle-left"></i> Previous</button>';
    }
    if ($offset + $limit < $total) {
        $html .= '<button type="button" class="ltv-btn ltv-btn-xs" onclick="' . $jsFn . '(' . ($offset + $limit) . ');">'
            . 'Next <i class="fa fa-angle-right"></i></button>';
    }

    return $html . '</span></div>';
}

/**
 * Filter chip row bound to a hidden input, so existing JS that reads
 * $('#id').val() keeps working unchanged. Clicking a chip stores its value
 * and runs $jsOnSelect (e.g. 'ltvLoad(0)').
 */
function p202_ltv_chips(string $inputId, array $options, string $current, string $jsOnSelect): string
{
    $esc = p202_ltv_esc(...);
    $html = '<input type="hidden" id="' . $esc($inputId) . '" value="' . $esc($current) . '">'
        . '<span class="ltv-chips" data-ltv-chips="' . $esc($inputId) . '">';
    foreach ($options as $value => $label) {
        $value = (string) $value;
        $html .= '<span class="ltv-chip' . ($value === $current ? ' active' : '') . '"'
            . ' data-value="' . $esc($value) . '"'
            . ' onclick="document.getElementById(\'' . $esc($inputId) . '\').value = this.getAttribute(\'data-value\'); ' . $jsOnSelect . '">'
            . $esc($label) . '</span>';
    }

    return $html . '</span>';
}
