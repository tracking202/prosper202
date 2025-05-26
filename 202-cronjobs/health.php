<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Simple health check file
echo json_encode([
    'status' => 'ok',
    'time' => date('Y-m-d H:i:s'),
    'description' => 'Cronjobs directory is accessible',
    'php_version' => phpversion()
]);
