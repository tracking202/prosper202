<?php

declare(strict_types=1);

// Analyze › IPs. One of the thirteen report pages that share
// AnalyzeReportController and templates/report.php; see the controller for
// what a page does with its query string.
$rootPath = dirname(__DIR__, 2);
include_once $rootPath . '/202-config/connect.php';
include_once $rootPath . '/202-config/class-dataengine.php';
require_once __DIR__ . '/AnalyzeReportController.php';

(new \Tracking202\Analyze\AnalyzeReportController('ip'))->handleRequest();
