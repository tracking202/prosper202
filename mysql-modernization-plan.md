# Full Plan: Adopt Gen 3 (Repository Pattern) Across Prosper202

## The Pattern We're Adopting

The Attribution module already establishes every convention. This plan replicates it systematically across the rest of the codebase.

### Gen 3 Blueprint (from Attribution)

```
Interface (contract)          →  TouchpointRepositoryInterface
MySQL implementation          →  Mysql/MysqlTouchpointRepository  (final readonly)
Null implementation (tests)   →  NullTouchpointRepository
Domain object (value object)  →  Touchpoint  (readonly, fromDatabaseRow(), toDatabaseRow())
Factory (wiring)              →  AttributionServiceFactory
Service (orchestration)       →  AttributionService
```

**Key conventions already established:**
- Constructor takes `(mysqli $writeConnection, ?mysqli $readConnection = null)`
- `prepareRead()` routes to read replica, `prepareWrite()` routes to primary
- `prepare()` private helper that throws `RuntimeException` on failure
- `bind()` helper with reference-safe parameter binding
- Domain objects use named constructor params + `fromDatabaseRow()` static factory
- Null implementations return empty arrays / no-op for testing without DB
- Factory falls back to Null implementations when no DB connection available

---

## Phase 0: Database Foundation Layer

**Goal:** Eliminate the duplicated `prepareRead`/`prepareWrite`/`prepare`/`bind` boilerplate that every Attribution repository copy-pastes.

### 0.1 — `Prosper202\Database\Connection` (new)

```php
// 202-config/Database/Connection.php
final readonly class Connection
{
    public function __construct(
        private mysqli $write,
        private ?mysqli $read = null
    ) {}

    /** Prepare on write connection. Throws on failure. */
    public function prepareWrite(string $sql): mysqli_stmt { ... }

    /** Prepare on read connection (falls back to write). Throws on failure. */
    public function prepareRead(string $sql): mysqli_stmt { ... }

    /** Bind parameters with reference safety. */
    public function bind(mysqli_stmt $stmt, string $types, array $values): void { ... }

    /** Execute with checked return value (CLAUDE.md rule #1). */
    public function execute(mysqli_stmt $stmt): void { ... }

    /** Run callback inside a transaction. Auto-rollback on exception. */
    public function transaction(callable $fn): mixed { ... }

    /** Fetch single row or null. Closes statement. */
    public function fetchOne(mysqli_stmt $stmt): ?array { ... }

    /** Fetch all rows. Closes statement. */
    public function fetchAll(mysqli_stmt $stmt): array { ... }

    /** Execute and return insert ID. Closes statement. */
    public function executeInsert(mysqli_stmt $stmt): int { ... }

    /** Execute and return affected rows. Closes statement. */
    public function executeUpdate(mysqli_stmt $stmt): int { ... }

    /** Get the write connection for cases that need raw access. */
    public function writeConnection(): mysqli { ... }

    /** Get the read connection for cases that need raw access. */
    public function readConnection(): mysqli { ... }
}
```

**Why:** Every Attribution repository has its own copy of `prepareRead`, `prepareWrite`, `prepare`, `bind`. New repositories will all need this. Extract once, reuse everywhere.

### 0.2 — `Prosper202\Database\ConnectionFactory` (new)

```php
// 202-config/Database/ConnectionFactory.php
final class ConnectionFactory
{
    private static ?Connection $instance = null;

    public static function create(): Connection
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        $db = \DB::getInstance();
        self::$instance = new Connection(
            $db->getConnection(),
            $db->getConnectionro()
        );
        return self::$instance;
    }
}
```

**Why:** Bridges the existing `DB` singleton to the new `Connection` class. Avoids changing the config layer.

### 0.3 — Retrofit existing Attribution repositories

Refactor the 7 existing Mysql repositories to use `Connection` instead of duplicating `prepareRead`/`prepareWrite`/`prepare`/`bind`. This is pure internal refactor — no interface changes, no behavior changes.

**Files to change:**
- `MysqlTouchpointRepository.php` — remove private prepare/bind methods, inject `Connection`
- `MysqlSnapshotRepository.php` — same
- `MysqlModelRepository.php` — same
- `MysqlConversionRepository.php` — same
- `MysqlSettingRepository.php` — same
- `MysqlAuditRepository.php` — same
- `MysqlExportRepository.php` — same
- `MysqlExportJobRepository.php` — same
- `ConversionJourneyRepository.php` — same
- `AttributionServiceFactory.php` — pass `Connection` instead of raw `mysqli`

Remove `MysqliStatementBinder` trait (absorbed into `Connection`).

---

## Phase 1: Lookup Table Repositories

**Goal:** Replace `class-indexes.php` (INDEXES class) — the single file with the most legacy patterns (38 `real_escape_string` calls, duplicated memcache-or-DB logic for every lookup).

### 1.1 — Domain Objects

```php
// 202-config/Domain/Country.php
final readonly class Country {
    public function __construct(
        public ?int $countryId,
        public string $countryCode,
        public string $countryName
    ) {}
    public static function fromDatabaseRow(array $row): self { ... }
}
```

Similar for: `City`, `Region`, `Isp`, `Browser`, `Platform`, `DeviceModel`, `Keyword`, `SiteUrl`, `SiteDomain`, `IpAddress`, `TrackingC1..C4`, `CustomVariable`, `UtmValue`.

These are small — most are just `(int $id, string $value)` pairs.

### 1.2 — Repository Interfaces

```php
// 202-config/Repository/LocationRepositoryInterface.php
interface LocationRepositoryInterface
{
    public function findOrCreateCountry(string $countryCode, string $countryName): int;
    public function findOrCreateCity(string $cityName, int $countryId): int;
    public function findOrCreateRegion(string $regionName, int $countryId): int;
    public function findOrCreateIsp(string $ispName): int;
    public function findOrCreateIp(string $ipAddress): int;
    public function findOrCreateIpV6(string $ipAddress): int;
    public function findOrCreateSiteUrl(string $url): int;
}

// 202-config/Repository/DeviceRepositoryInterface.php
interface DeviceRepositoryInterface
{
    public function findOrCreateBrowser(string $browserName): int;
    public function findOrCreatePlatform(string $platformName): int;
    public function findOrCreateDevice(string $deviceName, int $deviceType): int;
}

// 202-config/Repository/TrackingRepositoryInterface.php
interface TrackingRepositoryInterface
{
    public function findOrCreateKeyword(string $keyword): int;
    public function findOrCreateC1(string $value): int;
    public function findOrCreateC2(string $value): int;
    public function findOrCreateC3(string $value): int;
    public function findOrCreateC4(string $value): int;
    public function findOrCreateVariableSet(string $variables): int;
    public function findOrCreateCustomVariable(string $variable, int $ppcVariableId): int;
    public function findOrCreateUtm(string $value, string $utmType): int;
}
```

### 1.3 — MySQL Implementations

```php
// 202-config/Repository/Mysql/MysqlLocationRepository.php
final readonly class MysqlLocationRepository implements LocationRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    public function findOrCreateCountry(string $countryCode, string $countryName): int
    {
        $stmt = $this->connection->prepareRead(
            'SELECT country_id FROM 202_locations_country WHERE country_code = ?'
        );
        $this->connection->bind($stmt, 's', [$countryCode]);
        $this->connection->execute($stmt);
        $row = $this->connection->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row['country_id'];
        }

        $stmt = $this->connection->prepareWrite(
            'INSERT INTO 202_locations_country (country_code, country_name) VALUES (?, ?)'
        );
        $this->connection->bind($stmt, 'ss', [$countryCode, $countryName]);
        return $this->connection->executeInsert($stmt);
    }

    // ... same pattern for city, region, isp, ip, siteUrl
}
```

### 1.4 — Null Implementations

```php
// 202-config/Repository/NullLocationRepository.php
final class NullLocationRepository implements LocationRepositoryInterface
{
    public function findOrCreateCountry(string $countryCode, string $countryName): int { return 0; }
    public function findOrCreateCity(string $cityName, int $countryId): int { return 0; }
    // ...
}
```

### 1.5 — Caching Decorator (replaces inline memcache logic)

```php
// 202-config/Repository/CachedLocationRepository.php
final class CachedLocationRepository implements LocationRepositoryInterface
{
    public function __construct(
        private LocationRepositoryInterface $inner,
        private ?\Memcache|\Memcached $cache,
        private string $systemHash
    ) {}

    public function findOrCreateCountry(string $countryCode, string $countryName): int
    {
        $key = md5("country-id" . $countryCode . $this->systemHash);
        if ($this->cache) {
            $cached = $this->cache->get($key);
            if ($cached !== false) {
                return (int) $cached;
            }
        }

        $id = $this->inner->findOrCreateCountry($countryCode, $countryName);

        if ($this->cache) {
            $this->cache->set($key, $id, 0, 2592000); // 30 days
        }
        return $id;
    }
}
```

**Why a decorator:** Separates caching concern from DB concern. `class-indexes.php` interleaves memcache and MySQL logic line-by-line — every method has the exact same if/else memcache structure duplicated. The decorator eliminates that duplication.

### 1.6 — Wire and Retire

- Create a `RepositoryFactory` similar to `AttributionServiceFactory`
- Replace all `INDEXES::get_*()` calls in consumers with repository calls
- **Consumers to update:** `record_simple.php`, `record_adv.php`, `dl.php`, `class-indexes.php` callers in `tracking202/static/` files
- Delete `class-indexes.php` when all callers are migrated

---

## Phase 2: Click Recording Repository

**Goal:** Replace the 11 INSERT statements in `record_simple.php` (and similar in `record_adv.php`) with a single repository method that writes all click tables atomically.

### 2.1 — Domain Object

```php
// 202-config/Domain/ClickRecord.php
final readonly class ClickRecord
{
    public function __construct(
        public ?int $clickId,
        public int $userId,
        public int $affCampaignId,
        public int $ppcAccountId,
        public int $landingPageId,
        public string $clickCpc,
        public string $clickPayout,
        public int $clickTime,
        public int $textAdId,
        public int $keywordId,
        public int $ipId,
        public int $countryId,
        public int $regionId,
        public int $cityId,
        public int $ispId,
        public int $platformId,
        public int $browserId,
        public int $deviceId,
        public int $c1Id,
        public int $c2Id,
        public int $c3Id,
        public int $c4Id,
        public int $utmSourceId,
        public int $utmMediumId,
        public int $utmCampaignId,
        public int $utmTermId,
        public int $utmContentId,
        public string $gclid,
        public int $variableSetId,
        public int $clickRefererSiteUrlId,
        public int $clickLandingSiteUrlId,
        public int $clickOutboundSiteUrlId,
        public int $clickCloakingSiteUrlId,
        public int $clickRedirectSiteUrlId,
        public bool $clickCloaking,
        public bool $clickFiltered,
        public bool $clickBot,
        public bool $clickAlp,
        public int $rotatorId = 0,
        public int $ruleId = 0,
        public int $ruleRedirectId = 0,
    ) {}
}
```

### 2.2 — Repository Interface

```php
// 202-config/Repository/ClickRepositoryInterface.php
interface ClickRepositoryInterface
{
    /** Inserts across all click tables atomically. Returns the click_id. */
    public function recordClick(ClickRecord $click): int;

    /** Look up a click by ID with full join data. */
    public function findById(int $clickId, int $userId): ?array;

    /** Paginated click listing with filters. */
    public function findForUser(int $userId, array $filters, int $limit, int $offset): array;

    /** Count clicks matching filters. */
    public function countForUser(int $userId, array $filters): int;

    /** Mark a click as converted. */
    public function markConverted(int $clickId, string $payout): bool;
}
```

### 2.3 — MySQL Implementation

```php
// 202-config/Repository/Mysql/MysqlClickRepository.php
final readonly class MysqlClickRepository implements ClickRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    public function recordClick(ClickRecord $click): int
    {
        return $this->connection->transaction(function () use ($click): int {
            // 1. INSERT 202_clicks_counter → get click_id
            // 2. INSERT 202_clicks
            // 3. INSERT 202_clicks_spy
            // 4. INSERT 202_clicks_advance
            // 5. INSERT 202_clicks_tracking
            // 6. INSERT 202_clicks_record
            // 7. INSERT 202_clicks_site
            // 8. INSERT 202_clicks_variable
            // 9. INSERT 202_google (or 202_bing/202_facebook)
            // All with prepared statements, all checked
            return $clickId;
        });
    }
}
```

**Why a transaction:** Currently `record_simple.php` writes 8-11 tables with no transaction. If the process dies mid-way, you get orphaned partial click data. The transaction makes it atomic.

### 2.4 — Refactor Consumers

- `record_simple.php` becomes: parse input → build `ClickRecord` → call `$clickRepo->recordClick($click)`
- `record_adv.php` same pattern (shares ClickRecord, may need a few extra fields)
- `ClicksController::get()` and `ClicksController::list()` delegate to `$clickRepo->findById()` / `$clickRepo->findForUser()`
- `ConversionsController::create()` uses `$clickRepo->markConverted()`

---

## Phase 3: Tracker & Campaign Repositories

### 3.1 — TrackerRepository

```php
interface TrackerRepositoryInterface
{
    /** The big JOIN query from dl.php that fetches tracker + campaign + user prefs + PPC vars. */
    public function findByPublicId(string $trackerIdPublic): ?array;

    public function findById(int $trackerId, int $userId): ?array;
    public function create(array $data): int;
}
```

**Replaces:** The 20-line JOIN query in `dl.php` (lines 177-205) and `record_simple.php` (lines 79-88).

### 3.2 — CampaignRepository

```php
interface CampaignRepositoryInterface
{
    public function findById(int $campaignId, int $userId): ?array;
    public function findForUser(int $userId, array $filters, int $limit, int $offset): array;
    public function create(int $userId, array $data): int;
    public function update(int $campaignId, int $userId, array $data): bool;
    public function softDelete(int $campaignId, int $userId): bool;
}
```

**Replaces:** `CampaignsController` inline SQL (currently uses base `Controller` generic CRUD — this can stay as-is initially since it's already using prepared statements, but should eventually migrate for consistency).

### 3.3 — UserRepository

```php
interface UserRepositoryInterface
{
    public function findById(int $userId): ?array;
    public function findByUsername(string $username): ?array;
    public function create(array $userData, array $prefsData): int;
    public function updatePreferences(int $userId, array $prefs): bool;
    public function findUserPrefs(int $userId): ?array;
}
```

**Replaces:** `UsersController` transaction (INSERT user + INSERT user_prefs), login page queries, `account.php`.

### 3.4 — RotatorRepository

```php
interface RotatorRepositoryInterface
{
    public function findById(int $rotatorId, int $userId): ?array;
    public function findRules(int $rotatorId): array;
    public function create(int $userId, array $data): int;
    public function delete(int $rotatorId, int $userId): void;
    public function createRule(int $rotatorId, array $ruleData, array $criteria, array $redirects): int;
    public function updateRule(int $ruleId, array $ruleData, array $criteria, array $redirects): void;
    public function deleteRule(int $ruleId, int $rotatorId): void;
}
```

**Replaces:** `RotatorsController` (the most complex V3 controller — 438 lines of hand-written SQL with cascading transactions across rules, criteria, and redirects).

---

## Phase 4: Report & DataEngine Repositories

### 4.1 — ReportRepository

```php
interface ReportRepositoryInterface
{
    public function summary(int $userId, int $timeFrom, int $timeTo, array $entityFilters): array;
    public function breakdown(int $userId, string $breakdownType, int $timeFrom, int $timeTo, array $entityFilters, string $sortBy, string $sortDir, int $limit, int $offset): array;
    public function timeseries(int $userId, string $interval, int $timeFrom, int $timeTo, array $entityFilters): array;
    public function daypart(int $userId, int $timeFrom, int $timeTo, array $entityFilters): array;
    public function weekpart(int $userId, int $timeFrom, int $timeTo, array $entityFilters): array;
}
```

**Replaces:** `ReportsController` (currently 7 prepared queries). This is the natural seam for the Tinybird hybrid, if ever needed — swap `MysqlReportRepository` for `TinybirdReportRepository`.

### 4.2 — DataEngineRepository

```php
interface DataEngineRepositoryInterface
{
    public function setDirtyHour(int $clickId): void;
    public function processDirtyHours(): void;
    public function rebuildRange(int $timeFrom, int $timeTo, int $userId): void;
}
```

**Replaces:** `class-dataengine.php` and `class-dataengine-slim.php` (the two largest files at ~1500+ lines combined, 59 `real_escape_string` calls in dataengine alone). This is the hardest migration — do last.

---

## Phase 5: Network & Remaining CRUD Repositories

### 5.1 — NetworkRepository

```php
interface NetworkRepositoryInterface
{
    // Affiliate networks
    public function findAffNetwork(int $networkId, int $userId): ?array;
    public function findAffNetworksForUser(int $userId, int $limit, int $offset): array;
    public function createAffNetwork(int $userId, array $data): int;
    public function updateAffNetwork(int $networkId, int $userId, array $data): bool;

    // PPC networks + accounts
    public function findPpcNetwork(int $networkId): ?array;
    public function findPpcAccountsForUser(int $userId): array;
    public function createPpcAccount(int $userId, array $data): int;
}
```

### 5.2 — LandingPageRepository, TextAdRepository

These are straightforward CRUD — same pattern as Campaign. The V3 base `Controller` already handles them generically. Migrate when touching these files for other reasons.

---

## Phase 6: Hot Path Refactor

**Goal:** `dl.php` and `record_simple.php` become thin orchestration scripts.

### Before (record_simple.php — 614 lines):
```php
$db->real_escape_string(...)  // 75 times
_mysqli_query(...)            // scattered raw queries
$db->query("INSERT ...")      // 11 unguarded inserts
INDEXES::get_country_id(...)  // memcache-or-DB inline
```

### After (record_simple.php — ~80 lines):
```php
$connection = ConnectionFactory::create();
$locationRepo = new CachedLocationRepository(
    new MysqlLocationRepository($connection), $memcache, systemHash()
);
$deviceRepo = new CachedDeviceRepository(
    new MysqlDeviceRepository($connection), $memcache, systemHash()
);
$trackingRepo = new MysqlTrackingRepository($connection);
$clickRepo = new MysqlClickRepository($connection);
$trackerRepo = new MysqlTrackerRepository($connection);

// 1. Look up tracker/landing page
$tracker = $trackerRepo->findByPublicId($t202id);
if (!$tracker) { die(); }

// 2. Resolve all lookup IDs
$countryId = $locationRepo->findOrCreateCountry($countryCode, $countryName);
$cityId = $locationRepo->findOrCreateCity($cityName, $countryId);
// ... etc

// 3. Build domain object
$click = new ClickRecord(
    clickId: null,
    userId: $tracker['user_id'],
    // ... all fields populated from resolved IDs
);

// 4. Single atomic write
$clickId = $clickRepo->recordClick($click);

// 5. Post-write side effects (cookies, dirty hour, etc.)
setClickIdCookie($clickId, $click->affCampaignId);
```

---

## Phase 7: Cleanup

### 7.1 — Remove dead code
- Delete `class-indexes.php` (`INDEXES` class) — replaced by Location/Device/Tracking repositories
- Delete `_mysqli_query()` function from `functions.php` and `connect2.php`
- Remove `MysqliStatementBinder` trait — absorbed into `Connection`

### 7.2 — Remove `global $db` pattern
- Every file that uses `global $db` gets `Connection` injected instead
- The `DB` singleton remains (needed for bootstrap) but nothing references it directly except `ConnectionFactory`

### 7.3 — Remove `real_escape_string()`
- Every query now uses prepared statements via `Connection`
- `real_escape_string()` should have zero remaining call sites

### 7.4 — Update V3 base Controller
- Optionally refactor `api/V3/Controller.php` to use `Connection` internally
- The base controller's generic CRUD is already safe (prepared statements), so this is lowest priority
- Standalone controllers (`RotatorsController`, `ConversionsController`, `UsersController`, `ReportsController`) should delegate to their repositories

---

## File Map: What Gets Created

```
202-config/
├── Database/
│   ├── Connection.php              ← NEW (Phase 0)
│   └── ConnectionFactory.php       ← NEW (Phase 0)
├── Domain/
│   ├── ClickRecord.php             ← NEW (Phase 2)
│   ├── Country.php                 ← NEW (Phase 1)
│   ├── City.php                    ← NEW (Phase 1)
│   └── ...                         ← small value objects
├── Repository/
│   ├── ClickRepositoryInterface.php        ← NEW (Phase 2)
│   ├── LocationRepositoryInterface.php     ← NEW (Phase 1)
│   ├── DeviceRepositoryInterface.php       ← NEW (Phase 1)
│   ├── TrackingRepositoryInterface.php     ← NEW (Phase 1)
│   ├── TrackerRepositoryInterface.php      ← NEW (Phase 3)
│   ├── CampaignRepositoryInterface.php     ← NEW (Phase 3)
│   ├── UserRepositoryInterface.php         ← NEW (Phase 3)
│   ├── RotatorRepositoryInterface.php      ← NEW (Phase 3)
│   ├── ReportRepositoryInterface.php       ← NEW (Phase 4)
│   ├── DataEngineRepositoryInterface.php   ← NEW (Phase 4)
│   ├── NetworkRepositoryInterface.php      ← NEW (Phase 5)
│   ├── NullLocationRepository.php          ← NEW
│   ├── NullDeviceRepository.php            ← NEW
│   ├── NullClickRepository.php             ← NEW
│   ├── Null...                             ← one per interface
│   ├── Mysql/
│   │   ├── MysqlLocationRepository.php     ← NEW
│   │   ├── MysqlDeviceRepository.php       ← NEW
│   │   ├── MysqlTrackingRepository.php     ← NEW
│   │   ├── MysqlClickRepository.php        ← NEW
│   │   ├── MysqlTrackerRepository.php      ← NEW
│   │   ├── MysqlUserRepository.php         ← NEW
│   │   ├── MysqlRotatorRepository.php      ← NEW
│   │   ├── MysqlReportRepository.php       ← NEW
│   │   ├── MysqlDataEngineRepository.php   ← NEW
│   │   └── MysqlNetworkRepository.php      ← NEW
│   └── Cached/
│       ├── CachedLocationRepository.php    ← NEW (decorator)
│       ├── CachedDeviceRepository.php      ← NEW (decorator)
│       └── CachedTrackingRepository.php    ← NEW (decorator)
└── Attribution/Repository/                 ← EXISTING (refactored to use Connection)
```

## File Map: What Gets Deleted

```
202-config/class-indexes.php           ← DELETE after Phase 1 migration complete
202-config/Attribution/Repository/
    Mysql/MysqliStatementBinder.php    ← DELETE after Phase 0 (absorbed into Connection)
```

## File Map: What Gets Modified (major)

```
tracking202/static/record_simple.php   ← REWRITE (Phase 6) — 614→~80 lines
tracking202/static/record_adv.php      ← REWRITE (Phase 6) — similar
tracking202/redirect/dl.php            ← REWRITE (Phase 6) — redirect hot path
api/V3/Controllers/ReportsController   ← delegates to ReportRepository (Phase 4)
api/V3/Controllers/RotatorsController  ← delegates to RotatorRepository (Phase 3)
api/V3/Controllers/ConversionsController ← delegates to ClickRepository (Phase 2)
api/V3/Controllers/UsersController     ← delegates to UserRepository (Phase 3)
202-config/Attribution/Repository/Mysql/* ← inject Connection (Phase 0)
202-config/Attribution/AttributionServiceFactory.php ← use ConnectionFactory (Phase 0)
```

---

## Execution Order (dependency-aware)

```
Phase 0  ─── Connection + ConnectionFactory + retrofit Attribution repos
  │
Phase 1  ─── Location + Device + Tracking lookup repositories
  │           (replaces class-indexes.php, biggest code dedup win)
  │
Phase 2  ─── ClickRepository
  │           (replaces record_simple.php INSERT cascade)
  │
Phase 3  ─── Tracker + Campaign + User + Rotator repositories
  │           (replaces V3 standalone controllers)
  │
Phase 4  ─── Report + DataEngine repositories
  │           (replaces ReportsController + class-dataengine.php)
  │
Phase 5  ─── Network + remaining CRUD repositories
  │           (cleanup stragglers)
  │
Phase 6  ─── Hot path refactor (dl.php, record_simple.php, record_adv.php)
  │           (depends on Phase 1 + 2 + 3 repositories existing)
  │
Phase 7  ─── Delete dead code, remove global $db, zero real_escape_string
```

Phases 0-2 are the highest-value work. Phase 3 is moderate value. Phases 4-7 are progressive cleanup that can happen over time. Each phase is independently shippable.

---

## What Stays the Same

- **mysqli** — no PDO migration
- **DB singleton** — wrapped by ConnectionFactory, not replaced
- **memcache** — moved into caching decorators, same behavior
- **V3 base Controller** — generic CRUD keeps working, low priority to change
- **CLI commands** — already use API client, no DB access to migrate
- **Table schema** — no DDL changes needed
- **PSR-4 autoloading** — new namespaces follow existing `Prosper202\` prefix
