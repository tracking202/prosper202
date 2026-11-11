<?php

declare(strict_types=1);

use Prosper202\InboundLog\InboundLog;
use Prosper202\PaymentNotification\CurrencyMismatch;
use Prosper202\PaymentNotification\JVZoo\JVZooRole;
use Prosper202\PaymentNotification\JVZoo\RolelessTraffic;

/**
 * What Account › API integrations says on a payment integration's card
 * about notifications that recorded nothing lately, read from the inbound log.
 */

/**
 * @return array{currency: array<string, array{count: int, currencies: list<string>, latest: int, more: bool}>,
 *     jvzoo: ?RolelessTraffic} jvzoo is null when the log could not be read
 */
function p202_payment_card_state(\mysqli $db, int $userId, string $accountCurrency, ?int $now = null): array
{
    $now ??= time();
    $log = new InboundLog($db);
    try {
        return [
            'currency' => CurrencyMismatch::recent($log, $userId, $accountCurrency, $now - CurrencyMismatch::WINDOW),
            'jvzoo' => RolelessTraffic::recent($log, $userId, $now - RolelessTraffic::WINDOW),
        ];
    } catch (\Throwable $e) {
        error_log('API integrations: the inbound log could not be read for the payment cards: ' . $e->getMessage());

        return ['currency' => [], 'jvzoo' => null];
    }
}

/**
 * The warning on a card whose notifications were refused for their currency ('' for none).
 *
 * @param array<string, array{count: int, currencies: list<string>, latest: int, more: bool}> $found
 */
function p202_currency_mismatch_notice(
    array $found,
    string $endpoint,
    string $provider,
    string $accountCurrency,
    string $base
): string {
    $mine = $found[$endpoint] ?? null;
    if ($mine === null) {
        return '';
    }
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $count = ($mine['more'] ? 'At least ' : '') . number_format($mine['count']);
    $noun = $mine['count'] === 1 && !$mine['more'] ? ' notification' : ' notifications';
    $all = $mine['currencies'];
    $one = count($all) === 1 ? $all[0] : null;
    $in = $one ?? implode(', ', array_slice($all, 0, -1)) . ' and ' . $all[count($all) - 1];
    $log = $base . 'tracking202/setup/inbound_log.php?outcome=currency_mismatch&endpoint=' . rawurlencode($endpoint);

    return '<div class="alert alert-warning p202-flash" role="status" data-currency-mismatch="' . $e($endpoint) . '">'
        . '<i class="bi bi-exclamation-triangle"></i><div class="p202-flash__body"><strong>'
        . $e($count . ' ' . $provider . $noun . ' in ' . $in) . ' recorded nothing</strong> in the last 30 days:'
        . ' this account counts in ' . $e($accountCurrency) . ', and Prosper202 does not convert amounts.'
        . ' If you sell in ' . $e($one ?? 'one of them') . ', <a href="'
        . $e($base . '202-account/account.php#currency') . '">change the account currency to '
        . $e($one ?? 'it') . '</a>, and the sales after that are recorded. <a href="' . $e($log)
        . '">See the ones that were not</a>.</div></div>';
}

/**
 * The warning on the JVZoo card of an account that reads the 1.9.56 URL as
 * an affiliate's while its notifications there look like a vendor's, with
 * the one button that reads it as a vendor's ('' for none).
 */
function p202_jvzoo_roleless_notice(
    ?RolelessTraffic $traffic,
    JVZooRole $stored,
    string $action,
    string $tokenField
): string {
    $asAffiliate = $stored === JVZooRole::AFFILIATE;
    if ($traffic === null || !($traffic->conflict > 0 || ($asAffiliate && $traffic->looksLikeAVendor()))) {
        return '';
    }
    $line = static fn (int $count, string $one, string $many): string
        => number_format($count) . ' ' . ($count === 1 ? $one : $many);
    $lines = [];
    if ($traffic->vendorNoAffiliate > 0) {
        $lines[] = $line(
            $traffic->vendorNoAffiliate,
            'named no affiliate and was read as a vendor’s',
            'named no affiliate and were read as a vendor’s'
        );
    }
    if ($asAffiliate && $traffic->refused > 0) {
        $lines[] = $line(
            $traffic->refused,
            'named an affiliate, was read as an affiliate’s and recorded nothing',
            'named an affiliate, were read as an affiliate’s and recorded nothing'
        );
    }
    if ($asAffiliate && $traffic->asAffiliate > 0) {
        $lines[] = $line($traffic->asAffiliate, 'was accepted as an affiliate’s', 'were accepted as an affiliate’s');
    }
    if ($traffic->conflict > 0) {
        $why = ' refused because another account storing this JVZIPN secret key reads the URL the other way';
        $lines[] = $line($traffic->conflict, 'was' . $why, 'were' . $why);
    }
    $html = '<div class="alert alert-warning p202-flash" role="status" data-jvzoo-roleless-notice>'
        . '<i class="bi bi-exclamation-triangle"></i><div class="p202-flash__body">'
        . '<strong>JVZoo is still sending to the URL Prosper202 1.9.56 showed</strong>'
        . ' (<code>jvzoo.php</code> with no <code>?role=</code>). In the last 30 days: '
        . htmlspecialchars(implode('; ', $lines), ENT_QUOTES, 'UTF-8') . '.';
    if ($asAffiliate && $traffic->looksLikeAVendor()) {
        $html .= ' If you sell your own product on JVZoo, read that URL as a vendor’s, so the sales your affiliates'
            . ' refer are recorded too.';
        if ($traffic->asAffiliate > 0) {
            $html .= ' If you also promote products through it, paste the Affiliate IPN URL and the Vendor JVZIPN'
                . ' URL above into JVZoo instead, each where it belongs.';
        }
        if ($traffic->vendorNoAffiliate === 0) {
            $html .= ' If you only promote products, check that your affiliate links carry'
                . ' <code>?tid=[[subid]]</code>.';
        }
        $html .= '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" class="mt-2"'
            . ' data-jvzoo-read-as-vendor><input type="hidden" name="change_jvzoo_roleless_role" value="1">'
            . $tokenField . '<input type="hidden" name="' . JVZooRole::ROLELESS_COLUMN . '" value="'
            . JVZooRole::VENDOR->value . '">'
            . '<button class="btn btn-secondary btn-sm" type="submit">Read the 1.9.56 URL as a vendor’s</button>'
            . '</form>';
    }

    return $html . '</div></div>';
}
