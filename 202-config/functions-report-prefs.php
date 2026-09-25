<?php

declare(strict_types=1);

/**
 * The classic reports' filters, read from a request and written to
 * 202_users_pref.
 *
 * Every classic report reads its filters from the user's 202_users_pref row —
 * the AJAX fragments, the downloads and the data engine all do — so a report
 * page does not pass its filters to the thing that draws it; it stores them
 * and the fragment reads them back. The classic pages post them to
 * tracking202/ajax/set_user_prefs.php. A page on the v2 shell keeps its
 * filters in the query string instead (UI standard, rule 8: what someone is
 * looking at is a URL they can send), and applies them to the same row when
 * the page loads, so the fragments, the downloads and every other report
 * agree with what the page says.
 *
 * Two rules this file holds for every caller:
 *
 *   - Only what the request names is written. A page that shows three
 *     filters writes three columns; the classic form posted every field,
 *     hidden ones included, and a page that sent fewer through the classic
 *     writer would have blanked the rest of the user's filters without a
 *     word.
 *   - A value that does not parse is an error, never a default. A date
 *     that is not a date, an id that is not a number, a range nobody offers
 *     — each comes back as the sentence to show under its field, and nothing
 *     is written (CLAUDE.md error pattern #4).
 *
 * p202_report_parse_date() is also what set_user_prefs.php reads a date with,
 * so the classic calendar (mm/dd/yyyy, and the two-digit year its presets
 * fill in) and the v2 range picker (YYYY-MM-DD) are read by one function.
 */

/** The ppc_network_id meaning "clicks with no traffic source" (the column's maximum). */
const P202_REPORT_NO_TRAFFIC_SOURCE = '16777215';

/**
 * The request names a report page may send, with the column each writes and
 * how its value is checked.
 *
 * `id` values are unsigned integers bounded by their column's type: the
 * location, device, browser and platform columns are TINYINT UNSIGNED, so an
 * id above 255 cannot be stored there, and saying so beats the classic
 * writer's behaviour (a failed UPDATE in strict mode, a clipped id in lax).
 * An empty value or 0 means "all" and is stored as the classic writer stored
 * it: '' — except the traffic source, which it stored as NULL.
 *
 * @return array<string, array{column: string, kind: string, max?: int, options?: list<string>, label: string}>
 */
function p202_report_pref_fields(): array
{
    $tiny = 255;
    $medium = 16777215;
    return [
        'ppc_network_id' => ['column' => 'user_pref_ppc_network_id', 'kind' => 'id', 'max' => $medium, 'label' => 'Traffic source', 'null_when_empty' => true],
        'ppc_account_id' => ['column' => 'user_pref_ppc_account_id', 'kind' => 'id', 'max' => $medium, 'label' => 'Traffic source account'],
        'aff_network_id' => ['column' => 'user_pref_aff_network_id', 'kind' => 'id', 'max' => $medium, 'label' => 'Category'],
        'aff_campaign_id' => ['column' => 'user_pref_aff_campaign_id', 'kind' => 'id', 'max' => $medium, 'label' => 'Campaign'],
        'text_ad_id' => ['column' => 'user_pref_text_ad_id', 'kind' => 'id', 'max' => $medium, 'label' => 'Text ad'],
        'landing_page_id' => ['column' => 'user_pref_landing_page_id', 'kind' => 'id', 'max' => $medium, 'label' => 'Landing page'],
        'method_of_promotion' => ['column' => 'user_pref_method_of_promotion', 'kind' => 'enum', 'options' => ['', 'directlink', 'landingpage'], 'label' => 'Method of promotion'],
        'country_id' => ['column' => 'user_pref_country_id', 'kind' => 'id', 'max' => $tiny, 'label' => 'Country'],
        'region_id' => ['column' => 'user_pref_region_id', 'kind' => 'id', 'max' => $tiny, 'label' => 'Region'],
        'isp_id' => ['column' => 'user_pref_isp_id', 'kind' => 'id', 'max' => $tiny, 'label' => 'ISP/Carrier'],
        'device_id' => ['column' => 'user_pref_device_id', 'kind' => 'id', 'max' => $tiny, 'label' => 'Device type'],
        'browser_id' => ['column' => 'user_pref_browser_id', 'kind' => 'id', 'max' => $tiny, 'label' => 'Browser'],
        'platform_id' => ['column' => 'user_pref_platform_id', 'kind' => 'id', 'max' => $tiny, 'label' => 'Platform'],
        'subid' => ['column' => 'user_pref_subid', 'kind' => 'id', 'max' => PHP_INT_MAX, 'label' => 'Subid'],
        'ip' => ['column' => 'user_pref_ip', 'kind' => 'text', 'max' => 100, 'label' => 'Visitor IP'],
        'referer' => ['column' => 'user_pref_referer', 'kind' => 'text', 'max' => 100, 'label' => 'Referer'],
        'keyword' => ['column' => 'user_pref_keyword', 'kind' => 'text', 'max' => 100, 'label' => 'Keyword'],
        'user_pref_limit' => ['column' => 'user_pref_limit', 'kind' => 'enum', 'options' => ['10', '25', '50', '75', '100', '150', '200'], 'label' => 'Rows'],
        'user_pref_breakdown' => ['column' => 'user_pref_breakdown', 'kind' => 'enum', 'options' => ['hour', 'day', 'month', 'year'], 'label' => 'Group by'],
        'user_pref_show' => ['column' => 'user_pref_show', 'kind' => 'enum', 'options' => ['all', 'real', 'filtered', 'filtered_bot', 'leads'], 'label' => 'Clicks'],
        'user_cpc_or_cpv' => ['column' => 'user_cpc_or_cpv', 'kind' => 'enum', 'options' => ['cpc', 'cpv'], 'label' => 'Costs'],
        'group_1' => ['column' => 'user_pref_group_1', 'kind' => 'group', 'label' => 'Group by'],
        'group_2' => ['column' => 'user_pref_group_2', 'kind' => 'group', 'label' => 'Then by'],
        'group_3' => ['column' => 'user_pref_group_3', 'kind' => 'group', 'label' => 'Then by'],
        'group_4' => ['column' => 'user_pref_group_4', 'kind' => 'group', 'label' => 'Then by'],
    ];
}

/**
 * Read a report date in either shape a report form has submitted.
 *
 *   YYYY-MM-DD    the v2 range picker (<input type="date"> submits ISO
 *                 whatever the reader's locale displays)
 *   mm/dd/yyyy    the classic calendar
 *   mm/dd/yy      what the classic calendar's presets write into it
 *                 (date('m/d/y')); the classic reader took the two-digit
 *                 year through mktime(), which maps 0-69 to 2000-2069 and
 *                 70-99 to 1970-1999, and so does this
 *
 * Surrounding whitespace is ignored, as the classic reader's trim() did.
 * Anything else — a real-looking date that does not exist (02/30/2026), a
 * third shape, trailing text — is null, and the caller says so rather than
 * reading it as "no date".
 *
 * @return array{year: int, month: int, day: int}|null
 */
function p202_report_parse_date(string $value): ?array
{
    $value = trim($value);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    } elseif (preg_match('#^(\d{1,2})\s*/\s*(\d{1,2})\s*/\s*(\d{4}|\d{2})$#', $value, $m) === 1) {
        [$month, $day, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if (strlen($m[3]) === 2) {
            $year += $year < 70 ? 2000 : 1900;
        }
    } else {
        return null;
    }
    if ($year < 1970 || !checkdate($month, $day, $year)) {
        return null;
    }
    return ['year' => $year, 'month' => $month, 'day' => $day];
}

/**
 * The window a request asks for, as the columns that store it.
 *
 * `range` is a preset key of p202_report_ranges() or P202_RANGE_CUSTOM; a
 * custom window needs both dates, the first no later than the second, and is
 * stored as the classic writer stored one: an empty preset, the first day's
 * 00:00:00 and the last day's 23:59:59 in the current timezone (the caller
 * sets the user's first, as set_user_prefs.php does).
 *
 * @return array{columns: array<string, string>, errors: array<string, string>}
 */
function p202_report_range_columns(string $range, ?string $from, ?string $to): array
{
    if ($range !== P202_RANGE_CUSTOM) {
        if (!isset(p202_report_ranges()[$range])) {
            return ['columns' => [], 'errors' => ['range' => "'$range' is not a date range this report offers."]];
        }
        // The custom bounds are cleared, as the classic writer cleared them,
        // so a preset never leaves a stale window behind it.
        return ['columns' => [
            'user_pref_time_predefined' => $range,
            'user_pref_time_from' => '',
            'user_pref_time_to' => '',
        ], 'errors' => []];
    }

    $errors = [];
    $days = [];
    foreach (['from' => $from, 'to' => $to] as $which => $value) {
        $label = $which === 'from' ? 'start' : 'end';
        if ($value === null || trim($value) === '') {
            $errors['range'] = 'A custom range needs a start and an end date.';
            continue;
        }
        $parsed = p202_report_parse_date($value);
        if ($parsed === null) {
            $errors['range'] = "The $label date '" . $value . "' is not a date; use YYYY-MM-DD.";
            continue;
        }
        $days[$which] = $parsed;
    }
    if ($errors !== []) {
        return ['columns' => [], 'errors' => $errors];
    }

    $start = mktime(0, 0, 0, $days['from']['month'], $days['from']['day'], $days['from']['year']);
    $end = mktime(23, 59, 59, $days['to']['month'], $days['to']['day'], $days['to']['year']);
    if ($start > $end) {
        return ['columns' => [], 'errors' => ['range' => 'The start date is after the end date.']];
    }
    return ['columns' => [
        'user_pref_time_predefined' => '',
        'user_pref_time_from' => (string) $start,
        'user_pref_time_to' => (string) $end,
    ], 'errors' => []];
}

/**
 * The columns a report request writes, and the sentence for every value that
 * was refused.
 *
 * Only the names in `$names` are read, and of those only the ones the request
 * carries: a name the request does not send leaves its column alone. `range`
 * (with `from` and `to`) is the window; the rest are keys of
 * p202_report_pref_fields(). `$groups` lists the grouping ids the group
 * report offers; a `group_N` value outside it is refused, and `group_2` to
 * `group_4` also accept the "none" id.
 *
 * Pure: no database, no session. When `errors` is not empty the caller writes
 * nothing, so a request is applied whole or not at all.
 *
 * @param array<string, mixed> $query  the request, e.g. $_GET
 * @param list<string> $names          what this page offers
 * @param list<int|string> $groups     grouping ids for group_N
 * @return array{columns: array<string, string|null>, errors: array<string, string>, values: array<string, string>}
 *   columns  column => value to store (null for SQL NULL)
 *   errors   request name => sentence
 *   values   request name => the string the request carried, for the form
 */
function p202_report_prefs_from_query(array $query, array $names, array $groups = [], string $noneGroup = '0'): array
{
    $fields = p202_report_pref_fields();
    $columns = [];
    $errors = [];
    $values = [];

    $scalar = static function (string $name) use ($query): ?string {
        if (!array_key_exists($name, $query)) {
            return null;
        }
        $value = $query[$name];
        // A repeated or array-shaped parameter is not a value any control
        // here submits; refuse it rather than reading "Array".
        return is_scalar($value) ? trim((string) $value) : "\0array";
    };

    foreach ($names as $name) {
        if ($name === 'range') {
            $range = $scalar('range');
            if ($range === null) {
                continue;
            }
            $values['range'] = $range;
            $from = $scalar('from');
            $to = $scalar('to');
            $values['from'] = (string) $from;
            $values['to'] = (string) $to;
            if ($range === "\0array" || $from === "\0array" || $to === "\0array") {
                $errors['range'] = 'The date range was sent more than once.';
                continue;
            }
            $window = p202_report_range_columns($range, $from, $to);
            $columns += $window['columns'];
            $errors += $window['errors'];
            continue;
        }

        if (!isset($fields[$name])) {
            throw new InvalidArgumentException("p202_report_prefs_from_query(): '$name' is not a report filter");
        }
        $value = $scalar($name);
        if ($value === null) {
            continue;
        }
        $field = $fields[$name];
        $values[$name] = $value === "\0array" ? '' : $value;
        if ($value === "\0array") {
            $errors[$name] = $field['label'] . ' was sent more than once.';
            continue;
        }

        switch ($field['kind']) {
            case 'id':
                if ($value === '' || $value === '0') {
                    $columns[$field['column']] = !empty($field['null_when_empty']) ? null : '';
                    break;
                }
                if (preg_match('/^[1-9]\d{0,18}$/', $value) !== 1 || (strlen($value) === 19 && strcmp($value, (string) PHP_INT_MAX) > 0) || (int) $value > $field['max']) {
                    $errors[$name] = $field['label'] . " '" . $value . "' is not one this report can filter by.";
                    break;
                }
                $columns[$field['column']] = $value;
                break;

            case 'enum':
                if (!in_array($value, $field['options'], true)) {
                    $errors[$name] = $field['label'] . " '" . $value . "' is not one of " . implode(', ', array_filter($field['options'], static fn (string $o): bool => $o !== '')) . '.';
                    break;
                }
                $columns[$field['column']] = $value;
                break;

            case 'text':
                if (strlen($value) > $field['max']) {
                    $errors[$name] = $field['label'] . ' is longer than ' . $field['max'] . ' characters.';
                    break;
                }
                $columns[$field['column']] = $value;
                break;

            case 'group':
                $allowed = array_map('strval', $groups);
                if ($name !== 'group_1') {
                    $allowed[] = $noneGroup;
                }
                if (!in_array($value, $allowed, true)) {
                    $errors[$name] = $field['label'] . " '" . $value . "' is not a grouping this report offers.";
                    break;
                }
                $columns[$field['column']] = $value;
                break;

            default:
                throw new LogicException("p202_report_pref_fields(): '$name' has unknown kind '{$field['kind']}'");
        }
    }

    return ['columns' => $errors === [] ? $columns : [], 'errors' => $errors, 'values' => $values];
}

/**
 * The value a report control shows for a stored column.
 *
 * The classic writer stored "all" as '', '0' or NULL depending on the field,
 * and the v2 controls say it one way: ''. Everything else is shown as stored,
 * including a value the control no longer offers, which the filter bar keeps
 * as its own option rather than showing "All" over a filtered report.
 *
 * @param array<string, mixed> $row  a 202_users_pref row
 * @return array<string, string>  request name => value
 */
function p202_report_prefs_values(array $row): array
{
    $values = [];
    foreach (p202_report_pref_fields() as $name => $field) {
        $stored = $row[$field['column']] ?? null;
        $value = $stored === null ? '' : (string) $stored;
        if ($field['kind'] === 'id' && $value === '0') {
            $value = '';
        }
        if ($name === 'method_of_promotion' && $value === '0') {
            $value = '';
        }
        $values[$name] = $value;
    }
    return $values;
}

/**
 * Write report columns to the user's 202_users_pref row.
 *
 * One prepared UPDATE naming only the given columns, through Connection, so
 * a failed prepare, bind or execute throws a QueryException: a report that
 * silently kept the old filters would draw a report the page does not
 * describe. The column names come from p202_report_pref_fields() and the
 * window's three, never from the request, and anything else is refused.
 *
 * @param array<string, string|null> $columns
 */
function p202_report_prefs_save(\Prosper202\Database\Connection $conn, int $userId, array $columns): void
{
    if ($columns === []) {
        return;
    }
    $known = ['user_pref_time_predefined', 'user_pref_time_from', 'user_pref_time_to'];
    foreach (p202_report_pref_fields() as $field) {
        $known[] = $field['column'];
    }

    $assignments = [];
    $params = [];
    foreach ($columns as $column => $value) {
        if (!in_array($column, $known, true)) {
            throw new InvalidArgumentException("p202_report_prefs_save(): '$column' is not a report preference column");
        }
        // NULL for "all" on the traffic source, as the classic writer stored
        // it; and for the window's bounds when a preset clears them: they
        // are INT columns, which a strict server refuses '' for, and
        // grab_timeframe() reads NULL and '' alike.
        if ($value === null || (($column === 'user_pref_time_from' || $column === 'user_pref_time_to') && $value === '')) {
            $assignments[] = '`' . $column . '` = NULL';
            continue;
        }
        $assignments[] = '`' . $column . '` = ?';
        $params[] = $value;
    }
    $params[] = (string) $userId;

    $stmt = $conn->prepareWrite('UPDATE 202_users_pref SET ' . implode(', ', $assignments) . ' WHERE user_id = ?');
    $conn->bind($stmt, str_repeat('s', count($params)), $params);
    $conn->executeUpdate($stmt);
}

/**
 * The user's 202_users_pref row.
 *
 * A missing row is an empty array (a brand-new account, which every reader
 * of this row already treats as "all defaults"); a failed read throws.
 *
 * @return array<string, mixed>
 */
function p202_report_prefs_load(\Prosper202\Database\Connection $conn, int $userId): array
{
    $stmt = $conn->prepareRead('SELECT * FROM 202_users_pref WHERE user_id = ?');
    $conn->bind($stmt, 'i', [$userId]);
    return $conn->fetchOne($stmt) ?? [];
}
