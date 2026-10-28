<?php

/**
 * One LTV webhook dispatch run on a connection of its own, for
 * WebhookDispatchIntegrationTest's overlapping runs: `exclusive` is the
 * run both cron entry points make (WebhookDispatcher::runExclusive()),
 * `unlocked` is run() alone, which leaves the claim the only arbiter.
 *
 * argv: <exclusive|unlocked> <receiver base URL> <seconds> <start at, unix seconds>
 * Waits until the start time, so two runners begin together, then prints
 * the report as JSON (null when the lock was held), or the failure.
 *
 * env: P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 */

declare(strict_types=1);

[, $mode, $base, $seconds, $startAt] = $argv + [null, '', '', '0', '0'];

require __DIR__ . '/../../../vendor/autoload.php';

use Prosper202\Database\Connection;
use Prosper202\Ltv\WebhookDispatcher;
use Tests\Ltv\LocalWebhookReceiver;

mysqli_report(MYSQLI_REPORT_STRICT);
$db = new mysqli(
    (string) getenv('P202_TEST_DB_HOST'),
    (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
    (string) (getenv('P202_TEST_DB_PASS') ?: ''),
    (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
    (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
);
$send = static fn (array $delivery): array => LocalWebhookReceiver::send($base, $delivery);

while (microtime(true) < (float) $startAt) {
    usleep(10000);
}

try {
    $conn = new Connection($db);
    $deadline = time() + (int) $seconds;
    $report = match ($mode) {
        'exclusive' => WebhookDispatcher::runExclusive($conn, $deadline, $send),
        'unlocked' => (new WebhookDispatcher($conn, $send))->run($deadline),
        default => throw new InvalidArgumentException('mode: ' . $mode),
    };
    echo json_encode($report), "\n";
} catch (Throwable $e) {
    echo 'failed: ', $e::class, ': ', $e->getMessage(), "\n";
    exit(1);
}
