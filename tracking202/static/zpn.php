<?php

declare(strict_types=1);

include_once(substr(__DIR__, 0, -19) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/class-dataengine-slim.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/static-endpoint-helpers.php');

// Zaxaa's ZPN, verified by the hash_key of an account's stored API signature
// (ZaxaaAdapter); the sender is Zaxaa's server, so no privacy state applies.

$receiver = new \Prosper202\PaymentNotification\Receiver(
    new \Prosper202\PaymentNotification\Zaxaa\ZaxaaAdapter(),
    $db
);
$outcome = $receiver->receive(\Prosper202\PaymentNotification\InboundRequest::fromGlobals($_SERVER, $_POST));
\Prosper202\PaymentNotification\Response::send($outcome);
// A sale's traffic-source postbacks were queued with it; sent now that the provider has its answer (QZ3M).
p202SendQueuedPostbacksAfterAnswer($db, (int) ($outcome->conversionId ?? 0));
