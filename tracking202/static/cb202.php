<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-19) . '/202-config/connect.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/class-dataengine-slim.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/static-endpoint-helpers.php');

$mysql['user_id'] = 1;

$slack = false;
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook as url, 2up.cb_key AS cb_key FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '".$mysql['user_id']."'";
$user_results = $db->query($user_sql);
if ($user_results === false) {
    p202RespondJsonError(500, 'User lookup failed');
}
$user_row = $user_results->fetch_assoc();
if (!$user_row) {
    p202RespondJsonError(404, 'User not found');
}

if (!empty($user_row['url']))
    $slack = new Slack($user_row['url']);

if (function_exists('openssl_decrypt')) {
    // Reject malformed input loudly instead of fataling on a null/!object payload.
    $rawInput = file_get_contents('php://input');
    if ($rawInput === false || $rawInput === '') {
        p202RespondJsonError(400, 'Empty request body');
    }
    $message = json_decode($rawInput);
    if (!is_object($message) || !isset($message->notification, $message->iv)) {
        p202RespondJsonError(400, 'Malformed notification payload');
    }
    $encrypted = $message->{'notification'};
    $iv = $message->{'iv'};
    $decrypted = openssl_decrypt(
        base64_decode((string) $encrypted),
        'AES-128-CBC',
        substr(sha1((string) $user_row['cb_key']), 0, 32),
        OPENSSL_RAW_DATA,
        base64_decode((string) $iv)
    );
    if ($decrypted === false) {
        p202RespondJsonError(400, 'Unable to decrypt notification');
    }
    $decrypted = trim($decrypted, "\0..\32");
    $order = json_decode($decrypted, true);
    if (!is_array($order) || !isset($order['transactionType'])) {
        p202RespondJsonError(400, 'Malformed order payload');
    }

    if ($order['transactionType'] == 'TEST') {
        $user_sql = "UPDATE 202_users_pref
                     SET cb_verified=1
                     WHERE user_id='".$mysql['user_id']."'";
        if (!$db->query($user_sql)) {
            p202RespondJsonError(500, 'Failed to record verification');
        }

        if ($slack)
            $slack->push('cb_key_verified', []);

    } else if($order['transactionType'] == 'SALE') {
        if (!isset($order['trackingCodes'][0]) || !is_numeric($order['trackingCodes'][0])) {
            p202RespondJsonError(400, 'Missing tracking code');
        }
        $mysql['click_id'] = $db->real_escape_string((string) $order['trackingCodes'][0]);
        $mysql['click_payout'] = $db->real_escape_string((string) ($order['totalAccountAmount'] ?? '0'));

        $cpa_sql = "SELECT 202_cpa_trackers.tracker_id_public, 202_trackers.click_cpa FROM 202_cpa_trackers LEFT JOIN 202_trackers USING (tracker_id_public) WHERE click_id = '".$mysql['click_id']."'";
        $cpa_result = $db->query($cpa_sql);
        if ($cpa_result === false) {
            p202RespondJsonError(500, 'CPA lookup failed');
        }
        $cpa_row = $cpa_result->fetch_assoc();

        $mysql['click_cpa'] = $db->real_escape_string((string) ($cpa_row['click_cpa'] ?? ''));

        p202ApplyConversionUpdate(
            $db,
            (string) $mysql['click_id'],
            (string) $mysql['click_cpa'],
            true,
            (string) $mysql['click_payout']
        );
    }

} else {
    die("Missing Mcrypt!");
}
