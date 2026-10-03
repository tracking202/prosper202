<?php

declare(strict_types=1);

/**
 * Pure helpers for the Attribution dashboard (202-account/attribution.php):
 * the report window a query string means, exact decimal sums, and the
 * formatting of money, credit and shares. No globals, no session, no
 * database, so tests/Attribution/DashboardHelpersTest can execute each one.
 *
 * Money and credit arrive from MySQL as DECIMAL strings. Totals the page
 * adds up itself (a journey's credits per model, the clicks and cost of a
 * breakdown shown whole) are summed in integer units of the column's scale,
 * never through a float, so "the credits sum to 100%" on the page is the
 * same statement the engine's tests make about the table.
 */

/** The presets the dashboard offers, in the report calendar's words. */
function p202_attr_ranges(): array
{
    return [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'last7' => 'Last 7 Days',
        'last14' => 'Last 14 Days',
        'last30' => 'Last 30 Days',
        'last90' => 'Last 90 Days',
        'thismonth' => 'This Month',
        'lastmonth' => 'Last Month',
    ];
}

const P202_ATTR_DEFAULT_RANGE = 'last30';

/**
 * The window a range picker's values mean, in the current time zone (the
 * account's, which connect.php sets): whole days, from the first day's
 * midnight to the last day's 23:59:59. The "last N days" presets run from N
 * days before today's midnight through the end of today, as the classic
 * calendar counts them.
 *
 * A present but unreadable date is refused with a sentence, never quietly
 * replaced (CLAUDE.md error pattern #4); the window then falls back to the
 * default so the page still has something to show under the sentence.
 *
 * @return array{range: string, from: int, to: int, from_date: string, to_date: string, error: string|null}
 */
function p202_attr_window(?string $range, string $from, string $to, int $now): array
{
    $presets = p202_attr_ranges();
    $today = (int) mktime(0, 0, 0, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));
    $endOf = static fn (int $dayStart): int => (int) mktime(23, 59, 59, (int) date('n', $dayStart), (int) date('j', $dayStart), (int) date('Y', $dayStart));
    $daysBack = static fn (int $n): int => (int) mktime(0, 0, 0, (int) date('n', $today), (int) date('j', $today) - $n, (int) date('Y', $today));
    $error = null;

    if ($range === null || $range === '') {
        $range = ($from !== '' || $to !== '') ? P202_RANGE_CUSTOM : P202_ATTR_DEFAULT_RANGE;
    }
    if ($range !== P202_RANGE_CUSTOM && !isset($presets[$range])) {
        $error = "'" . $range . "' is not a range this report offers, so it shows " . $presets[P202_ATTR_DEFAULT_RANGE] . '.';
        $range = P202_ATTR_DEFAULT_RANGE;
    }

    if ($range === P202_RANGE_CUSTOM) {
        $start = p202_attr_parse_day($from);
        $end = p202_attr_parse_day($to);
        if ($start === null || $end === null) {
            $error = 'A custom range needs a start and an end date, each YYYY-MM-DD and a real day.';
            $range = P202_ATTR_DEFAULT_RANGE;
        } elseif ($start > $end) {
            $error = 'The start date is after the end date.';
            $range = P202_ATTR_DEFAULT_RANGE;
        } else {
            return ['range' => P202_RANGE_CUSTOM, 'from' => $start, 'to' => $endOf($end),
                'from_date' => date('Y-m-d', $start), 'to_date' => date('Y-m-d', $end), 'error' => null];
        }
    }

    [$start, $end] = match ($range) {
        'today' => [$today, $endOf($today)],
        'yesterday' => [$daysBack(1), $endOf($daysBack(1))],
        'last7' => [$daysBack(7), $endOf($today)],
        'last14' => [$daysBack(14), $endOf($today)],
        'last30' => [$daysBack(30), $endOf($today)],
        'last90' => [$daysBack(90), $endOf($today)],
        'thismonth' => [(int) mktime(0, 0, 0, (int) date('n', $now), 1, (int) date('Y', $now)),
            (int) mktime(23, 59, 59, (int) date('n', $now) + 1, 0, (int) date('Y', $now))],
        'lastmonth' => [(int) mktime(0, 0, 0, (int) date('n', $now) - 1, 1, (int) date('Y', $now)),
            (int) mktime(23, 59, 59, (int) date('n', $now), 0, (int) date('Y', $now))],
    };

    return ['range' => $range, 'from' => $start, 'to' => $end,
        'from_date' => date('Y-m-d', $start), 'to_date' => date('Y-m-d', $end), 'error' => $error];
}

/** Midnight (current time zone) of a real YYYY-MM-DD day, or null. */
function p202_attr_parse_day(string $value): ?int
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }

    return (int) mktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
}

/**
 * A decimal string as an integer number of 10^-$scale units.
 *
 * @throws UnexpectedValueException for anything that is not a plain decimal
 *   (MySQL's DECIMAL output always is; anything else is a bug, not input)
 */
function p202_attr_units(string $value, int $scale): int
{
    if (preg_match('/^(-)?(\d+)(?:\.(\d+))?$/D', trim($value), $m) !== 1) {
        throw new UnexpectedValueException('"' . $value . '" is not a decimal amount');
    }
    $fraction = $m[3] ?? '';
    if (strlen($fraction) > $scale) {
        if (rtrim(substr($fraction, $scale), '0') !== '') {
            throw new UnexpectedValueException('"' . $value . '" has more than ' . $scale . ' decimal places');
        }
        $fraction = substr($fraction, 0, $scale);
    }
    $units = (int) $m[2] * (10 ** $scale) + (int) str_pad($fraction, $scale, '0');

    return ($m[1] ?? '') === '-' ? -$units : $units;
}

/** Integer units back to a decimal string with $scale places. */
function p202_attr_from_units(int $units, int $scale): string
{
    $sign = $units < 0 ? '-' : '';
    $abs = abs($units);
    $whole = intdiv($abs, 10 ** $scale);
    $fraction = str_pad((string) ($abs % (10 ** $scale)), $scale, '0', STR_PAD_LEFT);

    return $sign . $whole . ($scale > 0 ? '.' . $fraction : '');
}

/**
 * The exact sum of decimal strings, at $scale places.
 *
 * @param list<string> $values
 */
function p202_attr_sum(array $values, int $scale): string
{
    $total = 0;
    foreach ($values as $value) {
        $total += p202_attr_units($value, $scale);
    }

    return p202_attr_from_units($total, $scale);
}

/** Round a decimal string half up to $places, as a string. */
function p202_attr_round(string $value, int $places): string
{
    $scale = 8;
    $units = p202_attr_units($value, $scale);
    $step = 10 ** ($scale - $places);
    $half = intdiv($step, 2);
    $rounded = $units >= 0 ? intdiv($units + $half, $step) : -intdiv(-$units + $half, $step);

    return p202_attr_from_units($rounded, $places);
}

/** Money as the page shows it: $1,234.50, rounded half up from the exact value. */
function p202_attr_money(string $value): string
{
    $rounded = p202_attr_round($value, 2);
    $negative = str_starts_with($rounded, '-');
    [$whole, $cents] = explode('.', ltrim($rounded, '-'));

    return ($negative ? '-' : '') . '$' . number_format((int) $whole) . '.' . $cents;
}

/** A credit sum (attributed conversions) to two places: 2.67. */
function p202_attr_credit(string $value): string
{
    $rounded = p202_attr_round($value, 2);
    [$whole, $cents] = explode('.', $rounded);

    return number_format((int) $whole) . '.' . $cents;
}

/** A credit (0–1) as a share: 0.33333333 → 33.33%. */
function p202_attr_share(string $credit): string
{
    return p202_attr_round(p202_attr_from_units(p202_attr_units($credit, 8) * 100, 8), 2) . '%';
}

/** A report dimension's label. */
function p202_attr_dimension_label(string $dimension): string
{
    return [
        'campaign' => 'Campaign',
        'traffic_source' => 'Traffic source account',
        'landing_page' => 'Landing page',
        'keyword' => 'Keyword',
        'c1' => 'c1',
        'c2' => 'c2',
        'c3' => 'c3',
        'c4' => 'c4',
        'country' => 'Country',
        'device' => 'Device type',
        'day' => 'Day',
    ][$dimension] ?? $dimension;
}

/** How long before the conversion a touch was: "3 d 4 h", "25 min", "under a minute". */
function p202_attr_before(int $seconds): string
{
    if ($seconds < 60) {
        return 'under a minute';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . ' min';
    }
    if ($seconds < 86400) {
        return intdiv($seconds, 3600) . ' h ' . intdiv($seconds % 3600, 60) . ' min';
    }

    return intdiv($seconds, 86400) . ' d ' . intdiv($seconds % 86400, 3600) . ' h';
}

/** The words an identity signal is shown with in a journey. */
function p202_attr_signal_label(string $signal): string
{
    return [
        'vid' => 'tracking cookie',
        'lpid' => 'landing-page id',
        'cust' => 'signed customer id',
    ][$signal] ?? str_replace('_', ' ', $signal);
}

/**
 * An API field sentence as a sentence on the page: a capital and a full
 * stop — unless it starts with a field name (first_weight + last_weight …),
 * which keeps its spelling.
 */
function p202_attr_sentence(string $sentence): string
{
    $sentence = trim($sentence);
    if ($sentence === '') {
        return '';
    }
    $first = (string) strtok($sentence, ' ');
    if (!str_contains($first, '_')) {
        $sentence = ucfirst($sentence);
    }

    return $sentence . (preg_match('/[.!?]$/', $sentence) === 1 ? '' : '.');
}
