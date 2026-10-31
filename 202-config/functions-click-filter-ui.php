<?php

/**
 * Personal settings' Click filtering card (202-account/account.php): its
 * three forms' handling, the read the card shows, and the card. Every value
 * goes through ClickFilterRules, the rule /users/{id}/click-filter asks too.
 * Needs functions-account-ui.php (flashes, redirects, field errors).
 */

declare(strict_types=1);

use Prosper202\Click\Filter\ClickFilterEntry;
use Prosper202\Click\Filter\ClickFilterListRefusal;
use Prosper202\Click\Filter\ClickFilterRules;
use Prosper202\Click\Filter\ClickFilterSettings;
use Prosper202\Click\Filter\ClickFilterTester;
use Prosper202\Click\Filter\MysqlClickFilterStore;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;

const P202_CLICK_FILTER_RETURN = '202-account/account.php#click-filtering';

const P202_CLICK_FILTER_ACTIONS = ['update_click_filter', 'add_click_filter_entry', 'remove_click_filter_entry'];

/**
 * A POST from the card: saved and redirected, or answered with the
 * refusals to show in place.
 *
 * @param array<array-key, mixed> $post the request body
 * @return array{errors: array<string, string>, form: array<string, string>, flashes: list<array{kind: string, text: string}>}
 */
function p202_click_filter_post(\mysqli $db, int $userId, array $post): array
{
    $answer = ['errors' => [], 'form' => [], 'flashes' => []];
    if (array_intersect(P202_CLICK_FILTER_ACTIONS, array_keys($post)) === []) {
        return $answer;
    }
    if (!AUTH::check_csrf_token()) {
        p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
        p202_account_redirect(P202_CLICK_FILTER_RETURN);
    }
    $store = new MysqlClickFilterStore(new Connection($db));
    if (isset($post['update_click_filter'])) {
        return p202_click_filter_save_settings($store, $userId, $post, $answer);
    }
    if (isset($post['add_click_filter_entry'])) {
        return p202_click_filter_add_entry($store, $userId, $post, $answer);
    }
    p202_click_filter_remove_entry($store, $userId, $post);
}

/**
 * A switch's box posts '1' when ticked and nothing when not; anything but a
 * string (a list, name[]=) goes to the rule as it came, which refuses it.
 *
 * @param array<array-key, mixed> $post
 * @param array{errors: array<string, string>, form: array<string, string>, flashes: list<array{kind: string, text: string}>} $answer
 * @return array{errors: array<string, string>, form: array<string, string>, flashes: list<array{kind: string, text: string}>}
 */
function p202_click_filter_save_settings(MysqlClickFilterStore $store, int $userId, array $post, array $answer): array
{
    $posted = [
        'sign_in_addresses' => $post['sign_in_addresses'] ?? '0',
        'crawler_ranges' => $post['crawler_ranges'] ?? '0',
        'repeat_visits' => $post['repeat_visits'] ?? '0',
        'repeat_window' => $post['repeat_window'] ?? '',
    ];
    try {
        [$settings, $errors] = ClickFilterRules::settings($posted, $store->settings($userId));
        if ($errors !== []) {
            $answer['errors'] = $errors;
            $answer['flashes'][] = ['kind' => 'bad', 'text' => 'Click filtering was not saved: ' . implode('; ', array_map(
                static fn (string $field, string $why): string => str_replace('_', ' ', $field) . ' ' . $why,
                array_keys($errors),
                $errors
            )) . '.'];

            return $answer;
        }
        $store->saveSettings($userId, $settings);
    } catch (QueryException | \mysqli_sql_exception | \UnexpectedValueException $e) {
        error_log('account.php: the click filter settings were not saved: ' . $e->getMessage());
        $answer['flashes'][] = [
            'kind' => 'bad',
            'text' => 'Your click filtering settings could not be saved. Nothing changed; try again.',
        ];

        return $answer;
    }
    p202_account_flash('ok', $settings->isDefault()
        ? 'Click filtering is saved: every built-in check is on, as it is by default.'
        : 'Click filtering is saved. It applies to clicks from now on; clicks already recorded keep how they were counted.');
    p202_click_filter_analytics('settings');
    p202_account_redirect(P202_CLICK_FILTER_RETURN);
}

/**
 * @param array<array-key, mixed> $post
 * @param array{errors: array<string, string>, form: array<string, string>, flashes: list<array{kind: string, text: string}>} $answer
 * @return array{errors: array<string, string>, form: array<string, string>, flashes: list<array{kind: string, text: string}>}
 */
function p202_click_filter_add_entry(MysqlClickFilterStore $store, int $userId, array $post, array $answer): array
{
    $input = [
        'address' => $post['filter_address'] ?? '',
        'action' => $post['filter_action'] ?? '',
        'note' => $post['filter_note'] ?? '',
    ];
    $answer['form'] = array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $input);
    [$range, $action, $note, $errors] = ClickFilterRules::newEntry($input);
    $labels = ['address' => 'The address', 'action' => 'Count or filter', 'note' => 'The note'];
    foreach ($errors as $field => $why) {
        $label = $labels[$field] ?? $field;
        $answer['errors']['filter_' . $field] = preg_match('/^(?:is|must|cannot) /', $why) === 1
            ? $label . ' ' . $why . '.'
            : $label . ': ' . $why . '.';
    }
    if ($answer['errors'] !== [] || $range === null) {
        return $answer;
    }
    try {
        $added = $store->addEntry($userId, $range, $action, $note);
    } catch (ClickFilterListRefusal $refusal) {
        $answer['errors']['filter_address'] = 'The address: ' . $refusal->getMessage() . '.';

        return $answer;
    } catch (QueryException | \mysqli_sql_exception | \UnexpectedValueException $e) {
        error_log('account.php: a click filter entry was not added: ' . $e->getMessage());
        $answer['flashes'][] = ['kind' => 'bad', 'text' => 'The address could not be added. Nothing changed; try again.'];

        return $answer;
    }
    p202_account_flash('ok', $added->range->text() . ($added->action === ClickFilterEntry::COUNT
        ? ' is always counted from now on.'
        : ' is always filtered from now on.'));
    p202_click_filter_analytics('entry_added');
    p202_account_redirect(P202_CLICK_FILTER_RETURN);
}

/** Analytics milestone (consent-gated, never fails the save): no address is sent. */
function p202_click_filter_analytics(string $change): void
{
    require_once __DIR__ . '/Messaging/Analytics.class.php';
    Analytics::event('click_filter_changed', ['via' => 'ui', 'change' => $change], 'analytics');
}

/** @param array<array-key, mixed> $post */
function p202_click_filter_remove_entry(MysqlClickFilterStore $store, int $userId, array $post): never
{
    $entryId = \Prosper202\Http\PostedId::read($post['entry_id'] ?? null);
    if ($entryId === null) {
        p202_account_flash('bad', 'That entry could not be read; nothing was removed.');
        p202_account_redirect(P202_CLICK_FILTER_RETURN);
    }
    try {
        $removed = $store->removeEntry($userId, $entryId);
    } catch (QueryException | \mysqli_sql_exception $e) {
        error_log('account.php: a click filter entry was not removed: ' . $e->getMessage());
        p202_account_flash('bad', 'The entry could not be removed. It still applies; try again.');
        p202_account_redirect(P202_CLICK_FILTER_RETURN);
    }
    p202_account_flash($removed ? 'ok' : 'warn', $removed
        ? 'The entry is removed: clicks from its addresses are filtered by the other rules from now on.'
        : 'That entry was not on your list; nothing was removed.');
    p202_account_redirect(P202_CLICK_FILTER_RETURN);
}

/**
 * The card's read: the filter as stored (null when it cannot be read, so no
 * form of defaults is shown to save over it) and an address test.
 *
 * @param array<array-key, mixed> $get the query string
 * @return array{filter: ?array{settings: ClickFilterSettings, entries: list<ClickFilterEntry>}, test: ?array<string, mixed>, errors: array<string, string>, flashes: list<array{kind: string, text: string}>}
 */
function p202_click_filter_view(\mysqli $db, int $userId, array $get): array
{
    $view = ['filter' => null, 'test' => null, 'errors' => [], 'flashes' => []];
    $conn = new Connection($db);
    try {
        $store = new MysqlClickFilterStore($conn);
        $view['filter'] = ['settings' => $store->settings($userId), 'entries' => $store->entries($userId)];
    } catch (QueryException | \mysqli_sql_exception | \UnexpectedValueException $e) {
        error_log('Account page: the click filter settings could not be read: ' . $e->getMessage());
        $view['flashes'][] = [
            'kind' => 'bad',
            'text' => 'Your click filtering settings could not be read just now. Reload the page to see them.',
        ];

        return $view;
    }
    if (!isset($get['test_click_filter'])) {
        return $view;
    }
    [$packed, $errors] = ClickFilterRules::testAddress($get['test_ip'] ?? null);
    if ($errors !== [] || $packed === null) {
        $view['errors']['test_ip'] = 'The address ' . ($errors['ip'] ?? 'could not be read') . '.';

        return $view;
    }
    try {
        $view['test'] = (new ClickFilterTester($conn))->test($userId, $packed);
    } catch (QueryException | \mysqli_sql_exception | \UnexpectedValueException $e) {
        error_log('Account page: a click filter test failed: ' . $e->getMessage());
        $view['flashes'][] = ['kind' => 'bad', 'text' => 'The address could not be tested just now; try again.'];
    }

    return $view;
}

/**
 * The card, for the filter p202_click_filter_view() read.
 *
 * @param array{settings: ClickFilterSettings, entries: list<ClickFilterEntry>} $filter
 * @param ?array<string, mixed> $test
 * @param array<string, string> $errors
 * @param array<string, string> $form what a refused add typed
 * @param string $testIp the address the test form was sent with
 */
function p202_click_filter_card(array $filter, ?array $test, array $errors, array $form, string $testIp): string
{
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $action = $e(get_absolute_url() . P202_CLICK_FILTER_RETURN);
    $token = p202_account_token_field();
    $settings = $filter['settings'];
    $options = static function (array $choices, string $current) use ($e): string {
        $out = '';
        foreach ($choices as $value => $label) {
            $out .= '<option value="' . $e((string) $value) . '"' . ((string) $value === $current ? ' selected' : '')
                . '>' . $e($label) . '</option>';
        }
        return $out;
    };
    $switch = static function (string $name, bool $on, string $label, string $help) use ($e): string {
        return '<div class="form-check form-switch mb-2">'
            . '<input class="form-check-input" type="checkbox" role="switch" id="' . $e($name) . '" name="'
            . $e($name) . '" value="1"' . ($on ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="' . $e($name) . '">' . $e($label) . '</label>'
            . '<div class="form-text mt-0">' . $e($help) . '</div></div>';
    };
    $field = static fn (string $name): string => p202_account_invalid($errors, $name);
    $error = static fn (string $name): string => p202_account_field_error($errors, $name);
    $windows = ['1h' => '1 hour', '6h' => '6 hours', '24h' => '24 hours (the default)', '7d' => '7 days'];

    $html = '<section class="p202-panel mt-4" id="click-filtering">'
        . '<div class="p202-panel__head"><h2 class="p202-panel__title">Click filtering</h2>'
        . '<span class="p202-panel__sub">which clicks your reports leave out of Real clicks</span></div>'
        . '<div class="p202-panel__body">'
        . '<p class="form-text mt-0">Every click is recorded and redirected. A filtered click is shown under Filtered'
        . ' clicks and left out of Real clicks; the API and the p202 command line say which rule filtered each one.'
        . ' Changes apply to clicks from now on.</p>'
        . '<form method="post" action="' . $action . '">'
        . '<input type="hidden" name="update_click_filter" value="1">' . $token
        . $switch('sign_in_addresses', $settings->signInAddresses, 'Your own clicks', 'Filter a click from an address a user of this install last signed in from.')
        . $switch('crawler_ranges', $settings->crawlerRanges, 'Search engine crawlers', 'Filter clicks from the built-in Google, Yahoo and Microsoft crawler ranges (IPv4).')
        . $switch('repeat_visits', $settings->repeatVisits, 'Repeat visits', 'Filter a second click from the same address within the window below: the first is counted.')
        . '<div class="row g-3 mb-3"><div class="col-md-6">'
        . '<label class="form-label" for="repeat_window">Repeat visit window</label>'
        . '<select class="form-select' . $field('repeat_window') . '" name="repeat_window" id="repeat_window">'
        . $options($windows, $settings->repeatWindow) . '</select>'
        . '<div class="form-text">24 hours keeps an address until the daily cleanup clears it, which is a day to two'
        . ' after its first click.</div>' . $error('repeat_window') . '</div></div>'
        . '<div class="form-text mb-3">All three are on by default. Bot detection, which reads the visitor\'s'
        . ' browser, always applies.</div>'
        . '<div class="p202-form-actions"><button class="btn btn-primary" type="submit">Save click filtering</button>'
        . '</div></form>'
        . '<h3 class="p202-panel__title mb-3 mt-4">Addresses you choose</h3>'
        . '<p class="form-text mt-0">An address or a block (203.0.113.7, 203.0.113.0/24, 2001:db8::/32) that is always'
        . ' counted, such as a client\'s office that tests your links, or always filtered, such as a click farm.'
        . ' Always count wins over every rule; always filter wins over the checks above. The address is the one the'
        . ' click arrives from, before privacy masking; a visitor can claim another through a proxy header, so keep'
        . ' Always count for addresses you trust. Up to ' . $e((string) ClickFilterRules::MAX_ENTRIES) . ' entries.</p>';

    if ($filter['entries'] !== []) {
        $html .= '<div class="p202-table-wrap mb-3"><table class="table p202-table">'
            . '<thead><tr><th>Address</th><th>Clicks from it are</th><th>Note</th><th>Added</th><th></th></tr></thead>'
            . '<tbody>';
        foreach ($filter['entries'] as $entry) {
            $address = $entry->range->text();
            $html .= '<tr><td><code>' . $e($address) . '</code></td><td>'
                . ($entry->action === ClickFilterEntry::COUNT
                    ? '<span class="p202-pill p202-pill--good">always counted</span>'
                    : '<span class="p202-pill p202-pill--bad">always filtered</span>')
                . '</td><td>' . $e($entry->note) . '</td><td>' . $e(date('M j, Y', $entry->createdAt)) . '</td><td>'
                . '<form method="post" action="' . $action . '" class="d-inline" data-p202-confirm="Remove '
                . $e($address) . ' from the list? Its clicks are filtered by the other rules from then on.">'
                . '<input type="hidden" name="remove_click_filter_entry" value="1">'
                . '<input type="hidden" name="entry_id" value="' . $e((string) $entry->id) . '">' . $token
                . '<button class="btn btn-outline-danger btn-sm" type="submit">Remove…</button></form></td></tr>';
        }
        $html .= '</tbody></table></div>';
    }

    $html .= '<form method="post" action="' . $action . '">'
        . '<input type="hidden" name="add_click_filter_entry" value="1">' . $token
        . '<div class="row g-3 mb-3"><div class="col-md-4">'
        . '<label class="form-label" for="filter_address">Address or block</label>'
        . '<input type="text" class="form-control' . $field('filter_address') . '" id="filter_address"'
        . ' name="filter_address" placeholder="203.0.113.0/24" autocomplete="off" spellcheck="false" value="'
        . $e($form['address'] ?? '') . '">' . $error('filter_address') . '</div>'
        . '<div class="col-md-3"><label class="form-label" for="filter_action">Its clicks are</label>'
        . '<select class="form-select' . $field('filter_action') . '" name="filter_action" id="filter_action">'
        . $options(['filter' => 'Always filtered', 'count' => 'Always counted'], $form['action'] ?? 'filter')
        . '</select>' . $error('filter_action') . '</div>'
        . '<div class="col-md-5"><label class="form-label" for="filter_note">Note</label>'
        . '<input type="text" class="form-control' . $field('filter_note') . '" id="filter_note" name="filter_note"'
        . ' maxlength="' . $e((string) ClickFilterRules::NOTE_CHARS) . '" placeholder="Client QA office" value="'
        . $e($form['note'] ?? '') . '">' . $error('filter_note') . '</div></div>'
        . '<div class="p202-form-actions"><button class="btn btn-secondary" type="submit">'
        . '<i class="bi bi-plus-lg"></i> Add to the list</button></div></form>'
        . '<h3 class="p202-panel__title mb-3 mt-4">Test an address</h3>'
        . '<form method="get" action="' . $action . '">'
        . '<input type="hidden" name="test_click_filter" value="1">'
        . '<label class="form-label" for="test_ip">Would a click from this address be filtered, and by which rule?'
        . '</label><div class="input-group">'
        . '<input type="text" class="form-control' . $field('test_ip') . '" id="test_ip" name="test_ip"'
        . ' placeholder="203.0.113.7" autocomplete="off" spellcheck="false" value="' . $e($testIp) . '">'
        . '<button class="btn btn-secondary" type="submit">Test</button></div>' . $error('test_ip') . '</form>';
    if ($test !== null) {
        $html .= '<div class="mt-3">' . p202_flash(
            $test['filtered'] ? 'warn' : 'ok',
            (string) $test['ip'] . ': ' . (string) $test['explanation']
        ) . '</div>';
    }

    return $html . '</div></section>';
}
