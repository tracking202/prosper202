<?php

declare(strict_types=1);

use Prosper202\Destinations\DestinationInputException;
use Prosper202\Destinations\DestinationStore;
use Prosper202\Destinations\GoogleAdsAccounts;
use Prosper202\Destinations\GoogleAdsDestination;
use Prosper202\Destinations\GoogleAdsPicker;

/*
 * Setup › Destinations: a connected Google Ads destination's account and
 * conversion action, picked from what Google lists for the connected user
 * (Prosper202\Destinations\GoogleAdsAccounts) instead of typed as IDs.
 *
 * p202_gads_picker() reads Google and answers what to show; it is asked only
 * for a destination that is connected and has no account or action yet, or
 * whose "change" link was followed. p202_gads_pick() saves a choice as the
 * destination's ordinary settings.
 */

/**
 * What the picker shows for one of the account's Google Ads destinations.
 *
 * @param array<string, mixed> $settings the destination's stored settings
 * @param bool $changeAccount the account is asked for again though one is stored
 * @return array{step: string, error: ?string, notes: list<string>, accounts: list<array<string, mixed>>,
 *     account: ?array<string, mixed>, actions: list<array<string, mixed>>, others: int, current: string}
 */
function p202_gads_picker(DestinationStore $store, GoogleAdsAccounts $google, int $userId, int $destinationId, array $settings, bool $changeAccount): array
{
    $state = ['step' => 'account', 'error' => null, 'notes' => [], 'accounts' => [], 'account' => null, 'actions' => [], 'others' => 0,
        'current' => is_string($settings['conversion_action'] ?? null) ? $settings['conversion_action'] : ''];
    try {
        $destination = $store->forSending($userId, $destinationId);
    } catch (\RuntimeException | \JsonException $e) {
        error_log(p202UncaughtLogLine('Setup › Destinations: the credentials of destination ' . $destinationId . ' could not be read', $e));

        return ['error' => 'This destination\'s credentials cannot be read. Delete it and add it again.'] + $state;
    }
    if ($destination === null) {
        return ['error' => 'That destination is not one of yours, or was deleted.'] + $state;
    }
    $credentials = $destination['credentials'];
    $stored = is_string($settings['customer_id'] ?? null) && $settings['customer_id'] !== '' ? $settings['customer_id'] : null;
    if ($stored !== null && !$changeAccount) {
        $state['account'] = [
            'customer_id' => $stored,
            'login_customer_id' => is_string($settings['login_customer_id'] ?? null) ? $settings['login_customer_id'] : null,
            'name' => is_string($settings['labels']['customer']['name'] ?? null) ? $settings['labels']['customer']['name'] : '',
            'currency' => '',
            'via' => null,
        ];
    } else {
        $listed = $google->accounts($credentials);
        $state['notes'] = $listed['notes'];
        if ($listed['error'] !== null) {
            return ['error' => $listed['error']] + $state;
        }
        $state['accounts'] = $listed['accounts'];
        if (count($listed['accounts']) !== 1) {
            return $state;
        }
        // One account: nothing to choose, so its conversion actions are offered at once.
        $state['account'] = $listed['accounts'][0];
        if ($state['account']['customer_id'] !== $stored) {
            $state['current'] = '';
        }
    }
    $state['step'] = 'action';
    $read = $google->conversionActions($credentials, $state['account']['customer_id'], $state['account']['login_customer_id']);
    $state['notes'] = array_merge($state['notes'], $read['notes']);
    $state['actions'] = $read['actions'];
    $state['others'] = $read['others'];
    $state['error'] = $read['error'];

    return $state;
}

/** The value a picked account is posted as: its customer ID, and the manager it is reached through. */
function p202_gads_account_value(array $account): string
{
    return $account['customer_id'] . (($account['login_customer_id'] ?? null) !== null ? ':' . $account['login_customer_id'] : '');
}

function p202_gads_account_label(array $account): string
{
    $label = ($account['name'] !== '' ? $account['name'] . ' · ' : '') . GoogleAdsAccounts::dashed($account['customer_id']);
    if ($account['currency'] !== '') {
        $label .= ' · ' . $account['currency'];
    }
    if (($account['via'] ?? null) !== null) {
        $label .= ' · through manager ' . $account['via'];
    }

    return $label;
}

/**
 * The picker's markup under a destination's row.
 *
 * @param array<string, mixed> $state p202_gads_picker()'s answer
 */
function p202_gads_picker_html(string $self, string $token, int $destinationId, array $state, bool $cancellable = false): string
{
    $cancel = $cancellable ? ' <a class="btn btn-secondary" href="' . p202_setup_e($self . '#d' . $destinationId) . '">Cancel</a>' : '';
    $e = static fn (string $text): string => p202_setup_e($text);
    $changeAccount = $self . '?choose=' . $destinationId . '&account=1#d' . $destinationId;
    $out = '';
    foreach ($state['notes'] as $note) {
        $out .= p202_flash('warn', $note);
    }
    if ($state['error'] !== null) {
        $out .= p202_flash('bad', $state['error']);
        if ($state['step'] === 'account') {
            return $out . '<div class="form-text mb-3">Fix that and reload this page, or set the IDs yourself: <code>p202 destination update ' . $destinationId . ' --customer-id … --conversion-action …</code>.</div>';
        }
    }
    $open = '<form method="post" action="' . $e($self) . '#d' . $destinationId . '" class="mb-3">' . p202_setup_token_field($token)
        . '<input type="hidden" name="action" value="pick_google_ads"><input type="hidden" name="destination_id" value="' . $destinationId . '">';
    if ($state['step'] === 'account') {
        $options = [];
        $names = '';
        foreach ($state['accounts'] as $account) {
            $options[p202_gads_account_value($account)] = p202_gads_account_label($account);
            if ($account['name'] !== '') {
                $names .= '<input type="hidden" name="gads_names[account][' . $e(p202_gads_account_value($account)) . ']" value="' . $e($account['name']) . '">';
            }
        }

        return $out . $open . $names
            . '<label class="form-label" for="gads_account_' . $destinationId . '">Google Ads account the conversions go to</label>'
            . '<select class="form-select mb-2" id="gads_account_' . $destinationId . '" name="gads_account" required>'
            . p202_setup_options($options, '', 'Choose an account') . '</select>'
            . '<div class="form-text mb-2">The accounts the Google user you connected can open. An account reached through a manager is sent to through that manager; nothing more to enter.</div>'
            . '<div class="p202-form-actions"><button type="submit" class="btn btn-primary">Continue</button>' . $cancel . '</div></form>';
    }
    $account = $state['account'];
    $out .= '<p class="mb-2">Account: <strong>' . $e(p202_gads_account_label($account)) . '</strong> <a class="p202-list__action" href="' . $e($changeAccount) . '">change account</a></p>';
    if ($state['error'] !== null) {
        return $out;
    }
    if ($state['actions'] === []) {
        return $out . p202_flash('warn', 'This account has no conversion action that takes uploaded click conversions'
            . ($state['others'] > 0 ? ' (its ' . $state['others'] . ' other' . ($state['others'] === 1 ? ' action counts' : ' actions count') . ' a website tag, an app or calls, which cannot take one)' : '')
            . '. In Google Ads, Goals › Conversions › New conversion action › Import › Other data sources or CRMs › Track conversions from clicks; then reload this page.');
    }
    $options = [];
    $names = $account['name'] !== ''
        ? '<input type="hidden" name="gads_names[account][' . $e(p202_gads_account_value($account)) . ']" value="' . $e($account['name']) . '">'
        : '';
    foreach ($state['actions'] as $action) {
        $options[$action['value']] = ($action['name'] !== '' ? $action['name'] : 'Action ' . $action['id'])
            . ($action['owner'] !== null ? ' · owned by manager ' . GoogleAdsAccounts::dashed($action['owner']) : '');
        if ($action['name'] !== '') {
            $names .= '<input type="hidden" name="gads_names[action][' . $e($action['value']) . ']" value="' . $e($action['name']) . '">';
        }
    }
    $current = $state['current'];
    $only = count($options) === 1 ? (string) array_key_first($options) : '';
    if ($current !== '' && !isset($options[$current])) {
        // The stored action is offered, selected, though Google no longer lists it (CLAUDE.md #4).
        $options = [$current => 'Action ' . $current . ' (set now; Google Ads no longer lists it as an enabled import action)'] + $options;
    }

    return $out . $open . $names . '<input type="hidden" name="gads_account" value="' . $e(p202_gads_account_value($account)) . '">'
        . '<label class="form-label" for="gads_action_' . $destinationId . '">Conversion action</label>'
        . '<select class="form-select mb-2" id="gads_action_' . $destinationId . '" name="gads_conversion_action" required>'
        . p202_setup_options($options, $current !== '' ? $current : $only, $current !== '' || $only !== '' ? null : 'Choose a conversion action') . '</select>'
        . '<div class="form-text mb-2">The account\'s enabled conversion actions that take uploaded clicks (source Import)'
        . ($state['others'] > 0 ? '; ' . $state['others'] . ' of another kind ' . ($state['others'] === 1 ? 'is' : 'are') . ' not listed' : '') . '.</div>'
        . '<div class="p202-form-actions"><button type="submit" class="btn btn-primary">Save</button>' . $cancel . '</div></form>';
}

/**
 * Save what the picker posted. Null when the account has no such
 * destination; else whether it now has both an account and an action.
 * The names posted beside the choices become the destination's labels.
 *
 * @param array<array-key, mixed> $post
 * @throws DestinationInputException
 */
function p202_gads_pick(DestinationStore $store, int $userId, int $destinationId, array $post, int $now): ?bool
{
    $account = $post['gads_account'] ?? null;
    if (!is_string($account) || preg_match('/\A([0-9]{10})(?::([0-9]{10}))?\z/D', $account, $m) !== 1) {
        throw new DestinationInputException('settings.customer_id', 'Choose the Google Ads account from the list.');
    }
    $action = $post['gads_conversion_action'] ?? '';
    if (!is_string($action)) {
        throw new DestinationInputException('settings.conversion_action', 'Choose one conversion action from the list.');
    }
    $names = is_array($post['gads_names'] ?? null) ? $post['gads_names'] : [];
    $name = static function (string $list, string $value) use ($names): ?string {
        $found = is_array($names[$list] ?? null) ? ($names[$list][$value] ?? null) : null;

        return is_string($found) && trim($found) !== '' ? $found : null;
    };
    $saved = (new GoogleAdsPicker($store, new GoogleAdsAccounts()))->save(
        $userId,
        $destinationId,
        $m[1],
        $m[2] ?? null,
        $action === '' ? null : $action,
        $name('account', $account),
        $action === '' ? null : $name('action', $action),
        $now
    );

    return $saved === null ? null : isset(((array) $saved['settings'])['conversion_action']);
}

/**
 * A setting as the list shows it: the name Google gave it with its ID, or
 * the ID alone when no name was stored (typed IDs, the CLI).
 *
 * @param array<string, mixed> $settings
 */
function p202_gads_named(array $settings, string $label, string $key): string
{
    $id = is_string($settings[$key] ?? null) ? $settings[$key] : '';
    $shown = $key === 'customer_id' ? GoogleAdsAccounts::dashed($id) : $id;
    $name = $settings['labels'][$label]['name'] ?? null;

    return is_string($name) && $name !== '' ? $name . ' (' . $shown . ')' : $shown;
}
