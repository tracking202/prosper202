#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * LTV outbound webhook dispatcher. Run every 1-5 minutes.
 *
 * Delivers pending rows from 202_ltv_webhook_deliveries (queued by the /ltv
 * API writes) to their endpoints. This cron is the ONLY place LTV webhook
 * HTTP happens — never inline in ingest or API handling.
 *
 * Hardening:
 *  - Each delivery is sent by Ltv\DeliverySender through the export
 *    webhooks' sender and guard (Attribution\WebhookSender,
 *    WebhookGuard::check()), re-checked at dispatch time (DNS may have
 *    changed since registration): https only, no private, loopback,
 *    link-local or metadata address in any spelling, pinned to the address
 *    checked, no proxy, redirects never followed.
 *  - Payloads signed with HMAC-SHA256 over the exact body, sent as
 *    X-P202-Signature: sha256=<hex>, so receivers can authenticate us.
 *  - Failures back off exponentially (2^attempts minutes). After
 *    MysqlWebhookRepository::MAX_ATTEMPTS the delivery is marked failed and
 *    the endpoint is marked dead — visible state, no silent infinite retry.
 *  - Response bodies stored truncated (1000 chars). No payload contents
 *    (potential PII) are ever written to logs — only ids and status codes.
 */

error_reporting(E_ALL);

require_once __DIR__ . '/../202-config/connect.php';

use Prosper202\Database\Connection;
use Prosper202\Ltv\DeliverySender;
use Prosper202\Ltv\MysqlWebhookRepository;

set_time_limit(0);

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "ltv_webhooks: database connection unavailable\n");
    exit(1);
}

$conn = new Connection($db);
$repo = new MysqlWebhookRepository($conn);
$sender = new DeliverySender();

$batchSize = 100;
$delivered = 0;
$failed = 0;

try {
    foreach ($repo->duePending($batchSize) as $delivery) {
        $deliveryId = (int) $delivery['delivery_id'];
        $webhookId = (int) $delivery['webhook_id'];

        // Claim before POSTing: overlapping cron runs both read the same
        // pending set, but only one worker wins this atomic UPDATE — the
        // other skips, so endpoints never receive a delivery twice.
        if (!$repo->claimDelivery($deliveryId, time())) {
            continue;
        }

        if ((string) $delivery['webhook_status'] !== 'active') {
            // Endpoint was disabled/died after this delivery was queued.
            $repo->recordAttempt($deliveryId, $webhookId, false, null, 'endpoint not active');
            $failed++;
            continue;
        }

        [$success, $statusCode, $responseBody] = $sender->post($delivery);
        $repo->recordAttempt($deliveryId, $webhookId, $success, $statusCode, $responseBody);

        if ($success) {
            $delivered++;
        } else {
            $failed++;
            error_log("ltv_webhooks: delivery {$deliveryId} to webhook {$webhookId} failed"
                . ' (status ' . ($statusCode ?? 0) . ')');
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ltv_webhooks failed: ' . $e->getMessage() . "\n");
    error_log('ltv_webhooks failed: ' . $e->getMessage());
    exit(1);
}

echo "ltv_webhooks: {$delivered} delivered, {$failed} failed/retrying\n";
