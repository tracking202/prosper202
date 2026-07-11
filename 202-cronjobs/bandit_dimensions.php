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
 * Never fatal across users: one user's failure is logged and the loop moves
 * on — the SaaS keeps serving the previous snapshot for that install.
 */

error_reporting(E_ALL);

include_once(str_repeat("../", 1) . '202-config/connect.php');

use Prosper202\Bandit\CtxToken;
use Prosper202\Bandit\PairingClient;
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

/** §4.1: names truncated to 80 chars (character-based, UTF-8). */
$truncateName = static function (string $name): string {
    $name = trim($name);

    return function_exists('mb_substr') ? mb_substr($name, 0, 80, 'UTF-8') : substr($name, 0, 80);
};

$client = new PairingClient();
$pushed = 0;
$failed = 0;

foreach ($paired as $row) {
    $userId = (int) $row['user_id'];
    try {
        $state = json_decode((string) ($row['bandit_bridge_config'] ?? ''), true);
        $state = is_array($state) ? $state : [];
        $webhookId = (int) ($state['webhook_id'] ?? 0);
        if ($webhookId <= 0) {
            throw new RuntimeException('no local webhook recorded for this pairing');
        }

        $installHash = trim((string) ($row['install_hash'] ?? ''));
        if ($installHash === '') {
            throw new RuntimeException('install_hash is empty');
        }

        $hookStmt = $conn->prepareRead(
            'SELECT webhook_secret FROM 202_ltv_webhooks WHERE webhook_id = ? AND user_id = ? LIMIT 1'
        );
        $conn->bind($hookStmt, 'ii', [$webhookId, $userId]);
        $hook = $conn->fetchOne($hookStmt);
        if ($hook === null) {
            throw new RuntimeException('webhook row ' . $webhookId . ' not found');
        }
        $webhookSecret = (string) $hook['webhook_secret'];
        if ($webhookSecret === '') {
            throw new RuntimeException('webhook row ' . $webhookId . ' has an empty secret');
        }

        // Backfill the derived t202ctx key for pre-ctx_token pairings — the
        // secret is already loaded, so this costs one UPDATE at most once.
        if (preg_match('/^[0-9a-f]{64}$/', (string) ($state['ctx_key'] ?? '')) !== 1) {
            $state['ctx_key'] = bin2hex(CtxToken::deriveKey($webhookSecret));
            $stateJson = json_encode($state);
            if ($stateJson !== false) {
                $persist = $conn->prepareWrite('UPDATE 202_users_pref SET bandit_bridge_config = ? WHERE user_id = ?');
                $conn->bind($persist, 'si', [$stateJson, $userId]);
                $conn->executeUpdate($persist);
            }
        }

        // Build the §4.1 snapshot: non-deleted rows, most-recently-updated
        // first within each cap, names truncated to 80 chars.
        $networks = [];
        $netStmt = $conn->prepareRead(
            'SELECT ppc_network_id, ppc_network_name
             FROM 202_ppc_networks
             WHERE user_id = ? AND ppc_network_deleted = 0
             ORDER BY ppc_network_time DESC, ppc_network_id DESC
             LIMIT 64'
        );
        $conn->bind($netStmt, 'i', [$userId]);
        foreach ($conn->fetchAll($netStmt) as $net) {
            $networks[] = [
                'id' => (int) $net['ppc_network_id'],
                'name' => $truncateName((string) $net['ppc_network_name']),
            ];
        }

        $accounts = [];
        $accStmt = $conn->prepareRead(
            'SELECT ppc_account_id, ppc_account_name, ppc_network_id
             FROM 202_ppc_accounts
             WHERE user_id = ? AND ppc_account_deleted = 0
             ORDER BY ppc_account_time DESC, ppc_account_id DESC
             LIMIT 256'
        );
        $conn->bind($accStmt, 'i', [$userId]);
        foreach ($conn->fetchAll($accStmt) as $acc) {
            $accounts[] = [
                'id' => (int) $acc['ppc_account_id'],
                'name' => $truncateName((string) $acc['ppc_account_name']),
                'network_id' => (int) $acc['ppc_network_id'],
            ];
        }

        $campaigns = [];
        $cmpStmt = $conn->prepareRead(
            'SELECT aff_campaign_id, aff_campaign_name, aff_network_id
             FROM 202_aff_campaigns
             WHERE user_id = ? AND aff_campaign_deleted = 0
             ORDER BY aff_campaign_time DESC, aff_campaign_id DESC
             LIMIT 256'
        );
        $conn->bind($cmpStmt, 'i', [$userId]);
        foreach ($conn->fetchAll($cmpStmt) as $cmp) {
            $campaigns[] = [
                'id' => (int) $cmp['aff_campaign_id'],
                'name' => $truncateName((string) $cmp['aff_campaign_name']),
                'aff_network_id' => (int) $cmp['aff_network_id'],
            ];
        }

        $landingPages = [];
        $lpStmt = $conn->prepareRead(
            'SELECT landing_page_id, landing_page_nickname
             FROM 202_landing_pages
             WHERE user_id = ? AND landing_page_deleted = 0
             ORDER BY landing_page_time DESC, landing_page_id DESC
             LIMIT 128'
        );
        $conn->bind($lpStmt, 'i', [$userId]);
        foreach ($conn->fetchAll($lpStmt) as $lp) {
            $landingPages[] = [
                'id' => (int) $lp['landing_page_id'],
                'name' => $truncateName((string) $lp['landing_page_nickname']),
            ];
        }

        $client->pushDimensions($installHash, [
            'networks' => $networks,
            'accounts' => $accounts,
            'campaigns' => $campaigns,
            'landing_pages' => $landingPages,
        ], $webhookSecret);

        $pushed++;
        echo 'bandit_dimensions: user ' . $userId . ' pushed '
            . count($networks) . ' networks, '
            . count($accounts) . ' accounts, '
            . count($campaigns) . ' campaigns, '
            . count($landingPages) . " landing pages\n";
    } catch (Throwable $e) {
        // Never fatal across users; the SaaS keeps the previous snapshot.
        $failed++;
        error_log('bandit_dimensions: user ' . $userId . ' push failed: ' . $e->getMessage());
        echo 'bandit_dimensions: user ' . $userId . ' FAILED: ' . $e->getMessage() . "\n";
    }
}

echo "bandit_dimensions: {$pushed} pushed, {$failed} failed\n";
