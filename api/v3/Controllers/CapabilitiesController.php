<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Prosper202\License\ClickServerKeyValidator;
use Prosper202\License\CliAccessCache;
use Prosper202\License\ShellAccessCache;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;

class CapabilitiesController
{
    /**
     * Licence answers already fetched in this request, per key, so
     * /capabilities asks my.tracking202.com at most once per key on a cold
     * cache (shell and cli share it). Holds null for "unknown" too.
     *
     * @var array<string, array{valid: bool, paid: ?bool}|null>
     */
    private array $licenceChecks = [];

    /**
     * Whether a request comes from the Go CLI, the only client the Pro gate
     * applies to. The legacy PHP CLI (cli/ApiClient.php, "p202-cli/1.0") is
     * deliberately not gated; the Go CLI sends "p202-cli/<version> (Go)".
     *
     * The User-Agent is the client's own claim, so this is a licensing
     * prompt for the product, not an access control: the same API key keeps
     * answering curl, the PHP CLI and the web app whatever the licence says,
     * and a Go CLI build that sent another User-Agent would too. Nothing
     * that must stay closed may rely on it (CLAUDE.md #16).
     */
    public static function isGoCliUserAgent(string $userAgent): bool
    {
        return str_starts_with($userAgent, 'p202-cli/') && str_contains($userAgent, '(Go)');
    }

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
                    // rotators + rules, attribution models, users).
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
                    // Play Integrity, opt-in per Android registration
                    // (integrity_mode), decoded by 202-cronjobs/app-installs.php
                    // with the credential set at /apps/{id}/integrity-credential.
                    'play_integrity' => [
                        'modes' => \Api\V3\Apps\Android\Integrity\IntegrityMode::values(),
                        'states' => \Api\V3\Apps\Android\Integrity\IntegrityState::values(),
                        'token_type' => 'standard',
                        'request_hash' => 'sha256_hex_of_canonical_install_body',
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
                    // Web events (plan §2.2): POST /events keyed by
                    // click_id, `event=` on pixels and postbacks, and
                    // p202.track() on landing pages.
                    'events' => [
                        'max_per_request' => \Api\V3\Controllers\EventsController::MAX_EVENTS,
                        'max_per_subject' => \Prosper202\Goals\GoalEngine::MAX_EVENTS_PER_SUBJECT,
                        'max_properties' => \Prosper202\Goals\GoalEvent::MAX_PROPERTIES,
                        'goal_tokens' => ['[[p202_goal]]', '[[p202_goal_id]]', '[[p202_goal_value]]'],
                    ],
                    // The conversion ledger's reads: GET
                    // /clicks/{id}/conversions explains a click's value row
                    // by row, and GET /conversions filters by click, source
                    // and goal and returns every row's provenance. `sources`
                    // is what the source filter accepts; `not_counted` the
                    // reasons a row can be left out of its click's value.
                    // The dimensions GET /reports/breakdown takes as
                    // `breakdown`, read from ReportsController itself.
                    'report_breakdowns' => ReportsController::breakdownDimensions(),
                    'conversion_ledger' => [
                        'sources' => array_map(static fn (\Prosper202\Conversion\Ledger\ConversionSource $s): string => $s->value, \Prosper202\Conversion\Ledger\ConversionSource::cases()),
                        'not_counted' => array_map(static fn (\Prosper202\Conversion\Ledger\NotCountedReason $r): string => $r->value, \Prosper202\Conversion\Ledger\NotCountedReason::cases()),
                        'click_breakdown' => true,
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
                'cli' => $this->cliAccess(),
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
        // Advertise exactly what the bulk endpoint enforces.
        return \Api\V3\Controller::configuredMaxBulkRows();
    }

    /**
     * Determine shell access by validating the user's ClickServer API key
     * against my.tracking202.com. Returns false if the user has no key or
     * the key is invalid.
     *
     * Results are cached per-key (ShellAccessCache: an allow for TTL_SECONDS,
     * a refusal for DENIAL_TTL_SECONDS) to avoid hitting my.tracking202.com
     * on every capabilities request. If ClickServer
     * is unreachable the last known result is used, up to
     * STALE_TTL_SECONDS old, and access is denied when no prior result
     * exists (fail-closed); the
     * unanswered check is not repeated for UNKNOWN_TTL_SECONDS.
     */
    private function shellAccess(): bool
    {
        if ($this->userId === null) {
            return false;
        }

        $customerKey = $this->loadClickServerKey();
        if ($customerKey === null || $customerKey === '') {
            return false; // no key, or a lookup that failed: the shell is fail-closed
        }

        // A refusal is believed for DENIAL_TTL_SECONDS, an allow for
        // TTL_SECONDS, as for the CLI: nothing else refreshes the entry when
        // the key becomes valid, and the two verdicts on /capabilities should
        // not disagree about it for an hour.
        $cached = ShellAccessCache::readVerdict($customerKey);
        if ($cached !== null) {
            return $cached;
        }
        // An unanswered check holds off the next for UNKNOWN_TTL_SECONDS, as
        // for the CLI: /capabilities is what the Go CLI opens with, and
        // during an outage every call would otherwise pay the timeouts.
        if (ShellAccessCache::recentlyUnknown($customerKey)) {
            return ShellAccessCache::readStale($customerKey) === true;
        }

        $result = $this->checkKey($customerKey);
        if ($result === null) {
            ShellAccessCache::markUnknown($customerKey);
            return ShellAccessCache::readStale($customerKey) === true;
        }
        return $result['valid'];
    }

    /**
     * Whether the Go CLI may run against this install: the ClickServer key's
     * account has an active Prosper202 ClickServer subscription (trials count).
     *
     * Cached per key (CliAccessCache). Only an authoritative answer is
     * cached, a refusal included: "valid, not paid" and "no such key" are the
     * service's own words. An allow holds for TTL_SECONDS (an hour, which a
     * lapsed subscription outlives), a refusal for DENIAL_TTL_SECONDS (five
     * minutes, which is how long a customer who starts the trial waits).
     * Fails OPEN whenever the answer is unknown (my.tracking202.com
     * unreachable, an error page, an answer without a boolean 'paid'),
     * whatever an earlier answer was: an outage never refuses anyone, and the
     * unanswered check holds off the next for UNKNOWN_TTL_SECONDS so a burst
     * of CLI requests during one pays the connect timeout once. Falls back
     * to the install owner's key when the API user has none, so sub-users of
     * a paid install are covered. Known residual: a cache
     * directory another local user pre-created is refused (ShellAccessCache::dir,
     * which logs it), and without it nothing is cached, hold-off included.
     */
    public function cliAccess(): bool
    {
        // A broken lookup must never become a denial: any error here is
        // "unknown", and unknown lets the CLI through.
        try {
            return $this->cliAccessVerdict();
        } catch (\Throwable $e) {
            error_log('p202: CLI licence check failed, allowing: ' . $e->getMessage());
            return true;
        }
    }

    private function cliAccessVerdict(): bool
    {
        if ($this->userId === null) {
            // Unreachable from the API (Auth::userId() is an int, and the
            // user-less controller only serves /versions); kept open like
            // every other unknown here rather than as the one silent denial.
            return true;
        }

        $customerKey = $this->loadClickServerKey();
        if ($customerKey === null) {
            // Whether this user has a key of their own is unknown; judging
            // them by the owner's would be a guess in either direction.
            return true;
        }
        if ($customerKey === '') {
            $customerKey = $this->loadOwnerClickServerKey();
            if ($customerKey === null) {
                return true; // the owner lookup failed: unknown, open
            }
        }
        if ($customerKey === '') {
            return false; // no key on the install is a real "not licensed"
        }

        $cached = CliAccessCache::readVerdict($customerKey);
        if ($cached !== null) {
            return $cached;
        }
        if (CliAccessCache::recentlyUnknown($customerKey)) {
            return true;
        }

        $result = $this->checkKey($customerKey);
        if ($result === null || $result['paid'] === null) {
            CliAccessCache::markUnknown($customerKey);
            return true;
        }
        return $result['paid'];
    }

    /**
     * The account owner's ClickServer key for the requesting user's install.
     * The owner is the lowest user_id on the same install_hash that is active,
     * not deleted and holds a key, which is exactly how the web app resolves
     * the account owner (AUTH::determineAccountOwnerId uses the same WHERE
     * and ORDER BY), so the CLI and the dashboard agree on whose key counts.
     *
     * @return string|null the owner's key; '' when nobody on the install holds
     *         one (not licensed); null when the lookup failed or the user has
     *         no install_hash to find an owner by (unknown, never "no key")
     */
    private function loadOwnerClickServerKey(): ?string
    {
        // Connection::bind (CLAUDE.md #7), not a direct $stmt->bind_param().
        // One string literal, single-quoted SQL strings: StaticSqlSchemaTest
        // prepares it against the installed schema, and '' is a string under
        // every sql_mode where "" is an identifier under ANSI_QUOTES.
        $conn = new Connection($this->db);
        try {
            $stmt = $conn->prepareRead(
                "SELECT u.install_hash,
                        (SELECT o.p202_customer_api_key FROM 202_users o
                          WHERE o.install_hash = u.install_hash
                          AND o.user_deleted != 1 AND o.user_active = 1
                          AND o.p202_customer_api_key IS NOT NULL AND o.p202_customer_api_key != ''
                          ORDER BY o.user_id ASC LIMIT 1) AS owner_key
                 FROM 202_users u WHERE u.user_id = ? LIMIT 1"
            );
        } catch (QueryException) {
            return null;
        }
        try {
            $conn->bind($stmt, 'i', [$this->userId]);
        } catch (QueryException) {
            $stmt->close();
            return null;
        }
        if (!mysqli_stmt_execute($stmt)) {
            $stmt->close();
            return null;
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            return null;
        }
        $row = $result->fetch_assoc();
        $stmt->close();
        if (!is_array($row) || trim((string)($row['install_hash'] ?? '')) === '') {
            return null; // no such user, or no install to find an owner on: unknown
        }
        return trim((string)($row['owner_key'] ?? ''));
    }

    /**
     * One licence call per key per request, filling both caches from it.
     * Short timeouts: this runs on the synchronous request path, so a slow
     * my.tracking202.com must not stall /capabilities for long.
     *
     * @return array{valid: bool, paid: ?bool}|null null = unknown (outage)
     */
    private function checkKey(string $customerKey): ?array
    {
        if (array_key_exists($customerKey, $this->licenceChecks)) {
            return $this->licenceChecks[$customerKey];
        }
        $result = ClickServerKeyValidator::check($customerKey, 2, 4);
        if ($result !== null) {
            ShellAccessCache::write($customerKey, $result['valid']);
            if ($result['paid'] !== null) {
                CliAccessCache::write($customerKey, $result['paid']);
            }
        }
        return $this->licenceChecks[$customerKey] = $result;
    }

    /**
     * The requesting user's own ClickServer key.
     *
     * @return string|null the key, '' when they have none, null when the
     *         lookup failed (unknown: never read as "no key"). A value, not a
     *         flag on the controller: /capabilities runs the shell check and
     *         the CLI check on one instance, and a flag set by the first
     *         lookup would have coloured the second.
     */
    private function loadClickServerKey(): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT p202_customer_api_key FROM 202_users WHERE user_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        // Connection::bind checks the bind (CLAUDE.md #7): unchecked, a failed
        // bind would run the query with no user and read as "this user has no
        // key", sending them to the owner's licence in silence.
        try {
            (new Connection($this->db))->bind($stmt, 'i', [$this->userId]);
        } catch (QueryException) {
            $stmt->close();
            return null;
        }
        if (!mysqli_stmt_execute($stmt)) {
            $stmt->close();
            return null;
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            return null;
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        return trim((string)($row['p202_customer_api_key'] ?? ''));
    }

}
