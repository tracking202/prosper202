<?php

declare(strict_types=1);

/**
 * The Update family on the v2 shell (U5): Update Subids, Update CPC, Reset
 * Campaign Subids, Delete Subids and Upload Revenue Reports.
 *
 * Every page here changes recorded history — which clicks converted, what a
 * click cost — so each write is a POST that carries the session token and is
 * refused in words when it does not (AUTH::check_csrf_token()); the pages
 * answer a write with its result in place, because the result is a report
 * (what was marked, what was skipped) and each write is safe to send twice:
 * marking a converted subid again adds nothing, clearing a cleared one
 * clears nothing, and setting a CPC sets the same value.
 *
 * The render helpers come from the Setup family (setup_ui.php: the token
 * field, native <select> options, checked queries); what is here is what the
 * Update pages add. The functions above the Data line are pure — no session,
 * no database — so tests/Update/UpdateUiHelpersTest.php calls them directly.
 */

require_once dirname(__DIR__, 2) . '/setup/_includes/setup_ui.php';

/** The largest CPC 202_clicks.click_cpc (DECIMAL(7,5)) can hold. */
const P202_UPDATE_CPC_MAX = '99.99999';

/** The sentence a refused token gets on every Update page. */
const P202_UPDATE_TOKEN_REFUSED = 'Your session expired before the form was sent. Nothing was changed; please send it again.';

/** The page header every Update page opens with. */
function p202_update_header(string $icon, string $title, string $description): string
{
    return '<div class="p202-page-header">'
        . '<div class="p202-page-header__icon"><i class="bi ' . p202_setup_e($icon) . '"></i></div>'
        . '<div class="p202-page-header__text">'
        . '<h1 class="p202-page-header__title">' . p202_setup_e($title) . '</h1>'
        . '<p class="p202-page-header__desc">' . p202_setup_e($description) . '</p>'
        . '</div></div>';
}

/**
 * The lines of a pasted list, trimmed, blanks dropped, whatever line ending
 * the browser sent.
 *
 * @return list<string>
 */
function p202_update_lines(string $text): array
{
    $lines = preg_split('/\R/', trim($text));
    if ($lines === false) {
        return [];
    }
    return array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
}

/**
 * A list for a sentence: the first $limit values, then "and N more".
 *
 * @param list<string> $values
 */
function p202_update_list_sentence(array $values, int $limit = 20): string
{
    $shown = implode(', ', array_slice($values, 0, $limit));
    $rest = count($values) - $limit;
    return $rest > 0 ? $shown . ' and ' . $rest . ' more' : $shown;
}

/**
 * An amount as money: two decimals at least, and every further digit the
 * ledger kept ('1.75000' is $1.75, '0.00125' is $0.00125), so a fraction of
 * a cent is shown rather than rounded away.
 */
function p202_update_money(string $amount): string
{
    $negative = str_starts_with($amount, '-');
    $digits = ltrim($amount, '-');
    [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '');
    $fraction = str_pad(rtrim($fraction, '0'), 2, '0');
    return ($negative ? '-$' : '$') . ($whole === '' ? '0' : $whole) . '.' . $fraction;
}

/**
 * The subid and commission columns a revenue report's header names, when it
 * names them plainly: the column picker pre-selects them and says so, and
 * the person changes them if the guess is wrong (UI standard, rule 4).
 *
 * The first header that matches wins; a header that matches both is taken
 * for the subid. Null when nothing matches — the radio is then left for the
 * person to choose, never guessed at random.
 *
 * @param list<string> $header
 * @return array{subid: ?int, amount: ?int}
 */
function p202_update_guess_columns(array $header): array
{
    $subid = null;
    $amount = null;
    foreach ($header as $index => $name) {
        $name = strtolower(trim((string) $name));
        if ($subid === null && preg_match('/sub[\s_-]*id|click[\s_-]*id|\bt202|\baff[\s_-]*sub|\bsid\b/', $name)) {
            $subid = (int) $index;
            continue;
        }
        if ($amount === null && preg_match('/commission|payout|revenue|amount|earning|sale|income/', $name)) {
            $amount = (int) $index;
        }
    }
    return ['subid' => $subid, 'amount' => $amount];
}

/**
 * Read the Update CPC form: which clicks, over which days, at what CPC.
 *
 * Pure: the caller has set the account's timezone (the days are the
 * account's days, 00:00:00 to 23:59:59). Ids are 0 for "all"; every id that
 * is not a whole number is refused under its field rather than read as 0,
 * which would widen the update to every click (error pattern #11: a value
 * that cannot be read must not resolve to the widest reading).
 *
 * @param array<string, mixed> $in normally $_GET or $_POST
 * @return array{values: array<string, mixed>, errors: array<string, string>}
 */
function p202_update_cpc_parse(array $in): array
{
    $values = [];
    $errors = [];
    $ids = [
        'aff_network_id' => 'category',
        'aff_campaign_id' => 'campaign',
        'ppc_network_id' => 'traffic source',
        'ppc_account_id' => 'traffic source account',
        'landing_page_id' => 'landing page',
        'text_ad_id' => 'text ad',
    ];
    foreach ($ids as $field => $what) {
        $raw = $in[$field] ?? '';
        $raw = is_string($raw) ? trim($raw) : null;
        if ($raw === '' || $raw === '0') {
            $values[$field] = 0;
        } elseif ($raw !== null && ctype_digit($raw) && strlen($raw) <= 9) {
            $values[$field] = (int) $raw;
        } else {
            $values[$field] = 0;
            $errors[$field] = 'Choose a ' . $what . ' from the list.';
        }
    }

    $method = $in['method_of_promotion'] ?? '';
    $method = is_string($method) ? $method : null;
    if ($method === '' || $method === 'directlink' || $method === 'landingpage') {
        $values['method_of_promotion'] = $method;
    } else {
        $values['method_of_promotion'] = '';
        $errors['method_of_promotion'] = 'Choose direct links, landing pages, or both.';
    }

    $days = [];
    foreach (['from' => 'first', 'to' => 'last'] as $field => $which) {
        $raw = $in[$field] ?? '';
        $raw = is_string($raw) ? trim($raw) : '';
        $values[$field] = $raw;
        if ($raw === '') {
            $errors[$field] = 'Enter the ' . $which . ' day to update.';
            continue;
        }
        $date = \Tracking202\Report\ReportFilterInput::parseDate($raw);
        if ($date === null) {
            $errors[$field] = "'" . $raw . "' is not a date. Use the date picker, or type it as YYYY-MM-DD.";
            continue;
        }
        $days[$field] = $date;
    }
    if (isset($days['from'], $days['to'])) {
        [$fy, $fm, $fd] = $days['from'];
        [$ty, $tm, $td] = $days['to'];
        $values['from_time'] = (int) mktime(0, 0, 0, $fm, $fd, $fy);
        $values['to_time'] = (int) mktime(23, 59, 59, $tm, $td, $ty);
        if ($values['from_time'] > $values['to_time']) {
            $errors['to'] = 'The last day is before the first day.';
        }
    }

    $cpc = $in['cpc'] ?? '';
    $cpc = is_string($cpc) ? trim(ltrim(trim($cpc), '$')) : '';
    $values['cpc'] = $cpc;
    if ($cpc === '') {
        $errors['cpc'] = 'Enter the CPC these clicks cost, for example 0.25.';
    } elseif (!preg_match('/^\d{1,2}(\.\d{1,5})?$|^\.\d{1,5}$/', $cpc)) {
        $errors['cpc'] = is_numeric($cpc) && (float) $cpc > (float) P202_UPDATE_CPC_MAX
            ? 'A CPC can be at most $' . P202_UPDATE_CPC_MAX . '.'
            : "'" . $cpc . "' is not a CPC. Use a number of dollars with up to five decimals, for example 0.00125.";
    } else {
        // Normalised as the column stores it, so the preview and the update
        // say and write the same number.
        $values['cpc'] = number_format((float) $cpc, 5, '.', '');
    }

    return ['values' => $values, 'errors' => $errors];
}

/**
 * The clicks an Update CPC request names, as joins and a WHERE with bound
 * values: the same clause counts them for the preview and updates them on
 * apply, so the number the person confirms is the number that changes.
 *
 * The joins and the method-of-promotion test are the classic handler's
 * (tracking202/ajax/update_cpc2.php before U5): a landing-page click is one
 * whose 202_clicks_site row names a landing page URL. Pure. $values is
 * p202_update_cpc_parse()'s, with no errors.
 *
 * @param array<string, mixed> $values
 * @return array{joins: string, where: string, types: string, params: list<int>}
 */
function p202_update_cpc_scope(array $values, int $userId): array
{
    $joins = ' LEFT JOIN 202_clicks_advance ON (202_clicks_advance.click_id = 202_clicks.click_id)'
        . ' LEFT JOIN 202_clicks_site ON (202_clicks_site.click_id = 202_clicks.click_id)'
        . ' LEFT JOIN 202_aff_campaigns ON (202_clicks.aff_campaign_id = 202_aff_campaigns.aff_campaign_id)'
        . ' LEFT JOIN 202_ppc_accounts ON (202_ppc_accounts.ppc_account_id = 202_clicks.ppc_account_id)';
    $where = ' WHERE 202_clicks.user_id = ? AND 202_clicks.click_time >= ? AND 202_clicks.click_time <= ?';
    $types = 'iii';
    $params = [$userId, (int) $values['from_time'], (int) $values['to_time']];
    $filters = [
        'aff_network_id' => '202_aff_campaigns.aff_network_id',
        'aff_campaign_id' => '202_clicks.aff_campaign_id',
        'text_ad_id' => '202_clicks_advance.text_ad_id',
        'landing_page_id' => '202_clicks.landing_page_id',
        'ppc_network_id' => '202_ppc_accounts.ppc_network_id',
        'ppc_account_id' => '202_clicks.ppc_account_id',
    ];
    foreach ($filters as $field => $column) {
        if ((int) ($values[$field] ?? 0) > 0) {
            $where .= ' AND ' . $column . ' = ?';
            $types .= 'i';
            $params[] = (int) $values[$field];
        }
    }
    if (($values['method_of_promotion'] ?? '') === 'landingpage') {
        $where .= ' AND 202_clicks_site.click_landing_site_url_id != 0';
    } elseif (($values['method_of_promotion'] ?? '') === 'directlink') {
        $where .= ' AND 202_clicks_site.click_landing_site_url_id = 0';
    }
    return ['joins' => $joins, 'where' => $where, 'types' => $types, 'params' => $params];
}

// ─── Data ──────────────────────────────────────────────────────────────

/**
 * One row the account owns, or null. For the ownership checks: a form value
 * naming another account's row is refused, not silently widened.
 *
 * Through Connection, whose bind/execute/fetch throw a QueryException on
 * failure: a failed lookup stops the page rather than reading as "not
 * yours" or, worse, as "yours" (error pattern #1). The primary is asked, not
 * a replica, because the answer decides a write.
 *
 * @return array<string, mixed>|null
 */
function p202_update_owned_row(\Prosper202\Database\Connection $conn, string $table, string $idColumn, int $id, int $userId): ?array
{
    $stmt = $conn->prepareWrite('SELECT * FROM `' . $table . '` WHERE `' . $idColumn . '` = ? AND user_id = ? LIMIT 1');
    $conn->bind($stmt, 'ii', [$id, $userId]);
    return $conn->fetchOne($stmt);
}

/**
 * The account's categories and their live campaigns, for the category select
 * and the campaign select it filters (p202-setup.js, data-p202-filter-by).
 *
 * @return array{networks: array<string, string>, campaigns: array<string, array{label: string, options: array<string, array{label: string, data: array<string, string>}>}>}
 */
function p202_update_campaign_lists(mysqli $db, int $userId): array
{
    $networks = [];
    foreach (p202_setup_rows($db, "SELECT aff_network_id, aff_network_name FROM 202_aff_networks WHERE user_id = '" . $userId . "' AND aff_network_deleted = 0 ORDER BY aff_network_name ASC") as $row) {
        $networks[(string) $row['aff_network_id']] = (string) $row['aff_network_name'];
    }
    return ['networks' => $networks, 'campaigns' => p202_setup_campaign_options($db, $userId)];
}

/**
 * The account's traffic sources and their accounts, shaped as the campaign
 * lists are.
 *
 * @return array{networks: array<string, string>, accounts: array<string, array{label: string, options: array<string, array{label: string, data: array<string, string>}>}>}
 */
function p202_update_traffic_lists(mysqli $db, int $userId): array
{
    $networks = [];
    foreach (p202_setup_rows($db, "SELECT ppc_network_id, ppc_network_name FROM 202_ppc_networks WHERE user_id = '" . $userId . "' AND ppc_network_deleted = 0 ORDER BY ppc_network_name ASC") as $row) {
        $networks[(string) $row['ppc_network_id']] = (string) $row['ppc_network_name'];
    }
    $accounts = [];
    foreach (p202_setup_rows($db, "SELECT pa.ppc_account_id, pa.ppc_account_name, pn.ppc_network_id, pn.ppc_network_name
        FROM 202_ppc_accounts AS pa INNER JOIN 202_ppc_networks AS pn ON (pn.ppc_network_id = pa.ppc_network_id)
        WHERE pa.user_id = '" . $userId . "' AND pa.ppc_account_deleted = 0 AND pn.ppc_network_deleted = 0
        ORDER BY pn.ppc_network_name ASC, pa.ppc_account_name ASC") as $row) {
        $group = 'n' . $row['ppc_network_id'];
        $accounts[$group]['label'] = (string) $row['ppc_network_name'];
        $accounts[$group]['options'][(string) $row['ppc_account_id']] = [
            'label' => (string) $row['ppc_account_name'],
            'data' => ['network' => (string) $row['ppc_network_id']],
        ];
    }
    return ['networks' => $networks, 'accounts' => $accounts];
}

/**
 * The names of the rows the request narrows to, after checking each is the
 * account's: an id of another account's row is refused under its field
 * ("You can not modify other peoples cpc history."), never widened to all.
 *
 * @param array<string, mixed> $values
 * @param array<string, string> $errors
 * @return array<string, string> field => what the summary says
 */
function p202_update_cpc_labels(\Prosper202\Database\Connection $conn, array $values, int $userId, array &$errors): array
{
    $lookups = [
        'aff_network_id' => ['202_aff_networks', 'aff_network_name', 'Every category'],
        'aff_campaign_id' => ['202_aff_campaigns', 'aff_campaign_name', 'Every campaign'],
        'ppc_network_id' => ['202_ppc_networks', 'ppc_network_name', 'Every traffic source'],
        'ppc_account_id' => ['202_ppc_accounts', 'ppc_account_name', 'Every account'],
        'landing_page_id' => ['202_landing_pages', 'landing_page_nickname', 'Every landing page'],
        'text_ad_id' => ['202_text_ads', 'text_ad_name', 'Every text ad'],
    ];
    $labels = [];
    foreach ($lookups as $field => [$table, $nameColumn, $all]) {
        $id = (int) ($values[$field] ?? 0);
        if ($id === 0) {
            $labels[$field] = $all;
            continue;
        }
        $row = p202_update_owned_row($conn, $table, $field, $id, $userId);
        if ($row === null) {
            $errors[$field] = 'You can not modify other peoples cpc history.';
            continue;
        }
        $labels[$field] = (string) $row[$nameColumn];
    }
    $labels['method_of_promotion'] = ['' => 'Direct links and landing pages', 'directlink' => 'Direct links only', 'landingpage' => 'Landing pages only'][$values['method_of_promotion']] ?? '';
    return $labels;
}
