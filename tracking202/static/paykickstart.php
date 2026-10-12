<?php

declare(strict_types=1);

// PayKickstart's IPN for products you sell. The checkout link's ?subid=
// arrives as custom_subid and is the click id; the notification is checked
// with the campaign secret keys saved under Settings › API integrations.

use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Database\Connection;
use Prosper202\Integration\NotificationSecrets;
use Prosper202\Integration\PayKickstartIpn;
use Prosper202\Integration\SaleNotification;

include_once(substr(__DIR__, 0, -19) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/class-dataengine-slim.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/static-endpoint-helpers.php');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    p202RespondJsonError(405, 'PayKickstart posts its notifications');
}
$post = $_POST;
if (!isset($post['event'], $post['hash'])) {
    p202RespondJsonError(400, 'Not a PayKickstart vendor IPN: event and hash are required'
        . ' (the affiliate IPN is not signed and is not accepted)');
}

try {
    $holders = NotificationSecrets::holders(new Connection($db), 'paykickstart_ipn_secret');
} catch (\Throwable $e) {
    error_log('paykickstart: the secret keys could not be read: ' . $e->getMessage());
    p202RespondJsonError(500, 'The PayKickstart secret keys could not be read');
}
if ($holders === []) {
    p202RespondJsonError(403, 'No account has saved a PayKickstart secret key (Settings › API integrations)');
}
$accounts = [];
foreach ($holders as $holder) {
    foreach (PayKickstartIpn::secrets($holder['secret']) as $secret) {
        if (PayKickstartIpn::verify($post, $secret)) {
            $accounts[] = $holder['user_id'];
            break;
        }
    }
}
if ($accounts === []) {
    p202RespondJsonError(403, 'hash does not match a saved PayKickstart secret key');
}

$event = is_string($post['event']) ? strtolower(trim($post['event'])) : '';
if ($event !== 'sales' && $event !== 'refund') {
    p202RespondNotRecorded('not_a_payment');
}
if (($post['mode'] ?? 'live') === 'test') {
    p202RespondNotRecorded('test_mode');
}
$subid = is_string($post['custom_subid'] ?? null) ? trim($post['custom_subid']) : '';
if ($subid === '') {
    p202RespondNotRecorded('no_click_id');
}
$clickId = p202ParseClickId($subid);
if ($clickId === null) {
    p202RespondNotRecorded('not_a_click_id');
}
$transactionId = is_string($post['transaction_id'] ?? null) ? trim($post['transaction_id']) : '';
if ($transactionId === '') {
    p202RespondJsonError(400, 'Missing transaction_id');
}
$invoice = is_string($post['invoice_id'] ?? null) ? trim($post['invoice_id']) : '';
$reversal = $event === 'refund';
$amount = PayKickstartIpn::amount($post['amount'] ?? null);
if (!$reversal && $amount === null) {
    p202RespondJsonError(400, 'amount is not an amount');
}
if ($reversal && $amount !== null && (float) $amount === 0.0) {
    $amount = null;
}

try {
    $outcome = SaleNotification::record($db, $accounts, $clickId, [
        'source' => ConversionSource::PAYKICKSTART->value,
        'transaction_id' => $transactionId,
        'amount' => $amount,
        'reversal' => $reversal,
        'reversal_ref' => $transactionId,
        // A refund that names a transaction of its own reverses the newest
        // sale on the click from the same purchase.
        'source_ref' => $invoice !== '' ? 'invoice:' . $invoice : '',
        'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ]);
} catch (\Throwable $e) {
    error_log('paykickstart: recording ' . $transactionId . ' on click ' . $clickId . ' failed: ' . $e->getMessage());
    p202RespondJsonError(500, 'Failed to record the notification');
}
p202RespondJson(['ok' => true] + $outcome);
