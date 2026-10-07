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
 * What a write does lives in 202-config/Update (CpcUpdate, SubidBatch,
 * OwnedRow) and in RevenueUploadImporter, which the REST API calls too
 * (POST /api/v3/clicks/cpc, /conversions/subids, …): the functions below that
 * read or decide a write delegate there, so a page and the API cannot drift
 * apart (CLAUDE.md #5). Their names stay for the pages and for
 * tests/Update/UpdateUiHelpersTest.php.
 *
 * The render helpers come from the Setup family (setup_ui.php: the token
 * field, native <select> options, checked queries); what is here is what the
 * Update pages add. The functions above the Data line are pure — no session,
 * no database — so tests/Update/UpdateUiHelpersTest.php calls them directly.
 */

require_once dirname(__DIR__, 2) . '/setup/_includes/setup_ui.php';

/** The largest CPC 202_clicks.click_cpc (DECIMAL(7,5)) can hold. */
const P202_UPDATE_CPC_MAX = \Prosper202\Update\CpcUpdate::MAX;

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
 * the browser sent (SubidBatch::lines()).
 *
 * @return list<string>
 */
function p202_update_lines(string $text): array
{
    return \Prosper202\Update\SubidBatch::lines($text);
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
 * names them plainly (RevenueUploadImporter::guessColumns()).
 *
 * @param list<string> $header
 * @return array{subid: ?int, amount: ?int}
 */
function p202_update_guess_columns(array $header): array
{
    return \Prosper202\Conversion\RevenueUploadImporter::guessColumns($header);
}

/**
 * Read the Update CPC form: which clicks, over which days, at what CPC
 * (CpcUpdate::parse(); the caller has set the account's timezone).
 *
 * @param array<string, mixed> $in normally $_GET or $_POST
 * @return array{values: array<string, mixed>, errors: array<string, string>}
 */
function p202_update_cpc_parse(array $in): array
{
    return \Prosper202\Update\CpcUpdate::parse($in);
}

/**
 * The clicks an Update CPC request names, as joins and a WHERE with bound
 * values (CpcUpdate::scope()).
 *
 * @param array<string, mixed> $values
 * @return array{joins: string, where: string, types: string, params: list<int>}
 */
function p202_update_cpc_scope(array $values, int $userId, ?int $throughClickId = null): array
{
    return \Prosper202\Update\CpcUpdate::scope($values, $userId, $throughClickId);
}

/**
 * What the person confirmed on the Update CPC check (CpcUpdate::snapshot()).
 *
 * @param array<string, mixed> $in normally $_POST
 * @return array{count: int, through: int}|null
 */
function p202_update_cpc_snapshot(array $in): ?array
{
    return \Prosper202\Update\CpcUpdate::snapshot($in);
}

// ─── Data ──────────────────────────────────────────────────────────────

/**
 * One row the account owns, or null (OwnedRow::find()).
 *
 * @return array<string, mixed>|null
 */
function p202_update_owned_row(\Prosper202\Database\Connection $conn, string $table, string $idColumn, int $id, int $userId): ?array
{
    return \Prosper202\Update\OwnedRow::find($conn, $table, $idColumn, $id, $userId);
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
 * account's (CpcUpdate::labels()): an id of another account's row is refused
 * under its field, never widened to all.
 *
 * @param array<string, mixed> $values
 * @param array<string, string> $errors
 * @return array<string, string> field => what the summary says
 */
function p202_update_cpc_labels(\Prosper202\Database\Connection $conn, array $values, int $userId, array &$errors): array
{
    return \Prosper202\Update\CpcUpdate::labels($conn, $values, $userId, $errors);
}
