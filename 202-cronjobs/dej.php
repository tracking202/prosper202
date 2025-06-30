<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    include_once(str_repeat("../", 1) . '202-config/connect.php');
    include_once(str_repeat("../", 1) . '202-config/class-dataengine.php');

    set_time_limit(0);

    $snippet = "";
    $start = isset($_GET['s']) ? (int)$_GET['s'] : time() - 3600;
    //$end =$_GET['e'];

    $de = new DataEngine();
    $de->getSummary($start, $start + 3599, $snippet, 1, true);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
    error_log("DEJ Error: " . $e->getMessage());
}
