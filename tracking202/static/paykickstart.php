<?php

declare(strict_types=1);

include_once(substr(__DIR__, 0, -19) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/class-dataengine-slim.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/static-endpoint-helpers.php');

// PayKickstart's product IPN, which a vendor sets under each product's IPN
// Integration tab. The account is the one whose stored IPN secret key signs
// it, and the click is the checkout link's subid (PayKickstartAdapter); a
// sale is recorded, a refund reverses it, and every answer says why in the
// status PayKickstart's delivery log shows (Response). The sender is
// PayKickstart's server: no visitor, so no privacy state applies.

$receiver = new \Prosper202\PaymentNotification\Receiver(
    new \Prosper202\PaymentNotification\PayKickstart\PayKickstartAdapter(),
    $db
);
\Prosper202\PaymentNotification\Response::send(
    $receiver->receive(\Prosper202\PaymentNotification\InboundRequest::fromGlobals($_SERVER, $_POST))
);
