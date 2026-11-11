<?php

declare(strict_types=1);

// Paid 1.9.56's scheduled Google Ads feed (Prosper202\OfflineConversion\LegacyGoogleFeed).
$p202LegacyFeedRoot = dirname(__DIR__, 5);
require_once $p202LegacyFeedRoot . '/vendor/autoload.php';

use Prosper202\OfflineConversion\LegacyGoogleFeed;
use Prosper202\OfflineConversion\LegacyGoogleFeedFetches;

// The answer first, with no database: it stands when the database is down.
LegacyGoogleFeed::refuse((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

// A fetch naming a schedule is noted. The configuration connects at file scope, so it is loaded here.
if (LegacyGoogleFeedFetches::schedule($_GET) !== null && is_file($p202LegacyFeedRoot . '/202-config.php')) {
    $db = null;
    try {
        require_once $p202LegacyFeedRoot . '/202-config.php';
    } catch (\Throwable $p202LegacyFeedError) {
        $db = null;
    }
    LegacyGoogleFeed::note($db, $_GET, time());
}
