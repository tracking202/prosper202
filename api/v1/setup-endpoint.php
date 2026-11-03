<?php

declare(strict_types=1);

// The paid 1.9.56 setup reads under api/v1/{resource}/ (Api\V1\LegacySetup).
// Each index.php sets $p202LegacySetupResource and includes this.
include_once dirname(__DIR__, 2) . '/202-config.php';
// Goes on when the database is out of reach: it answers the outage in JSON.
$p202OutageFallback = true;
include_once dirname(__DIR__, 2) . '/202-config/connect2.php';
require_once dirname(__DIR__) . '/legacy/functions.php';
// Api\V1 has no PSR-4 entry: Api\ maps to api/, which would look in api/V1/.
require_once __DIR__ . '/LegacySetup.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

[$p202LegacyStatus, $p202LegacyBody] = \Api\V1\LegacySetup::answer(
    isset($db) && $db instanceof mysqli ? $db : null,
    (string) $p202LegacySetupResource,
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $_GET
);
http_response_code($p202LegacyStatus);
if ($p202LegacyStatus === 405) {
    header('Allow: GET');
}
echo legacy_api_json($p202LegacyBody);
