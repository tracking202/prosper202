<?php

declare(strict_types=1);

/**
 * The "a new version is available" banner the chrome shows under the header.
 *
 * 202-js/p202-chrome.js asks 202-account/ajax/check-for-update.php to refresh
 * the session's update state, then draws what 202-account/ajax/update-needed.php
 * answers into #update_needed. Dismissing it posts to ajax/delay-alert.php,
 * which snoozes it for an hour in this session.
 *
 * The banner was Bootstrap 3 panel markup loaded by the classic shell's
 * custom.php, so the v2 pages had carried no banner at all; it moved to the
 * component layer with U8. It is the kit's flash (202-account/ui-kit.php),
 * dismissible. The remote release feed's headline and body are text here:
 * no remote markup reaches the page. The feed's register link is used only
 * when it is an http(s) URL.
 *
 * A pure function of its arguments, so tests/Api/V3/UpdateBannerTest.php
 * renders every state.
 *
 * @param array{
 *     show?: bool,
 *     managed?: bool,
 *     not_possible?: bool,
 *     update_needed?: bool,
 *     premium?: bool,
 *     premium_details?: array<string, mixed>,
 *     has_customer_key?: bool
 * } $state
 */
function p202_update_banner(array $state, string $base): string
{
    if (empty($state['show'])) {
        return '';
    }
    $managed = !empty($state['managed']);
    $notPossible = !empty($state['not_possible']);
    $updateNeeded = !empty($state['update_needed']);
    $premium = !empty($state['premium']);
    if (!$notPossible && !$updateNeeded && !$premium) {
        return '';
    }

    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $details = is_array($state['premium_details'] ?? null) ? $state['premium_details'] : [];
    $detail = static fn (string $key): string => is_scalar($details[$key] ?? null) ? trim((string) $details[$key]) : '';
    $manual = '<a class="btn btn-sm btn-outline-secondary" href="https://my.tracking202.com/" target="_blank" rel="noopener">Download the upgrade files</a>';
    $oneClick = '<a class="btn btn-sm btn-primary" href="' . $e($base . '202-account/auto-upgrade-premium.php') . '">1-Click Upgrade</a>';

    $title = 'A new version of Prosper202 is available.';
    $text = '';
    $actions = [];
    if ($managed) {
        $text = 'This install is a managed deployment built from git, so the 1-Click Upgrade is off: files it changed would be reverted on the next redeploy. Pull the new version and redeploy; the database upgrade runs after you sign in again.';
    } elseif ($notPossible) {
        $text = 'The 1-Click Upgrade needs a writable 202-config/ directory and the PHP zip extension, and this server has not got both. Fix that to upgrade in one click, or upgrade by hand.';
        $actions[] = $manual;
    } elseif ($updateNeeded) {
        $text = 'Upgrade in one click, or by hand. The upgrade page lists what is new.';
        $actions[] = $oneClick;
        $actions[] = $manual;
    } else {
        $title = $detail('headline') !== '' ? $detail('headline') : $title;
        $parts = array_filter([$detail('body'), $detail('release-date') !== '' ? 'Released ' . $detail('release-date') . '.' : '']);
        $text = implode(' ', $parts);
        if (empty($state['has_customer_key'])) {
            $text = trim($text . ' To upgrade, add your Prosper202 Customer API key in Personal Settings.');
            $register = $detail('register-link');
            if (preg_match('~^https?://~i', $register) === 1) {
                $label = $detail('register-button-text') !== '' ? $detail('register-button-text') : 'Get a Customer API key';
                $actions[] = '<a class="btn btn-sm btn-outline-secondary" href="' . $e($register) . '" target="_blank" rel="noopener">' . $e($label) . '</a>';
            }
            $actions[] = '<a class="btn btn-sm btn-primary" href="' . $e($base . '202-account/account.php') . '">Personal Settings</a>';
        } else {
            $label = $detail('order-button-text') !== '' ? $detail('order-button-text') : '1-Click Upgrade';
            $price = $detail('upgrade-price');
            if ($price !== '' && is_numeric($price)) {
                $label .= ' ($' . $price . ')';
            }
            $actions[] = '<a class="btn btn-sm btn-primary" href="' . $e($base . '202-account/auto-upgrade-premium.php') . '">' . $e($label) . '</a>';
        }
    }

    $html = '<div class="alert alert-warning p202-flash alert-dismissible" role="status" data-p202-update-banner>'
        . '<i class="bi bi-arrow-up-circle"></i>'
        . '<div class="p202-flash__body"><strong>' . $e($title) . '</strong>';
    if ($text !== '') {
        $html .= ' ' . $e($text);
    }
    if ($actions !== []) {
        $html .= '<div class="p202c-update__actions">' . implode('', $actions) . '</div>';
    }
    $html .= '</div><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Hide for an hour"></button></div>';
    return $html;
}
