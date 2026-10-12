<?php

declare(strict_types=1);

/*
 * Signs a payment platform's notification the way the platform's own
 * published code signs it, for payment-notifications.sh: the server is held
 * to the vendors' samples, never to its own implementation.
 *
 *   php payment-notification-sign.php <jvzoo|zaxaa|paykickstart> <secret> '<json fields>'
 *   php payment-notification-sign.php slack <secret> <timestamp> '<raw body>'
 *
 * Prints the form-encoded body, or for slack the X-Slack-Signature value.
 */

[$self, $vendor, $secret] = $argv + [null, '', ''];

if ($vendor === 'slack') {
    // docs.slack.dev, "Verifying requests from Slack".
    echo 'v0=' . hash_hmac('sha256', 'v0:' . $argv[3] . ':' . $argv[4], $secret);
    exit(0);
}

$post = json_decode($argv[3] ?? '', true, 512, JSON_THROW_ON_ERROR);

switch ($vendor) {
    case 'jvzoo':
        // JVZoo's jvzipnVerification(), "JVZIPN - How to create your own
        // integrated script", over the fields to be posted.
        $pop = "";
        $ipnFields = array();
        foreach ($post as $key => $value) {
            if ($key == "cverify") {
                continue;
            }
            $ipnFields[] = $key;
        }
        sort($ipnFields);
        foreach ($ipnFields as $field) {
            $pop = $pop . $post[$field] . "|";
        }
        $pop = $pop . $secret;
        if ('UTF-8' != mb_detect_encoding($pop)) {
            $pop = mb_convert_encoding($pop, "UTF-8");
        }
        $post['cverify'] = strtoupper(substr(sha1($pop), 0, 8));
        break;
    case 'zaxaa':
        $signed = $post['seller_id'] . $secret . $post['trans_receipt'] . $post['trans_amount'];
        $post['hash_key'] = strtoupper(md5($signed));
        break;
    case 'paykickstart':
        // PayKickstart's is_valid_ipn(), the hash it compares.
        $data = $post;
        unset($data['hash'], $data['verification_code']);
        $data = array_filter(array_map('trim', $data));
        foreach ($data as $key => $item) {
            $data[$key] = iconv("UTF-8", "ISO-8859-1//IGNORE", $item);
        }
        ksort($data, SORT_STRING);
        $post['hash'] = hash_hmac('sha1', implode("|", $data), $secret);
        break;
    default:
        fwrite(STDERR, "unknown vendor $vendor\n");
        exit(2);
}

echo http_build_query($post);
