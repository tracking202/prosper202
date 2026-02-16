# Backend/API Server Upgrades Validation Evidence

## Scope
Validation artifact for `task-plan-backend-api-server-upgrades.md` covering R1-R8 gates, compatibility checks, observability, and rollout controls.

## Release Gate Matrix

### R1
- Capability/version contracts covered by tests:
  - `tests/Api/V3/SyncFeaturesTest.php` (`testVersionsEndpointContract`, capabilities schema assertions)
  - `tests/Api/V3/RouterTest.php` (`testLegacyCoreApiV3PathsStillMatch`)

### R2
- Deterministic and edge-case plan behavior:
  - `tests/Api/V3/SyncFeaturesTest.php` (`testPlanDeterministicAndCollisionWarningsWithInMemoryEngine`)
  - `tests/Api/V3/SyncFeaturesTest.php` (`testPlanManualCollisionModeThrowsWithInMemoryEngine`)

### R3
- Job lifecycle integration:
  - `tests/Api/V3/SyncFeaturesTest.php` (enqueue/poll/cancel/retry/partial/audit)
  - `tests/Api/V3/SyncFeaturesTest.php` (`testWorkerProgressUnderQueuedLoad`) for queue throughput/progress under load

### R4
- Bulk upsert idempotency + row-level behavior:
  - `tests/Api/V3/ControllerTest.php` bulk-upsert coverage

### R5
- Incremental + deletion propagation:
  - `tests/Api/V3/SyncFeaturesTest.php` (`testIncrementalSyncUsesManifestLastSyncEpoch`)
  - `tests/Api/V3/SyncFeaturesTest.php` (`testListChangesDeletedSinceReturnsOnlyDeleteOperations`)

### R6
- Concurrency/prune controls:
  - `tests/Api/V3/ControllerTest.php` conflict payload assertions
  - `tests/Api/V3/SyncFeaturesTest.php` prune token enforcement and preview behavior

### R7
- FK remap + idempotent repeat behavior:
  - `go-cli/cmd/cmd_test.go` tracker remap and second-run idempotency tests

### R8
- Audit/completeness/redaction:
  - `tests/Api/V3/SyncFeaturesTest.php` status completeness + CSV export + sensitive-field redaction

## Observability Validation
- Tracing spans:
  - `tests/Api/V3/SyncFeaturesTest.php` (`testExecuteRecordsTracingSpansForStages`)
- Alert thresholds:
  - `tests/Api/V3/ControllerTest.php` (`testMetricsIncludesAlertsAndTracing`)

## Migration/Compatibility Controls
- Deprecation is gated by adoption threshold:
  - runtime headers in `api/v3/index.php`
  - documented in `docs/sync-migration-guide.md`

## Command Evidence

Executed:

```bash
php -l api/V3/Support/ServerStateStore.php
php -l api/V3/Support/SyncEngine.php
php -l api/V3/Controllers/SyncController.php
php -l api/V3/Controllers/SystemController.php
php -l api/V3/Controller.php
php -l api/v3/index.php
php -l tests/Api/V3/ControllerTest.php
php -l tests/Api/V3/RouterTest.php
php -l tests/Api/V3/SyncFeaturesTest.php
php -l 202-config/Database/Schema/TableRegistry.php
php -l 202-config/Database/Tables/SyncTables.php
php -l 202-config/Database/Tables/UserTables.php
php -l 202-config/Database/SchemaInstaller.php
php -l 202-config/functions-upgrade.php
cd go-cli && go test ./... && go build ./... && go vet ./...
```

Result summary:

- PHP lint: passed for all touched files.
- Go test/build/vet: passed.
- PHPUnit binary unavailable in this runtime (`vendor/bin/phpunit` missing), so PHPUnit execution is pending in CI/runtime with PHPUnit installed.
