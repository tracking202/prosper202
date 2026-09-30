<?php

declare(strict_types=1);

// Include the base connect file
include_once(substr(__DIR__, 0, -18) . '/202-config/connect.php');

// Include the controller
require_once __DIR__ . '/MobileAppsController.php';

// Create and run the controller
try {
    $controller = new \Tracking202\Setup\MobileAppsController();
    $controller->handleRequest();
} catch (\Exception $e) {
    error_log('Mobile Apps Setup Error: ' . $e->getMessage());
    header('location: ' . get_absolute_url() . 'tracking202/setup/?error=mobile_apps_error');
    exit;
}
