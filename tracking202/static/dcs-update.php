<?php

declare(strict_types=1);

// dcs.updateDataObj()'s click update (Prosper202\Click\PageUpdate), and
// 1.9.56's redirect/u.php, which includes this. A refusal records nothing
// and answers 204, logged: a customer's page shows no error for it.

use Api\V3\Apps\Android\InstallTokenKey;
use Api\V3\Apps\PublicIntake;
use Prosper202\Click\PageUpdate;
use Prosper202\Database\Connection;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (!isset($p202UpdateEndpoint) || !is_string($p202UpdateEndpoint)) {
    $p202UpdateEndpoint = 'dcs-update.php';
}
$p202UpdateRefuse = static function (string $why) use ($p202UpdateEndpoint): never {
    error_log('dcs update refused (' . $p202UpdateEndpoint . '): ' . $why);
    http_response_code(204);
    exit;
};
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
$root = dirname(__DIR__, 2);
if (!is_file($root . '/202-config.php') || !is_file($root . '/vendor/autoload.php')) {
    http_response_code(503);
    exit;
}
require_once $root . '/vendor/autoload.php';
require_once $root . '/202-config.php';
// Before any refusal: each one writes a log line.
PublicIntake::rateLimit('dcs-update', 600, 60);
if ($method !== 'POST') {
    if (($p202UpdateGetImage ?? false) === true && ($method === 'GET' || $method === 'HEAD')) {
        error_log('dcs update refused (' . $p202UpdateEndpoint . '): a GET carries no update proof');
        header('Content-Type: image/gif');
        header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
        if ($method === 'GET') {
            echo base64_decode('R0lGODlhAQABAIAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
        }
        exit;
    }
    $p202UpdateRefuse('method ' . (preg_match('/\A[A-Z]{1,10}\z/', $method) === 1 ? $method : '?'));
}
$declared = $_SERVER['CONTENT_LENGTH'] ?? null;
if (is_string($declared) && ctype_digit($declared) && (int) $declared > PageUpdate::BODY_LIMIT) {
    $p202UpdateRefuse('body over ' . PageUpdate::BODY_LIMIT . ' bytes');
}
if (str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data')) {
    // 1.9.56's beacon sent FormData; PHP has parsed it.
    $fields = $_POST;
} else {
    $body = PublicIntake::readBody(PageUpdate::BODY_LIMIT);
    if (strlen($body) > PageUpdate::BODY_LIMIT) {
        $p202UpdateRefuse('body over ' . PageUpdate::BODY_LIMIT . ' bytes');
    }
    parse_str($body, $fields);
}
$proof = $fields['proof'] ?? null;
if (!is_string($proof) || $proof === '') {
    $p202UpdateRefuse('no update proof');
}
$parsed = PageUpdate::fields($fields);
if ($parsed === null) {
    $p202UpdateRefuse('a field was sent twice');
}
if (!$parsed['consent']) {
    $p202UpdateRefuse('the visitor refused tracking');
}
if ($parsed['refused'] !== []) {
    $names = array_map(
        static fn (string $name): string => preg_match('/\A[a-z0-9_]{1,40}\z/', $name) === 1 ? $name : '?',
        array_slice($parsed['refused'], 0, 10)
    );
    error_log('dcs update (' . $p202UpdateEndpoint . '): ignored fields a page may not change: '
        . implode(', ', $names));
}
try {
    $db = PublicIntake::database();
    $key = InstallTokenKey::load($db);
    if ($key === null) {
        throw new RuntimeException('installation token key is missing');
    }
    $result = (new PageUpdate(new Connection($db)))->apply($proof, $parsed['values'], time(), $key);
} catch (Throwable $error) {
    error_log('dcs update failed (' . $p202UpdateEndpoint . '): ' . $error->getMessage());
    http_response_code(503);
    exit;
}
if ($result === 'refused') {
    $p202UpdateRefuse('the proof is invalid, expired or no longer names its click');
}
http_response_code(204);
