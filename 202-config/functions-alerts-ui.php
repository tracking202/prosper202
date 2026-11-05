<?php

/**
 * Personal settings' Money-loss alerts card (202-account/account.php): its
 * form's handling, the read the card shows, and the card. Every value goes
 * through AlertSettings::fromInput(). Needs functions-account-ui.php.
 */

declare(strict_types=1);

use Prosper202\Alerts\AlertKind;
use Prosper202\Alerts\AlertNotifier;
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
    if (!isset($post['update_money_loss_alerts'])) {
        return $answer;
    }
    if (!AUTH::check_csrf_token()) {
        p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
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
 * The card's read: the settings as stored and where alerts would go, or
 * null with a flash when they cannot be read (so no form of defaults is
 * shown to save over them).
 *
 * @return array{settings: ?AlertSettings, contact: array{email: string, slack: string, domain: string},
 *     flashes: list<array{kind: string, text: string}>}
 */
function p202_alerts_view(\mysqli $db, int $userId): array
{
    $view = ['settings' => null, 'contact' => ['email' => '', 'slack' => '', 'domain' => ''], 'flashes' => []];
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
    }

    return $view;
}

/**
 * @param array{email: string, slack: string, domain: string} $contact
 * @param array<string, string> $errors
 * @param array<string, mixed> $form what a refused save sent
 */
function p202_alerts_card(AlertSettings $settings, array $contact, array $errors, array $form): string
{
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
        . '</code> or <code>' . $e(AlertKind::SpendWithoutConversions->value) . '</code> (or to every event).';

    return '<section class="p202-panel mt-4" id="money-loss-alerts">'
        . '<div class="p202-panel__head"><h2 class="p202-panel__title">Money-loss alerts</h2>'
        . '<span class="p202-panel__sub">when a campaign keeps buying clicks but its conversions stop</span></div>'
        . '<div class="p202-panel__body">'
        . '<p class="form-text mt-0">A postback or pixel that breaks quietly costs every click until someone looks.'
        . ' Once an hour the cron job checks each of your campaigns, and tells you once per incident when a campaign'
        . ' that converts steadily has had no conversion for several times its usual gap while real clicks keep'
        . ' arriving, or when a campaign\'s cost today passes your daily cap with no conversion today. A conversion'
        . ' re-arms the first; the second is told at most once a day. Email goes to your account\'s address, Slack'
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
        . '</form></div></section>';
}
