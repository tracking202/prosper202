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
        // The order total is the payout. A value that is not a number is
        // refused rather than recorded as 0 (CLAUDE.md #4): a silent zero
        // would look like a free sale in every report.
        $amount = $order['totalAccountAmount'] ?? '0';
        if (!is_numeric($amount)) {
            p202RespondJsonError(400, 'Malformed totalAccountAmount');
        }
        $click_id = (int) $order['trackingCodes'][0];

        // One conversion row per receipt (pixel_type 2: a server-to-server
        // postback), through the same writer as every other conversion path.
        // The receipt is the transaction id, so ClickBank's repeated INS
        // deliveries of one sale de-duplicate, and two sales on one click are
        // two rows. Before, this endpoint only flagged the click and
        // overwrote its payout, and left no trace of either sale.
        try {
            $outcome = p202RecordLegacyConversion($db, $click_id, 2, [
                'transaction_id'   => trim((string) ($order['receipt'] ?? '')),
                'use_pixel_payout' => true,
                'payout'           => (string) $amount,
                'ip'               => (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
                'user_agent'       => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ]);
        } catch (\Throwable $conversionError) {
            error_log('cb202: conversion recording failed for click ' . $click_id . ': ' . $conversionError->getMessage());
            p202RespondJsonError(500, 'Failed to record conversion');
        }
        if (!$outcome['recorded'] && !$outcome['duplicate'] && $outcome['reason'] !== 'already_lead') {
            // A sale naming a click this install does not have: say so, so
            // ClickBank's log shows the mismatch instead of a silent 200.
            p202RespondJsonError(404, 'Unknown tracking code');
        }
    }

} else {
    die("Missing Mcrypt!");
}
