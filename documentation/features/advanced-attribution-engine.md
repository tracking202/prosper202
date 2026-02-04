# Advanced Attribution Engine Rollout Checklist

This guide tracks the remaining work to deliver the Advanced Attribution Engine as a production-ready feature. The list is organised so teams can work top-to-bottom, updating the checkboxes as milestones are completed.

## Quick Overview
- **What’s shipped already:** database schema, PHP domain layer (strategies, repositories, job runner), CLI rebuild script, initial PHPUnit coverage, API surface scaffolding.
- **What’s left:** production-grade strategy logic, UI/API wiring, scheduling, exports, permissions, observability, documentation polish, and automated testing reliability.

## Task Checklist

### 1. Attribution Logic & Data Integrity
- [x] Define database schema (models, snapshots, touchpoints, settings, audit) and installer/upgrade paths.
- [x] Implement core repositories (MySQL + fallbacks) and service factory wiring.
- [x] Provide baseline strategies (last-touch, time-decay, position-based, assisted) and job runner.
- [x] Implement per-strategy touchpoint credit calculations (last-touch, time-decay, position-based, assisted).
- [x] Extend position-based and assisted models to support true multi-touch journeys when touch history is available.
  - Implemented shared journey persistence via `202_conversion_touchpoints`, hydrated repositories, and regression coverage for multi-touch batches. Historical conversions can be retrofitted using `202-cronjobs/backfill-conversion-journeys.php`.
- [x] Add validation for model weighting configs and scope rules (reject invalid payloads with actionable errors).
- [x] Add batching/pagination to conversion fetches to protect memory during large backfills.

### 2. Scheduling & Operations
- [x] Provide CLI entry point (`202-cronjobs/attribution-rebuild.php`).
- [x] Register cronjobs (`202_dataengine_job`/`202_cronjobs`) with safe defaults and overlapping-run protection.
- [x] Log attribution job runs and errors (`202_cronjob_logs`, audit hooks).
- [x] Add system check to `202-account/ajax/system-checks.php` for prerequisites (PHP version, cron, schema).

### 3. API & UI Experience
- [x] Complete REST endpoints for model CRUD, snapshot retrieval, sandbox comparisons (auth + validation; pagination to follow).
- [x] Build dashboard/report screens in `202-account` & `202-charts` (model selector, comparison cards, charts).
  - Delivered dedicated `202-account/attribution.php` with filters, KPI cards, chart containers, sandbox controls, and export listings powered by `202-js/attribution.js`.
- [x] Implement sandbox workflow UI (model toggles, confidence hints, promote-to-default action).
  - Sandbox comparison state now wired to `/api/v2/attribution/sandbox` with promote controls and empty-state messaging surfaced in the dashboard panel.
- [x] Provide CSV/XLS exports + webhook integrations for snapshots.
  - Added `/api/v2/attribution/models/:id/exports`, background worker `202-cronjobs/attribution-export.php`, and download bridge `202-account/attribution-export.php` backed by the `202_attribution_exports` table.
- [x] Surface documentation links/tooltips inside UI.

### 4. Security, Permissions & Audit
- [x] Extend `202_permissions`/`202_roles` with attribution-specific capabilities.
- [x] Enforce role checks in API.
- [x] Record attribution actions in `202_attribution_audit`.
- [x] Ensure data deletion/retention flows purge attribution tables (GDPR/CCPA compliance).

### 5. Observability & Performance
- [x] Add instrumentation (execution time, processed rows, error counts) and surface in admin diagnostics.
- [ ] Cache common snapshot queries (Memcached/Redis) with invalidation on recompute.
  - Plan: Introduce cache facade in snapshot repository, key by model+scope+hour window, and flush relevant keys after each rebuild batch.
- [x] Document guidance for scaling (indexing, resource usage, cron frequency).
  - Plan: Publish an “Operating at Scale” doc summarising recommended MySQL indexes, cron cadence, and batch sizing along with infrastructure sizing tips.

### 6. Testing & Tooling
- [x] Introduce PHPUnit coverage for strategies and job runner (mock repositories).
- [x] Resolve PHPUnit bootstrap issue (vendor mismatch) and ensure suites run locally/CI.
- [x] Add strategy-unit tests for position-based and assisted variants once credit logic is refined.
- [ ] Add integration tests for API endpoints + UI smoke tests (Cypress/Playwright if available).
  - API regression coverage for exports and scheduling now lives under `tests/Attribution/Api` and `tests/Attribution/Export`; front-end smoke automation remains outstanding.
- [x] Update `phpstan`/lint config to include attribution namespaces.

### 7. Documentation & Enablement
- [x] Write customer-facing setup & user guides (`documentation/tutorials-and-guides`).
- [x] Produce API reference updates (`documentation/api/`) with endpoint details and payload samples.
- [x] Draft release notes & upgrade instructions, highlighting new cron requirements.
- [x] Create troubleshooting guide (common errors, cron failures, data reconciliation tips).

## Attribution Dashboard & Export Workflow
- **Accessing the dashboard:** Navigate to **Account ▸ Attribution** to open `202-account/attribution.php`. Model filters, date range selectors, KPI cards, and chart regions pull data from `/api/v2/attribution/metrics` and `/api/v2/attribution/models` via `202-js/attribution.js`.
- **Using the sandbox:** Select comparison models in the sandbox panel to trigger `/api/v2/attribution/sandbox`. The UI surfaces placeholder insights until the computation engine backfills live metrics; promote actions dispatch `PATCH /api/v2/attribution/models/:id`.
- **Scheduling exports:** Use the export drawer on the dashboard to request CSV/XLS snapshots. Requests call `/api/v2/attribution/models/:modelId/exports`, enqueueing jobs in `202_attribution_exports` and generating download tokens served through `202-account/attribution-export.php`.
- **Processing pipeline:** The cron worker `202-cronjobs/attribution-export.php` claims pending jobs, streams snapshot data through `SnapshotExporter`, and issues optional webhooks using `WebhookDispatcher`. Export files are processed using chunked encoding to minimize memory usage, with a 10MB size limit for webhook dispatch. Logs appear in cron output, and job status updates render in the dashboard export history list.

## How to Use This Checklist
1. Review each section before beginning implementation work for the sprint.
2. When a task is completed, update the checkbox to `[x]` and, if needed, add short notes or links to commits/PRs.
3. Keep related documentation in sync, especially when touching API/UI or operational tasks.
4. Once all checkboxes are marked, the feature is ready for GA release.

## Contact
Questions or updates?  (`#prosper202-attribution
