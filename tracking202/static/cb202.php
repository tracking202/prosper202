<?php

declare(strict_types=1);

include_once(substr(__DIR__, 0, -19) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/class-dataengine-slim.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/static-endpoint-helpers.php');

// ClickBank's Instant Notification Service (version 6.0 and later). The
// account is the one whose stored ClickBank secret key decrypts the
// notification (ClickBankAdapter); a key two accounts store is refused. A
// test marks that account's key verified and, once answered (ClickBank waits
// 3 seconds), tells that account's own Slack webhook. The sender is
// ClickBank's server: no visitor, so no privacy state applies.

$body = file_get_contents('php://input');
$receiver = new \Prosper202\PaymentNotification\Receiver(
    new \Prosper202\PaymentNotification\ClickBank\ClickBankAdapter(),
    $db
);
$outcome = $receiver->receive(
    \Prosper202\PaymentNotification\InboundRequest::fromGlobals($_SERVER, $_POST, $body === false ? '' : $body)
);
\Prosper202\PaymentNotification\Response::send($outcome);

if ($outcome->kind === \Prosper202\PaymentNotification\OutcomeKind::TEST && $outcome->accountId !== null) {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    try {
        $webhook = \Prosper202\PaymentNotification\ClickBank\ClickBankAdapter::slackWebhook(
            new \Prosper202\Database\Connection($db),
            $outcome->accountId
        );
        if ($webhook !== null) {
            (new Slack($webhook))->push('cb_key_verified', []);
        }
    } catch (\Throwable $e) {
        error_log('ClickBank notification: the Slack notice for account ' . $outcome->accountId
            . ' could not be sent: ' . $e->getMessage());
    }
}
