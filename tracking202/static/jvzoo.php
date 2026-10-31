<?php

declare(strict_types=1);

include_once(substr(__DIR__, 0, -19) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/class-dataengine-slim.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/static-endpoint-helpers.php');

// JVZoo's JVZIPN, which an affiliate sets as a product's Affiliate IPN
// (?role=affiliate) and a vendor as a product's JVZIPN URL (?role=vendor).
// The account is the one whose stored JVZIPN secret key signs it, and the
// click is the role's field (JVZooAdapter); a sale or rebill is recorded, a
// refund or chargeback reverses it, and every answer says why in the status
// JVZoo retries on (Response). The sender is JVZoo's server: no visitor, so
// no privacy state applies.

$receiver = new \Prosper202\PaymentNotification\Receiver(
    new \Prosper202\PaymentNotification\JVZoo\JVZooAdapter(
        \Prosper202\PaymentNotification\JVZoo\JVZooRole::fromQuery($_GET['role'] ?? null)
    ),
    $db
);
\Prosper202\PaymentNotification\Response::send(
    $receiver->receive(\Prosper202\PaymentNotification\InboundRequest::fromGlobals($_SERVER, $_POST))
);
