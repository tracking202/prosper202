#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * LTV backfill: link historical conversions to customers and populate the
 * revenue ledger, so accounts enabling LTV see their existing history
 * instead of an empty report.
 *
 * Two passes, both idempotent (safe to re-run; a crash resumes where it left
 * off):
 *
 *  Pass 1 — unlinked conversions for accounts with the c-param fallback
 *  configured (202_users_pref.user_ltv_customer_cparam = 1..4): resolve the
 *  customer from the click's cN value, stamp conversion + click, and append
 *  the ledger event.
 *
 *  Pass 2 — conversions already linked to a customer but missing their
 *  ledger event (e.g. recorded between schema migration and code deploy):
 *  append the missing event.
 *
 * Ledger events are written with source='import' and the deterministic
 * idempotency key 'backfill:conv:{conv_id}' — plus the UNIQUE(conv_id) key —
 * so re-runs are no-ops. Rollups are bumped only for newly inserted events;
 * the ltv_maintenance reconcile corrects any residue either way.
 *
 * Usage: php run_ltv_backfill.php [--user=ID] [--batch-size=N] [--dry-run]
 */

include_once dirname(__DIR__) . '/connect.php';

use Prosper202\Database\Connection;
use Prosper202\Ltv\MysqlCustomerRepository;

if (!isset($db) || !($db instanceof mysqli)) {
    die("Error: Database connection not available\n");
}

$options = getopt('', ['user::', 'batch-size::', 'dry-run']);
$onlyUser = isset($options['user']) ? (int) $options['user'] : 0;
$batchSize = isset($options['batch-size']) ? max(10, (int) $options['batch-size']) : 500;
$dryRun = isset($options['dry-run']);

$conn = new Connection($db);
$customers = new MysqlCustomerRepository($conn);

$totalLinked = 0;
$totalEvents = 0;
$totalSkipped = 0;

/**
 * Append the ledger event for one historical conversion and bump rollups,
 * inside its own small transaction. Returns true when a new event was
 * inserted.
 */
$backfillEvent = function (array $row, int $customerId) use ($conn, $customers): bool {
    $userId = (int) $row['user_id'];
    $convId = (int) $row['conv_id'];
    $convTime = (int) $row['conv_time'];
    $payout = (float) $row['click_payout'];
    $deleted = (int) $row['deleted'] === 1;

    return $conn->transaction(function () use ($conn, $customers, $row, $customerId, $userId, $convId, $convTime, $payout, $deleted): bool {
        $currency = $customers->accountCurrency($userId);
        $event = $customers->insertRevenueEvent($userId, $customerId, [
            'event_type' => 'purchase',
            'amount' => $payout,
            'currency' => $currency,
            'occurred_at' => $convTime,
            'source' => 'import',
            'conv_id' => $convId,
            'click_id' => (int) $row['click_id'],
            'transaction_id' => $row['transaction_id'] !== null ? (string) $row['transaction_id'] : null,
            'idempotency_key' => 'backfill:conv:' . $convId,
        ], time());

        if ($event['inserted'] && !$deleted) {
            $customers->applyEventToRollups($userId, $customerId, 'purchase', $payout, $convTime, time());
        }
        if ($event['inserted'] && $deleted) {
            // Soft-deleted conversion: import both the purchase and its void so
            // the ledger's SUM stays correct without touching rollups.
            $customers->insertRevenueEvent($userId, $customerId, [
                'event_type' => 'adjustment',
                'amount' => -$payout,
                'currency' => $currency,
                'occurred_at' => $convTime,
                'source' => 'import',
                'external_ref' => 'void:conv:' . $convId,
                'idempotency_key' => 'void:conv:' . $convId,
            ], time());
        }

        return $event['inserted'];
    });
};

echo "LTV backfill starting" . ($dryRun ? ' (dry run)' : '') . "...\n";

try {
    // ---------- Pass 1: unlinked conversions via the c-param fallback ----------
    $userWhere = $onlyUser > 0 ? "AND up.user_id = {$onlyUser}" : '';
    $prefResult = $db->query(
        "SELECT up.user_id, up.user_ltv_customer_cparam AS cparam
         FROM 202_users_pref up
         WHERE up.user_ltv_customer_cparam BETWEEN 1 AND 4 {$userWhere}"
    );
    if ($prefResult === false) {
        throw new RuntimeException('Failed to read c-param prefs: ' . $db->error);
    }

    while ($pref = $prefResult->fetch_assoc()) {
        $userId = (int) $pref['user_id'];
        $cparam = (int) $pref['cparam'];
        $column = 'c' . $cparam;
        echo "Pass 1: user {$userId} (customer ref = {$column})\n";

        $lastConvId = 0;
        while (true) {
            // Keyset pagination on conv_id; only conversions whose click has a
            // non-empty cN value can be linked.
            $sql = "SELECT cl.conv_id, cl.click_id, cl.user_id, cl.conv_time, cl.click_payout,
                           cl.transaction_id, cl.deleted, t.{$column} AS ref
                    FROM 202_conversion_logs cl
                    JOIN 202_clicks_tracking ct ON ct.click_id = cl.click_id
                    JOIN 202_tracking_{$column} t ON t.{$column}_id = ct.{$column}_id
                    WHERE cl.user_id = {$userId}
                      AND cl.customer_id IS NULL
                      AND cl.conv_id > {$lastConvId}
                      AND t.{$column} <> ''
                    ORDER BY cl.conv_id ASC
                    LIMIT {$batchSize}";
            $result = $db->query($sql);
            if ($result === false) {
                throw new RuntimeException('Pass 1 query failed: ' . $db->error);
            }
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $lastConvId = (int) $row['conv_id'];
                $ref = trim((string) $row['ref']);
                if ($ref === '') {
                    $totalSkipped++;
                    continue;
                }
                if ($dryRun) {
                    $totalLinked++;
                    continue;
                }

                $customerId = $conn->transaction(
                    fn (): int => $customers->resolveOrCreateByAlias(
                        $userId,
                        'custom',
                        $ref,
                        [],
                        (int) $row['click_id'],
                        (int) $row['conv_time']
                    )
                );

                // Stamp the conversion and cache the link on the click.
                $stmt = $conn->prepareWrite(
                    'UPDATE 202_conversion_logs SET customer_id = ? WHERE conv_id = ? AND customer_id IS NULL'
                );
                $conn->bind($stmt, 'ii', [$customerId, (int) $row['conv_id']]);
                $conn->executeUpdate($stmt);
                $conn->transaction(function () use ($customers, $row, $customerId): void {
                    $customers->stampClickCustomer((int) $row['click_id'], $customerId);
                });

                if ($backfillEvent($row, $customerId)) {
                    $totalEvents++;
                }
                $totalLinked++;
            }

            echo "  ...{$totalLinked} linked so far\n";
            if ($dryRun) {
                // Dry run never updates rows, so keyset pagination alone
                // (conv_id > last) advances the scan.
                continue;
            }
        }
    }

    // ---------- Pass 2: linked conversions missing their ledger event ----------
    $userWhere2 = $onlyUser > 0 ? "AND cl.user_id = {$onlyUser}" : '';
    $lastConvId = 0;
    while (true) {
        $sql = "SELECT cl.conv_id, cl.click_id, cl.user_id, cl.conv_time, cl.click_payout,
                       cl.transaction_id, cl.deleted, cl.customer_id
                FROM 202_conversion_logs cl
                LEFT JOIN 202_revenue_events re ON re.conv_id = cl.conv_id
                WHERE cl.customer_id IS NOT NULL
                  AND re.event_id IS NULL
                  AND cl.conv_id > {$lastConvId}
                  {$userWhere2}
                ORDER BY cl.conv_id ASC
                LIMIT {$batchSize}";
        $result = $db->query($sql);
        if ($result === false) {
            throw new RuntimeException('Pass 2 query failed: ' . $db->error);
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        if ($rows === []) {
            break;
        }

        foreach ($rows as $row) {
            $lastConvId = (int) $row['conv_id'];
            if ($dryRun) {
                $totalEvents++;
                continue;
            }
            if ($backfillEvent($row, (int) $row['customer_id'])) {
                $totalEvents++;
            }
        }
        echo "Pass 2: ...{$totalEvents} events so far\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "\nBackfill failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Re-running is safe — completed work is idempotent.\n");
    exit(1);
}

echo "\nBackfill complete: {$totalLinked} conversions linked, {$totalEvents} ledger events written, {$totalSkipped} skipped.\n";
if (!$dryRun) {
    echo "Run 202-cronjobs/ltv_maintenance.php --full to reconcile rollups.\n";
}
