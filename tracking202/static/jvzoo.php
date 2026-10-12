<?php

declare(strict_types=1);

// JVZoo's JVZIPN (v1). The affiliate link's ?tid= arrives as caffitid and is
// the click id; the notification is checked with the JVZoo secret key saved
// under Settings › API integrations.

use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Database\Connection;
use Prosper202\Integration\JvzooIpn;
use Prosper202\Integration\NotificationSecrets;
use Prosper202\Integration\SaleNotification;

include_once(substr(__DIR__, 0, -19) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/class-dataengine-slim.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/static-endpoint-helpers.php');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    p202RespondJsonError(405, 'JVZIPN posts its notifications');
}
$post = $_POST;
if (JvzooIpn::isV2($post)) {
    p202RespondJsonError(400, 'A JVZIPN v2 notification: its cverify does not cover the amount or the tracking id,'
        . ' so it is refused. Set this URL as a v1 JVZIPN URL.');
}
if (!isset($post['ctransaction'], $post['cverify'])) {
    p202RespondJsonError(400, 'Not a JVZIPN notification: ctransaction and cverify are required');
}

try {
    $holders = NotificationSecrets::holders(new Connection($db), 'jvzoo_ipn_secret_key');
} catch (\Throwable $e) {
    error_log('jvzoo: the secret keys could not be read: ' . $e->getMessage());
    p202RespondJsonError(500, 'The JVZoo secret keys could not be read');
}
if ($holders === []) {
    p202RespondJsonError(403, 'No account has saved a JVZoo secret key (Settings › API integrations)');
}
$accounts = [];
foreach ($holders as $holder) {
    if (JvzooIpn::verify($post, $holder['secret'])) {
        $accounts[] = $holder['user_id'];
    }
}
if ($accounts === []) {
    p202RespondJsonError(403, 'cverify does not match a saved JVZoo secret key');
}

$type = is_string($post['ctransaction']) ? strtoupper(trim($post['ctransaction'])) : '';
$reversal = in_array($type, JvzooIpn::REVERSE, true);
if (!$reversal && !in_array($type, JvzooIpn::CREDIT, true)) {
    p202RespondNotRecorded('not_a_payment');
}
$tid = is_string($post['caffitid'] ?? null) ? trim($post['caffitid']) : '';
if ($tid === '') {
    p202RespondNotRecorded('no_click_id');
}
$clickId = p202ParseClickId($tid);
if ($clickId === null) {
    p202RespondNotRecorded('not_a_click_id');
}
$receipt = is_string($post['ctransreceipt'] ?? null) ? trim($post['ctransreceipt']) : '';
if ($receipt === '') {
    p202RespondJsonError(400, 'Missing ctransreceipt');
}
$amount = JvzooIpn::amount($post['ctransamount'] ?? null);
if (!$reversal && $amount === null) {
    p202RespondJsonError(400, 'ctransamount is not an amount');
}
if ($reversal && $amount !== null && (float) $amount === 0.0) {
    $amount = null;
}

try {
    $outcome = SaleNotification::record($db, $accounts, $clickId, [
        'source' => ConversionSource::JVZOO->value,
        'transaction_id' => $receipt,
        'amount' => $amount,
        'reversal' => $reversal,
        'reversal_ref' => $type,
        'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ]);
} catch (\Throwable $e) {
    error_log('jvzoo: recording receipt ' . $receipt . ' on click ' . $clickId . ' failed: ' . $e->getMessage());
    p202RespondJsonError(500, 'Failed to record the notification');
}
p202RespondJson(['ok' => true] + $outcome);
