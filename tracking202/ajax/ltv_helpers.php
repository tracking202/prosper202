<?php

declare(strict_types=1);

/**
 * Shared formatting helpers for the LTV AJAX partials — one definition of
 * money/escaping/date rendering instead of a per-file closure trio that
 * drifts (the customer view's timestamped date format already had).
 */

function p202_ltv_money(mixed $v): string
{
    return number_format((float) $v, 2);
}

function p202_ltv_esc(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function p202_ltv_when(mixed $ts, bool $withTime = false): string
{
    return ((int) $ts) > 0 ? date($withTime ? 'M j, Y g:ia' : 'M j, Y', (int) $ts) : '—';
}

/**
 * Neutralize spreadsheet formula injection for exported cells: Excel treats
 * cells starting with =, +, -, or @ (or tab/CR) as formulas even inside an
 * HTML .xls table, and refs/names/emails/companies are customer-controlled
 * (pixels, API pushes). A leading apostrophe forces Excel to render the cell
 * as text without altering the visible value. HTML-escape separately.
 */
function p202_ltv_xls_cell(mixed $v): string
{
    $s = (string) $v;
    if ($s !== '' && str_contains("=+-@\t\r", $s[0])) {
        $s = "'" . $s;
    }

    return $s;
}
