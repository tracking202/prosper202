# MySQL to Tinybird Evaluation — and SQL Modernization Plan

## Part 1: Tinybird Migration Assessment

### Does it make sense? No, not as a full replacement.

Prosper202 has **70+ InnoDB tables**, **141 INSERT sites**, **188 UPDATE/DELETE sites**, and **13 files using transactions**. It's an OLTP+OLAP hybrid. Tinybird is a ClickHouse-based OLAP engine — append-only, no transactions, no UPDATE/DELETE, no foreign keys.

**Fundamental mismatches:**

| Prosper202 needs | Tinybird provides |
|---|---|
| ACID transactions (click tracking writes 5-10 tables atomically) | No transactions |
| UPDATE/DELETE (campaign CRUD, user management, RBAC) | Append-only (ReplacingMergeTree at best) |
| Foreign key joins across 30+ lookup tables | No FK constraints, join support is limited |
| Sub-millisecond latency on redirect hot path (`dl.php`) | Hosted API with network latency |
| Normalized relational schema | Columnar, denormalized by design |

**Where Tinybird could help (hybrid, if needed later):**

The `202_dataengine` table is already a denormalized analytics materialization. If reporting performance becomes a bottleneck at scale, streaming click events to Tinybird and moving `ReportsController` queries there (~5-10 files) would be viable. But this is premature until MySQL aggregation actually hurts.

**Better first steps for scale:** Add time-range partitioning to `202_dataengine` (infrastructure already exists via `PartitionInstaller`), or optimize indexes.

---

## Part 2: Current SQL Access — What Exists Today

The codebase has **three generations** of database access coexisting:

### Generation 1: Legacy (dominant — ~70% of DB access)
```php
// Raw string concatenation with escaping
$mysql['id'] = $db->real_escape_string($value);
$sql = "SELECT * FROM table WHERE id='" . $mysql['id'] . "'";
$result = _mysqli_query($sql);
```
- **637 `real_escape_string()` calls** across 50+ files
- **`_mysqli_query()`** global wrapper that suppresses errors with `@`
- No prepared statements, no error checking, no type safety
- Used in: `class-indexes.php`, `class-dataengine.php`, `dl.php`, `record_simple.php`, all setup pages

### Generation 2: API V3 Controllers (modern-ish — ~20%)
```php
// Prepared statements with manual bind_param
class ClicksController {
    public function __construct(private readonly \mysqli $db, private readonly int $userId) {}

    public function list(array $params): array {
        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) { throw new DatabaseException(...); }
    }
}
```
- **146 `prepare()` calls** across 32 files
- Proper error checking, DI of `$db`, typed
- Base `Controller` class provides generic CRUD
- But: SQL still inline in controllers, no repository separation

### Generation 3: Attribution Repositories (newest — ~10%)
```php
// Interface-backed repository with read/write connection separation
final readonly class MysqlTouchpointRepository implements TouchpointRepositoryInterface {
    public function __construct(
        private mysqli $writeConnection,
        private ?mysqli $readConnection = null
    ) {}
}
```
- **10 repository implementations** + interfaces + null implementations for testing
- Clean separation of concerns, testable, supports read replicas
- Uses `MysqliStatementBinder` trait for safe parameter binding

---

## Part 3: Modernization Plan

### Strategy: Extend Generation 3 across the codebase

The Attribution module already established the right pattern. The goal is to propagate it everywhere, incrementally, without a big-bang rewrite.

### Layer 1: Database Foundation (`Prosper202\Database`)

**New files to create:**

1. **`Connection.php`** — Typed wrapper around the existing `DB` singleton
   - Wraps `mysqli` with proper error handling (no more `@` suppression)
   - Provides `prepare()`, `execute()`, `query()`, `transaction()` with automatic error checking
   - Enforces the CLAUDE.md rule: every `execute()` return value is checked
   - Read/write connection awareness (already have `$db`/`$dbro`)

2. **`Statement.php`** — Fluent prepared statement builder
   - Replaces manual `bind_param` type string counting
   - `$stmt->bind('i', $id)->bind('s', $name)->execute()` or `->bindAll(['i' => $id, 's' => $name])`
   - Auto-closes statements, prevents resource leaks

3. **`Transaction.php`** — Closure-based transaction helper
   - `$db->transaction(function($conn) { ... })` with automatic rollback on exception
   - Replaces the 13 manual `begin_transaction`/`commit`/`rollback` blocks

### Layer 2: Repository Interfaces (`Prosper202\Repository`)

One repository per domain entity, following the Attribution pattern:

| Repository | Replaces queries in |
|---|---|
| `ClickRepository` | `dl.php`, `record_simple.php`, `record_adv.php`, `ClicksController` |
| `CampaignRepository` | `CampaignsController`, setup pages |
| `TrackerRepository` | `dl.php`, `TrackersController`, `generate_tracking_link.php` |
| `LocationRepository` | `class-indexes.php` (country, city, region, ISP lookups) |
| `DeviceRepository` | `class-indexes.php` (browser, platform, device lookups) |
| `UserRepository` | `UsersController`, `account.php`, login pages |
| `ReportRepository` | `ReportsController`, `class-dataengine.php` |
| `RotatorRepository` | `RotatorsController`, `rotator.php` |
| `NetworkRepository` | `AffNetworksController`, `PpcNetworksController` |
| `ConversionRepository` | Already exists in Attribution module |

Each gets:
- An interface in `Prosper202\Repository\`
- A MySQL implementation in `Prosper202\Repository\Mysql\`
- A null implementation for testing

### Layer 3: Migrate Incrementally

**Phase 1 — Foundation + highest-value targets** (safest, biggest impact)
1. Create `Connection`, `Statement`, `Transaction` classes
2. Create `LocationRepository` + `DeviceRepository` (replaces `class-indexes.php` — 38 `real_escape_string` calls, most duplicated code in the codebase)
3. Create `ClickRepository` (centralizes the scattered click INSERT/SELECT logic)
4. Wire into existing V3 controllers (they already take `$db` in constructors)

**Phase 2 — Hot path migration**
5. Refactor `dl.php` to use `TrackerRepository` + `ClickRepository`
6. Refactor `record_simple.php` and `record_adv.php`
7. Convert all remaining `_mysqli_query()` call sites

**Phase 3 — Cleanup**
8. Remove `_mysqli_query()` global function
9. Remove `global $db` usage patterns
10. Deprecate `real_escape_string()` — all queries use prepared statements

### What NOT to change:
- **Keep mysqli** — it works, PDO migration adds churn for zero gain
- **Keep the DB singleton** — just wrap it properly
- **Keep memcache integration** — move it into repositories as a caching decorator
- **Don't add an ORM** — the V3 Controller base class already provides sufficient generic CRUD
