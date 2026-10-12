<?php

declare(strict_types=1);

// Zaxaa's Payment Notification (ZPN). The order link's ?custom= arrives as
// each product's custom value and is the click id; the notification is
// checked with the Zaxaa API signature saved under Settings › API
// integrations. See ZaxaaZpn for what that check does not cover.

use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Database\Connection;
use Prosper202\Integration\NotificationSecrets;
use Prosper202\Integration\SaleNotification;
use Prosper202\Integration\ZaxaaZpn;

include_once(substr(__DIR__, 0, -19) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/class-dataengine-slim.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/static-endpoint-helpers.php');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    p202RespondJsonError(405, 'ZPN posts its notifications');
}
$post = $_POST;
if (!isset($post['trans_type'], $post['hash_key'])) {
    p202RespondJsonError(400, 'Not a ZPN notification: trans_type and hash_key are required');
}

try {
    $holders = NotificationSecrets::holders(new Connection($db), 'zaxaa_api_signature');
} catch (\Throwable $e) {
    error_log('zpn: the API signatures could not be read: ' . $e->getMessage());
    p202RespondJsonError(500, 'The Zaxaa API signatures could not be read');
}
if ($holders === []) {
    p202RespondJsonError(403, 'No account has saved a Zaxaa API signature (Settings › API integrations)');
}
$verified = array_values(array_filter($holders, static fn (array $h): bool => ZaxaaZpn::verify($post, $h['secret'])));
if ($verified === []) {
    p202RespondJsonError(403, 'hash_key does not match a saved Zaxaa API signature');
}

$type = is_string($post['trans_type']) ? strtoupper(trim($post['trans_type'])) : '';
$reversal = in_array($type, ZaxaaZpn::REVERSE, true);
if (!$reversal && !in_array($type, ZaxaaZpn::CREDIT, true)) {
    p202RespondNotRecorded('not_a_payment');
}
if (($post['trans_testmode'] ?? '0') === '1') {
    p202RespondNotRecorded('test_mode');
}

$accounts = array_column($verified, 'user_id');
if (!$reversal) {
    // An amount is recorded in the account's currency, with nothing that
    // converts it, so a payment in another is refused rather than counted
    // as though it were.
    $currency = is_string($post['trans_currency'] ?? null) ? strtoupper(trim($post['trans_currency'])) : '';
    if ($currency !== '') {
        $inCurrency = array_filter($verified, static fn (array $h): bool => strtoupper($h['currency']) === $currency);
        $accounts = array_column($inCurrency, 'user_id');
        if ($accounts === []) {
            $accountCurrencies = implode(', ', array_unique(array_column($verified, 'currency')));
            p202RespondJsonError(422, 'trans_currency ' . $currency . ' is not the account currency'
                . ' (' . $accountCurrencies . ')');
        }
    }
}

try {
    $products = ZaxaaZpn::products($post, !$reversal);
} catch (\InvalidArgumentException $e) {
    p202RespondJsonError(400, $e->getMessage());
}

$answers = [];
foreach ($products as $product) {
    $clickId = $product['custom'] === '' ? null : p202ParseClickId($product['custom']);
    if ($clickId === null) {
        $answers[] = [
            'transaction_id' => $product['transaction_id'],
            'recorded' => false,
            'duplicate' => false,
            'conv_id' => 0,
            'reason' => $product['custom'] === '' ? 'no_click_id' : 'not_a_click_id',
        ];
        continue;
    }
    try {
        $outcome = SaleNotification::record($db, $accounts, $clickId, [
            'source' => ConversionSource::ZAXAA->value,
            'transaction_id' => $product['transaction_id'],
            'amount' => $product['amount'],
            'reversal' => $reversal,
            'reversal_ref' => $type,
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);
        $answers[] = ['transaction_id' => $product['transaction_id']] + $outcome;
    } catch (\Throwable $e) {
        error_log('zpn: recording ' . $product['transaction_id'] . ' on click ' . $clickId . ' failed: '
            . $e->getMessage());
        p202RespondJsonError(500, 'Failed to record the notification');
    }
}
p202RespondJson(['ok' => true, 'products' => $answers]);
