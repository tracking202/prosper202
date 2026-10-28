<?php

declare(strict_types=1);

/**
 * Shared v2 partials: the pieces every report family is built from.
 *
 *   p202_date_range()          the range picker and its two native date inputs
 *   p202_report_filter_bar()   one GET form: range, common filters, Advanced
 *   p202_report_filters()      the classic reports' filter vocabulary, as specs
 *                              for the bar
 *   p202_data_table()          the standard .p202-table, optionally sortable
 *
 * Each is a pure function of its arguments and returns markup; nothing here
 * reads a global, the session or the database. A page gathers its values and
 * option lists, and the partial renders them — so the same markup comes out
 * of every page that uses it, and a test can render one without an install.
 *
 * The markup is the kit's (202-account/ui-kit.php renders each partial in
 * every state): Bootstrap 5 classes and the component layer only, parts
 * included (error pattern #19). The behaviour is p202-ui.js's, opted into
 * with data attributes: `data-p202-range` for the range picker,
 * `data-p202-sort` for a sortable table. Every string that reaches the page
 * is escaped here, except the values a docblock names as trusted HTML.
 *
 * Two rules the partials hold on behalf of every caller, because each has
 * shipped wrong once:
 *
 *   - A filter's current value is never dropped. A <select> whose value is
 *     not among its options would render its first option — "All" — over a
 *     report that IS still filtered, and the next Apply would widen it
 *     without a word. The value is kept as its own option, marked as not in
 *     the list.
 *   - An Advanced filter that is set is never hidden. The disclosure opens,
 *     says how many are set, and does not let a remembered "closed" state
 *     fold an active filter out of sight.
 */

/** The range picker's value for "use the two dates". */
const P202_RANGE_CUSTOM = 'custom';

/**
 * The date presets of the classic report calendar, in its order and words.
 *
 * The keys are the values `202_users_pref.user_pref_time_predefined` stores
 * and `grab_timeframe()` resolves, so a v2 report and a classic one mean the
 * same window by the same name (MobileAppsReportController::RANGES documents
 * what happened when one label had two meanings).
 *
 * @return array<string, string>
 */
function p202_report_ranges(): array
{
    return [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'last7' => 'Last 7 Days',
        'last14' => 'Last 14 Days',
        'last30' => 'Last 30 Days',
        'thismonth' => 'This Month',
        'lastmonth' => 'Last Month',
        'thisyear' => 'This Year',
        'lastyear' => 'Last Year',
        'alltime' => 'All Time',
    ];
}

/**
 * The range picker: a preset <select> and two native date inputs.
 *
 * The dates are submitted only while the picker reads "custom", so the
 * server has one answer to "which window is this". Without JavaScript the
 * inputs render disabled whenever a preset is chosen (a disabled field is not
 * submitted) and a hint says to choose Custom Date; p202-ui.js then makes
 * them editable, withholds them by removing their `name` instead, and turns
 * typing a date into choosing Custom Date. This is the markup Analyze ›
 * Mobile Apps shipped, and tests/browser drives it there.
 *
 * Dates are ISO `YYYY-MM-DD`, which is what `<input type="date">` submits
 * whatever the reader's locale displays. The classic calendar posted
 * `mm/dd/yyyy`; a page moving to this control reads the new shape.
 *
 * @param array{
 *     range: string,
 *     from?: string,
 *     to?: string,
 *     ranges?: array<string, string>,
 *     custom?: string,
 *     custom_label?: string,
 *     name?: string,
 *     from_name?: string,
 *     to_name?: string,
 *     id?: string,
 *     label?: string,
 *     min?: string,
 *     max?: string,
 *     error?: string,
 * } $spec
 *   range        the selected preset key, or the custom value
 *   from, to     the window's first and last day, YYYY-MM-DD; shown for a
 *                preset too, so the reader sees what it resolved to
 *   ranges       presets, key => label (default p202_report_ranges())
 *   custom       the value meaning "use the dates" (default 'custom')
 *   name         the picker's field name (default 'range'); from_name and
 *                to_name the dates' (default 'from', 'to')
 *   id           id prefix, unique on the page (default 'range')
 *   min, max     bounds for both date inputs, YYYY-MM-DD
 *   error        the server's sentence when it refused the window
 *
 * @throws InvalidArgumentException when `range` is neither a preset nor the
 *   custom value: the controller resolves an unknown one before rendering,
 *   so reaching here with one is a bug, not input.
 */
function p202_date_range(array $spec): string
{
    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $ranges = $spec['ranges'] ?? p202_report_ranges();
    $custom = (string) ($spec['custom'] ?? P202_RANGE_CUSTOM);
    $range = (string) $spec['range'];
    if ($custom === '' || isset($ranges[$custom])) {
        throw new InvalidArgumentException('p202_date_range(): the custom value must be non-empty and not also a preset');
    }
    if ($range !== $custom && !isset($ranges[$range])) {
        throw new InvalidArgumentException("p202_date_range(): '$range' is not one of the presets or '$custom'");
    }
    foreach (['from', 'to', 'min', 'max'] as $key) {
        $value = (string) ($spec[$key] ?? '');
        if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException("p202_date_range(): $key must be YYYY-MM-DD, got '$value'");
        }
    }

    $id = (string) ($spec['id'] ?? 'range');
    $name = (string) ($spec['name'] ?? 'range');
    $fields = ['from' => (string) ($spec['from_name'] ?? 'from'), 'to' => (string) ($spec['to_name'] ?? 'to')];
    $isCustom = $range === $custom;
    $error = trim((string) ($spec['error'] ?? ''));
    $invalid = $error !== '' ? ' is-invalid' : '';

    // Each label travels with its control: the row wraps between the
    // groups, never between a label and what it names.
    $html = '<div class="p202-toolbar"><label class="form-label mb-0" for="' . $e($id) . '">' . $e((string) ($spec['label'] ?? 'Range')) . '</label>';
    $html .= '<select class="form-select form-select-sm' . $invalid . '" style="width:auto" id="' . $e($id) . '" name="' . $e($name) . '" data-p202-range="' . $e($custom) . '">';
    foreach ($ranges as $key => $label) {
        $html .= '<option value="' . $e((string) $key) . '"' . ((string) $key === $range ? ' selected' : '') . '>' . $e((string) $label) . '</option>';
    }
    $html .= '<option value="' . $e($custom) . '"' . ($isCustom ? ' selected' : '') . '>' . $e((string) ($spec['custom_label'] ?? 'Custom Date')) . '</option>';
    $html .= '</select></div>';

    $bounds = '';
    foreach (['min', 'max'] as $bound) {
        if ((string) ($spec[$bound] ?? '') !== '') {
            $bounds .= ' ' . $bound . '="' . $e((string) $spec[$bound]) . '"';
        }
    }
    foreach (['from' => 'From', 'to' => 'To'] as $which => $label) {
        $fieldId = $id . '-' . $which;
        $html .= '<div class="p202-toolbar"><label class="form-label mb-0" for="' . $e($fieldId) . '">' . $label . '</label>';
        $html .= '<input class="form-control form-control-sm' . $invalid . '" style="width:auto" type="date"'
            . ' id="' . $e($fieldId) . '" name="' . $e($fields[$which]) . '"'
            . ' value="' . $e((string) ($spec[$which] ?? '')) . '"' . $bounds
            . ' data-p202-range-field="' . $e($fields[$which]) . '"'
            . ($isCustom ? '' : ' disabled') . '></div>';
    }
    if (!$isCustom) {
        // Only true without JavaScript, the one case where the inputs really
        // are disabled; p202-ui.js hides it.
        $html .= '<span class="form-text" data-p202-range-hint>Choose <em>' . $e((string) ($spec['custom_label'] ?? 'Custom Date')) . '</em> to set these.</span>';
    }
    if ($error !== '') {
        $html .= '<div class="invalid-feedback d-block">' . $e($error) . '</div>';
    }
    return $html;
}

/**
 * The filter vocabulary of the classic reports, as specs for the filter bar.
 *
 * The names are the fields `display_calendar()` renders and
 * `tracking202/ajax/set_user_prefs.php` reads, so a v2 report reads the query
 * string with the same names the classic one posts, and a per-user default
 * stored in `202_users_pref` maps across one to one. Traffic source,
 * campaign and which clicks to count are the common case; the rest sit
 * under Advanced, where the classic calendar had them under "More Options".
 *
 * A stored "All" is read in every spelling the classic writer left: '' and
 * NULL, and the '0' its "--" options posted, which is normalized to '' for
 * every menu that has an "All" entry, so it shows as "All" and is not
 * resubmitted as a filter.
 *
 * `$lists` supplies the options this function cannot know — each is
 * value => label, or group label => [value => label] for <optgroup>s — and
 * a filter asked for without its list is an error rather than an empty menu.
 *
 * @param array<string, string|int|null> $values  the current value per name, from the URL
 * @param array<string, array<string|int, string|array<string|int, string>>> $lists
 * @param list<string>|null $include  the names this page offers, in the order
 *   given; null for the default set (everything but `user_pref_breakdown`,
 *   which only the grouped-by-time pages have)
 * @return list<array<string, mixed>>
 */
function p202_report_filters(array $values, array $lists = [], ?array $include = null): array
{
    $catalog = [
        'ppc_network_id' => ['label' => 'Traffic source', 'type' => 'select', 'any' => 'All traffic sources'],
        'aff_campaign_id' => ['label' => 'Campaign', 'type' => 'select', 'any' => 'All campaigns'],
        'user_pref_show' => ['label' => 'Clicks', 'type' => 'select', 'any' => null, 'default' => 'all', 'options' => [
            'all' => 'All clicks',
            'real' => 'Real clicks',
            'filtered' => 'Filtered out clicks',
            'filtered_bot' => 'Filtered out bot clicks',
            'leads' => 'Converted clicks',
        ]],
        'ppc_account_id' => ['label' => 'Traffic source account', 'type' => 'select', 'any' => 'All accounts', 'advanced' => true],
        'aff_network_id' => ['label' => 'Category', 'type' => 'select', 'any' => 'All categories', 'advanced' => true],
        'landing_page_id' => ['label' => 'Landing page', 'type' => 'select', 'any' => 'All landing pages', 'advanced' => true],
        'text_ad_id' => ['label' => 'Text ad', 'type' => 'select', 'any' => 'All text ads', 'advanced' => true],
        'method_of_promotion' => ['label' => 'Method of promotion', 'type' => 'select', 'any' => 'Any', 'advanced' => true, 'options' => [
            'directlink' => 'Direct link',
            'landingpage' => 'Landing page',
        ]],
        'country_id' => ['label' => 'Country', 'type' => 'select', 'any' => 'All countries', 'advanced' => true],
        'region_id' => ['label' => 'Region', 'type' => 'select', 'any' => 'All regions', 'advanced' => true],
        'isp_id' => ['label' => 'ISP/Carrier', 'type' => 'select', 'any' => 'All ISPs', 'advanced' => true],
        'device_id' => ['label' => 'Device type', 'type' => 'select', 'any' => 'All devices', 'advanced' => true],
        'browser_id' => ['label' => 'Browser', 'type' => 'select', 'any' => 'All browsers', 'advanced' => true],
        'platform_id' => ['label' => 'Platform', 'type' => 'select', 'any' => 'All platforms', 'advanced' => true],
        'subid' => ['label' => 'Subid', 'type' => 'search', 'advanced' => true],
        'ip' => ['label' => 'Visitor IP', 'type' => 'search', 'advanced' => true],
        'keyword' => ['label' => 'Keyword', 'type' => 'search', 'advanced' => true],
        'referer' => ['label' => 'Referer', 'type' => 'search', 'advanced' => true],
        'user_pref_limit' => ['label' => 'Rows', 'type' => 'select', 'any' => null, 'default' => '50', 'advanced' => true, 'options' => [
            '10' => '10', '25' => '25', '50' => '50', '75' => '75', '100' => '100', '150' => '150', '200' => '200',
        ]],
        'user_pref_breakdown' => ['label' => 'Group by', 'type' => 'select', 'any' => null, 'default' => 'day', 'advanced' => true, 'options' => [
            'hour' => 'Hour', 'day' => 'Day', 'month' => 'Month', 'year' => 'Year',
        ]],
        'user_cpc_or_cpv' => ['label' => 'Costs', 'type' => 'select', 'any' => null, 'default' => 'cpc', 'advanced' => true, 'options' => [
            'cpc' => 'CPC costs', 'cpv' => 'CPV costs',
        ]],
    ];

    $names = $include ?? array_values(array_diff(array_keys($catalog), ['user_pref_breakdown']));
    $specs = [];
    foreach ($names as $name) {
        if (!isset($catalog[$name])) {
            throw new InvalidArgumentException("p202_report_filters(): '$name' is not a classic report filter; known: " . implode(', ', array_keys($catalog)));
        }
        $spec = $catalog[$name] + ['name' => $name, 'advanced' => false];
        if ($spec['type'] === 'select' && !isset($spec['options'])) {
            if (!isset($lists[$name])) {
                throw new InvalidArgumentException("p202_report_filters(): '$name' needs its options in \$lists['$name']");
            }
            $spec['options'] = $lists[$name];
        }
        // A display setting with no value shows its default rather than a
        // blank; the defaults are the columns' own in 202_users_pref
        // (user_pref_show is NULL there, which the classic menu shows as
        // its first entry, "all").
        $value = (string) ($values[$name] ?? '');
        // The classic calendar's "--" option posted 0 for every menu that
        // has an "All" entry here, and set_user_prefs.php stored it as
        // given; every classic reader treats 0 as "not filtering". Read
        // as a value, it would render a selected "0 (not in your list)",
        // count as a set Advanced filter, and be submitted back as 0.
        if ($value === '0' && $spec['type'] === 'select' && ($spec['any'] ?? null) !== null) {
            $value = '';
        }
        $spec['value'] = $value === '' && isset($spec['default']) ? (string) $spec['default'] : $value;
        $specs[] = $spec;
    }
    return $specs;
}

/**
 * A report's filters: one GET form, so what someone is looking at is a URL
 * they can send (UI standard, rule 8).
 *
 * The first row holds the range, the common filters, Apply and an optional
 * Reset; the rest sit in an "Advanced" `.p202-disclosure` below it, which
 * opens by itself and counts them when any of them is set.
 *
 * @param array{
 *     action: string,
 *     hidden?: array<string, scalar|null>,
 *     range?: array<string, mixed>|null,
 *     filters?: list<array<string, mixed>>,
 *     id?: string,
 *     submit?: string,
 *     reset?: string,
 *     aside?: string,
 *     note?: string,
 *     remember?: string,
 *     advanced_hint?: string,
 * } $bar
 *   action   the report's URL
 *   hidden   fields carried unchanged (a view, a grouping); null drops one
 *   range    a p202_date_range() spec, or null for a report with no window
 *   filters  specs, e.g. from p202_report_filters(). Each: name, label,
 *            type ('select' | 'search' | 'text' | 'suggest'), value,
 *            options (select: value => label, or group => [value => label]),
 *            any (select: the empty option's label; null for none),
 *            suggestions (suggest: list of strings for its <datalist>),
 *            placeholder, hint, error (the server's sentence), advanced,
 *            default (the value that means "not filtering", for a select
 *            with no empty option; an Advanced filter at its default does
 *            not count as set)
 *   id       id prefix, unique on the page (default 'filters')
 *   reset    a URL that clears the filters; renders a Reset link
 *   aside    TRUSTED HTML for the row's right-hand slot (a help icon, a
 *            download button)
 *   note     one line saying what the defaults are, under the row
 *   remember the disclosure's data-p202-remember key (default: the id)
 */
function p202_report_filter_bar(array $bar): string
{
    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $id = (string) ($bar['id'] ?? 'filters');

    $common = '';
    $advanced = '';
    $activeAdvanced = 0;
    foreach ($bar['filters'] ?? [] as $filter) {
        $isAdvanced = !empty($filter['advanced']);
        if ($isAdvanced) {
            $advanced .= '<div class="col-sm-6 col-lg-3">' . p202_filter_control($filter, $id, false) . '</div>';
            $current = (string) ($filter['value'] ?? '');
            if ($current !== '' && $current !== (string) ($filter['default'] ?? '')) {
                $activeAdvanced++;
            }
        } else {
            $common .= p202_filter_control($filter, $id, true);
        }
    }

    $html = '<form method="get" action="' . $e((string) $bar['action']) . '" id="' . $e($id) . '">';
    foreach ($bar['hidden'] ?? [] as $name => $value) {
        if ($value === null) {
            continue;
        }
        $html .= '<input type="hidden" name="' . $e((string) $name) . '" value="' . $e((string) $value) . '">';
    }
    $html .= '<div class="p202-table-toolbar"><div class="p202-toolbar">';
    if (isset($bar['range']) && is_array($bar['range'])) {
        $html .= p202_date_range($bar['range'] + ['id' => $id . '-range']);
    }
    $html .= $common;
    $html .= '<button class="btn btn-primary btn-sm" type="submit">' . $e((string) ($bar['submit'] ?? 'Apply')) . '</button>';
    if ((string) ($bar['reset'] ?? '') !== '') {
        $html .= '<a class="btn btn-link btn-sm" href="' . $e((string) $bar['reset']) . '">Reset</a>';
    }
    $html .= '</div>';
    if ((string) ($bar['aside'] ?? '') !== '') {
        $html .= '<div class="p202-table-toolbar__aside">' . $bar['aside'] . '</div>';
    }
    $html .= '</div>';

    if ((string) ($bar['note'] ?? '') !== '') {
        $html .= '<div class="form-text mb-2">' . $e((string) $bar['note']) . '</div>';
    }

    if ($advanced !== '') {
        // A set filter is never folded out of sight: the disclosure opens,
        // and a remembered "closed" is not consulted, because p202-ui.js
        // would restore it over the `open` this renders.
        $remember = $activeAdvanced === 0
            ? ' data-p202-remember="' . $e((string) ($bar['remember'] ?? $id)) . '"'
            : '';
        $hint = $activeAdvanced > 0
            ? $activeAdvanced . ' set'
            : (string) ($bar['advanced_hint'] ?? 'more filters');
        $html .= '<details class="p202-disclosure mb-3"' . ($activeAdvanced > 0 ? ' open' : '') . $remember . '>'
            . '<summary>Advanced <span class="p202-disclosure__hint">' . $e($hint) . '</span></summary>'
            . '<div class="p202-disclosure__body"><div class="row g-2">' . $advanced . '</div></div>'
            . '</details>';
    }

    return $html . '</form>';
}

/**
 * One filter's label and control. Inline for the first row (label beside the
 * control), stacked for the Advanced grid (label above, hint below).
 *
 * @param array<string, mixed> $filter
 * @internal used by p202_report_filter_bar()
 */
function p202_filter_control(array $filter, string $prefix, bool $inline): string
{
    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $name = (string) ($filter['name'] ?? '');
    if ($name === '') {
        throw new InvalidArgumentException('p202_report_filter_bar(): every filter needs a name');
    }
    $type = (string) ($filter['type'] ?? 'text');
    $value = (string) ($filter['value'] ?? '');
    $fieldId = $prefix . '-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
    $error = trim((string) ($filter['error'] ?? ''));
    $size = $type === 'select' ? 'form-select form-select-sm' : 'form-control form-control-sm';
    $class = $size . ($error !== '' ? ' is-invalid' : '');
    $style = $inline ? ' style="width:auto"' : '';
    $placeholder = (string) ($filter['placeholder'] ?? '');
    $placeholderAttr = $placeholder !== '' ? ' placeholder="' . $e($placeholder) . '"' : '';

    $label = '<label class="form-label' . ($inline ? ' mb-0' : '') . '" for="' . $e($fieldId) . '">' . $e((string) ($filter['label'] ?? $name)) . '</label>';

    switch ($type) {
        case 'select':
            $options = $filter['options'] ?? null;
            if (!is_array($options)) {
                throw new InvalidArgumentException("p202_report_filter_bar(): select '$name' has no options");
            }
            $control = '<select class="' . $class . '"' . $style . ' id="' . $e($fieldId) . '" name="' . $e($name) . '">';
            $any = array_key_exists('any', $filter) ? $filter['any'] : 'All';
            if ($any !== null) {
                $control .= '<option value=""' . ($value === '' ? ' selected' : '') . '>' . $e((string) $any) . '</option>';
            }
            $listed = false;
            $optionsHtml = '';
            foreach ($options as $key => $labelOrGroup) {
                if (is_array($labelOrGroup)) {
                    $optionsHtml .= '<optgroup label="' . $e((string) $key) . '">';
                    foreach ($labelOrGroup as $optionValue => $optionLabel) {
                        $selected = (string) $optionValue === $value;
                        $listed = $listed || $selected;
                        $optionsHtml .= '<option value="' . $e((string) $optionValue) . '"' . ($selected ? ' selected' : '') . '>' . $e((string) $optionLabel) . '</option>';
                    }
                    $optionsHtml .= '</optgroup>';
                    continue;
                }
                $selected = (string) $key === $value;
                $listed = $listed || $selected;
                $optionsHtml .= '<option value="' . $e((string) $key) . '"' . ($selected ? ' selected' : '') . '>' . $e((string) $labelOrGroup) . '</option>';
            }
            // A value the list does not carry keeps its own option: without
            // one the menu would show its first entry over a report that is
            // still filtered, and the next Apply would drop the filter.
            if ($value !== '' && !$listed) {
                $control .= '<option value="' . $e($value) . '" selected>' . $e($value) . ' (not in your list)</option>';
            }
            $control .= $optionsHtml . '</select>';
            break;

        case 'suggest':
            $listId = $fieldId . '-list';
            $control = '<input class="' . $class . '"' . $style . ' type="text" id="' . $e($fieldId) . '" name="' . $e($name) . '" value="' . $e($value) . '" list="' . $e($listId) . '" autocomplete="off"' . $placeholderAttr . '>';
            $control .= '<datalist id="' . $e($listId) . '">';
            foreach ($filter['suggestions'] ?? [] as $suggestion) {
                $control .= '<option value="' . $e((string) $suggestion) . '"></option>';
            }
            $control .= '</datalist>';
            break;

        case 'search':
        case 'text':
            $control = '<input class="' . $class . '"' . $style . ' type="' . $type . '" id="' . $e($fieldId) . '" name="' . $e($name) . '" value="' . $e($value) . '"' . $placeholderAttr . '>';
            break;

        default:
            throw new InvalidArgumentException("p202_report_filter_bar(): '$name' has unknown type '$type'; use select, search, text or suggest");
    }

    // The first row has no room for a hint under each control; a common
    // filter that needs one explains itself in the bar's note instead. Its
    // label and control are one group, so the row wraps between filters and
    // never between a label and what it names — which puts the feedback
    // outside the control's parent, where Bootstrap's `.is-invalid ~` rule
    // cannot reach it, so it is shown outright.
    $hint = (string) ($filter['hint'] ?? '');
    if ($inline) {
        return '<div class="p202-toolbar">' . $label . $control . '</div>'
            . ($error !== '' ? '<div class="invalid-feedback d-block">' . $e($error) . '</div>' : '');
    }
    return $label . $control . ($hint !== '' ? '<div class="form-text">' . $e($hint) . '</div>' : '')
        . ($error !== '' ? '<div class="invalid-feedback">' . $e($error) . '</div>' : '');
}

/**
 * The standard data table: `.p202-table` in its `.p202-table-wrap`, numbers
 * right-aligned, an optional totals row, and an empty state in place of a
 * table with no rows.
 *
 * Sorting is client-side (tablesort.js, wired by p202-ui.js) and therefore
 * only honest when every row is on the page, so it is opt-in: pass
 * `sortable => true` for a complete table. A paginated or truncated table
 * sorts on the server, by link, because sorting one page of rows reorders
 * that page and nothing else.
 *
 * @param list<array{key: string, label: string, num?: bool, sort?: string|false, href?: string}> $columns
 *   key    the row field this column reads
 *   num    a number: right-aligned, tabular, sorted numerically
 *   sort   'number', 'text' or false (not sortable); defaults from `num`
 *   href   (a table that is not `sortable`) a URL that reloads the report
 *          sorted by this column on the server; the heading becomes that
 *          link, and `sorted` says which column the rows are in now
 * @param list<array<string, mixed>> $rows  one array per row, keyed by column
 *   key. A cell is a scalar (escaped) or an array: ['text' => escaped] or
 *   ['html' => TRUSTED], either with an optional 'sort' => the raw value to
 *   order by (a formatted "$1,304.00" sorts by 1304).
 * @param array{
 *     id?: string,
 *     caption?: string,
 *     sortable?: bool,
 *     sorted?: array{key: string, dir: string},
 *     totals?: array<string, mixed>|null,
 *     empty?: array{icon?: string, title: string, body: string, action?: string, href?: string},
 * } $options
 *   caption  what the table is, for a screen reader (visually hidden)
 *   sorted   the order the server delivered, so the header says so:
 *            ['key' => …, 'dir' => 'ascending'|'descending']
 *   totals   a row shaped like the others; its first cell is the label
 *   empty    the empty state shown instead of a table with no rows
 */
function p202_data_table(array $columns, array $rows, array $options = []): string
{
    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    if ($columns === []) {
        throw new InvalidArgumentException('p202_data_table(): a table needs at least one column');
    }

    if ($rows === []) {
        $empty = $options['empty'] ?? ['title' => 'Nothing to show', 'body' => 'No rows match these filters.'];
        return '<div class="p202-empty">'
            . '<i class="bi ' . $e((string) ($empty['icon'] ?? 'bi-inbox')) . ' p202-empty__icon"></i>'
            . '<strong class="p202-empty__title">' . $e((string) $empty['title']) . '</strong>'
            . '<div>' . $e((string) $empty['body']) . '</div>'
            . ((string) ($empty['action'] ?? '') !== '' && (string) ($empty['href'] ?? '') !== ''
                ? '<div class="p202-empty__action"><a class="btn btn-primary btn-sm" href="' . $e((string) $empty['href']) . '">' . $e((string) $empty['action']) . '</a></div>'
                : '')
            . '</div>';
    }

    $sortable = !empty($options['sortable']);
    $sorted = $options['sorted'] ?? null;
    if ($sorted !== null && !in_array($sorted['dir'] ?? '', ['ascending', 'descending'], true)) {
        throw new InvalidArgumentException("p202_data_table(): sorted.dir must be 'ascending' or 'descending'");
    }

    $cell = static function (mixed $value) use ($e): array {
        if (is_array($value)) {
            $content = isset($value['html']) ? (string) $value['html'] : $e((string) ($value['text'] ?? ''));
            $sort = array_key_exists('sort', $value) && $value['sort'] !== null ? ' data-sort="' . $e((string) $value['sort']) . '"' : '';
            return [$content, $sort];
        }
        return [$e((string) ($value ?? '')), ''];
    };

    $id = (string) ($options['id'] ?? '');
    $html = '<div class="p202-table-wrap"><table class="table table-hover p202-table"'
        . ($id !== '' ? ' id="' . $e($id) . '"' : '')
        . ($sortable ? ' data-p202-sort' : '') . '>';
    if ((string) ($options['caption'] ?? '') !== '') {
        $html .= '<caption class="visually-hidden">' . $e((string) $options['caption']) . '</caption>';
    }
    $html .= '<thead><tr>';
    foreach ($columns as $column) {
        $num = !empty($column['num']);
        $method = $column['sort'] ?? ($num ? 'number' : 'text');
        $classes = [];
        if ($num) {
            $classes[] = 'num';
        }
        $attrs = '';
        if ($sorted !== null && ($sorted['key'] ?? null) === $column['key']) {
            $attrs .= ' aria-sort="' . $e((string) $sorted['dir']) . '"';
        }
        $label = $e((string) $column['label']);
        if ($sortable && $method !== false) {
            if (!in_array($method, ['number', 'text'], true)) {
                throw new InvalidArgumentException("p202_data_table(): column '{$column['key']}' sort must be 'number', 'text' or false");
            }
            // tablesort reads data-sort-method; 'text' is its default
            // comparison, which has no name to ask for.
            if ($method === 'number') {
                $attrs .= ' data-sort-method="number"';
            }
            $label = '<button type="button" class="p202-sort">' . $label . '</button>';
        } elseif ($sortable) {
            $classes[] = 'no-sort';
        } elseif ((string) ($column['href'] ?? '') !== '') {
            // U3: a server-sorted column. The heading is a link to the same
            // report in that order, styled as the sort control it is.
            $label = '<a class="p202-sort" href="' . $e((string) $column['href']) . '">' . $label . '</a>';
        }
        $html .= '<th scope="col"' . ($classes !== [] ? ' class="' . implode(' ', $classes) . '"' : '') . $attrs . '>' . $label . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    $renderRow = static function (array $row, string $rowClass) use ($columns, $cell): string {
        $out = '<tr' . ($rowClass !== '' ? ' class="' . $rowClass . '"' : '') . '>';
        foreach ($columns as $column) {
            [$content, $sort] = $cell($row[$column['key']] ?? '');
            $out .= '<td' . (!empty($column['num']) ? ' class="num"' : '') . $sort . '>' . $content . '</td>';
        }
        return $out . '</tr>';
    };

    foreach ($rows as $row) {
        $html .= $renderRow($row, '');
    }
    if (isset($options['totals']) && is_array($options['totals'])) {
        // In the body, as the kit renders it, and marked no-sort so
        // tablesort leaves it where it is: last.
        $html .= $renderRow($options['totals'], 'p202-table__totals no-sort');
    }
    return $html . '</tbody></table></div>';
}
