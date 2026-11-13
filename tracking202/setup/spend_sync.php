<?php

declare(strict_types=1);

include_once(substr(__DIR__, 0, -18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission('access_to_setup_section')) {
    header('location: ' . get_absolute_url() . 'tracking202/');
    die();
}

/*
 * Setup › Traffic Sources' ChatGPT Ads spend card posts here to turn one
 * connection's hourly spend pull on or off, then goes back to the card.
 */

$back = get_absolute_url() . 'tracking202/setup/ppc_accounts.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('location: ' . $back . '#chatgpt-spend');
    die();
}
if (!AUTH::check_csrf_token()) {
    header('location: ' . $back . '?spend_sync=token#chatgpt-spend');
    die();
}
$connectionId = \Prosper202\Http\PostedId::read($_POST['connection_id'] ?? null);
$enabled = $_POST['enabled'] ?? null;
if ($connectionId === null || !in_array($enabled, ['0', '1'], true)) {
    header('location: ' . $back . '?spend_sync=invalid#chatgpt-spend');
    die();
}
$conn = new \Prosper202\Database\Connection($db);
$sync = new \Prosper202\Spend\OpenAi\SpendSync(
    $conn,
    new \Prosper202\Spend\OpenAi\AdConnectionSpendConnections(new \Prosper202\AdPlatforms\AdConnectionStore($conn))
);
$done = $sync->setEnabled((int) $_SESSION['user_id'], $connectionId, $enabled === '1');
header('location: ' . $back . '?spend_sync=' . ($done ? ($enabled === '1' ? 'on' : 'off') : 'missing') . '#chatgpt-spend');
die();
