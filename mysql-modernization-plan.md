# SQL Modernization: Adopt Gen 3 Repository Pattern

**Status:** Proposed
**Author:** Engineering
**Date:** 2026-03-01
**Scope:** All database access in Prosper202

---

## Table of Contents

1. [Problem Statement](#1-problem-statement)
2. [Architecture Decision Record](#2-architecture-decision-record)
3. [Current State Assessment](#3-current-state-assessment)
4. [Target Architecture](#4-target-architecture)
5. [Migration Strategy](#5-migration-strategy)
6. [Phased Implementation](#6-phased-implementation)
7. [Testing Strategy](#7-testing-strategy)
8. [Risk Register](#8-risk-register)
9. [Observability & Success Criteria](#9-observability--success-criteria)
10. [Performance Budget](#10-performance-budget)
11. [File Manifest](#11-file-manifest)
12. [Dependency Graph & Parallelism](#12-dependency-graph--parallelism)
13. [What Stays the Same](#13-what-stays-the-same)

---

## 1. Problem Statement

The codebase has three coexisting generations of database access. They produce different failure modes, different security postures, and different testability characteristics. Today:

- **637 `real_escape_string()` calls** build SQL via string concatenation — any one could be an injection vector if a type changes.
- **`_mysqli_query()`** suppresses errors with `@`, meaning failed queries in the click-recording hot path silently produce orphaned partial data across 8-11 tables.
- **Zero transaction usage** on the click-write path (`record_simple.php`, `record_adv.php`, `dl.php`) — a process crash mid-write leaves inconsistent state in production.
- **58 `global $db` usages** make the dependency graph invisible and unit testing impossible for those call sites.
- **`class-indexes.php`** (58 query call sites) duplicates the same memcache-or-DB conditional in every single method — ~800 lines of structural repetition.
- The Attribution module already solved all of these problems for its domain. The solution exists; it needs to be propagated.

The risk of doing nothing is that each new feature built on Gen 1 patterns inherits all these failure modes. Every bug fix in Gen 1 code requires manually verifying escape correctness instead of relying on prepared statements.

---

## 2. Architecture Decision Record

### ADR-001: Extend Gen 3 repository pattern to all database access

**Context:** We need a single, consistent database access pattern. Three options were evaluated.

### Option A: PDO migration

Replace `mysqli` with PDO across the entire codebase. Named parameters, unified exception mode, database-agnostic.

**Rejected because:**
- ~460 `$db->query()` calls + 146 `$stmt->bind_param()` calls need mechanical translation. Pure churn.
- `mysqli` is not the problem — the problem is string concatenation around `mysqli`. Prepared statements via `mysqli` are equivalent to PDO in security and correctness.
- PDO's named parameters are nice but insufficient justification for touching every DB call site.
- The Attribution module already works on `mysqli`. A PDO migration would require rewriting the one part of the codebase that's already correct.
- Risk/reward: high risk of introducing regressions in a full rewrite, zero functional gain.

### Option B: Introduce an ORM (Doctrine, Eloquent)

**Rejected because:**
- The V3 base `Controller` already provides a schema-driven CRUD abstraction (field definitions, soft deletes, cursor pagination, optimistic locking). 7 of 16 controllers use it with <30 lines each. An ORM would duplicate what already works.
- ORM entity mapping is overkill for the lookup tables (`class-indexes.php`) where the access pattern is exclusively `findOrCreate` returning an integer ID.
- The hot path (`dl.php`, `record_simple.php`) does 13-18 queries per request. ORM overhead (identity map, unit-of-work, hydration) on the redirect latency path is unacceptable.
- Adds a major dependency (Doctrine DBAL alone is ~30 packages) to a project that currently has 6 production dependencies.

### Option C: Extract and propagate the Gen 3 pattern (selected)

The Attribution module established: interface → MySQL implementation → null implementation → domain objects → factory. This works. It's already tested. It's already in production.

**Selected because:**
- Zero new dependencies.
- The pattern already exists and has test coverage (8 test files, 4 in-memory fakes, CI green).
- Incremental migration — each repository can be built and shipped independently.
- Preserves `mysqli` (no mechanical rewrite risk).
- Caching decorators naturally replace the interleaved memcache logic.
- Read/write connection separation is already built into the constructor convention.
- Null implementations enable unit testing of any code that depends on data access.

### Key design decisions within Option C

| Decision | Rationale |
|---|---|
| **Extract `Connection` class** | 9 existing repositories copy-paste `prepareRead`/`prepareWrite`/`prepare`/`bind`. This is the only new abstraction introduced. |
| **Strangler fig migration, not big-bang** | Each legacy call site is replaced one-at-a-time. Old and new code coexist. No feature flags needed — the repository returns the same data types as the raw queries. |
| **Caching as decorator, not inheritance** | `class-indexes.php` interleaves memcache and MySQL in every method. A decorator separates concerns and eliminates ~400 lines of structural duplication. |
| **Transaction wrapping for click writes** | `record_simple.php` writes 8-11 tables without a transaction. Adding `Connection::transaction()` makes this atomic with zero behavioral change on the happy path. |
| **Keep the V3 base Controller** | It already handles 7 simple CRUD entities correctly with prepared statements. Forcing those through repositories adds indirection for no safety gain. Only the 9 standalone controllers with custom SQL get repositories. |
| **No domain objects for simple CRUD** | `AffNetworksController`, `PpcAccountsController` etc. pass arrays through the base Controller schema DSL. Adding value objects for these would be over-engineering. Domain objects are reserved for complex aggregates: clicks (11-table write), rotators (cascading rules/criteria/redirects), conversions (journey tracking). |

---

## 3. Current State Assessment

### 3.1 — Three generations of database access

| | Gen 1 (Legacy) | Gen 2 (V3 Controllers) | Gen 3 (Attribution) |
|---|---|---|---|
| **Pattern** | `real_escape_string` + string concat | `prepare` + `bind_param` inline in controllers | Interface → Mysql impl → Null impl |
| **Error handling** | `@$db->query()` or `or record_mysql_error()` (calls `die()`) | `if (!$stmt->execute()) throw DatabaseException` | `RuntimeException` on prepare failure |
| **Testability** | None (requires live DB + global state) | PHPUnit mocks possible but fragile | In-memory fakes, fully isolated |
| **Injection safety** | Depends on every call site escaping correctly | Prepared statements | Prepared statements |
| **Read replica support** | No | No | `$writeConnection` / `$readConnection` |
| **Transaction support** | None in hot path | Manual `begin_transaction`/`commit`/`rollback` | Manual (will become `Connection::transaction()`) |
| **% of DB access** | ~55% (by call-site count) | ~25% | ~20% |

### 3.2 — Call-site counts (actual grep results)

| Pattern | Count | Files |
|---|---|---|
| `_mysqli_query()` calls | ~789 | 59 files |
| `real_escape_string()` calls | ~1,368 | 127 files |
| `$db->query()` direct calls | ~460 | 96 files |
| `global $db` usages | ~58 | 11 files |
| `memcache_mysql_fetch_assoc()` calls | ~224 | 38 files |
| `$stmt->bind_param()` (V3 style) | ~146 | 32 files |
| `begin_transaction()` calls | 19 | 13 files |

### 3.3 — Hot path query counts (per single request)

| File | Purpose | Queries/request | Transactions |
|---|---|---|---|
| `tracking202/redirect/dl.php` | Click redirect | 13 queries | 0 |
| `tracking202/static/record_simple.php` | Pixel click recording | 18 queries | 0 |
| `tracking202/static/record_adv.php` | Advanced click recording | ~22 queries | 0 |
| `tracking202/redirect/pci.php` | Postback conversion | ~8 queries | 0 |

### 3.4 — Error handling on the hot path

`_mysqli_query()` in `functions.php` (the version loaded by tracking scripts):

```php
$result = @$db->query($sql);  // @ suppresses warnings
return $result;                // false on failure, no exception, no logging
```

`record_mysql_error()` in `functions-tracking202.php`:
```php
$clean['mysql_error_text'] = mysqli_error($db);
// ... logs to error file, then:
die();  // kills the process — no rollback of prior writes
```

**Consequence:** A failed INSERT on table 6 of 11 in `record_simple.php` either silently returns `false` (if using `_mysqli_query`) or kills the process (if using `$db->query() or record_mysql_error()`). Either way, tables 1-5 have orphaned data with no cleanup.

### 3.5 — Existing test infrastructure

| Asset | Status |
|---|---|
| PHPUnit 9.5 | Configured, CI green |
| `tests/TestCase.php` | Base class with `createMockDb()`, `createMysqliMock()`, superglobal management |
| `tests/Attribution/Support/RepositoryFakes.php` | 4 in-memory fakes (Model, Snapshot, Touchpoint, Export) |
| PHPStan 2.1 | Configured with baseline (~95KB of existing ignores) |
| GitHub Actions CI | `php-unit.yml` (tests), `php-lint.yml` (syntax), `pr-checks.yml` (PR gates) |
| `phpunit.ci.xml` | Lenient mode for CI (no coverage, allows warnings) |
| Integration test exclusion | `@group integration` excluded in CI, opt-in locally |

---

## 4. Target Architecture

### 4.1 — The Gen 3 blueprint

Every database-touching domain follows this structure:

```
Prosper202\Repository\{Domain}RepositoryInterface     ← contract
Prosper202\Repository\Mysql\Mysql{Domain}Repository    ← production implementation
Prosper202\Repository\Null{Domain}Repository           ← test double
Prosper202\Repository\Cached\Cached{Domain}Repository  ← optional: wraps Mysql impl (lookup tables only)
Prosper202\Domain\{Entity}                             ← value object (complex domains only)
```

### 4.2 — `Prosper202\Database\Connection`

The single new abstraction. Consolidates boilerplate from the 9 existing Attribution repositories.

```php
final readonly class Connection
{
    public function __construct(
        private mysqli $write,
        private ?mysqli $read = null
    ) {}

    public function prepareWrite(string $sql): mysqli_stmt;
    public function prepareRead(string $sql): mysqli_stmt;
    public function bind(mysqli_stmt $stmt, string $types, array $values): void;
    public function execute(mysqli_stmt $stmt): void;    // throws on failure (CLAUDE.md rule #1)
    public function transaction(callable $fn): mixed;    // auto-rollback on exception
    public function fetchOne(mysqli_stmt $stmt): ?array;  // fetch + close
    public function fetchAll(mysqli_stmt $stmt): array;   // fetch + close
    public function executeInsert(mysqli_stmt $stmt): int; // execute + insert_id + close
    public function executeUpdate(mysqli_stmt $stmt): int; // execute + affected_rows + close
    public function writeConnection(): mysqli;
    public function readConnection(): mysqli;
}
```

**Design constraints:**
- `execute()` MUST check the return value and throw `RuntimeException` on failure. This is the single most important behavior — it prevents the silent-failure mode of Gen 1.
- `fetchOne()`/`fetchAll()` MUST close the statement after fetching. This prevents resource leaks that currently require manual `$stmt->close()` (which is forgotten in ~30% of existing code paths based on review of V3 controllers).
- `transaction()` MUST call `rollback()` on any exception before re-throwing. This is what makes click writes safe.
- `bind()` MUST handle PHP references correctly (the existing Attribution repos use `&$values[$index]` — this is a known `bind_param` footgun that must be handled exactly once in `Connection` and never again).

### 4.3 — Repository interface design principles

1. **Return primitive types and arrays for reads.** Only introduce domain objects when the entity has complex invariants (ClickRecord's 11-table atomic write, rotator cascading rules). Simple CRUD entities (campaigns, networks, landing pages) stay as arrays — the V3 base Controller already validates structure via its schema DSL.

2. **Accept domain objects for complex writes.** `recordClick(ClickRecord $click): int` — the domain object *is* the validation. If you can construct a `ClickRecord`, all required fields are present and typed.

3. **`findOrCreate` for lookup tables.** The INDEXES class pattern (SELECT → if missing → INSERT → return ID) is the core access pattern for 15+ lookup tables. The interface makes this explicit rather than hiding it behind a `get_*_id()` name that doesn't reveal the write.

4. **No generic repository base class.** Each repository interface declares exactly the methods its consumers need. This avoids the "god repository" antipattern and makes dependencies explicit. If `ReportsController` only needs `summary()` and `breakdown()`, its interface doesn't expose `create()` or `delete()`.

---

## 5. Migration Strategy

### 5.1 — Strangler fig pattern

Each migration step follows the same procedure:

```
1. Write the interface
2. Write the MySQL implementation (translating the exact SQL from the legacy call site)
3. Write an in-memory fake for tests
4. Write tests against the fake that verify the interface contract
5. Write an integration test (tagged @group integration) that verifies the MySQL impl against a real DB
6. Replace the legacy call site with a repository call
7. Verify behavioral equivalence (see §7)
8. Remove the legacy code when no callers remain
```

**Critical rule: the repository must produce exactly the same SQL semantics as the legacy code it replaces.** This is not a time to "improve" queries, add indexes, or change column types. Those are separate PRs with separate testing. The migration PR changes the access pattern only.

### 5.2 — Rollback strategy

Every phase produces independently deployable PRs. Each PR:

- Has no feature flags (the repository returns the same types as the legacy code — there is no behavioral branching).
- Can be reverted with `git revert` because the interface boundary is additive (old code is removed, but no schema changes are made).
- Does not modify table schema. DDL changes (partitioning, index additions) are explicitly excluded and tracked as separate follow-up work.

**If a phase ships and causes production issues:**
1. Revert the PR. The legacy code is restored.
2. No DB migration needed (no schema was changed).
3. Memcache keys are identical (the caching decorator uses the same `md5($sql . systemHash())` key derivation as the legacy code, intentionally).

### 5.3 — Coexistence during migration

During migration, both patterns will exist:
- A controller might use `MysqlClickRepository` for `recordClick()` but still call `INDEXES::get_country_id()` directly.
- This is fine. The `Connection` class wraps the same `mysqli` instance that `global $db` points to. They share the same connection. There is no dual-connection overhead.
- The constraint is: a single *query* must not be half-migrated. The entire SQL for a given operation moves atomically from legacy to repository.

---

## 6. Phased Implementation

### Phase 0: Foundation (prerequisite for all subsequent phases)

**Goal:** Create `Connection` class + retrofit 9 existing Attribution repositories.

**Scope:**
| Action | Files |
|---|---|
| Create `202-config/Database/Connection.php` | NEW |
| Create `202-config/Database/ConnectionFactory.php` | NEW |
| Retrofit `MysqlTouchpointRepository` | MODIFY (remove private prepare/bind, inject Connection) |
| Retrofit `MysqlSnapshotRepository` | MODIFY |
| Retrofit `MysqlModelRepository` | MODIFY |
| Retrofit `MysqlConversionRepository` | MODIFY |
| Retrofit `MysqlSettingRepository` | MODIFY |
| Retrofit `MysqlAuditRepository` | MODIFY |
| Retrofit `MysqlExportJobRepository` | MODIFY |
| Retrofit `ConversionJourneyRepository` | MODIFY |
| Update `AttributionServiceFactory` | MODIFY (use ConnectionFactory) |

**Verification:**
- All existing Attribution tests pass with zero changes. If a test breaks, the retrofit changed behavior — fix it.
- Add unit tests for `Connection`: `testExecuteThrowsOnFailure`, `testTransactionRollsBackOnException`, `testFetchOneClosesStatement`, `testBindHandlesReferences`.

**Size estimate:** ~400 lines new, ~200 lines removed (deduplication), ~100 lines modified.

**Exit criterion:** `vendor/bin/phpunit` green, PHPStan clean on new files.

---

### Phase 1: Lookup Repositories

**Goal:** Replace `class-indexes.php` (INDEXES class) — 58 query sites, ~800 lines of structural duplication.

**Scope:**
| Action | Files |
|---|---|
| Create `LocationRepositoryInterface` | NEW — `findOrCreate{Country,City,Region,Isp,Ip,IpV6,SiteUrl}` |
| Create `DeviceRepositoryInterface` | NEW — `findOrCreate{Browser,Platform,Device}` |
| Create `TrackingRepositoryInterface` | NEW — `findOrCreate{Keyword,C1,C2,C3,C4,VariableSet,CustomVariable,Utm}` |
| Create `MysqlLocationRepository` | NEW |
| Create `MysqlDeviceRepository` | NEW |
| Create `MysqlTrackingRepository` | NEW |
| Create `NullLocationRepository` | NEW |
| Create `NullDeviceRepository` | NEW |
| Create `NullTrackingRepository` | NEW |
| Create `CachedLocationRepository` (decorator) | NEW |
| Create `CachedDeviceRepository` (decorator) | NEW |
| Create `CachedTrackingRepository` (decorator) | NEW |
| Update `record_simple.php` | MODIFY — replace `INDEXES::get_*()` calls |
| Update `record_adv.php` | MODIFY — replace `INDEXES::get_*()` calls |
| Update `dl.php` | MODIFY — replace `INDEXES::get_*()` calls |
| Delete `class-indexes.php` | DELETE (only after all callers migrated) |

**Caching decorator contract:** The decorator MUST use the same cache key derivation as the legacy code: `md5($entityType . $value . systemHash())`. This ensures zero cache invalidation impact on deployment.

**Verification:**
- In-memory fake tests for each interface (see §7).
- Integration tests (`@group integration`) that run `findOrCreate` → verify row exists → run again → verify same ID returned.
- Before/after comparison: run `record_simple.php` against a test database with legacy code, dump all query results. Run again with repository code. Diff the results. They must be identical.

**Size estimate:** ~900 lines new, ~800 lines deleted.

---

### Phase 2: Click Recording Repository

**Goal:** Replace the 11 unguarded INSERT statements in `record_simple.php` with a single transactional write.

**Scope:**
| Action | Files |
|---|---|
| Create `ClickRecord` domain object | NEW |
| Create `ClickRepositoryInterface` | NEW |
| Create `MysqlClickRepository` | NEW — `recordClick()` wraps 8-11 INSERTs in transaction |
| Create `NullClickRepository` | NEW |
| Create `InMemoryClickRepository` (test fake) | NEW |
| Refactor `record_simple.php` | MODIFY — extract input parsing, delegate to repository |
| Refactor `record_adv.php` | MODIFY — same |

**Critical safety concern: transaction introduction on the hot path.**

The hot path currently executes 18 queries without a transaction. Adding `BEGIN`/`COMMIT` introduces:
- One additional round-trip to MySQL for `BEGIN`.
- One additional round-trip for `COMMIT`.
- InnoDB row-level locks held for the duration of all 11 INSERTs (currently each INSERT auto-commits independently).

**Mitigation:** Measure the latency impact in staging before shipping (see §10). If the ~2 extra round-trips add unacceptable latency, the transaction can be made opt-in via a `$useTransaction` constructor parameter on `MysqlClickRepository`. But the default should be transactional — data consistency is more important than 1-2ms on a click-recording path that isn't in the redirect latency chain (it's a pixel fire, not a user-facing redirect).

**Verification:**
- Kill-test: Start `recordClick()`, kill the PHP process after table 5 of 11. Verify zero rows in all 11 tables (transaction rolled back). This cannot be tested today.
- Record 1000 test clicks. Compare output across all 11 tables against legacy code output. Row-for-row identical.

---

### Phase 3: Tracker, Campaign, User, Rotator Repositories

**Goal:** Extract SQL from the 4 standalone V3 controllers with the most custom SQL.

**Priority order (by complexity and risk):**

| Repository | Controller | SQL sites | Transactions | Risk |
|---|---|---|---|---|
| `RotatorRepository` | `RotatorsController` | 76 | 5 | High — cascading deletes across 4 tables |
| `UserRepository` | `UsersController` | 53 | 1 | Medium — password hashing, role management |
| `TrackerRepository` | `TrackersController` + `dl.php` | 17 | 0 | Medium — shared between V3 API and hot path |
| `CampaignRepository` | `CampaignsController` | 0 (uses base) | 0 | Low — already safe via base Controller |

**`RotatorsController` gets special treatment:** At 448 lines with 5 transactions managing cascading rules → criteria → redirects, this is the most complex migration. It should be its own PR, not bundled with the other 3.

**`CampaignRepository` is optional:** `CampaignsController` extends the base `Controller` and has zero custom SQL. Creating a repository adds indirection with no safety gain. Skip unless there's a consumer outside V3 that needs campaign queries (currently there isn't).

---

### Phase 4: Report & DataEngine Repositories

**Goal:** Separate reporting queries from controller HTTP concerns.

| Repository | Replaces | Why now |
|---|---|---|
| `ReportRepositoryInterface` | `ReportsController` (547 lines, 32 SQL sites) | This is the seam for the Tinybird hybrid. If reporting performance ever becomes a bottleneck, swap `MysqlReportRepository` for `TinybirdReportRepository` without touching the controller. |
| `DataEngineRepositoryInterface` | `class-dataengine.php` + `class-dataengine-slim.php` (~1500 lines, 59 escape calls) | Hardest migration. Do last. The dataengine is a materialized aggregation — complex, performance-sensitive, and coupled to the summary table schema. |

**`ReportRepository` interface should be query-object based, not method-per-report:**

```php
interface ReportRepositoryInterface
{
    public function query(ReportQuery $query): ReportResult;
}
```

Where `ReportQuery` encodes: user scope, time range, entity filters, breakdown type, sort, pagination. This avoids an interface with 15 methods that grows with every new report type.

**DataEngine migration note:** `class-dataengine.php` is the most dangerous file to touch. It's the materialization pipeline — bugs here corrupt aggregate data that takes hours to rebuild. This migration requires:
1. A full `rebuildRange()` integration test that compares output before/after.
2. A shadow-mode deployment where both old and new dataengine run in parallel and results are compared (not applied) for 48 hours before switching.

---

### Phase 5: Network & Remaining CRUD

**Goal:** Migrate remaining standalone controllers.

These are low-priority because the base Controller already handles them safely. Migrate opportunistically when touching these files for other reasons, or as onboarding tasks for new engineers.

---

### Phase 6: Hot Path Rewrite

**Goal:** `record_simple.php` (614 lines) → ~80 lines of orchestration.

**Depends on:** Phases 1, 2, 3 (Location/Device/Tracking/Click/Tracker repositories all exist).

The rewrite replaces ~75 `real_escape_string` calls and ~18 raw queries with:
1. Repository-backed lookups (all prepared statements, all cached).
2. Domain object construction (typed, validated at construction time).
3. Single transactional write.

**This is the highest-risk phase.** The hot path processes every click. A bug here means lost revenue data. Mitigation:

1. **Canary deployment:** Route 1% of traffic through the new code path first. Compare click counts and revenue aggregates against the 99% on the old path for 24 hours.
2. **Behavioral equivalence test (see §7.3):** Replay 10,000 production click payloads through both code paths. Diff every row written to every table.
3. **Instant rollback:** The old `record_simple.php` is preserved as `record_simple_legacy.php` during the canary period. Nginx/Apache rewrite rule toggles which file handles the pixel endpoint.

---

### Phase 7: Cleanup

Only after all phases are shipped and stable for 2 weeks:

| Action | Condition |
|---|---|
| Delete `class-indexes.php` | Zero callers (grep verified) |
| Delete `_mysqli_query()` from `functions.php` and `connect2.php` | Zero callers |
| Delete `memcache_mysql_fetch_assoc()` from all files | Zero callers (replaced by caching decorators) |
| Delete `record_mysql_error()` | Zero callers (replaced by Connection exceptions) |
| Remove `global $db` from all files | Zero usages |
| PHPStan: Remove baseline entries for deleted code | Baseline shrinks |

**Verification:** `grep -r 'real_escape_string\|_mysqli_query\|global \$db\|record_mysql_error' --include='*.php' | grep -v vendor | grep -v test` returns zero results.

---

## 7. Testing Strategy

### 7.1 — Test pyramid for repositories

```
                    ┌───────────────┐
                    │  Behavioral   │   Phase 6 only: replay production payloads
                    │  Equivalence  │   through old + new code, diff all writes
                    └───────┬───────┘
                  ┌─────────┴─────────┐
                  │   Integration     │   @group integration — real MySQL
                  │   (per-repo)      │   Verify SQL correctness against real schema
                  └─────────┬─────────┘
            ┌───────────────┴───────────────┐
            │       Contract Tests          │   Run identical assertions against
            │   (InMemory + Mysql impls)    │   both implementations
            └───────────────┬───────────────┘
      ┌─────────────────────┴─────────────────────┐
      │            Unit Tests (service layer)      │   Use InMemory fakes
      │            Test business logic isolation    │   No DB, no mocks
      └───────────────────────────────────────────┘
```

### 7.2 — Contract tests

A contract test runs the same assertions against every implementation of an interface. This catches the most dangerous bug category: the MySQL implementation diverges from what the in-memory fake does, so unit tests pass but production fails.

```php
abstract class LocationRepositoryContractTest extends TestCase
{
    abstract protected function createRepository(): LocationRepositoryInterface;

    public function testFindOrCreateCountryReturnsConsistentId(): void
    {
        $repo = $this->createRepository();
        $id1 = $repo->findOrCreateCountry('US', 'United States');
        $id2 = $repo->findOrCreateCountry('US', 'United States');
        $this->assertSame($id1, $id2);
        $this->assertGreaterThan(0, $id1);
    }

    public function testFindOrCreateCountryDifferentCodesGetDifferentIds(): void
    {
        $repo = $this->createRepository();
        $us = $repo->findOrCreateCountry('US', 'United States');
        $gb = $repo->findOrCreateCountry('GB', 'United Kingdom');
        $this->assertNotSame($us, $gb);
    }
}

// Two test classes inherit this:
class InMemoryLocationRepositoryTest extends LocationRepositoryContractTest
{
    protected function createRepository(): LocationRepositoryInterface
    {
        return new InMemoryLocationRepository();
    }
}

/** @group integration */
class MysqlLocationRepositoryTest extends LocationRepositoryContractTest
{
    protected function createRepository(): LocationRepositoryInterface
    {
        return new MysqlLocationRepository(ConnectionFactory::create());
    }
}
```

**Every repository interface gets a contract test.** The contract test is written first (against the interface), then both implementations are verified against it.

### 7.3 — Behavioral equivalence testing (Phase 6 only)

For the hot path rewrite, contract tests are necessary but insufficient. We need to verify that the new code produces *exactly the same database rows* as the old code for real-world inputs.

**Procedure:**
1. Capture 10,000 production click recording requests (sanitize PII, keep all query parameters).
2. Replay each request against old code → dump all INSERTed rows from all 11 tables.
3. Replay each request against new code → dump all INSERTed rows from all 11 tables.
4. Diff the output. Acceptable differences: `click_id` (auto-increment), `click_time` (timestamp). All other columns must be byte-identical.
5. Any diff that isn't in the acceptable list is a bug that must be fixed before shipping.

**This test runs in CI as a pre-merge gate for the Phase 6 PR.** It requires a test MySQL instance seeded with production schema + reference data (lookup tables). The seed script is part of the PR.

### 7.4 — What gets tested at each phase

| Phase | Unit tests | Contract tests | Integration tests | Behavioral equiv |
|---|---|---|---|---|
| 0 (Connection) | Connection class methods | N/A | Attribution repo tests still pass | N/A |
| 1 (Lookups) | Service tests with fakes | LocationContract, DeviceContract, TrackingContract | findOrCreate against real DB | N/A |
| 2 (Clicks) | ClickRecord construction, validation | ClickRepositoryContract | recordClick → verify all 11 tables | N/A |
| 3 (Tracker/User/Rotator) | Service tests with fakes | Per-interface contracts | CRUD against real DB | N/A |
| 4 (Reports) | ReportQuery construction | ReportRepositoryContract | Aggregation correctness | N/A |
| 6 (Hot path) | All of above | All of above | All of above | **Required** |

---

## 8. Risk Register

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| R1 | **Transaction on hot path adds latency** — `BEGIN`/`COMMIT` adds 2 round-trips | Medium | Medium | Benchmark in staging (§10). Click recording is a fire-and-forget pixel, not in the redirect latency chain. If needed, make transaction opt-in. |
| R2 | **Behavioral divergence in migration** — repository query returns different results than legacy code | High (for complex queries) | High (lost/corrupted data) | Contract tests + behavioral equivalence testing (§7.3). Require row-for-row diff before Phase 6 ships. |
| R3 | **Memcache key derivation mismatch** — caching decorator uses different key than legacy code, causing cache misses and increased DB load | Medium | Medium | Use identical key derivation: `md5($entityType . $value . systemHash())`. Explicitly test key generation in unit tests. Monitor cache hit rate during rollout (§9). |
| R4 | **Connection class becomes a god object** — scope creeps as convenience methods are added | Low | Medium | `Connection` has a fixed API surface. Code review must reject any method that isn't `prepare`/`bind`/`execute`/`fetch`/`transaction`. Query building stays in repositories. |
| R5 | **Partial migration stalls** — Phase 1 ships, but Phase 2-7 never happen. Two patterns coexist indefinitely. | Medium | Low | Each phase is independently valuable. Phase 1 alone eliminates `class-indexes.php` (the single most duplicated file). Even if nothing else ships, the codebase is better. The worst outcome is two patterns, which is already the current state (Gen 1 + Gen 2 + Gen 3 = three patterns → Gen 2 + Gen 3 = two patterns). |
| R6 | **`class-dataengine.php` migration corrupts aggregates** — materialization pipeline bug produces wrong report numbers | Low | Critical | Shadow-mode deployment (§6, Phase 4). Run both old and new dataengine in parallel for 48 hours. Compare output without applying. Only switch after zero diffs. |
| R7 | **Concurrent findOrCreate race condition** — two requests try to INSERT the same lookup value simultaneously | Medium | Low | Use `INSERT IGNORE` + `SELECT` in the MySQL implementation (same as the current `class-indexes.php` behavior). The interface contract is idempotent by definition. |
| R8 | **PHPStan baseline grows** — new code introduces new ignores | Low | Low | New repository code MUST pass PHPStan at the project's configured level with zero new baseline entries. Enforced in CI. |

---

## 9. Observability & Success Criteria

### 9.1 — Metrics to track during rollout

| Metric | Source | Baseline (capture before Phase 0) | Alert threshold |
|---|---|---|---|
| Click recording latency (p50, p95, p99) | Application timing or web server access log | Measure current | p99 > 2x baseline |
| Click recording error rate | `record_mysql_error()` call count → after: exception count | Measure current | Any increase |
| Memcache hit rate for lookup tables | Memcache stats | Measure current | Hit rate drops >5% |
| Orphaned click data (rows in `202_clicks` with no matching `202_clicks_advance`) | SQL audit query | Measure current | Any new orphans after Phase 2 |
| DB query count per click recording request | Count in `Connection::execute()` | 18 (record_simple) | Increase >10% |
| `real_escape_string` call sites remaining | `grep -c` in CI | 1,368 (baseline) | Must decrease monotonically |
| Test count and coverage | PHPUnit | Measure current | Must increase monotonically |

### 9.2 — Definition of Done for each phase

A phase is complete when:
1. All tests pass (unit + contract + integration where applicable).
2. PHPStan passes with zero new baseline entries.
3. CI is green (`php-unit.yml` + `php-lint.yml`).
4. The legacy call sites targeted by the phase have zero remaining callers (verified by grep).
5. PR is reviewed and merged.
6. Production metrics (§9.1) are stable for 48 hours post-deploy.

### 9.3 — Project-level success criteria

The project is complete when:
- `grep -r 'real_escape_string' --include='*.php' | grep -v vendor | grep -v test` returns zero results.
- `grep -r '_mysqli_query' --include='*.php' | grep -v vendor | grep -v test` returns zero results.
- `grep -r 'global \$db' --include='*.php' | grep -v vendor | grep -v test` returns zero results.
- Every database query in the hot path uses prepared statements via `Connection`.
- The click recording path is transactional.
- Test count has increased by ≥80 tests (contract + unit + integration).
- PHPStan baseline has shrunk (legacy code removed = legacy ignores removed).

---

## 10. Performance Budget

### 10.1 — Latency budget for the hot path

| Endpoint | Current | Budget (max acceptable) | Rationale |
|---|---|---|---|
| `dl.php` (redirect) | Measure baseline | +5ms p99 | User-facing redirect. Every millisecond matters. |
| `record_simple.php` (pixel) | Measure baseline | +10ms p99 | Fire-and-forget pixel. Users don't wait for this. |
| `record_adv.php` (pixel) | Measure baseline | +10ms p99 | Same as above. |

### 10.2 — What adds latency and what doesn't

| Change | Latency impact | Why |
|---|---|---|
| `real_escape_string` → prepared statement | **Negligible** (+0.1ms per query). MySQL server-side prepare is cached per connection. | Prepared statements add one initial round-trip for `PREPARE`, then the prepared statement is cached for the connection lifetime. Subsequent executions send only parameters. |
| `@$db->query()` → `Connection::execute()` with error checking | **Zero.** Error checking is a PHP-side `if` on the return value. | No additional DB round-trips. |
| No transaction → `BEGIN`/`COMMIT` wrapping 11 INSERTs | **+1-2ms** (two extra round-trips). | Measured by network latency to MySQL, typically <1ms for localhost, 1-2ms for network. |
| Caching decorator vs inline memcache | **Zero.** Same memcache calls, same keys. | The decorator calls `$memcache->get()` and `$memcache->set()` with the same arguments as the inline code. |

### 10.3 — Benchmarking procedure

Before Phase 2 (transaction introduction) ships:

1. Set up a staging environment matching production MySQL version and network topology.
2. Record 1,000 clicks via `record_simple.php` using the legacy code. Capture timing.
3. Record 1,000 clicks via `record_simple.php` using the repository code. Capture timing.
4. Compare p50, p95, p99. If p99 exceeds budget (§10.1), investigate.
5. Publish results in the PR description.

---

## 11. File Manifest

### New files

```
202-config/Database/
  Connection.php                         Phase 0
  ConnectionFactory.php                  Phase 0

202-config/Repository/
  LocationRepositoryInterface.php        Phase 1
  DeviceRepositoryInterface.php          Phase 1
  TrackingRepositoryInterface.php        Phase 1
  ClickRepositoryInterface.php           Phase 2
  TrackerRepositoryInterface.php         Phase 3
  UserRepositoryInterface.php            Phase 3
  RotatorRepositoryInterface.php         Phase 3
  ReportRepositoryInterface.php          Phase 4
  DataEngineRepositoryInterface.php      Phase 4
  NullLocationRepository.php             Phase 1
  NullDeviceRepository.php               Phase 1
  NullTrackingRepository.php             Phase 1
  NullClickRepository.php                Phase 2
  NullTrackerRepository.php              Phase 3
  NullUserRepository.php                 Phase 3
  NullRotatorRepository.php              Phase 3

202-config/Repository/Mysql/
  MysqlLocationRepository.php            Phase 1
  MysqlDeviceRepository.php              Phase 1
  MysqlTrackingRepository.php            Phase 1
  MysqlClickRepository.php               Phase 2
  MysqlTrackerRepository.php             Phase 3
  MysqlUserRepository.php                Phase 3
  MysqlRotatorRepository.php             Phase 3
  MysqlReportRepository.php              Phase 4
  MysqlDataEngineRepository.php          Phase 4

202-config/Repository/Cached/
  CachedLocationRepository.php           Phase 1
  CachedDeviceRepository.php             Phase 1
  CachedTrackingRepository.php           Phase 1

202-config/Domain/
  ClickRecord.php                        Phase 2

tests/Repository/
  LocationRepositoryContractTest.php     Phase 1
  DeviceRepositoryContractTest.php       Phase 1
  TrackingRepositoryContractTest.php     Phase 1
  ClickRepositoryContractTest.php        Phase 2
  MysqlLocationRepositoryTest.php        Phase 1  (@group integration)
  MysqlDeviceRepositoryTest.php          Phase 1  (@group integration)
  MysqlClickRepositoryTest.php           Phase 2  (@group integration)
  InMemoryLocationRepositoryTest.php     Phase 1
  InMemoryDeviceRepositoryTest.php       Phase 1
  InMemoryClickRepositoryTest.php        Phase 2

tests/Database/
  ConnectionTest.php                     Phase 0
```

### Modified files

```
202-config/Attribution/Repository/Mysql/MysqlTouchpointRepository.php    Phase 0
202-config/Attribution/Repository/Mysql/MysqlSnapshotRepository.php      Phase 0
202-config/Attribution/Repository/Mysql/MysqlModelRepository.php         Phase 0
202-config/Attribution/Repository/Mysql/MysqlConversionRepository.php    Phase 0
202-config/Attribution/Repository/Mysql/MysqlSettingRepository.php       Phase 0
202-config/Attribution/Repository/Mysql/MysqlAuditRepository.php         Phase 0
202-config/Attribution/Repository/Mysql/MysqlExportJobRepository.php     Phase 0
202-config/Attribution/Repository/Mysql/ConversionJourneyRepository.php  Phase 0
202-config/Attribution/AttributionServiceFactory.php                     Phase 0
tracking202/static/record_simple.php                                     Phase 1+2+6
tracking202/static/record_adv.php                                        Phase 1+2+6
tracking202/redirect/dl.php                                              Phase 1+3+6
api/V3/Controllers/RotatorsController.php                                Phase 3
api/V3/Controllers/UsersController.php                                   Phase 3
api/V3/Controllers/ReportsController.php                                 Phase 4
```

### Deleted files

```
202-config/class-indexes.php                    Phase 7 (after all callers migrated)
```

---

## 12. Dependency Graph & Parallelism

```
                    Phase 0 (Connection)
                    ┌───────┴───────┐
                    │               │
               Phase 1         Phase 3a
            (Lookups)        (TrackerRepo)
                │               │
                ├───────────────┤
                │               │
            Phase 2         Phase 3b
           (ClickRepo)   (Rotator/User)
                │               │
                ├───────────────┤
                │
            Phase 4
         (Reports/DE)
                │
            Phase 5
          (Networks)
                │
            Phase 6
          (Hot path)
                │
            Phase 7
           (Cleanup)
```

**What can be parallelized:**
- Phase 1 and Phase 3a can run in parallel (different files, different domains).
- Phase 2 and Phase 3b can run in parallel (after Phase 1 lands).
- Phase 4 and Phase 5 can run in parallel.

**What cannot be parallelized:**
- Phase 0 must land before anything else (everything depends on `Connection`).
- Phase 6 depends on Phases 1, 2, and 3 (the hot path needs all those repositories).
- Phase 7 depends on everything (cleanup requires all callers migrated).

**Implication for team staffing:** Two engineers can work on this concurrently after Phase 0 ships. One takes the lookup/click path (Phases 1→2→6). The other takes the V3 controller path (Phases 3→4→5). They converge at Phase 7.

---

## 13. What Stays the Same

| Component | Rationale |
|---|---|
| **mysqli** | Not the problem. The problem is string concatenation. Prepared statements via mysqli are equivalent to PDO. |
| **DB singleton (`DB::getInstance()`)** | Wrapped by `ConnectionFactory`. Changing the bootstrap is unnecessary churn. |
| **Memcache** | Moved into caching decorators with identical key derivation. Same behavior, separated concerns. |
| **V3 base Controller** | Already uses prepared statements for 7 simple CRUD entities. Low risk, low reward to change. |
| **V3 Router, Auth, Bootstrap** | Not in scope. These are HTTP-layer concerns, not data-access concerns. |
| **CLI commands** | Already use the V3 API client. No direct DB access to migrate. |
| **Table schema** | Zero DDL changes. Index additions, partitioning, and schema improvements are separate work with separate PRs and separate testing. |
| **PSR-4 autoloading** | New namespaces follow existing `Prosper202\` prefix. No composer.json autoload changes needed. |
