<?php

declare(strict_types=1);

include_once(substr(__DIR__, 0, -19) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/class-dataengine-slim.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/static-endpoint-helpers.php');

// Shopify's order webhooks (orders/create or orders/paid, refunds/create),
// which a store owner adds under Settings › Notifications › Webhooks. The
// account is the one whose stored webhook secret signs it, and the click is
// the t202subid line item property (ShopifyAdapter); paid 1.9.56 installs
// sent them to gpb.php, which hands them here. The sender is Shopify's
// server: no visitor, so no privacy state applies.

$body = file_get_contents('php://input');
$receiver = new \Prosper202\PaymentNotification\Receiver(
    new \Prosper202\PaymentNotification\Shopify\ShopifyAdapter($db),
    $db
);
$outcome = $receiver->receive(
    \Prosper202\PaymentNotification\InboundRequest::fromGlobals($_SERVER, $_POST, $body === false ? '' : $body)
);
\Prosper202\PaymentNotification\Response::send(
    $outcome,
    \Prosper202\PaymentNotification\Shopify\ShopifyAdapter::deliveryStatus($outcome->kind)
);
