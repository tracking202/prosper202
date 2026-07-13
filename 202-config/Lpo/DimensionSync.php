<?php

declare(strict_types=1);

namespace Prosper202\Lpo;

use mysqli;
use Prosper202\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * Dimension-map sync helpers for the Landing Page Optimizer pairing
 * (p202-edge-sync §4, segments-v2 G10).
 *
 * Two halves:
 *
 *  - markDirty(): called from the dictionary CRUD save paths
 *    (setup/ppc_accounts.php, setup/aff_campaigns.php, setup/aff_networks.php,
 *    setup/landing_pages.php) after a successful INSERT/UPDATE. It only flips
 *    `dims_dirty` / `dims_dirty_at` inside the existing bandit_bridge_config
 *    JSON pref on 202_users_pref — deliberately no new column and no HTTP,
 *    and never fatal: an admin save must never break because the pairing is
 *    unhappy or the bandit schema predates the upgrade.
 *
 *  - pushForUser() / clearDirty(): the §4.1/§4.2 snapshot push, shared by the
 *    nightly full sync (202-cronjobs/bandit_dimensions.php) and the hourly
 *    dirty-user tier (202-cronjobs/index.php). The dirty flag is cleared only
 *    after a successful push — a failed push leaves it set so the next hourly
 *    tick retries, and the nightly full sync remains the backstop either way.
 */
final class DimensionSync
{
    /**
     * Flag the user's dimension snapshot as dirty after a dictionary
     * INSERT/UPDATE, so the hourly cron tier re-pushes it (segments-v2 G10).
     *
     * Stored inside the existing bandit_bridge_config JSON pref as
     * `dims_dirty: true` plus `dims_dirty_at` (a strictly monotonic
     * per-save token, normally epoch seconds of the latest dictionary
     * change). Monotonic — max(now, previous + 1) — because clearDirty()
     * uses the value to detect saves that raced a push: two saves inside
     * the same wall-clock second would otherwise write identical tokens
     * (and byte-identical pref JSON), letting a push that snapshotted
     * between them clear the flag even though the snapshot missed the
     * second save.
     *
     * Only acts for users paired with bandit_status='active'. Absolutely
     * never fatal and never any HTTP: any problem (pre-upgrade schema
     * without the bandit columns, DB hiccup, malformed JSON) leaves the
     * admin save exactly as it was — the nightly full sync covers the gap.
     */
    public static function markDirty(?mysqli $db, int $userId): void
    {
        try {
            if (!$db instanceof mysqli || $userId <= 0) {
                return;
            }

            $conn = new Connection($db);
            $stmt = $conn->prepareRead(
                'SELECT bandit_status, bandit_bridge_config FROM 202_users_pref WHERE user_id = ? LIMIT 1'
            );
            $conn->bind($stmt, 'i', [$userId]);
            $row = $conn->fetchOne($stmt);

            if ($row === null || (string) ($row['bandit_status'] ?? '') !== 'active') {
                return;
            }

            // Persist through the byte-guarded CAS (fresh re-read inside):
            // an unconditional UPDATE from the row read above would clobber
            // any pref write landing in between — e.g. the six-hour pull's
            // freshly fetched config/fetched_at/ctx_key, and EventBridge
            // routing reads config.enabled_events from this same JSON. The
            // save-path hook is one-shot (no cron re-applies it), so a guard
            // miss retries with fresh bytes instead of dropping the flag
            // until the nightly full sync.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $landed = self::casMutateState($conn, $userId, static function (array $state): array {
                    $state['dims_dirty'] = true;
                    // Strictly monotonic per-save token (see the docblock),
                    // recomputed from the FRESH state on every attempt: a
                    // save landing in the same second as the previous one
                    // must still produce a new value, or clearDirty() could
                    // not tell it apart from the save whose snapshot was
                    // already pushed.
                    $state['dims_dirty_at'] = max(time(), (int) ($state['dims_dirty_at'] ?? 0) + 1);

                    return $state;
                });
                if ($landed) {
                    return;
                }
            }
            error_log('DimensionSync::markDirty user ' . $userId . ': dirty flag did not land after 3 CAS attempts');
        } catch (Throwable $e) {
            // Pre-upgrade schemas miss the bandit columns entirely — that is
            // the expected quiet case, not worth log noise on every save.
            if (!Connection::isMysqlError($e, 1054, 'Unknown column')) {
                error_log('DimensionSync::markDirty user ' . $userId . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Clear the dirty flag after a successful push.
     *
     * Re-reads the pref (the push itself may have backfilled ctx_key into
     * it) and only clears when `dims_dirty_at` still equals the value
     * observed before the push ($expectedDirtyAt): if a dictionary save
     * landed mid-push, the snapshot just sent is already stale, so the flag
     * stays set for the next hourly tick.
     *
     * The write is an atomic compare-and-set on the exact pref bytes read
     * here — not just the PHP precheck above. A markDirty() (or any other
     * pref write) landing between this read and the UPDATE changes those
     * bytes, so the guard misses, zero rows update, and the newer dirty
     * flag survives for the next hourly tick instead of being clobbered
     * by a stale re-encode.
     */
    public static function clearDirty(Connection $conn, int $userId, mixed $expectedDirtyAt): void
    {
        $stmt = $conn->prepareRead(
            'SELECT bandit_bridge_config FROM 202_users_pref WHERE user_id = ? LIMIT 1'
        );
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            return;
        }

        $rawJson = (string) ($row['bandit_bridge_config'] ?? '');
        $state = json_decode($rawJson, true);
        if (!is_array($state) || empty($state['dims_dirty'])) {
            return;
        }
        if (($state['dims_dirty_at'] ?? null) !== $expectedDirtyAt) {
            return;
        }

        unset($state['dims_dirty'], $state['dims_dirty_at']);
        $stateJson = json_encode($state);
        if ($stateJson === false) {
            return;
        }

        $update = $conn->prepareWrite(
            'UPDATE 202_users_pref SET bandit_bridge_config = ? WHERE user_id = ? AND bandit_bridge_config = ?'
        );
        $conn->bind($update, 'sis', [$stateJson, $userId, $rawJson]);
        $conn->executeUpdate($update);
    }

    /**
     * Concurrency-safe persist of a bandit_bridge_config mutation.
     *
     * Re-reads the CURRENT pref bytes, applies $mutate to the decoded
     * state, and writes back guarded by a compare-and-set on the exact
     * bytes read. Writers holding an older decode (e.g. the six-hour
     * bridge-config pull, whose SELECT precedes a slow SaaS HTTP fetch)
     * must persist through this instead of an unconditional UPDATE: the
     * stale re-encode would erase keys written in between, such as a
     * mid-pull markDirty()'s dims_dirty — the hourly dimension push
     * would then skip the user until the nightly full sync.
     *
     * Single attempt, mirroring clearDirty(): a guard miss means another
     * writer changed the state in the microseconds between the re-read and
     * the UPDATE, so the caller's change simply does not land this pass.
     * Recurring jobs (the 6-hour pull, the ctx_key backfill) re-apply on
     * their next run and may ignore the return value; one-shot callers
     * (markDirty on an admin save) check it and retry with fresh bytes.
     * What is guaranteed either way is that OTHER writers' keys are never
     * clobbered.
     *
     * @param callable(array): array $mutate pure, idempotent transform of the decoded state
     * @return bool true when the mutated state is persisted (or the pref
     *              already had the desired bytes); false on a guard miss,
     *              a missing pref row, or a failed re-encode
     */
    public static function casMutateState(Connection $conn, int $userId, callable $mutate): bool
    {
        $stmt = $conn->prepareRead(
            'SELECT bandit_bridge_config FROM 202_users_pref WHERE user_id = ? LIMIT 1'
        );
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            return false;
        }

        $rawJson = (string) ($row['bandit_bridge_config'] ?? '');
        $state = json_decode($rawJson, true);
        $state = is_array($state) ? $state : [];

        $stateJson = json_encode($mutate($state));
        if ($stateJson === false) {
            return false;
        }
        if ($stateJson === $rawJson) {
            return true; // already in the desired shape
        }

        $update = $conn->prepareWrite(
            'UPDATE 202_users_pref SET bandit_bridge_config = ? WHERE user_id = ? AND bandit_bridge_config = ?'
        );
        $conn->bind($update, 'sis', [$stateJson, $userId, $rawJson]);

        return $conn->executeUpdate($update) > 0;
    }

    /**
     * Resolve the pairing webhook secret, opportunistically backfill the
     * derived t202ctx key, build the §4.1 snapshot and POST it via
     * PairingClient::pushDimensions — the exact per-user path the nightly
     * cron has always used, extracted here so the hourly dirty tier shares
     * it instead of duplicating.
     *
     * Throws on any failure (missing pairing rows, transport/HTTP errors):
     * callers decide the policy — the crons log and keep the dirty flag set.
     *
     * @param ?string $bridgeConfigJson the raw bandit_bridge_config pref value
     * @param ?string $installHash the 202_users.install_hash for this user
     * @return array{networks: int, accounts: int, campaigns: int, landing_pages: int}
     *         entity counts actually pushed (for cron progress output)
     */
    public static function pushForUser(
        Connection $conn,
        PairingClient $client,
        int $userId,
        ?string $bridgeConfigJson,
        ?string $installHash
    ): array {
        $state = json_decode((string) ($bridgeConfigJson ?? ''), true);
        $state = is_array($state) ? $state : [];
        $webhookId = (int) ($state['webhook_id'] ?? 0);
        if ($webhookId <= 0) {
            throw new RuntimeException('no local webhook recorded for this pairing');
        }

        $installHash = trim((string) ($installHash ?? ''));
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
        // secret is already loaded, so this costs one guarded write at most
        // once. $bridgeConfigJson was selected by the cron before the
        // per-user work started (possibly minutes stale behind a SaaS
        // fetch), so the persist goes through casMutateState: a bare UPDATE
        // from that stale decode would drop anything written in between — a
        // fresh 6-hour config pull, or an admin save's dims_dirty (and with
        // it the failed-push retry). The callback re-checks on the fresh
        // bytes; if another writer already backfilled, the mutation is a
        // byte-identical no-op. Single attempt: this is a recurring-job
        // path, the next run re-applies.
        if (preg_match('/^[0-9a-f]{64}$/', (string) ($state['ctx_key'] ?? '')) !== 1) {
            $ctxKeyHex = bin2hex(CtxToken::deriveKey($webhookSecret));
            self::casMutateState($conn, $userId, static function (array $fresh) use ($ctxKeyHex): array {
                if (preg_match('/^[0-9a-f]{64}$/', (string) ($fresh['ctx_key'] ?? '')) !== 1) {
                    $fresh['ctx_key'] = $ctxKeyHex;
                }

                return $fresh;
            });
        }

        $snapshot = self::buildSnapshot($conn, $userId);

        $client->pushDimensions($installHash, $snapshot, $webhookSecret);

        return [
            'networks' => count($snapshot['networks']),
            'accounts' => count($snapshot['accounts']),
            'campaigns' => count($snapshot['campaigns']),
            'landing_pages' => count($snapshot['landing_pages']),
        ];
    }

    /**
     * Build the §4.1 dictionary snapshot: non-deleted rows only,
     * most-recently-updated first within each cap (64 networks /
     * 256 accounts / 256 campaigns / 128 landing pages), names truncated
     * to 80 chars. Keywords are never synced.
     *
     * @return array{
     *     networks: list<array{id: int, name: string}>,
     *     accounts: list<array{id: int, name: string, network_id: int}>,
     *     campaigns: list<array{id: int, name: string, aff_network_id: int}>,
     *     landing_pages: list<array{id: int, name: string}>
     * }
     */
    public static function buildSnapshot(Connection $conn, int $userId): array
    {
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
                'name' => self::truncateName((string) $net['ppc_network_name']),
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
                'name' => self::truncateName((string) $acc['ppc_account_name']),
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
                'name' => self::truncateName((string) $cmp['aff_campaign_name']),
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
                'name' => self::truncateName((string) $lp['landing_page_nickname']),
            ];
        }

        return [
            'networks' => $networks,
            'accounts' => $accounts,
            'campaigns' => $campaigns,
            'landing_pages' => $landingPages,
        ];
    }

    /** §4.1: names truncated to 80 chars (character-based, UTF-8). */
    private static function truncateName(string $name): string
    {
        $name = trim($name);

        return function_exists('mb_substr') ? mb_substr($name, 0, 80, 'UTF-8') : substr($name, 0, 80);
    }
}
