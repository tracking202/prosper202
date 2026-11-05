<?php

declare(strict_types=1);

use Api\V3\Apps\Android\InstallTokenKey;
use Api\V3\Apps\PublicIntake;
use Api\V3\Bootstrap;
use Prosper202\Click\PurLinkExit;
use Prosper202\Database\Connection;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
$root = dirname(__DIR__, 2);
if (!is_file($root . '/202-config.php') || !is_file($root . '/vendor/autoload.php')) {
    http_response_code(503);
    exit;
}
require_once $root . '/vendor/autoload.php';
const P202_PURLINK_EXIT_BODY_LIMIT = 2048;
PublicIntake::preflight(P202_PURLINK_EXIT_BODY_LIMIT, ['POST']);
require_once $root . '/202-config.php';
PublicIntake::rateLimit('purlink-exit', 600, 60);
$body = PublicIntake::readBody(P202_PURLINK_EXIT_BODY_LIMIT);
if (strlen($body) > P202_PURLINK_EXIT_BODY_LIMIT) {
    Bootstrap::errorResponse('Request body too large', 413);
    exit;
}
parse_str($body, $fields);
if (
    array_diff(array_keys($fields), ['proof', 'campaign', 'sequence']) !== []
    || !isset($fields['proof'], $fields['sequence']) || !is_string($fields['proof']) || $fields['proof'] === ''
    || !is_string($fields['sequence']) || (isset($fields['campaign']) && !is_string($fields['campaign']))
) {
    Bootstrap::errorResponse('Invalid PurLink exit request', 422);
    exit;
}
try {
    $db = PublicIntake::database();
    $key = InstallTokenKey::load($db);
    if ($key === null) {
        throw new RuntimeException('installation token key is missing');
    }
    $result = (new PurLinkExit(new Connection($db)))->recordOrdered(
        $fields['proof'], $fields['campaign'] ?? null, $fields['sequence'], time(), $key
    );
} catch (Throwable $error) {
    error_log('PurLink exit failed: ' . $error->getMessage());
    Bootstrap::errorResponse('Service unavailable', 503);
    exit;
}
if ($result === 'refused') {
    Bootstrap::errorResponse('PurLink exit was not accepted', 404);
    exit;
}
if ($result === 'conflict') {
    Bootstrap::errorResponse('PurLink exit sequence conflicts with an earlier report', 409);
    exit;
}
Bootstrap::jsonResponse(['status' => $result]);
