<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();

/*
 * "Not fraud" on a Visitors or Spy row: the account says IPQualityScore's
 * verdict on the click was wrong (Prosper202\Fraud\FraudVerdictUndo). The
 * click and the account's other clicks from its address that were filtered
 * for their score count again, and the address is always counted from then
 * on. Answers JSON: {ok, message}. Only the account's own clicks.
 */
header('Content-Type: application/json; charset=UTF-8');
$answer = static function (int $status, bool $ok, string $message): never {
    http_response_code($status);
    $json = json_encode(['ok' => $ok, 'message' => $message]);
    die($json === false ? '{"ok":false,"message":"The answer could not be written."}' : $json);
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !AUTH::check_csrf_token()) {
    $answer(403, false, 'Invalid or expired form token. Reload the page and try again.');
}
// The Click filter list this writes to is Personal Settings', which the same roles change.
if (!$userObj->hasPermission('access_to_personal_settings')) {
    $answer(403, false, 'Your role cannot change the click filter, so it cannot mark a click as not fraud.');
}
$clickId = \Prosper202\Http\PostedId::read($_POST['click_id'] ?? null);
if ($clickId === null) {
    $answer(422, false, 'That is not a click.');
}

try {
    $undone = (new \Prosper202\Fraud\FraudVerdictUndo(new \Prosper202\Database\Connection($db)))->undo((int) $_SESSION['user_id'], $clickId);
} catch (\Throwable $e) {
    error_log('click_not_fraud.php: click ' . $clickId . ': ' . $e->getMessage());
    $answer(500, false, 'The click could not be changed. Nothing may have been written; reload the page to see.');
}
if ($undone === null) {
    $answer(404, false, 'That click is not one of yours filtered for its fraud score.');
}

$message = $undone['clicks'] . ($undone['clicks'] === 1 ? ' click from ' : ' clicks from ') . $undone['address']
    . ($undone['clicks'] === 1 ? ' counts again.' : ' count again.')
    . ($undone['more'] ? ' More clicks from it are still filtered: choose Not fraud on one of them to count the next ' . \Prosper202\Fraud\FraudVerdictUndo::MAX_CLICKS . '.' : '')
    . ($undone['exempted']
        ? ' The address is on your click filter list as always count, so it is not scored again.'
        : ' But ' . $undone['exempt_problem'] . '.');
$answer(200, true, $message);
