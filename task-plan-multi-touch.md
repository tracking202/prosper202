# Multi-touch Journey Support Plan

## Completed
- [x] Extend conversion domain objects to expose optional ordered touch journeys.
- [x] Refactor position-based strategy to distribute credit across journey touchpoints.
- [x] Update assisted strategy to attribute assists across journey touchpoints.
- [x] Refresh unit tests to cover multi-touch scenarios and adjust expectations.
- [x] Run attribution test suite to validate changes.

## Remaining Work

### 1. Persist full touch journeys for each conversion
- [x] Expand MySQL schema to capture ordered touch sequences (e.g., append journey JSON column or normalize via `202_clicks_advance` mapping).
- [x] Update ETL/cronjobs that populate conversion tables to insert all qualifying touches for a user.
- [x] Backfill existing conversions with historical touch journeys using migration script.

### 2. Hydrate journey data in repositories
- [x] Update `MysqlConversionRepository::fetchForUser()` and related data access layers to join touch history tables and populate `ConversionRecord::$journey`.
- [x] Add caching layer safeguards to prevent stale single-touch records when journeys change.
- [x] Document new repository contracts to clarify required journey ordering and dedup rules.

### 3. Extend attribution strategies for full journeys
- [x] Ensure position-based, assisted, and time-decay strategies can apportion credit across arbitrarily long journeys.
- [x] Add guard rails for missing/partial journeys (fallback to last touch with telemetry).
- [x] Provide configuration toggles to enable/disable multi-touch per advertiser or campaign.

### 4. Validate with integration tests and analytics
- [x] Add integration tests that simulate multi-touch conversions end-to-end through the repository layer.
- [x] Instrument analytics dashboards to display multi-touch credit allocation and monitor anomalies.
- [x] Coordinate QA runbook covering new schema migrations, data hydration, and reporting.
