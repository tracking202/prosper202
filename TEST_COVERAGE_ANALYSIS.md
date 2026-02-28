# Test Coverage Analysis — Prosper202

**Date:** 2026-02-28
**Scope:** Full codebase analysis of ~394 PHP source files against ~28 test files

---

## Executive Summary

The project has **28 PHP test files** covering portions of a **394-file PHP codebase**. Test coverage is concentrated in a few well-tested modules (CLI infrastructure, Auth, Redirect, Attribution exports) while large portions of the codebase — particularly API V3 controllers, attribution calculation strategies, database infrastructure, and legacy UI code — have zero test coverage.

| Area | Source Files | Test Files | Estimated Method Coverage |
|------|-------------|------------|--------------------------|
| API V3 Core (Router, Auth, Controller, Exceptions) | 12 | 4 | ~70% |
| API V3 Controllers (16 controllers) | 16 | 0 dedicated | ~5% (only Reports/System partial) |
| API V3 Support (ServerStateStore, SyncEngine, RemoteApiClient) | 3 | 0 | 0% |
| Attribution Services & Calculation | 12 | 3 | ~25% |
| Attribution Repositories (MySQL) | 8 | 2 | ~20% |
| Attribution Analytics & Export | 6 | 2 | ~30% |
| CLI Infrastructure (ApiClient, Config, Formatter) | 5 | 5 | ~95% |
| CLI Commands (48 command classes) | 48 | 3 | ~12% |
| Auth & User | 4 | 3 | ~60% |
| Config Utilities | 2 | 2 | ~75% |
| Fraud Detection | 2 | 1 | ~80% |
| Redirect (dl.php) | 2 | 4 | ~90% |
| Database Schema/Install | 12 | 0 | 0% |
| Validation | 3 | 0 | 0% |
| Legacy classes (DataEngine, FilterEngine, Cache, Dashboard, Slack) | 8 | 0 | 0% |
| Web UI (tracking202/ ajax, setup, analyze, overview) | ~139 | 0 | 0% |
| Cronjobs | 11 | 0 | 0% |

---

## Priority 1 — Critical Gaps (High Impact, High Risk)

### 1.1 Attribution Calculation Strategies — ZERO tests

**Files:**
- `202-config/Attribution/Calculation/LastTouchStrategy.php`
- `202-config/Attribution/Calculation/TimeDecayStrategy.php`
- `202-config/Attribution/Calculation/PositionBasedStrategy.php`
- `202-config/Attribution/Calculation/AssistedStrategy.php`

**Why this matters:** These are the core algorithms that determine how revenue credit is distributed across touchpoints. Incorrect attribution directly corrupts financial reporting. Each strategy implements `calculate(ModelDefinition $model, ConversionBatch $batch): CalculationResult` with non-trivial math (exponential decay, position weighting, normalization).

**What to test:**
- Single-touchpoint journeys (all credit to one touch)
- Multi-touchpoint journeys (credit distribution correctness)
- Edge cases: empty journeys, zero revenue, negative costs
- Weight normalization sums to 1.0
- Revenue and cost apportionment per touchpoint
- Time decay: varying half-life values, very old clicks, simultaneous clicks
- Position-based: first/last/middle weight splits with 2, 3, N touches
- Rounding correction (last position absorbs remainder)

**Estimated effort:** Medium. All four strategies are pure functions with no DB dependency — they take value objects and return value objects. These are the easiest high-value tests to write.

---

### 1.2 API V3 Controllers — 13 of 16 completely untested

**Untested controllers:**
- `AffNetworksController` — Affiliate network CRUD
- `CampaignsController` — Campaign CRUD
- `ClicksController` — Click queries (read-only)
- `ConversionsController` — Conversion CRUD
- `LandingPagesController` — Landing page CRUD
- `PpcAccountsController` — PPC account CRUD
- `PpcNetworksController` — PPC network CRUD
- `RotatorsController` — Rotator CRUD with rules sub-resource
- `SyncController` — Server-side sync jobs
- `TextAdsController` — Text ad CRUD
- `TrackersController` — Tracker CRUD
- `UsersController` — User management, roles, API keys
- `CapabilitiesController` — API capabilities

**Partially tested:** `ReportsController` (daypart & timeseries only), `SystemController` (cronStatus, errors, dbStats, metrics only), `AttributionController` (metrics, anomalies, export scheduling only).

**Why this matters:** These controllers handle all data mutations through the API. Each extends `Controller.php` (which IS tested at the abstract level) but adds custom `beforeCreate`/`afterCreate` hooks, field definitions, custom validation, and endpoint-specific logic. Bugs in field definitions (`fieldDefinitions()` returning wrong types, missing required flags, wrong max_length) silently corrupt data.

**What to test:**
- `fieldDefinitions()` returns correct types, required flags, readonly flags for each entity
- `tableName()`, `primaryKey()`, `deletedColumn()`, `userIdColumn()` return correct values
- Lifecycle hooks (`beforeCreate`, `afterCreate`, `beforeUpdate`, `beforeDelete`) execute custom logic
- Entity-specific endpoints (e.g., `RotatorsController::createRule`, `UsersController::assignRole`)
- `CapabilitiesController` version discovery response shape

**Estimated effort:** Medium-High. Most CRUD controllers can be tested by verifying their field definitions and table config without a database, since the base Controller is already tested. Custom endpoints need mock DB.

---

### 1.3 API V3 Support Classes — ZERO tests

**Files:**
- `api/V3/Support/ServerStateStore.php` — Manages idempotency keys, change tracking, metrics, tracing, audit logs
- `api/V3/Support/SyncEngine.php` — Orchestrates data synchronization between servers
- `api/V3/Support/RemoteApiClient.php` — HTTP client for remote sync

**Why this matters:** `ServerStateStore` is used by every bulk upsert and every change-tracked mutation. If idempotency replay returns stale data or change tracking drops events, data sync between servers silently diverges. `SyncEngine` orchestrates complex multi-step sync jobs with state machines.

**What to test:**
- ServerStateStore: idempotency key storage/replay, change recording, metric increment/read, trace span creation
- SyncEngine: plan generation, job execution state machine, conflict resolution, error recovery
- RemoteApiClient: request building, authentication header injection, error handling

**Estimated effort:** High. These classes are stateful and depend on DB, but the risk they carry justifies the investment.

---

### 1.4 AttributionJobRunner — ZERO tests

**File:** `202-config/Attribution/AttributionJobRunner.php`

**Why this matters:** This is the batch processing engine that fetches conversions, runs calculation strategies, and persists snapshots/touchpoints. It orchestrates the entire attribution pipeline. A bug here means all attribution data is wrong.

**What to test:**
- Empty conversion set returns gracefully
- Single conversion with single touchpoint processes correctly
- Batch pagination (fetches in chunks of 5000)
- Multiple models process independently
- Snapshot creation/update for each hour bucket
- Touchpoint persistence with correct credit values
- Audit recording
- Error handling when snapshot creation fails mid-batch

**Estimated effort:** Medium. Can be tested with repository fakes (the project already has `tests/Attribution/Support/RepositoryFakes.php`).

---

## Priority 2 — High-Value Gaps (Moderate Risk)

### 2.1 Attribution Repositories — Mostly untested

**Tested:** `MysqlConversionRepository` (basic), `MysqlExportRepository` (moderate)
**Untested:**
- `MysqlModelRepository` — Model CRUD, slug uniqueness, default promotion, cascading deletes
- `MysqlSettingRepository` — Multi-touch settings per scope, scope precedence queries
- `MysqlSnapshotRepository` — Time-range queries, snapshot upserts, purge operations
- `MysqlTouchpointRepository` — Touchpoint persistence
- `MysqlAuditRepository` — Audit log writes
- `ConversionJourneyRepository` — Journey data loading

**What to test:** Focus on query correctness — verify the right columns are bound in the right order (per CLAUDE.md error pattern #7: `bind_param` type string mismatches), execute() return values are checked (error pattern #1), and scope filtering works correctly.

**Estimated effort:** Medium. Requires mock `mysqli`/`mysqli_stmt` objects (the base `TestCase.php` already provides helpers for this).

---

### 2.2 Controller Base Class — Untested paths

The abstract `Controller.php` has good test coverage for basic CRUD, but these paths are untested:

- **list() filtering:** `filter` array parameter, `updated_since`/`deleted_since`, cursor pagination
- **User ID scoping:** The `userIdColumn()` mechanism that restricts records to the authenticated user
- **bulkUpsert() success paths:** Actual create/update within bulk operations (only validation/idempotency tested)
- **Change recording:** `recordChange()` for sync engine integration
- **ETag/versioning flow:** End-to-end optimistic concurrency

**What to test:** Create a concrete test controller subclass that exercises each abstract hook and verify the SQL generated for filter/scope combinations.

---

### 2.3 WebhookDispatcher — ZERO tests, security-critical

**File:** `202-config/Attribution/Export/WebhookDispatcher.php`

**Why this matters:** This class makes outbound HTTP requests to user-configured URLs. It contains SSRF protection (private IP range detection, DNS resolution validation). Untested SSRF protection is effectively no protection.

**What to test:**
- Private IPv4 ranges blocked (10.x, 172.16-31.x, 192.168.x, 127.x)
- Private IPv6 ranges blocked (::1, fc00::/7, fe80::/10)
- DNS rebinding protection (resolve-then-connect)
- File size limits enforced
- Base64 encoding for file payloads
- Timeout enforcement
- Error handling for network failures

**Estimated effort:** Medium. Mock cURL or stream context.

---

### 2.4 Database Schema/Installation — ZERO tests

**Files:**
- `202-config/Database/SchemaInstaller.php`
- `202-config/Database/PartitionInstaller.php`
- `202-config/Database/DataSeeder.php`
- `202-config/Database/Schema/SchemaBuilder.php`
- `202-config/Database/Schema/TableRegistry.php`
- 10 table definition files in `202-config/Database/Tables/`

**Why this matters:** Schema installation runs once per deployment but failures are catastrophic. Table definitions drive every SQL query in the system.

**What to test:**
- `SchemaBuilder` produces valid SQL for each column type
- `TableRegistry` returns all expected table names
- Each table definition class returns consistent column definitions
- `SchemaInstaller` handles pre-existing tables gracefully
- Partition strategies produce valid MySQL PARTITION BY clauses

**Estimated effort:** Low-Medium. The builder/registry classes are pure logic.

---

### 2.5 Validation Module — ZERO tests

**Files:**
- `202-config/Validation/SetupFormValidator.php`
- `202-config/Validation/ValidationResult.php`
- `202-config/Validation/ValidationException.php`

**What to test:** Input validation rules, error collection, exception structure.

**Estimated effort:** Low. Pure logic, no dependencies.

---

## Priority 3 — Moderate Gaps

### 3.1 CLI Commands — 41 of 48 untested

The CLI infrastructure (ApiClient, Config, Formatter) has excellent coverage. But individual commands are untested. Most are CRUD-generated via `CrudCommands::generate()`, which IS tested for command structure — but not for execution behavior.

**Highest-value command tests:**
- `UserCreateCommand` — Interactive password prompting, API call flow
- `ConfigTestCommand` — Connection verification logic
- `SystemHealthCommand` — Health check output parsing
- `AttributionExportScheduleCommand` — Export scheduling parameters
- Any command with custom `handle()` logic beyond CRUD

**Estimated effort:** Low per command. Most are thin wrappers around ApiClient.

---

### 3.2 Role & User Permission System

- `Role.class.php` — ZERO tests (depends on DB singleton)
- `User.class.php` — 4 of 7 tests are SKIPPED (DB dependency)

**Recommendation:** Refactor `Role` and `User` to accept a `mysqli` instance instead of calling `DB::getInstance()`, then test permission checking and role loading.

---

### 3.3 Auth — Untested edge cases

The `Auth` class is well-tested but missing:
- `scopes()` method — never called in tests
- `hasScope()` — never called (only `requireScope()` tested)
- Scope parsing (JSON arrays, comma-separated, wildcard)
- `apiKeyScopeColumnExists()` backward compatibility path

---

### 3.4 Bootstrap / RequestContext — ZERO tests

- `Bootstrap.php` — `init()`, `db()`, `jsonResponse()`, `errorResponse()` untested
- `RequestContext.php` — Header normalization, actor user ID, version tracking untested

These are used everywhere but have no direct tests.

---

## Priority 4 — Long-term / Low Risk

### 4.1 Legacy Classes — ZERO tests

| File | Purpose |
|------|---------|
| `DashboardAPI.class.php` | Dashboard data API |
| `DashboardDataManager.class.php` | Data aggregation |
| `class-dataengine.php` | Full data engine |
| `class-dataengine-slim.php` | Lightweight data engine |
| `class-filterengine.php` | Filter logic |
| `class-cache.php` | Caching layer |
| `class-indexes.php` | Index management |
| `Slack.class.php` | Slack notifications |

These are older parts of the codebase. Testing them is valuable but lower priority unless they're actively changing.

### 4.2 Web UI Layer — ZERO tests

The `tracking202/` directory contains ~139 PHP files (AJAX endpoints, setup pages, analysis views, overview dashboards) with no server-side tests. There is 1 Playwright E2E test for the attribution dashboard.

### 4.3 Cronjobs — ZERO tests

11 cronjob files in `202-cronjobs/` have no tests. Key ones:
- `attribution-export.php`
- `attribution-rebuild.php`
- `sync-worker.php`
- `daily-email.php`

---

## Recommended Test Implementation Order

| Order | What | Why | Estimated Tests |
|-------|------|-----|----------------|
| 1 | Attribution calculation strategies (4 files) | Core financial logic, pure functions, easy to test | ~40-60 tests |
| 2 | API V3 controller field definitions & config | Catches schema mismatches, moderate effort | ~30-50 tests |
| 3 | AttributionJobRunner with repository fakes | Exercises entire attribution pipeline | ~15-25 tests |
| 4 | WebhookDispatcher SSRF validation | Security-critical, focused scope | ~15-20 tests |
| 5 | Missing repository CRUD operations | Catches SQL binding errors | ~30-40 tests |
| 6 | ServerStateStore idempotency & change tracking | Data integrity for sync | ~20-30 tests |
| 7 | Validation module | Quick wins, pure logic | ~10-15 tests |
| 8 | Schema builder & table registry | Installation safety net | ~15-20 tests |
| 9 | Controller filtering & pagination | Completeness for base Controller | ~15-20 tests |
| 10 | CLI command execution tests | Thin wrappers, low risk | ~20-30 tests |

**Total recommended new tests: ~210-310**

---

## Structural Observations

### What's working well
- **Redirect module (dl.php):** 4 test files, 60+ tests, unit through integration. Exemplary.
- **CLI infrastructure:** ApiClient, Config, Formatter have near-complete coverage.
- **Auth password handling:** Thorough including legacy MD5 migration.
- **Router:** 30+ tests covering patterns, groups, middleware registration.
- **Base TestCase:** Well-designed mock helpers (createMysqliMock, buildStmtMock, session management).

### What needs improvement
- **Repository fakes exist but are underused:** `tests/Attribution/Support/RepositoryFakes.php` provides in-memory implementations but only `AttributionSettingsServiceTest` and `AttributionServiceExportTest` use them. The job runner, integration service, and other services could all be tested this way.
- **Global state dependencies block testing:** `User.class.php`, `Role.class.php`, `functions-db.php` all depend on global `$db`/`$memcache` singletons, making them hard to test. Refactoring to accept dependencies as parameters would unlock testability.
- **CI runs with relaxed strictness:** `phpunit.ci.xml` disables strict mode, risky test detection, and deprecation-to-exception conversion. This means tests that pass in CI might be masking real issues.
- **No code coverage reporting in CI:** Tests run with `--no-coverage`, so there's no automated tracking of coverage trends.
