<?php

declare(strict_types=1);

// 1.9.56's dcs.updateDataObj() endpoint. It trusted the click id the page
// sent; now only a POST carrying record.php's update proof changes anything
// (static/dcs-update.php). Its GET was an image: a 1x1 gif, logged, nothing recorded.
$p202UpdateEndpoint = 'redirect/u.php';
$p202UpdateMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($p202UpdateMethod === 'GET' || $p202UpdateMethod === 'HEAD') {
    error_log('dcs update refused (' . $p202UpdateEndpoint . '): a GET carries no update proof');
    header('Content-Type: image/gif');
    header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    if ($p202UpdateMethod === 'GET') {
        echo base64_decode('R0lGODlhAQABAIAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
    }
    exit;
}
require dirname(__DIR__) . '/static/dcs-update.php';
