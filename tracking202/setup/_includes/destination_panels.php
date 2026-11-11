<?php

declare(strict_types=1);

use Prosper202\Destinations\DestinationForm;
use Prosper202\Destinations\DestinationQueue;
use Prosper202\Destinations\Ga4Destination;
use Prosper202\Destinations\GoogleAdsDestination;

/**
 * Setup › Destinations' per-destination panels: what its conversions came to,
 * the edit form, and the full delivery log. Pure functions of their
 * arguments; destinations.php reads the request and the database.
 */

/** The privacy setting's name as Personal settings shows it. */
function p202_destination_privacy_name(string $setting): string
{
    return ['disabled' => 'Disabled', 'eu' => 'Enabled for European Traffic', 'all' => 'Enabled for All Traffic'][$setting] ?? $setting;
}

/** Whether a destination states both consent signals granted. */
function p202_destination_consent_granted(array $settings): bool
{
    $consent = (array) ($settings['consent'] ?? []);

    return ($consent['ad_user_data'] ?? null) === 'granted' && ($consent['ad_personalization'] ?? null) === 'granted';
}

/** What the privacy setting in force does to a destination's sends, for a form's consent fields. */
function p202_destination_privacy_sentence(string $setting): string
{
    return match ($setting) {
        'all' => 'Your privacy setting (' . p202_destination_privacy_name($setting) . ') holds back every visitor, so no conversion is sent whatever is stated here.',
        'disabled' => 'Your privacy setting is ' . p202_destination_privacy_name($setting) . ', so a visitor who may be in the EEA or UK is sent only when both signals are Granted.',
        default => 'Your privacy setting (' . p202_destination_privacy_name($setting) . ') holds back every visitor who may be in the EEA or UK, whatever is stated here. They are sent only with the setting Disabled and both signals Granted.',
    };
}

/** The link to where the privacy setting is changed. */
function p202_destination_privacy_link(string $base): string
{
    return '<a href="' . p202_setup_e($base) . '202-account/account.php#user_pref_privacy">Personal settings › Advanced › GDPR &amp; privacy</a>'
        . ' (the stricter of your setting and the install owner\'s applies)';
}

/** The note under a destination whose sends the privacy setting holds back; '' when it holds none it would otherwise send. */
function p202_destination_privacy_note(string $setting, array $settings, string $base): string
{
    if ($setting === 'all') {
        $text = 'Nothing is sent: your privacy setting (' . p202_destination_privacy_name($setting) . ') holds back every visitor.';
    } elseif ($setting !== 'disabled' && p202_destination_consent_granted($settings)) {
        $text = 'Both consent signals are Granted, but visitors who may be in the EEA or UK are still withheld: your privacy setting ('
            . p202_destination_privacy_name($setting) . ') holds them back. They are sent only with the setting Disabled.';
    } else {
        return '';
    }

    return '<div class="alert alert-warning p202-flash" role="status"><i class="bi bi-exclamation-triangle"></i><div class="p202-flash__body">'
        . p202_setup_e($text) . ' Change it in ' . p202_destination_privacy_link($base) . '.</div></div>';
}

/** About how long the cron job takes to send $n rows. */
function p202_destination_send_time(int $n): string
{
    $minutes = (int) ceil($n / DestinationQueue::SENT_A_MINUTE);

    return $minutes < 90 ? 'about ' . $minutes . ($minutes === 1 ? ' minute' : ' minutes') : 'about ' . round($minutes / 60, 1) . ' hours';
}

/** How many conversions a destination still has to send, and how long that takes. */
function p202_destination_waiting_sentence(int $waiting): string
{
    return number_format($waiting) . ' still to send: ' . p202_destination_send_time($waiting) . ' at the cron job\'s rate (about '
        . DestinationQueue::SENT_A_MINUTE . ' a minute, shared with your other destinations), longer for a Google Ads click under 6 hours old.'
        . ' Reload the page to see the counts move.';
}

/**
 * The last 7 days of a destination's conversions by status, why the failed
 * and withheld ones were, and the buttons that send them again.
 *
 * @param array<string, mixed> $d
 * @param array{counts: array<string, int>, reasons: list<array{status: string, reason: string, n: int}>, resendable: array<string, int>} $health
 */
function p202_destination_health(array $d, array $health, string $self, string $token, bool $canSeeLog): string
{
    $id = (int) $d['destination_id'];
    $labels = ['sent' => ['sent', ' p202-pill--good'], 'pending' => ['waiting', ''], 'failed' => ['failed', ' p202-pill--bad'], 'withheld' => ['withheld', ' p202-pill--warn'], 'cancelled' => ['cancelled', '']];
    $log = p202_setup_e($self) . '?log=' . $id;
    ob_start(); ?>
    <div class="form-text mb-2">Last 7 days:
        <?php echo array_sum($health['counts']) === 0 ? 'no conversions queued for it.' : ''; ?>
        <?php foreach ($labels as $status => [$label, $class]) {
            $n = $health['counts'][$status] ?? 0;
            if ($n === 0) {
                continue;
            }
            $pill = '<span class="p202-pill' . $class . '">' . number_format($n) . ' ' . $label . '</span>';
            echo $canSeeLog ? '<a href="' . $log . '&amp;status=' . $status . '">' . $pill . '</a> ' : $pill . ' ';
        } ?>
        <?php echo $canSeeLog ? '· <a href="' . $log . '">full log</a>' : ''; ?>
    </div>
    <?php if (($health['waiting'] ?? 0) > 0) { ?>
    <div class="form-text mb-2"><?php echo p202_setup_e(p202_destination_waiting_sentence((int) $health['waiting'])); ?></div>
    <?php } ?>
    <?php foreach ($health['reasons'] as $reason) { ?>
    <div class="form-text"><?php echo number_format($reason['n']) . ' ' . p202_setup_e($reason['status']); ?>: <?php echo p202_setup_e($reason['reason'] !== '' ? (string) preg_replace('/\A(withheld|not sent): /', '', $reason['reason']) : 'no reason recorded'); ?></div>
    <?php } ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mt-2 mb-3">
        <?php foreach (['failed' => 'Fix the cause first (the reason above), then send them again with all their tries.', 'withheld' => 'They are checked again: one still held back is withheld again.'] as $status => $title) {
            $n = $health['resendable'][$status] ?? 0;
            if ($n === 0) {
                continue;
            } ?>
        <form method="post" action="<?php echo p202_setup_e($self); ?>#d<?php echo $id; ?>" data-p202-confirm="Send the <?php echo number_format($n) . ' ' . $status; ?> conversions of the last <?php echo DestinationQueue::RESEND_DAYS; ?> days again?">
            <?php echo p202_setup_token_field($token); ?>
            <input type="hidden" name="action" value="resend_all"><input type="hidden" name="destination_id" value="<?php echo $id; ?>"><input type="hidden" name="status" value="<?php echo $status; ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary" title="<?php echo p202_setup_e($title); ?>">Send the <?php echo number_format($n) . ' ' . $status; ?> again</button>
        </form>
        <?php } ?>
        <form method="post" action="<?php echo p202_setup_e($self); ?>#d<?php echo $id; ?>" class="d-flex gap-2 align-items-center">
            <?php echo p202_setup_token_field($token); ?>
            <input type="hidden" name="action" value="backfill"><input type="hidden" name="destination_id" value="<?php echo $id; ?>">
            <label class="form-text mt-0" for="backfill<?php echo $id; ?>">Send earlier conversions, from the last</label>
            <select class="form-select form-select-sm w-auto" id="backfill<?php echo $id; ?>" name="days">
                <?php foreach ([1, 3, 7, 30, 90] as $days) {
                    if ($days <= DestinationQueue::backfillDays((string) $d['type'])) {
                        echo '<option value="' . $days . '">' . $days . ($days === 1 ? ' day' : ' days') . '</option>';
                    }
                } ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-secondary">Send</button>
        </form>
    </div>
    <?php return (string) ob_get_clean();
}

/**
 * A destination's edit form, closed until asked for or refused.
 *
 * @param array<string, mixed> $d as DestinationStore::get() shapes it
 * @param array<int, string> $campaigns the account's live campaigns, id to label
 * @param array<string, mixed>|null $posted the refused edit of this destination, to show again
 * @param array<string, mixed> $errors its errors
 */
function p202_destination_edit(array $d, array $campaigns, ?array $posted, array $errors, string $self, string $token, string $privacy, string $base): string
{
    $id = (int) $d['destination_id'];
    $isAds = $d['type'] === GoogleAdsDestination::TYPE;
    $settings = (array) $d['settings'];
    $consent = (array) ($settings['consent'] ?? []);
    $text = static fn (string $key, string $stored): string => p202_setup_e($posted === null ? $stored : (is_string($posted[$key] ?? null) ? $posted[$key] : ''));
    $stored = static fn (string $key): string => is_scalar($settings[$key] ?? null) ? (string) $settings[$key] : '';
    $choice = static fn (string $key, string $storedValue): string => $posted === null ? $storedValue : (is_string($posted[$key] ?? null) ? $posted[$key] : '');
    $selectedCampaigns = array_map('strval', $posted === null ? (array) ($settings['campaign_ids'] ?? []) : array_filter((array) ($posted['campaign_ids'] ?? []), 'is_string'));
    $every = $posted === null ? $selectedCampaigns === [] : ($posted['all_campaigns'] ?? '') === '1';
    $checked = static fn (bool $on): string => $on ? ' checked' : '';
    // A stored campaign that is gone is still offered, selected (CLAUDE.md #4): saving says to take it out.
    $offered = $campaigns;
    foreach ($selectedCampaigns as $one) {
        $offered[(int) $one] ??= 'Campaign ' . $one . ' (deleted)';
    }
    if ($isAds) {
        $rows = $posted === null
            ? array_map(static fn ($campaign, $action): array => [(string) $campaign, (string) $action], array_keys((array) ($settings['campaign_conversion_actions'] ?? [])), array_values((array) ($settings['campaign_conversion_actions'] ?? [])))
            : array_map(null, array_map('strval', array_filter((array) ($posted['cca_campaign'] ?? []), 'is_string')), array_map('strval', array_filter((array) ($posted['cca_action'] ?? []), 'is_string')));
    } else {
        $rows = $posted === null
            ? array_map(static fn ($name, $field): array => [(string) $name, (string) $field], array_keys((array) ($settings['params'] ?? [])), array_values((array) ($settings['params'] ?? [])))
            : array_map(null, array_map('strval', array_filter((array) ($posted['param_name'] ?? []), 'is_string')), array_map('strval', array_filter((array) ($posted['param_field'] ?? []), 'is_string')));
    }
    $rows = array_values(array_filter($rows, static fn ($row): bool => is_array($row) && is_string($row[0] ?? null) && is_string($row[1] ?? null) && $row[0] . $row[1] !== ''));
    for ($i = 0; $i < DestinationForm::BLANK_ROWS; $i++) {
        $rows[] = ['', ''];
    }
    $field = static function (string $name, string $key, string $label, string $value, string $help = '', string $type = 'text', string $placeholder = '') use ($id, $errors): string {
        return '<div class="mb-3"><label class="form-label" for="e' . $id . '_' . $name . '">' . p202_setup_e($label) . '</label>'
            . '<input type="' . $type . '" class="form-control' . p202_setup_invalid($errors, $key) . '" id="e' . $id . '_' . $name . '" name="' . $name . '" value="' . $value . '" placeholder="' . p202_setup_e($placeholder) . '">'
            . p202_setup_feedback($errors, $key)
            . ($help !== '' ? '<div class="form-text">' . p202_setup_e($help) . '</div>' : '') . '</div>';
    };
    $shown = ['name', 'settings.measurement_id', 'settings.event_name', 'settings.client_id_source', 'settings.user_id_source', 'settings.params', 'credentials.api_secret',
        'settings.customer_id', 'settings.login_customer_id', 'settings.conversion_action', 'settings.campaign_conversion_actions', 'credentials.developer_token',
        'credentials.oauth_client_id', 'credentials.oauth_client_secret', 'settings.campaign_ids', 'settings.consent.ad_user_data', 'settings.consent.ad_personalization'];
    $keep = 'Leave blank to keep the stored one.';
    ob_start(); ?>
    <details class="p202-disclosure mb-3" id="edit<?php echo $id; ?>"<?php echo $posted !== null ? ' open' : ''; ?>>
        <summary>Edit <span class="p202-disclosure__hint">name, settings, campaigns, credentials</span></summary>
        <div class="p202-disclosure__body">
            <form method="post" action="<?php echo p202_setup_e($self); ?>#d<?php echo $id; ?>" autocomplete="off">
                <?php echo p202_setup_token_field($token); ?>
                <input type="hidden" name="action" value="update"><input type="hidden" name="destination_id" value="<?php echo $id; ?>">
                <?php echo p202_setup_error_flashes($errors, $shown); ?>
                <?php echo $field('name', 'name', 'Name', $text('name', (string) $d['name'])); ?>
                <?php if ($isAds) {
                    echo $field('customer_id', 'settings.customer_id', 'Customer ID', $text('customer_id', $stored('customer_id')), 'The Google Ads account the conversions go to.', 'text', '123-456-7890');
                    echo $field('login_customer_id', 'settings.login_customer_id', 'Manager account ID (optional)', $text('login_customer_id', $stored('login_customer_id')), 'Only when your access to that account is through a manager account.');
                    echo $field('conversion_action', 'settings.conversion_action', 'Conversion action ID', $text('conversion_action', $stored('conversion_action')), 'Goals › Conversions › your action: the number after ctId= in its address.');
                } else {
                    echo $field('measurement_id', 'settings.measurement_id', 'Measurement ID', $text('measurement_id', $stored('measurement_id')), '', 'text', 'G-XXXXXXXXXX');
                    echo $field('api_secret', 'credentials.api_secret', 'API secret', '', $keep . ' Stored encrypted, never shown.', 'password');
                    echo $field('event_name', 'settings.event_name', 'Event name', $text('event_name', $stored('event_name')));
                } ?>
                <?php if (!$isAds) { ?>
                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6">
                        <label class="form-label" for="e<?php echo $id; ?>_client_id_source">GA client id</label>
                        <select class="form-select<?php echo p202_setup_invalid($errors, 'settings.client_id_source'); ?>" id="e<?php echo $id; ?>_client_id_source" name="client_id_source">
                            <?php echo p202_setup_options(['click' => 'Made from the click', 'c1' => 'In c1', 'c2' => 'In c2', 'c3' => 'In c3', 'c4' => 'In c4'], $choice('client_id_source', $stored('client_id_source') ?: 'click')); ?>
                        </select>
                        <?php echo p202_setup_feedback($errors, 'settings.client_id_source'); ?>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label" for="e<?php echo $id; ?>_user_id_source">GA user id</label>
                        <select class="form-select<?php echo p202_setup_invalid($errors, 'settings.user_id_source'); ?>" id="e<?php echo $id; ?>_user_id_source" name="user_id_source">
                            <?php echo p202_setup_options(['none' => 'Not sent', 'customer' => 'The customer (p202c.<customer id>)'], $choice('user_id_source', $stored('user_id_source') ?: 'none')); ?>
                        </select>
                        <?php echo p202_setup_feedback($errors, 'settings.user_id_source'); ?>
                    </div>
                </div>
                <?php } ?>
                <div class="mb-3">
                    <span class="form-label d-block"><?php echo $isAds ? 'Conversion action per campaign' : 'Event parameters'; ?></span>
                    <?php foreach ($rows as $n => [$first, $second]) { ?>
                    <div class="row g-2 mb-2">
                        <?php if ($isAds) {
                            $rowCampaigns = $campaigns;
                            if ($first !== '') {
                                $rowCampaigns[(int) $first] ??= 'Campaign ' . $first . ' (deleted)';
                            } ?>
                        <div class="col-7">
                            <select class="form-select" name="cca_campaign[]" aria-label="Campaign <?php echo $n + 1; ?>">
                                <?php echo p202_setup_options($rowCampaigns, $first, 'Campaign…'); ?>
                            </select>
                        </div>
                        <div class="col-5"><input type="text" class="form-control" name="cca_action[]" value="<?php echo p202_setup_e($second); ?>" placeholder="Conversion action ID" aria-label="Conversion action <?php echo $n + 1; ?>"></div>
                        <?php } else { ?>
                        <div class="col-6"><input type="text" class="form-control" name="param_name[]" value="<?php echo p202_setup_e($first); ?>" placeholder="Parameter name" aria-label="Parameter name <?php echo $n + 1; ?>"></div>
                        <div class="col-6">
                            <select class="form-select" name="param_field[]" aria-label="Click field <?php echo $n + 1; ?>">
                                <?php echo p202_setup_options(array_combine(Ga4Destination::PARAM_FIELDS, Ga4Destination::PARAM_FIELDS), $second, 'Not sent'); ?>
                            </select>
                        </div>
                        <?php } ?>
                    </div>
                    <?php } ?>
                    <?php echo p202_setup_feedback($errors, $isAds ? 'settings.campaign_conversion_actions' : 'settings.params'); ?>
                    <div class="form-text"><?php echo $isAds
                        ? 'A campaign listed here uploads to its own action; every other campaign uses the action above. Clear a row\'s action to remove it. Save to get more rows.'
                        : 'Each parameter is sent with the click field you pick (value, currency and transaction_id are always sent). Set a row to Not sent to remove it. Save to get more rows.'; ?></div>
                </div>
                <div class="mb-3">
                    <span class="form-label d-block">Campaigns</span>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="e<?php echo $id; ?>_all_campaigns" name="all_campaigns" value="1"<?php echo $checked($every); ?>>
                        <label class="form-check-label" for="e<?php echo $id; ?>_all_campaigns">Every campaign</label>
                    </div>
                    <select class="form-select<?php echo p202_setup_invalid($errors, 'settings.campaign_ids'); ?>" name="campaign_ids[]" multiple size="<?php echo max(2, min(8, count($offered))); ?>" aria-label="Only these campaigns">
                        <?php foreach ($offered as $campaignId => $label) {
                            echo '<option value="' . (int) $campaignId . '"' . (in_array((string) $campaignId, $selectedCampaigns, true) ? ' selected' : '') . '>' . p202_setup_e($label) . '</option>';
                        } ?>
                    </select>
                    <?php echo p202_setup_feedback($errors, 'settings.campaign_ids'); ?>
                    <div class="form-text">To send only some campaigns' conversions, untick Every campaign and select them (Ctrl or ⌘ click for several).</div>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="e<?php echo $id; ?>_send_user_data" name="send_user_data" value="1"<?php echo $checked($posted === null ? !empty($settings['send_user_data']) : ($posted['send_user_data'] ?? '') === '1'); ?>>
                    <label class="form-check-label" for="e<?php echo $id; ?>_send_user_data">Send hashed email and phone</label>
                </div>
                <div class="row g-3 mb-2">
                    <?php foreach (['ad_user_data' => 'Ad User Data', 'ad_personalization' => 'Ad Personalization'] as $key => $label) { ?>
                    <div class="col-12 col-sm-6">
                        <label class="form-label" for="e<?php echo $id . '_' . $key; ?>"><?php echo $label; ?> consent</label>
                        <select class="form-select<?php echo p202_setup_invalid($errors, 'settings.consent.' . $key); ?>" id="e<?php echo $id . '_' . $key; ?>" name="<?php echo $key; ?>">
                            <?php echo p202_setup_options(['' => 'Not stated', 'granted' => 'Granted', 'denied' => 'Denied'], $choice($key, is_string($consent[$key] ?? null) ? $consent[$key] : '')); ?>
                        </select>
                        <?php echo p202_setup_feedback($errors, 'settings.consent.' . $key); ?>
                    </div>
                    <?php } ?>
                </div>
                <div class="form-text mb-3"><?php echo p202_setup_e($privacy); ?> Change it in <?php echo p202_destination_privacy_link($base); ?>.</div>
                <?php if ($isAds) {
                    echo $field('developer_token', 'credentials.developer_token', 'Developer token', '', $keep, 'password');
                    echo $field('oauth_client_id', 'credentials.oauth_client_id', 'OAuth client ID', '', $keep . ' A different client needs Connect Google Ads again.');
                    echo $field('oauth_client_secret', 'credentials.oauth_client_secret', 'OAuth client secret', '', $keep, 'password');
                } ?>
                <div class="p202-form-actions">
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
                <div class="form-text">Saving makes the conversions waiting for this destination due at once.</div>
            </form>
        </div>
    </details>
    <?php return (string) ob_get_clean();
}

/**
 * A destination's delivery log, a page at a time, with a send-again button on
 * each failed or withheld row.
 *
 * @param array<string, mixed> $d
 * @param list<array<string, mixed>> $rows one more than $perPage when a next page exists
 */
function p202_destination_log(array $d, array $rows, ?string $status, int $page, int $perPage, string $self, string $base, string $token, callable $when): string
{
    $id = (int) $d['destination_id'];
    $more = count($rows) > $perPage;
    $rows = array_slice($rows, 0, $perPage);
    $here = p202_setup_e($self) . '?log=' . $id;
    $link = static fn (?string $s, int $p): string => $here . ($s !== null ? '&amp;status=' . $s : '') . ($p > 1 ? '&amp;page=' . $p : '');
    $pill = ['sent' => ' p202-pill--good', 'failed' => ' p202-pill--bad', 'withheld' => ' p202-pill--warn'];
    ob_start(); ?>
    <section class="p202-panel" id="log">
        <div class="p202-panel__head">
            <h2 class="p202-panel__title">Delivery log: <?php echo p202_setup_e($d['name']); ?></h2>
            <span class="p202-panel__sub"><a href="<?php echo p202_setup_e($self); ?>#d<?php echo $id; ?>">back to destinations</a></span>
        </div>
        <div class="p202-panel__body">
            <div class="form-text mb-3">Show:
                <?php foreach ([null => 'everything'] + array_combine(DestinationQueue::STATUSES, ['waiting', 'sent', 'failed', 'withheld', 'cancelled']) as $s => $label) {
                    $s = $s === '' ? null : (string) $s;
                    echo $s === $status ? '<span class="p202-pill p202-pill--accent">' . $label . '</span> ' : '<a href="' . $link($s, 1) . '">' . $label . '</a> ';
                } ?>
            </div>
            <?php if ($rows === []) { ?>
            <div class="p202-empty">
                <i class="bi bi-send p202-empty__icon"></i>
                <strong class="p202-empty__title">Nothing here</strong>
                <div><?php echo $page > 1 ? 'No more rows.' : ($status === null ? 'No conversion has been queued for this destination yet.' : 'No delivery has this status.'); ?></div>
            </div>
            <?php } else { ?>
            <div class="p202-table-wrap">
                <table class="table p202-table">
                    <thead><tr><th>When</th><th>Conversion</th><th>Status</th><th class="num">Tries</th><th>Last answer</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $row) {
                            $conversion = match (true) {
                                $row['conv_id'] === null => 'test',
                                $row['click_id'] !== null => '<a href="' . p202_setup_e($base) . 'tracking202/logs/?click_id=' . (int) $row['click_id']
                                    . '" title="This click\'s conversions in Conversion Logs">' . (int) $row['conv_id'] . '</a>',
                                default => (string) (int) $row['conv_id'],
                            }; ?>
                        <tr>
                            <td><?php echo p202_setup_e($when((int) $row['created_at'])); ?></td>
                            <td><?php echo $conversion; ?></td>
                            <td><span class="p202-pill<?php echo $pill[(string) $row['status']] ?? ''; ?>"><?php echo p202_setup_e($row['status'] === 'pending' ? 'waiting' : (string) $row['status']); ?></span></td>
                            <td class="num"><?php echo (int) $row['attempts']; ?></td>
                            <td><?php echo p202_setup_e(trim(($row['last_status_code'] !== null ? $row['last_status_code'] . ' ' : '') . (string) ($row['last_error'] ?? ''))); ?></td>
                            <td>
                                <?php if ($row['kind'] === DestinationQueue::KIND_CONVERSION && in_array($row['status'], DestinationQueue::RESENDABLE, true)) { ?>
                                <form method="post" action="<?php echo $link($status, $page); ?>">
                                    <?php echo p202_setup_token_field($token); ?>
                                    <input type="hidden" name="action" value="resend"><input type="hidden" name="destination_id" value="<?php echo $id; ?>"><input type="hidden" name="delivery_id" value="<?php echo (int) $row['delivery_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">send again</button>
                                </form>
                                <?php } ?>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php } ?>
            <?php if ($page > 1 || $more) { ?>
            <nav class="mt-3" aria-label="Pages">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item<?php echo $page > 1 ? '' : ' disabled'; ?>"><a class="page-link" href="<?php echo $link($status, max(1, $page - 1)); ?>">‹ newer</a></li>
                    <li class="page-item active" aria-current="page"><a class="page-link" href="<?php echo $link($status, $page); ?>"><?php echo $page; ?></a></li>
                    <li class="page-item<?php echo $more ? '' : ' disabled'; ?>"><a class="page-link" href="<?php echo $link($status, $page + 1); ?>">older ›</a></li>
                </ul>
            </nav>
            <?php } ?>
        </div>
    </section>
    <?php return (string) ob_get_clean();
}
