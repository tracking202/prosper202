<?php

/**
 * Personal settings' Money-loss alerts card (202-account/account.php): its
 * form's handling, its test alert, the read the card shows, and the card;
 * and the account's alerts as Home and the card list them. Every value goes
 * through AlertSettings::fromInput(). The card needs functions-account-ui.php.
 */

declare(strict_types=1);

use Prosper202\Alerts\AlertKind;
use Prosper202\Alerts\AlertNotifier;
use Prosper202\Alerts\AlertRun;
use Prosper202\Alerts\AlertSettings;
use Prosper202\Alerts\MysqlAlertStore;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;

const P202_ALERTS_RETURN = '202-account/account.php#money-loss-alerts';

/**
 * A POST of the card: saved and redirected, or answered with the refusals
 * to show in place, and what was typed.
 *
 * @param array<array-key, mixed> $post the request body
 * @return array{errors: array<string, string>, form: array<string, mixed>,
 *     flashes: list<array{kind: string, text: string}>}
 */
function p202_alerts_post(\mysqli $db, int $userId, array $post): array
{
    $answer = ['errors' => [], 'form' => [], 'flashes' => []];
    if (!isset($post['update_money_loss_alerts']) && !isset($post['send_test_money_loss_alert'])) {
        return $answer;
    }
    if (!AUTH::check_csrf_token()) {
        p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
        p202_account_redirect(P202_ALERTS_RETURN);
    }
    if (isset($post['send_test_money_loss_alert'])) {
        [$kind, $text] = p202_alerts_send_test($db, $userId, $_SERVER, dirname(__DIR__), time());
        p202_account_flash($kind, $text);
        p202_account_redirect(P202_ALERTS_RETURN);
    }
    $posted = [
        'enabled' => $post['alerts_enabled'] ?? '0',
        'notify_slack' => $post['alerts_notify_slack'] ?? '0',
        'notify_email' => $post['alerts_notify_email'] ?? '0',
        'notify_webhook' => $post['alerts_notify_webhook'] ?? '0',
        'gap_multiple' => $post['alerts_gap_multiple'] ?? '',
        'min_conversions' => $post['alerts_min_conversions'] ?? '',
        'daily_cost_cap' => $post['alerts_daily_cost_cap'] ?? '',
    ];
    [$settings, $errors] = AlertSettings::fromInput($posted);
    if ($settings === null) {
        $labels = [
            'enabled' => 'Alerts', 'notify_slack' => 'Slack', 'notify_email' => 'Email', 'notify_webhook' => 'Webhooks',
            'gap_multiple' => 'The multiple of the usual gap', 'min_conversions' => 'The converting hours a week',
            'daily_cost_cap' => 'The daily cost cap',
        ];
        foreach ($errors as $field => $why) {
            $answer['errors']['alerts_' . $field] = ($labels[$field] ?? $field) . ' ' . $why . '.';
        }
        $answer['form'] = $posted;
        $answer['flashes'][] = [
            'kind' => 'bad',
            'text' => 'Money-loss alerts were not saved; see the fields marked below.',
        ];

        return $answer;
    }
    try {
        (new MysqlAlertStore(new Connection($db)))->saveSettings($userId, $settings);
    } catch (QueryException | \mysqli_sql_exception $e) {
        error_log('account.php: the money-loss alert settings were not saved: ' . $e->getMessage());
        $answer['flashes'][] = [
            'kind' => 'bad',
            'text' => 'Your money-loss alerts could not be saved. Nothing changed; try again.',
        ];

        return $answer;
    }
    p202_account_flash('ok', $settings->enabled
        ? 'Money-loss alerts are on. The cron job checks your campaigns every hour.'
        : 'Money-loss alerts are off for your account. Turn them back on here at any time.');
    p202_account_redirect(P202_ALERTS_RETURN);
}

/**
 * Sends a test alert on the channels the account's saved settings turn on,
 * as the hourly run sends a real one: the flash's kind and its sentence,
 * which says what each channel did.
 *
 * @param array<string, mixed> $server normally $_SERVER
 * @param ?AlertNotifier $notifier the install's when null
 * @return array{0: 'ok'|'warn'|'bad', 1: string}
 */
function p202_alerts_send_test(
    \mysqli $db,
    int $userId,
    array $server,
    string $installRoot,
    int $now,
    ?AlertNotifier $notifier = null
): array {
    $conn = new Connection($db);
    try {
        $settings = (new MysqlAlertStore($conn))->settings($userId);
        if (!$settings->enabled) {
            return ['warn', 'No test alert was sent: money-loss alerts are off for your account. Turn them on and'
                . ' save, then send the test.'];
        }
        $notifier ??= new AlertNotifier(
            $conn,
            new \Prosper202\Notifications\SlackWebhook(),
            null,
            AlertNotifier::settingsUrl($conn, $server, $installRoot)
        );
        $contact = $notifier->contact($userId);
        $result = $notifier->deliver(AlertNotifier::testAlert($userId, $now), $settings, $contact);
    } catch (\Throwable $e) {
        error_log('account.php: the test alert could not be sent: ' . get_class($e) . ': ' . $e->getMessage());

        return ['bad', 'The test alert could not be sent. Try again; if it keeps failing, the server error log'
            . ' says why.'];
    }
    $took = [
        AlertNotifier::SLACK => 'Slack took it: look for it in your channel.',
        AlertNotifier::EMAIL => 'Email: this server\'s mail system accepted it for ' . $contact['email']
            . '. If it has not arrived in a few minutes (look in spam too), this server is not delivering mail:'
            . ' use Slack or a webhook as well. Every alert is listed on Home and on this card whatever happens to'
            . ' its message.',
        AlertNotifier::WEBHOOK => 'Webhooks: queued as alert.test for your webhooks that take the alerts; the cron'
            . ' job sends it within a minute.',
    ];
    $lines = [];
    foreach ($result['taken'] as $channel) {
        $lines[] = $took[$channel];
    }
    foreach ($result['failed'] as $channel => $why) {
        $lines[] = AlertNotifier::CHANNEL_NAMES[$channel] . ': failed, ' . $why . '.';
    }
    foreach ($result['skipped'] as $channel => $why) {
        $lines[] = AlertNotifier::CHANNEL_NAMES[$channel] . ': not tried, ' . $why . '.';
    }
    if ($result['taken'] === []) {
        return ['bad', 'The test alert reached no channel, so a real alert would be shown only on Home and on this'
            . ' card. ' . implode(' ', $lines)];
    }

    return [
        $result['failed'] === [] ? 'ok' : 'warn',
        'Test alert sent with your saved settings. ' . implode(' ', $lines),
    ];
}

/**
 * The account's alerts as Home and the settings card list them: when, what,
 * the page that shows the problem, and what each channel did with it. ''
 * when there are none.
 *
 * @param list<array{kind: AlertKind, campaign_id: int, message: string, first_seen_at: int, last_seen_at: int,
 *     delivery: int, attempts: int, sent_by: list<string>, delivery_note: string}> $alerts MysqlAlertStore::recent()
 * @param string $base the install's address (get_absolute_url())
 */
function p202_alerts_list(array $alerts, string $base, int $now): string
{
    if ($alerts === []) {
        return '';
    }
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $rows = '';
    foreach ($alerts as $alert) {
        $active = $alert['last_seen_at'] >= $now - AlertRun::ACTIVE_SECONDS;
        $names = implode(', ', array_map(
            static fn (string $channel): string => AlertNotifier::CHANNEL_NAMES[$channel] ?? $channel,
            $alert['sent_by']
        ));
        $note = $alert['delivery_note'] !== '' ? ' ' . $alert['delivery_note'] . '.' : '';
        $delivery = match ($alert['delivery']) {
            MysqlAlertStore::SENT => 'Sent by ' . $names . '.' . $note,
            MysqlAlertStore::FAILED => ($alert['attempts'] >= AlertRun::MAX_ATTEMPTS
                ? 'Never sent, after ' . $alert['attempts'] . ' tries.'
                : 'Not sent yet (try ' . $alert['attempts'] . ' of ' . AlertRun::MAX_ATTEMPTS . ').') . $note,
            MysqlAlertStore::NOWHERE => 'Shown here only: no channel had anywhere to send it.' . $note,
            default => 'Not sent yet.',
        };
        $rows .= '<tr><td class="text-nowrap">' . $e(date('Y-m-d H:i', $alert['first_seen_at'])) . '</td>'
            . '<td><span class="p202-pill' . ($active ? ' p202-pill--bad">Going on' : '">Earlier') . '</span> '
            . '<strong>' . $e($alert['kind']->title()) . '</strong><div>' . $e($alert['message']) . '</div>'
            . '<div><a href="' . $e($base . $alert['kind']->page($alert['campaign_id'])) . '">'
            . $e($alert['kind']->pageLabel()) . '</a></div></td>'
            . '<td' . ($alert['delivery'] === MysqlAlertStore::FAILED ? ' class="text-danger"' : '') . '>'
            . $e($delivery) . '</td></tr>';
    }

    return '<div class="p202-table-wrap"><table class="table p202-table">'
        . '<thead><tr><th>First seen</th><th>Alert</th><th>How it was sent</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div>';
}

/**
 * Home's panel: the account's alerts of the last 7 days, the ones still
 * going on first in time. '' when there are none, or when they cannot be
 * read (logged; Home has nothing else to say about it).
 */
function p202_alerts_home_panel(\mysqli $db, int $userId, string $base, int $now): string
{
    try {
        $alerts = (new MysqlAlertStore(new Connection($db)))->recent($userId, $now - 7 * 86400, 10);
    } catch (\Throwable $e) {
        error_log('Home: the money-loss alerts could not be read: ' . get_class($e) . ': ' . $e->getMessage());

        return '<section class="p202-panel mb-4" id="money-loss-alerts"><div class="p202-panel__head">'
            . '<h2 class="p202-panel__title">Money-loss alerts</h2></div><div class="p202-panel__body">'
            . 'Your alerts could not be read just now. Reload the page to see them.</div></section>';
    }
    if ($alerts === []) {
        return '';
    }
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    return '<section class="p202-panel mb-4" id="money-loss-alerts"><div class="p202-panel__head">'
        . '<h2 class="p202-panel__title">Money-loss alerts</h2>'
        . '<span class="p202-panel__sub">the last 7 days · <a href="' . $e($base . AlertNotifier::SETTINGS_PAGE)
        . '">settings</a></span></div>'
        . '<div class="p202-panel__body">' . p202_alerts_list($alerts, $base, $now) . '</div></section>';
}

/**
 * The card's read: the settings as stored and where alerts would go, or
 * null with a flash when they cannot be read (so no form of defaults is
 * shown to save over them); and the alerts of the last 30 days, null when
 * they cannot be read (the card says so rather than "none").
 *
 * @return array{settings: ?AlertSettings, contact: array{email: string, slack: string, domain: string},
 *     recent: ?list<array<string, mixed>>, flashes: list<array{kind: string, text: string}>}
 */
function p202_alerts_view(\mysqli $db, int $userId): array
{
    $view = [
        'settings' => null,
        'contact' => ['email' => '', 'slack' => '', 'domain' => ''],
        'recent' => [],
        'flashes' => [],
    ];
    $conn = new Connection($db);
    try {
        $view['settings'] = (new MysqlAlertStore($conn))->settings($userId);
        $view['contact'] = (new AlertNotifier($conn))->contact($userId);
    } catch (QueryException | \mysqli_sql_exception | \UnexpectedValueException $e) {
        error_log('Account page: the money-loss alert settings could not be read: ' . $e->getMessage());
        $view['settings'] = null;
        $view['flashes'][] = [
            'kind' => 'bad',
            'text' => 'Your money-loss alert settings could not be read just now. Reload the page to see them.',
        ];

        return $view;
    }
    try {
        $view['recent'] = (new MysqlAlertStore($conn))->recent($userId, time() - 30 * 86400, 20);
    } catch (\Throwable $e) {
        error_log('Account page: the money-loss alerts could not be read: ' . get_class($e) . ': ' . $e->getMessage());
        $view['recent'] = null;
    }

    return $view;
}

/**
 * @param array{email: string, slack: string, domain: string} $contact
 * @param array<string, string> $errors
 * @param array<string, mixed> $form what a refused save sent
 * @param ?list<array<string, mixed>> $recent the account's alerts of the last 30 days (MysqlAlertStore::recent()),
 *        null when they could not be read
 */
function p202_alerts_card(
    AlertSettings $settings,
    array $contact,
    array $errors,
    array $form,
    ?array $recent = []
): string {
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $action = $e(get_absolute_url() . P202_ALERTS_RETURN);
    $values = $settings->toArray();
    if ($form !== []) {
        foreach ($form as $key => $value) {
            $values[$key] = is_string($value) ? $value : '';
        }
    }
    $on = static fn (string $key): bool => $values[$key] === true || $values[$key] === '1';
    $text = static fn (string $key): string => is_bool($values[$key]) ? '' : (string) $values[$key];
    $switch = static function (string $key, string $label, string $help) use ($e, $on, $errors): string {
        $name = 'alerts_' . $key;

        return '<div class="form-check form-switch mb-2">'
            . '<input class="form-check-input' . p202_account_invalid($errors, $name) . '"'
            . ' type="checkbox" role="switch" id="'
            . $e($name) . '" name="' . $e($name) . '" value="1"' . ($on($key) ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="' . $e($name) . '">' . $e($label) . '</label>'
            . '<div class="form-text mt-0">' . $help . '</div>' . p202_account_field_error($errors, $name) . '</div>';
    };
    $number = static function (
        string $key,
        string $label,
        string $help,
        string $attrs
    ) use (
        $e,
        $text,
        $errors
    ): string {
        $name = 'alerts_' . $key;

        return '<div class="col-md-4"><label class="form-label" for="' . $e($name) . '">' . $e($label) . '</label>'
            . '<input type="text" inputmode="decimal" class="form-control' . p202_account_invalid($errors, $name)
            . '" id="'
            . $e($name) . '" name="' . $e($name) . '" value="' . $e($text($key)) . '" ' . $attrs . '>'
            . '<div class="form-text">' . $e($help) . '</div>' . p202_account_field_error($errors, $name) . '</div>';
    };
    $integrations = '<a href="' . $e(get_absolute_url() . '202-account/api-integrations.php#slack')
        . '">API integrations</a>';
    $slackHelp = $contact['slack'] === ''
        ? 'No Slack webhook is set for your account: add one on ' . $integrations . '.'
        : 'To the Slack incoming webhook set on ' . $integrations . '.';
    $emailHelp = $contact['email'] === ''
        ? 'Your account has no valid email address; set one under your profile above.'
        : 'To ' . $e($contact['email']) . ', sent by this server\'s mail().';
    $webhookHelp = 'Queued for your account\'s webhooks subscribed to <code>' . $e(AlertKind::ConversionsStopped->value)
        . '</code>, <code>' . $e(AlertKind::SpendWithoutConversions->value) . '</code> or <code>'
        . $e(AlertKind::PostbackRefusalsSpiked->value) . '</code> (or to every event).';

    return '<section class="p202-panel mt-4" id="money-loss-alerts">'
        . '<div class="p202-panel__head"><h2 class="p202-panel__title">Money-loss alerts</h2>'
        . '<span class="p202-panel__sub">when a campaign keeps buying clicks but its conversions stop</span></div>'
        . '<div class="p202-panel__body">'
        . '<p class="form-text mt-0">A postback or pixel that breaks quietly costs every click until someone looks.'
        . ' Once an hour the cron job checks each of your campaigns, and tells you once per incident when a campaign'
        . ' that converts steadily has had no conversion for several times its usual gap while real clicks keep'
        . ' arriving, when a campaign\'s cost today passes your daily cap with no conversion today, or when far more'
        . ' of your postbacks and pixels are refused in an hour than usual (Setup › Inbound Log). A conversion'
        . ' re-arms the first; the others are told at most once a day. Email goes to your account\'s address, Slack'
        . ' only if you set a webhook, and webhooks only to ones subscribed to the alerts.</p>'
        . '<form method="post" action="' . $action . '">'
        . '<input type="hidden" name="update_money_loss_alerts" value="1">' . p202_account_token_field()
        . $switch(
            'enabled',
            'Alerts on',
            'On by default. Turn this off to stop every money-loss alert for your account.'
        )
        . '<h3 class="p202-panel__title mb-2 mt-3">Where they go</h3>'
        . $switch('notify_slack', 'Slack', $slackHelp)
        . $switch('notify_email', 'Email', $emailHelp)
        . $switch('notify_webhook', 'Webhooks', $webhookHelp)
        . '<h3 class="p202-panel__title mb-2 mt-3">When</h3>'
        . '<div class="row g-3 mb-3">'
        . $number(
            'gap_multiple',
            'Times the usual gap',
            'No conversion for this many times the campaign\'s usual time between conversions, and as many times'
            . ' its usual clicks per conversion. ' . AlertSettings::GAP_MULTIPLE_MIN . ' to '
            . AlertSettings::GAP_MULTIPLE_MAX . '; 5 by default.',
            'maxlength="2"'
        )
        . $number(
            'min_conversions',
            'Converting hours a week to be steady',
            'A campaign whose conversions arrived in fewer different hours of the last ' . AlertSettings::LOOKBACK_DAYS
            . ' days is not checked for this. Uploaded conversions do not count. 10 by default.',
            'maxlength="4"'
        )
        . $number(
            'daily_cost_cap',
            'Daily cost cap per campaign',
            'In your account currency, for each campaign, on your account\'s day. 0 is no cap.',
            'maxlength="10"'
        )
        . '</div>'
        . '<div class="p202-form-actions">'
        . '<button class="btn btn-primary" type="submit">Save money-loss alerts</button></div>'
        . '</form>'
        . '<h3 class="p202-panel__title mb-2 mt-4">Check that they reach you</h3>'
        . '<form method="post" action="' . $action . '" id="money-loss-alerts-test">'
        . '<input type="hidden" name="send_test_money_loss_alert" value="1">' . p202_account_token_field()
        . '<button class="btn btn-secondary" type="submit">Send a test alert</button>'
        . '<div class="form-text">Sent now to each place switched on above, with your saved settings, the way a real'
        . ' alert is. This page then says what each one did with it.</div></form>'
        . '<h3 class="p202-panel__title mb-2 mt-4" id="money-loss-alerts-recent">Your alerts, the last 30 days</h3>'
        . match (true) {
            $recent === null => '<p class="form-text mt-0 mb-0">Your alerts could not be read just now. Reload the'
                . ' page to see them.</p>',
            $recent === [] => '<p class="form-text mt-0 mb-0">None. An alert is listed here and on Home when it is'
                . ' raised, whether or not its message arrives.</p>',
            default => p202_alerts_list($recent, get_absolute_url(), time()),
        }
        . '</div></section>';
}
