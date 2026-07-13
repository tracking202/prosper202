#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Bandit dimension-map push (p202-edge-sync §4.1/§4.2). Run nightly.
 *
 * For every user paired with the Landing Page Optimizer (bandit_status =
 * 'active') this snapshots the install's slowly-changing dictionaries —
 * traffic-source networks/accounts, campaigns and landing pages — and POSTs
 * them to the SaaS control plane via PairingClient::pushDimensions, signed
 * over the raw body with the pairing webhook secret (the exact
 * MysqlWebhookRepository::signature scheme the webhook dispatcher uses).
 * The SaaS compiles the map into the site config so segment buckets and
 * portal pickers can show real names ("Facebook Ads") instead of ids.
 *
 * Snapshot rules (§4.1): non-deleted rows only; caps 64 networks / 256
 * accounts / 256 campaigns / 128 landing pages keeping most-recently-updated
 * first; names truncated to 80 chars. Keywords are never synced.
 *
 * Also opportunistically backfills the derived t202ctx key into the
 * bandit_bridge_config pref (the webhook secret is already in hand here),
 * complementing the same backfill in bridge_config.php.
 *
 * The per-user secret-resolve/snapshot/push path lives in
 * DimensionSync::pushForUser, shared with the hourly dirty-user tier in
 * 202-cronjobs/index.php (segments-v2 G10). This nightly run stays the full
 * unconditional sync: every active pairing, dirty flag or not.
 *
 * Never fatal across users: one user's failure is logged and the loop moves
 * on — the SaaS keeps serving the previous snapshot for that install.
 */

error_reporting(E_ALL);

include_once(str_repeat("../", 1) . '202-config/connect.php');

use Prosper202\Lpo\DimensionSync;
use Prosper202\Lpo\PairingClient;
use Prosper202\Database\Connection;

set_time_limit(0);

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "bandit_dimensions: database connection unavailable\n");
    exit(1);
}

$conn = new Connection($db);

try {
    $stmt = $conn->prepareRead(
        "SELECT p.user_id, p.bandit_bridge_config, u.install_hash
         FROM 202_users_pref p
         JOIN 202_users u ON u.user_id = p.user_id
         WHERE p.bandit_status = 'active'"
    );
    $paired = $conn->fetchAll($stmt);
} catch (Throwable $e) {
    if (Connection::isMysqlError($e, 1054, 'Unknown column')) {
        // Pre-upgrade schema: the bandit columns do not exist yet.
        echo "bandit_dimensions: bandit schema not installed; nothing to do\n";
        exit(0);
    }
    fwrite(STDERR, 'bandit_dimensions failed: ' . $e->getMessage() . "\n");
    error_log('bandit_dimensions failed: ' . $e->getMessage());
    exit(1);
}

$client = new PairingClient();
$pushed = 0;
$failed = 0;

foreach ($paired as $row) {
    $userId = (int) $row['user_id'];
    try {
        // Secret resolution, ctx_key backfill, §4.1 snapshot and the signed
        // POST all live in the shared per-user path (segments-v2 G10).
        $counts = DimensionSync::pushForUser(
            $conn,
            $client,
            $userId,
            isset($row['bandit_bridge_config']) ? (string) $row['bandit_bridge_config'] : null,
            isset($row['install_hash']) ? (string) $row['install_hash'] : null
        );

        $pushed++;
        echo 'bandit_dimensions: user ' . $userId . ' pushed '
            . $counts['networks'] . ' networks, '
            . $counts['accounts'] . ' accounts, '
            . $counts['campaigns'] . ' campaigns, '
            . $counts['landing_pages'] . " landing pages\n";
    } catch (Throwable $e) {
        // Never fatal across users; the SaaS keeps the previous snapshot.
        $failed++;
        error_log('bandit_dimensions: user ' . $userId . ' push failed: ' . $e->getMessage());
        echo 'bandit_dimensions: user ' . $userId . ' FAILED: ' . $e->getMessage() . "\n";
    }
}

echo "bandit_dimensions: {$pushed} pushed, {$failed} failed\n";
