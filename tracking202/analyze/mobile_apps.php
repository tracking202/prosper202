<?php

declare(strict_types=1);

// Include the base connect file. dirname() rather than the substr() form the
// setup pages use: this directory's name is one character longer, and a magic
// offset that has to be recounted per directory is a footgun for the next page.
$rootPath = dirname(__DIR__, 2);
include_once $rootPath . '/202-config/connect.php';

// Include the controller
require_once __DIR__ . '/MobileAppsReportController.php';

// Create and run the controller
try {
    $controller = new \Tracking202\Analyze\MobileAppsReportController();
    $controller->handleRequest();
} catch (\Exception $e) {
    error_log('Mobile Apps Report Error: ' . $e->getMessage());
    header('location: ' . get_absolute_url() . 'tracking202/analyze/?error=mobile_apps_error');
    exit;
}
