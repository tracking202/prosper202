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
 *  - SSRF guard re-checked at dispatch time (DNS may have changed since
 *    registration): https only, no private/loopback/reserved addresses,
 *    redirects are never followed.
 *  - Payloads signed with HMAC-SHA256 over the exact body, sent as
 *    X-P202-Signature: sha256=<hex>, so receivers can authenticate us.
 *  - Failures back off exponentially (2^attempts minutes). After
 *    MysqlWebhookRepository::MAX_ATTEMPTS the delivery is marked failed and
 *    the endpoint is marked dead — visible state, no silent infinite retry.
 *  - Response bodies stored truncated (1000 chars). No payload contents
 *    (potential PII) are ever written to logs — only ids and status codes.
 */

error_reporting(E_ALL);

include_once(str_repeat("../", 1) . '202-config/connect.php');

use Prosper202\Database\Connection;
use Prosper202\Ltv\MysqlWebhookRepository;

set_time_limit(0);

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "ltv_webhooks: database connection unavailable\n");
    exit(1);
}

$conn = new Connection($db);
$repo = new MysqlWebhookRepository($conn);

$batchSize = 100;
$delivered = 0;
$failed = 0;

try {
    foreach ($repo->duePending($batchSize) as $delivery) {
        $deliveryId = (int) $delivery['delivery_id'];
        $webhookId = (int) $delivery['webhook_id'];
        $url = (string) $delivery['webhook_url'];
        $body = (string) $delivery['payload'];

        if ((string) $delivery['webhook_status'] !== 'active') {
            // Endpoint was disabled/died after this delivery was queued.
            $repo->recordAttempt($deliveryId, $webhookId, false, null, 'endpoint not active');
            $failed++;
            continue;
        }

        try {
            MysqlWebhookRepository::assertUrlAllowed($url);
        } catch (Throwable $guard) {
            $repo->recordAttempt($deliveryId, $webhookId, false, null, 'blocked: ' . $guard->getMessage());
            $failed++;
            continue;
        }

        $signature = MysqlWebhookRepository::signature($body, (string) $delivery['webhook_secret']);

        $ch = curl_init($url);
        if ($ch === false) {
            $repo->recordAttempt($deliveryId, $webhookId, false, null, 'curl init failed');
            $failed++;
            continue;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-P202-Signature: ' . $signature,
                'X-P202-Event: ' . (string) $delivery['event_name'],
                'X-P202-Delivery: ' . $deliveryId,
                'User-Agent: Prosper202-LTV-Webhook/1.0',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // SSRF: never follow redirects
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        $responseBody = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $success = $responseBody !== false && $statusCode >= 200 && $statusCode < 300;
        $repo->recordAttempt(
            $deliveryId,
            $webhookId,
            $success,
            $statusCode > 0 ? $statusCode : null,
            $responseBody !== false ? (string) $responseBody : ('curl: ' . $curlError)
        );

        if ($success) {
            $delivered++;
        } else {
            $failed++;
            error_log("ltv_webhooks: delivery {$deliveryId} to webhook {$webhookId} failed (status {$statusCode})");
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ltv_webhooks failed: ' . $e->getMessage() . "\n");
    error_log('ltv_webhooks failed: ' . $e->getMessage());
    exit(1);
}

echo "ltv_webhooks: {$delivered} delivered, {$failed} failed/retrying\n";
