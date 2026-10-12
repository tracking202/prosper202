<?php

declare(strict_types=1);

// A Slack app's slash command. The request is checked with the app's
// signing secret saved under Settings › API integrations, and answered for
// the account that saved it, visible only to whoever typed the command.

use Api\V3\Controllers\ReportsController;
use Prosper202\Database\Connection;
use Prosper202\Integration\NotificationSecrets;
use Prosper202\Integration\SlackCommand;

include_once(substr(__DIR__, 0, -19) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/static-endpoint-helpers.php');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    p202RespondJsonError(405, 'Slack posts its commands');
}
$body = file_get_contents('php://input');
if (!is_string($body)) {
    p202RespondJsonError(400, 'The request body could not be read');
}
parse_str($body, $fields);
if (($fields['ssl_check'] ?? '') === '1') {
    http_response_code(200);
    die();
}

$reply = static function (string $text): never {
    p202RespondJson(['response_type' => 'ephemeral', 'text' => $text]);
};

try {
    $holders = NotificationSecrets::holders(new Connection($db), 'user_slack_signing_secret');
} catch (\Throwable $e) {
    error_log('slack: the signing secrets could not be read: ' . $e->getMessage());
    $reply('Prosper202 could not read its Slack settings just now. Try again.');
}
$timestamp = (string) ($_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? '');
$signature = (string) ($_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? '');
$now = time();
$matches = array_values(array_filter(
    $holders,
    static fn (array $h): bool => SlackCommand::verify($body, $timestamp, $signature, $h['secret'], $now)
));
if ($matches === []) {
    p202RespondJsonError(401, 'The request is not signed with a saved Slack signing secret,'
        . ' or its timestamp is more than five minutes off');
}
if (count($matches) > 1) {
    $reply('This Slack app\'s signing secret is saved on more than one Prosper202 account, so it cannot tell'
        . ' whose stats to show. Give each account its own Slack app.');
}
$account = $matches[0];

$command = is_string($fields['command'] ?? null) ? $fields['command'] : '';
try {
    [$action, $period] = SlackCommand::parse(is_string($fields['text'] ?? null) ? $fields['text'] : '');
} catch (\InvalidArgumentException $e) {
    $reply($e->getMessage() . "\n" . SlackCommand::help($command));
}
if ($action === 'help') {
    $reply(SlackCommand::help($command));
}

try {
    $summary = (new ReportsController($db, $account['user_id']))->summary(['period' => (string) $period]);
} catch (\Throwable $e) {
    error_log('slack: stats for user ' . $account['user_id'] . ' failed: ' . $e->getMessage());
    $reply('Prosper202 could not read your stats just now. Try again.');
}
$reply(SlackCommand::stats((string) $period, $summary['data'], $account['currency']));
