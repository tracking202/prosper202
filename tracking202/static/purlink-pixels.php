<?php

declare(strict_types=1);

use Api\V3\Apps\Android\InstallTokenKey;
use Api\V3\Apps\PublicIntake;
use Api\V3\Bootstrap;
use Prosper202\Click\PurLinkPixels;
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
const P202_PURLINK_PIXELS_BODY_LIMIT = 2048;
PublicIntake::preflight(P202_PURLINK_PIXELS_BODY_LIMIT, ['POST']);
require_once $root . '/202-config.php';
PublicIntake::rateLimit('purlink-pixels', 600, 60);
$body = PublicIntake::readBody(P202_PURLINK_PIXELS_BODY_LIMIT);
if (strlen($body) > P202_PURLINK_PIXELS_BODY_LIMIT) {
    Bootstrap::errorResponse('Request body too large', 413);
    exit;
}
parse_str($body, $fields);
// p202_consent=0 with the poll refuses the Meta Purchase (RequestSignals::consentGiven()).
$names = array_keys($fields);
sort($names);
if (!in_array($names, [['proof'], ['p202_consent', 'proof']], true)
    || !is_string($fields['proof']) || $fields['proof'] === '') {
    Bootstrap::errorResponse('Invalid PurLink pixel request', 422);
    exit;
}
try {
    $db = PublicIntake::database();
    $key = InstallTokenKey::load($db);
    if ($key === null) {
        throw new RuntimeException('installation token key is missing');
    }
    $result = (new PurLinkPixels(new Connection($db)))->poll(
        $fields['proof'],
        time(),
        $key,
        // p202VisitorMayBeInEu(), without connect2.php's bootstrap.
        static fn (): bool => \Prosper202\Http\EuropeanVisitor::mayBe(\Prosper202\Http\VisitorIp::fromServer($_SERVER)),
        array_intersect_key($fields, ['p202_consent' => true])
    );
} catch (Throwable $error) {
    error_log('PurLink deferred pixel failed: ' . $error->getMessage());
    Bootstrap::errorResponse('Service unavailable', 503);
    exit;
}
if ($result['status'] === 'refused') {
    Bootstrap::errorResponse('PurLink pixel proof was not accepted', 404);
    exit;
}
// The markup is the account's own traffic source pixel code, rendered by the
// landing page that asked; tokens in it are encoded by replaceTokens().
Bootstrap::jsonResponse(['status' => $result['status'], 'markup' => $result['markup']]);
