<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Prosper202\License\ClickServerKeyValidator;
use Prosper202\License\ShellAccessCache;

class CapabilitiesController
{
    public function __construct(
        private readonly \mysqli $db,
        private readonly ?int $userId = null,
    ) {
    }

    public function versions(): array
    {
        return [
            'data' => [
                'preferred' => 'v3',
                'supported' => ['v3'],
            ],
        ];
    }

    public function capabilities(): array
    {
        return [
            'data' => [
                'api_version' => 'v3',
                'entity_support' => $this->entitySupport(),
                'sync_features' => [
                    'diff' => true,
                    'sync_plan' => true,
                    'async_jobs' => true,
                    'incremental' => true,
                    'prune' => true,
                    'force_update' => true,
                    'server_fk_remap' => true,
                ],
                'features' => [
                    // Scoped API keys: creation accepts a scope; every route
                    // enforces <area>:read/<area>:write (see Auth::hasScope).
                    'api_key_scopes' => $this->apiKeyScopesEnabled(),
                    // Idempotency-Key honored on single POST creates across
                    // the operator surface (CRUD entities, conversions,
                    // rotators + rules, attribution models + exports, users).
                    // LTV write endpoints keep their own upsert/dedup
                    // semantics; API-key creation is excluded (secret
                    // responses are never stored for replay).
                    'create_idempotency' => true,
                    // ?dry_run=1 on DELETE previews the delete (record +
                    // cascade counts) without performing it, across the
                    // operator surface: CRUD entities, conversions, rotators
                    // + rules, attribution models, users, API keys, roles.
                    // Unsupported endpoints reject rather than fall through.
                    'delete_dry_run' => true,
                    // Visitor-authored strings (keyword/city/region/ISP/
                    // browser/platform/device names) are stripped of
                    // control and bidirectional characters and length-capped
                    // at serialization (ResponseSanitizer).
                    'response_sanitization' => true,
                    // ?staged=1 on an operator-surface write records it as a
                    // proposal with a server-issued change id instead of
                    // executing; /staged-changes lists, applies, and
                    // discards. Applying re-runs the write in full against
                    // current state and the applier's credentials. The
                    // `stage` scope action mints propose-only keys.
                    'staged_writes' => true,
                    // App measurement. `app_platforms` is what the registry
                    // (/apps) accepts a registration for. `app_postbacks` is
                    // one entry per platform-signed postback protocol the
                    // receiver verifies and stores: Apple's SKAdNetwork at
                    // /.well-known/skadnetwork/report-attribution/ and
                    // AdAttributionKit at
                    // /.well-known/appattribution/report-attribution/, served
                    // (with signature state and SKAN decoding) under
                    // /apps/postbacks and /apps/report. Each registration's
                    // document is served to its build at runtime via
                    // GET /apps/schema, selected by the X-P202-App-Token
                    // header, so changes need no store resubmission.
                    'app_platforms' => \Api\V3\Apps\AppIdentity::PLATFORMS,
                    'app_postbacks' => \Api\V3\Apps\Apple\Protocols::NAMES,
                    // The Android intake: the SDK's POST /apps/installs and
                    // /apps/installs/{install_uuid}/events (pre-auth, by app
                    // token), the states an install is classified into, and
                    // the operator's reads under /apps/{id}/installs and
                    // /apps/{id}/install-token. Store links carry the click
                    // as [[p202_install_token]].
                    'app_installs' => [
                        'stores' => \Api\V3\Apps\Android\InstallPayload::STORES,
                        'match_states' => \Api\V3\Apps\Android\MatchState::values(),
                        'max_events_per_request' => \Api\V3\Apps\Android\InstallEventsIntake::MAX_EVENTS,
                    ],
                    // Goals: versioned, data-only definitions owned by a
                    // campaign, an app registration or the account, and
                    // evaluated per subject by one specification whose
                    // vectors every evaluator shares
                    // (tests/fixtures/app-sdk-contract/goals/,
                    // format_version below). /goals/evaluate runs it
                    // without writing anything.
                    'goals' => [
                        'scopes' => array_map(static fn (\Prosper202\Goals\GoalScope $s): string => $s->value, \Prosper202\Goals\GoalScope::cases()),
                        'subjects' => [\Prosper202\Goals\GoalSubject::CLICK, \Prosper202\Goals\GoalSubject::INSTALL],
                        'evaluator_format' => 1,
                    ],
                ],
                'limits' => [
                    'max_bulk_rows' => $this->maxBulkRows(),
                    'max_job_concurrency' => 5,
                    'max_job_events_page' => 500,
                    'rate_limits' => [
                        'sync_per_minute' => 30,
                        'bulk_upsert_per_minute' => 60,
                    ],
                ],
                'shell' => $this->shellAccess(),
                'server' => [
                    'build' => $this->resolveBuildVersion(),
                    'commit' => defined('P202_GIT_COMMIT') ? (string)P202_GIT_COMMIT : 'unknown',
                    'environment' => defined('P202_ENV') ? (string)P202_ENV : 'unknown',
                    'timezone_support' => $this->timezoneSupport(),
                ],
            ],
        ];
    }

    /**
     * Scope enforcement always runs; what "enabled" means for clients is
     * whether scoped keys can actually be minted, which needs the scope
     * column on 202_api_keys (fresh installs have it; 1.9.75 backfills).
     */
    private function apiKeyScopesEnabled(): bool
    {
        return \Api\V3\Auth::apiKeyScopeColumnExists($this->db);
    }

    private function entitySupport(): array
    {
        $base = [
            'list' => true,
            'get' => true,
            'create' => true,
            'update' => true,
            'delete' => true,
            'bulk_upsert' => true,
        ];

        return [
            'aff-networks' => $base,
            'ppc-networks' => $base,
            'ppc-accounts' => $base,
            'campaigns' => $base,
            'landing-pages' => $base,
            'text-ads' => $base,
            'forecast-events' => $base,
            'trackers' => $base,
            'apps' => ['bulk_upsert' => false] + $base,
            'app-skan-encodings' => ['bulk_upsert' => false] + $base,
            'app-postbacks' => ['list' => true, 'get' => true, 'create' => false, 'update' => false, 'delete' => false, 'bulk_upsert' => false],
            // Written only by the SDK through the public intake.
            'app-installs' => ['list' => true, 'get' => true, 'create' => false, 'update' => false, 'delete' => false, 'bulk_upsert' => false],
            // DELETE archives: the goal keeps its versions and outcomes.
            'goals' => ['bulk_upsert' => false] + $base,
        ];
    }

    private function resolveBuildVersion(): string
    {
        $result = $this->db->query('SELECT version FROM 202_version LIMIT 1');
        if ($result === false) {
            return 'unknown';
        }
        $row = $result->fetch_assoc();
        return (string)($row['version'] ?? 'unknown');
    }

    private function timezoneSupport(): string
    {
        $stmt = $this->db->prepare("SELECT CONVERT_TZ('2000-01-01 00:00:00', '+00:00', 'UTC') AS tz");
        if (!$stmt) {
            return 'unknown';
        }

        // @phpstan-ignore-next-line capability probe; execute is return-checked with graceful 'unknown' fallback, no Connection in scope
        if (!$stmt->execute()) {
            $stmt->close();
            return 'unknown';
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            return 'unknown';
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        return ($row['tz'] ?? null) === null ? 'fallback-only' : 'named-timezone';
    }

    private function maxBulkRows(): int
    {
        $raw = getenv('P202_MAX_BULK_ROWS');
        if (is_string($raw) && trim($raw) !== '') {
            $parsed = (int)$raw;
            if ($parsed > 0) {
                return min(5000, $parsed);
            }
        }
        return 500;
    }

    /**
     * Determine shell access by validating the user's ClickServer API key
     * against my.tracking202.com. Returns false if the user has no key or
     * the key is invalid.
     *
     * Results are cached per-key (ShellAccessCache::TTL_SECONDS) to avoid
     * hitting my.tracking202.com on every capabilities request. If ClickServer
     * is unreachable the last known result is used regardless of age, and
     * access is denied when no prior result exists (fail-closed).
     */
    private function shellAccess(): bool
    {
        if ($this->userId === null) {
            return false;
        }

        $customerKey = $this->loadClickServerKey();
        if ($customerKey === '') {
            return false;
        }

        $cached = ShellAccessCache::read($customerKey);
        if ($cached !== null) {
            return $cached;
        }

        // Short timeouts: this runs on the synchronous request path, so a
        // slow ClickServer must not stall /capabilities for long.
        $result = ClickServerKeyValidator::validate($customerKey, 2, 4);
        if ($result === null) {
            return ShellAccessCache::readStale($customerKey) === true;
        }
        ShellAccessCache::write($customerKey, $result);
        return $result;
    }

    private function loadClickServerKey(): string
    {
        $stmt = $this->db->prepare(
            'SELECT p202_customer_api_key FROM 202_users WHERE user_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return '';
        }
        // bind_param() binds by reference; a readonly property can't be passed
        // by reference (PHP 8: "Cannot indirectly modify readonly property"),
        // so copy it to a local first.
        $userId = $this->userId;
        $stmt->bind_param('i', $userId);
        if (!mysqli_stmt_execute($stmt)) {
            $stmt->close();
            return '';
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            return '';
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        return trim((string)($row['p202_customer_api_key'] ?? ''));
    }

}
