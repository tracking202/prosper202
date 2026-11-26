<?php

declare(strict_types=1);

// The ChatGPT Ads pixel's browser reference for a click
// (Prosper202\Click\OpenAiBrowserRef), posted by the landing page once the
// pixel has set its __obref cookie, and again with p202_consent=0 when the
// visitor withdraws consent. A refusal records nothing and answers 204,
// logged: a customer's page shows no error for it.

use Api\V3\Apps\Android\InstallTokenKey;
use Api\V3\Apps\PublicIntake;
use Prosper202\Click\OpenAiBrowserRef;
use Prosper202\Click\PageUpdate;
use Prosper202\Database\Connection;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
$p202RefRefuse = static function (string $why): never {
    error_log('ChatGPT Ads browser reference refused: ' . $why);
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
PublicIntake::rateLimit('openai-ref', 600, 60);
if ($method !== 'POST') {
    $p202RefRefuse('method ' . (preg_match('/\A[A-Z]{1,10}\z/', $method) === 1 ? $method : '?'));
}
$declared = $_SERVER['CONTENT_LENGTH'] ?? null;
if (is_string($declared) && ctype_digit($declared) && (int) $declared > OpenAiBrowserRef::BODY_LIMIT) {
    $p202RefRefuse('body over ' . OpenAiBrowserRef::BODY_LIMIT . ' bytes');
}
$body = PublicIntake::readBody(OpenAiBrowserRef::BODY_LIMIT);
if (strlen($body) > OpenAiBrowserRef::BODY_LIMIT) {
    $p202RefRefuse('body over ' . OpenAiBrowserRef::BODY_LIMIT . ' bytes');
}
parse_str($body, $fields);
$parsed = OpenAiBrowserRef::parse($fields);
if (is_string($parsed)) {
    $p202RefRefuse($parsed);
}
try {
    $db = PublicIntake::database();
    $key = InstallTokenKey::load($db);
    if ($key === null) {
        throw new RuntimeException('installation token key is missing');
    }
    $result = (new OpenAiBrowserRef(new Connection($db)))->apply($parsed['proof'], $parsed['obref'], time(), $key);
} catch (Throwable $error) {
    error_log('ChatGPT Ads browser reference failed: ' . PageUpdate::failureText($error));
    http_response_code(503);
    exit;
}
if ($result === 'refused') {
    $p202RefRefuse('the proof is invalid, expired or no longer names its click on its page');
}
http_response_code(204);
