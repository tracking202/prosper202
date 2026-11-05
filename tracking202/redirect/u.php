<?php

declare(strict_types=1);

// 1.9.56's dcs.updateDataObj() endpoint. It trusted the click id the page
// sent; now only a POST carrying record.php's update proof changes anything
// (static/dcs-update.php). Its GET was an image: a 1x1 gif, logged, nothing recorded.
$p202UpdateEndpoint = 'redirect/u.php';
$p202UpdateGetImage = true;
require dirname(__DIR__) . '/static/dcs-update.php';
