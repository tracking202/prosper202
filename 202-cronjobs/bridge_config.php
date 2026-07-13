#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Bandit bridge remote-config pull. Run every 6 hours.
 *
 * For every user paired with the Landing Page Optimizer (lpo_status =
 * 'active') this fetches the bridge config from the SaaS, verifies its
 * HMAC-SHA256 signature against the pairing webhook secret (hash_equals;
 * both sides sign json_encode(config) with PHP defaults), persists it to
 * 202_users_pref.lpo_bridge_config, and applies it to the local webhook
 * row: enabled_events maps onto subscribed_events ('*' = '' = subscribe-all)
 * and a hook_url change re-runs the SSRF guard (assertUrlAllowed) before the
 * URL is updated. This makes event routing and endpoints adjustable
 * server-side after install, without a Prosper202 release.
 *
 * Fails closed: any verification, validation, or transport failure keeps the
 * currently applied config for that user — the bridge never guesses.
 */

error_reporting(E_ALL);

include_once(str_repeat("../", 1) . '202-config/connect.php');

use Prosper202\Lpo\CtxToken;
use Prosper202\Lpo\DimensionSync;
use Prosper202\Lpo\PairingClient;
use Prosper202\Database\Connection;
use Prosper202\Ltv\MysqlWebhookRepository;

set_time_limit(0);

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "bridge_config: database connection unavailable\n");
    exit(1);
}

$conn = new Connection($db);

try {
    $stmt = $conn->prepareRead(
        "SELECT p.user_id, p.lpo_bridge_config, u.install_hash
         FROM 202_users_pref p
         JOIN 202_users u ON u.user_id = p.user_id
         WHERE p.lpo_status = 'active'"
    );
    $paired = $conn->fetchAll($stmt);
} catch (Throwable $e) {
    if (Connection::isMysqlError($e, 1054, 'Unknown column')) {
        // Pre-upgrade schema: the bandit columns do not exist yet.
        echo "bridge_config: bandit schema not installed; nothing to do\n";
        exit(0);
    }
    fwrite(STDERR, 'bridge_config failed: ' . $e->getMessage() . "\n");
    error_log('bridge_config failed: ' . $e->getMessage());
    exit(1);
}

$client = new PairingClient();
$now = time();
$updated = 0;
$fresh = 0;
$failed = 0;

foreach ($paired as $row) {
    $userId = (int) $row['user_id'];
    try {
        $state = json_decode((string) ($row['lpo_bridge_config'] ?? ''), true);
        $state = is_array($state) ? $state : [];
        $webhookId = (int) ($state['webhook_id'] ?? 0);
        if ($webhookId <= 0) {
            throw new RuntimeException('no local webhook recorded for this pairing');
        }

        // Backfill the derived t202ctx key for pairings created before
        // ctx_token support (p202-edge-sync §3.3). New pairings cache it at
        // connect time; older ones pick it up here on the next cron pass —
        // the redirect hot path never derives or writes, it just skips
        // minting until this lands. Runs BEFORE the freshness gate so
        // config-fresh users are healed too.
        if (preg_match('/^[0-9a-f]{64}$/', (string) ($state['ctx_key'] ?? '')) !== 1) {
            $keyStmt = $conn->prepareRead(
                'SELECT webhook_secret FROM 202_ltv_webhooks WHERE webhook_id = ? AND user_id = ? LIMIT 1'
            );
            $conn->bind($keyStmt, 'ii', [$webhookId, $userId]);
            $keyRow = $conn->fetchOne($keyStmt);
            if ($keyRow !== null && (string) $keyRow['webhook_secret'] !== '') {
                $ctxKeyHex = bin2hex(CtxToken::deriveKey((string) $keyRow['webhook_secret']));
                $state['ctx_key'] = $ctxKeyHex; // keep this loop's view coherent
                // CAS persist onto the FRESH pref state, not this loop's
                // pre-loop decode: an admin save may have set dims_dirty
                // since the SELECT, and an unconditional write from the
                // stale decode would erase it (hourly push then skips the
                // user until the nightly full sync).
                DimensionSync::casMutateState($conn, $userId, function (array $freshState) use ($ctxKeyHex): array {
                    if (preg_match('/^[0-9a-f]{64}$/', (string) ($freshState['ctx_key'] ?? '')) !== 1) {
                        $freshState['ctx_key'] = $ctxKeyHex;
                    }
                    return $freshState;
                });
            }
        }

        // Respect the server-set poll interval (default 6h).
        $minPollMinutes = (int) ($state['config']['min_poll_minutes'] ?? 0);
        $minPollMinutes = $minPollMinutes > 0 ? $minPollMinutes : 360;
        if ((int) ($state['fetched_at'] ?? 0) > $now - $minPollMinutes * 60) {
            $fresh++;
            continue;
        }

        $installHash = trim((string) ($row['install_hash'] ?? ''));
        if ($installHash === '') {
            throw new RuntimeException('install_hash is empty');
        }

        $hookStmt = $conn->prepareRead(
            'SELECT webhook_url, webhook_secret FROM 202_ltv_webhooks WHERE webhook_id = ? AND user_id = ? LIMIT 1'
        );
        $conn->bind($hookStmt, 'ii', [$webhookId, $userId]);
        $hook = $conn->fetchOne($hookStmt);
        if ($hook === null) {
            throw new RuntimeException('webhook row ' . $webhookId . ' not found');
        }

        $response = $client->fetchBridgeConfig($installHash);
        $config = $response['config'] ?? null;
        $sig = (string) ($response['sig'] ?? '');
        if (!is_array($config) || $sig === '') {
            throw new RuntimeException('response missing config or sig');
        }

        // Verify before trusting a single field. The SaaS signs the exact
        // json_encode(config) bytes with the pairing webhook secret; PHP's
        // decode/encode round-trip preserves key order and escaping, so
        // re-encoding reproduces them.
        $configJson = json_encode($config);
        if ($configJson === false) {
            throw new RuntimeException('config re-encode failed');
        }
        $expected = MysqlWebhookRepository::signature($configJson, (string) $hook['webhook_secret']);
        if (!hash_equals($expected, $sig)) {
            throw new RuntimeException('signature verification failed');
        }

        // Apply enabled_events -> subscribed_events ('' = subscribe-all).
        // Names are shape-checked so a bad element can never corrupt the
        // stored CSV; an empty or absent list leaves the row untouched.
        $enabled = $config['enabled_events'] ?? null;
        if (is_array($enabled) && $enabled !== []) {
            $enabled = array_values(array_unique(array_map(strval(...), $enabled)));
            foreach ($enabled as $eventName) {
                if ($eventName !== '*' && preg_match('/^[a-z0-9_]+(\.[a-z0-9_-]+)+$/', $eventName) !== 1) {
                    throw new RuntimeException('enabled_events contains a malformed name: ' . $eventName);
                }
            }
            $subscribed = in_array('*', $enabled, true) ? '' : implode(',', $enabled);
            $update = $conn->prepareWrite(
                'UPDATE 202_ltv_webhooks SET subscribed_events = ?, updated_at = ? WHERE webhook_id = ? AND user_id = ?'
            );
            $conn->bind($update, 'siii', [$subscribed, $now, $webhookId, $userId]);
            $conn->executeUpdate($update);
        }

        // Apply a hook_url change, re-running the SSRF guard first.
        $newUrl = trim((string) ($config['hook_url'] ?? ''));
        if ($newUrl !== '' && $newUrl !== (string) $hook['webhook_url']) {
            MysqlWebhookRepository::assertUrlAllowed($newUrl);
            $update = $conn->prepareWrite(
                'UPDATE 202_ltv_webhooks SET webhook_url = ?, updated_at = ? WHERE webhook_id = ? AND user_id = ?'
            );
            $conn->bind($update, 'siii', [$newUrl, $now, $webhookId, $userId]);
            $conn->executeUpdate($update);
        }

        // CAS persist onto the FRESH pref state, not the pre-loop decode:
        // the SaaS fetch above leaves a wide window in which an admin/API
        // save can set dims_dirty (or the backfill above wrote ctx_key),
        // and an unconditional write from the stale decode would erase
        // those keys. The mutator re-applies only this cron's own fields;
        // a guard miss (a writer racing the final microseconds) simply
        // self-heals on the next 6-hour pass — same fail-closed posture
        // as every other failure in this loop.
        DimensionSync::casMutateState($conn, $userId, function (array $freshState) use ($config, $now): array {
            $freshState['config'] = $config;
            $freshState['fetched_at'] = $now;
            return $freshState;
        });
        $updated++;
    } catch (Throwable $e) {
        // Fail closed: keep the currently applied config for this user.
        $failed++;
        error_log('bridge_config: user ' . $userId . ' pull failed, keeping current config: ' . $e->getMessage());
    }
}

echo "bridge_config: {$updated} updated, {$fresh} fresh, {$failed} failed\n";
