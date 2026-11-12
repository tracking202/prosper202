<?php

declare(strict_types=1);

use Prosper202\InboundLog\InboundLog;
use Prosper202\PaymentNotification\CurrencyMismatch;
use Prosper202\PaymentNotification\HeldNotifications;
use Prosper202\PaymentNotification\JVZoo\JVZooRole;
use Prosper202\PaymentNotification\JVZoo\RolelessTraffic;

/**
 * What Account › API integrations says on a payment integration's card
 * about notifications that recorded nothing lately, read from the inbound log.
 */

/**
 * @return array{held: array<string, array<string, array{count: int, latest: int}>>, jvzoo: ?RolelessTraffic}
 *     held: HeldNotifications::counts(); jvzoo is null when the log could not be read
 */
function p202_payment_card_state(\mysqli $db, int $userId, ?int $now = null): array
{
    $now ??= time();
    $state = ['held' => [], 'jvzoo' => null];
    try {
        $state['held'] = (new HeldNotifications($db))->counts($userId);
    } catch (\Throwable $e) {
        error_log('API integrations: the held payment notifications could not be read: ' . $e->getMessage());
    }
    try {
        $state['jvzoo'] = RolelessTraffic::recent(new InboundLog($db), $userId, $now - RolelessTraffic::WINDOW);
    } catch (\Throwable $e) {
        error_log('API integrations: the inbound log could not be read for the JVZoo card: ' . $e->getMessage());
    }

    return $state;
}

/**
 * What a card says of the notifications held for their currency: a button
 * that records the ones the account now counts in, and a warning naming the
 * setting for the ones it does not ('' for none).
 *
 * @param array<string, array<string, array{count: int, latest: int}>> $counts HeldNotifications::counts()
 * @param bool $linksLog whether the role opens Setup › Inbound Log, which the warning then links
 */
function p202_currency_mismatch_notice(
    array $counts,
    string $source,
    string $provider,
    string $accountCurrency,
    string $base,
    string $action,
    string $tokenField,
    bool $linksLog
): string {
    $held = CurrencyMismatch::held($counts, $source, $accountCurrency);
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $noun = static fn (int $n): string => number_format($n) . ' ' . ($n === 1 ? 'notification' : 'notifications');
    $html = '';
    if ($held['recordable'] > 0) {
        $html .= '<div class="alert alert-info p202-flash" role="status" data-held-recordable="' . $e($source) . '">'
            . '<i class="bi bi-info-circle"></i><div class="p202-flash__body"><strong>'
            . $e($noun($held['recordable']) . ' in ' . $accountCurrency) . ' '
            . ($held['recordable'] === 1 ? 'is' : 'are') . ' held</strong> from when this account counted in another'
            . ' currency. Recording them puts each sale on its click at the amount ' . $e($provider) . ' stated, dated'
            . ' when it happened; one already on file is not recorded twice.'
            . '<form method="post" action="' . $e($action) . '" class="mt-2" data-record-held>'
            . '<input type="hidden" name="record_held_notifications" value="' . $e($source) . '">' . $tokenField
            . '<button class="btn btn-secondary btn-sm" type="submit">Record '
            . ($held['recordable'] === 1 ? 'it' : 'them') . '</button></form></div></div>';
    }
    if ($held['waiting'] > 0) {
        $all = $held['waiting_currencies'];
        $one = count($all) === 1 ? $all[0] : null;
        $in = $one ?? implode(', ', array_slice($all, 0, -1)) . ' and ' . $all[count($all) - 1];
        $log = $base . 'tracking202/setup/inbound_log.php?outcome=currency_mismatch&endpoint='
            . rawurlencode($source);
        $html .= '<div class="alert alert-warning p202-flash" role="status" data-currency-mismatch="'
            . $e($source) . '">'
            . '<i class="bi bi-exclamation-triangle"></i><div class="p202-flash__body"><strong>'
            . $e($noun($held['waiting']) . ' from ' . $provider . ' in ' . $in) . ' recorded nothing</strong>: this'
            . ' account counts in ' . $e($accountCurrency) . ', and Prosper202 does not convert amounts. They are'
            . ' held. If you sell in ' . $e($one ?? 'one of them') . ', <a href="'
            . $e($base . '202-account/account.php#currency') . '">change the account currency to '
            . $e($one ?? 'it') . '</a>, then record them here.'
            . ($linksLog ? ' <a href="' . $e($log) . '">See them in the Inbound Log</a>.' : '') . '</div></div>';
    }

    return $html;
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
        . ' (<code>jvzoo.php</code> with no <code>?role=</code>). In the last 30 days'
        . ($traffic->more ? ', at least' : '') . ': '
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
