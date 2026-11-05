<?php

declare(strict_types=1);

// Paid 1.9.56's scheduled Google Ads feed (Prosper202\OfflineConversion\LegacyGoogleFeed).
require_once dirname(__DIR__, 5) . '/vendor/autoload.php';

\Prosper202\OfflineConversion\LegacyGoogleFeed::refuse((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
