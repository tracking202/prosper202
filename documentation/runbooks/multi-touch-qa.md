# Multi-touch Attribution QA Runbook

This runbook covers operational validation for the multi-touch attribution stack (journey capture, hourly rebuilds, and reporting). It is intended for release QA, on-call investigations, and maintenance of attribution data pipelines.

## 1. Schema Checks

1. Verify the core attribution and journey tables exist and match expected columns/indexes:
   ```sql
   SHOW TABLES LIKE '202\\_%attribution%';
   SHOW CREATE TABLE 202_conversion_touchpoints; -- confirms conv_id/click_id indexes
   ```
   Required structures:
   - `202_conversion_touchpoints` with `conv_id`, `click_id`, ordered `position`, and secondary indexes for conversion and click lookups.【F:202-config/functions-install.php†L224-L235】
   - Attribution tables (`202_attribution_models`, `202_attribution_settings`, `202_attribution_snapshots`, `202_attribution_touchpoints`, `202_attribution_audit`) for model metadata, defaults, hourly aggregates, credits, and audit history.【F:202-config/functions-install.php†L237-L316】
2. Confirm `202_conversion_logs` still tracks the base conversion row referenced by journeys, including `click_id`, `conv_time`, and soft-delete flag for purges.【F:202-config/functions-install.php†L203-L221】
3. For upgrades, run Dashboard → System Checks to ensure the attribution cron health check passes after schema verification.【F:documentation/tutorials-and-guides/05-upgrading-prosper202.md†L11-L16】

## 2. Feature Toggles & Configuration

1. Attribute models expose `is_active` and `is_default` toggles in `202_attribution_models`; ensure only one default per user/scope by reviewing `202_attribution_settings` mappings.【F:202-config/functions-install.php†L237-L301】
2. Use the attribution API to flip toggles without direct SQL:
   - `PATCH /api/v2/attribution/models/{modelId}` to update `is_active`, weighting config, or naming.
   - `POST /api/v2/attribution/models` to spin up test models; mark defaults via `is_default` in the payload.【F:documentation/api/00-api-integrations.md†L27-L32】
3. After toggling, re-run the rebuild cron for the affected window so hourly snapshots and credits reflect the change (see Section 5). Monitor `202_attribution_audit` for the recorded action and actor metadata.【F:202-config/functions-install.php†L304-L314】

## 3. Journey Validation

1. Spot-check new conversions:
   ```sql
   SELECT * FROM 202_conversion_touchpoints WHERE conv_id = ? ORDER BY position;
   SELECT click_id, conv_time, click_time FROM 202_conversion_logs WHERE conv_id = ?;
   ```
   Ensure positions are sequential from 0 and the converting click appears exactly once.【F:documentation/features/multi-touch-journeys.md†L7-L26】【F:202-config/Attribution/Repository/Mysql/ConversionJourneyRepository.php†L17-L168】
2. Validate hydration by loading a report or running repository code that calls `ConversionJourneyRepository::fetchJourneysForConversions`; journeys should return chronologically sorted touches with click tie-breaks by `click_id`.【F:202-config/Attribution/Repository/Mysql/ConversionJourneyRepository.php†L73-L168】
3. Confirm cache eviction: new journeys should invalidate `attribution_journey_{conv_id}` keys, preventing stale payloads from persisting in Memcache(d).【F:202-config/Attribution/Repository/Mysql/ConversionJourneyRepository.php†L61-L220】
4. When backfilling, remember the default 30-day lookback and 25-touch cap; journeys outside the window or exceeding the cap require manual review.【F:202-config/Attribution/Repository/Mysql/ConversionJourneyRepository.php†L17-L168】

## 4. Purge & Backfill Workflows

1. To surgically rebuild journeys, delete corrupted rows (the repository automatically truncates per conversion before writing new touches):
   ```sql
   DELETE FROM 202_conversion_touchpoints WHERE conv_id IN (...);
   ```
   Subsequent persistence or backfill will repopulate the journey.【F:202-config/Attribution/Repository/Mysql/ConversionJourneyRepository.php†L30-L210】
2. Run the CLI backfill utility for historical ranges:
   ```bash
   php 202-cronjobs/backfill-conversion-journeys.php --start=<epoch> --end=<epoch> --batch-size=500
   php 202-cronjobs/backfill-conversion-journeys.php --user=<user_id> --start=<epoch>
   ```
   Options support per-user scoping, rolling windows, and adjustable batch sizes; the script paginates through `202_conversion_logs`, persists journeys, and reports errors to STDERR.【F:202-cronjobs/backfill-conversion-journeys.php†L18-L118】
3. For attribution snapshot cleanup, re-run the rebuild cron with `--start/--end` covering the window after purging or modifying models to regenerate hourly aggregates.【F:202-cronjobs/attribution-rebuild.php†L1-L79】

## 5. Maintenance Cron & Dashboard Verification

1. The hourly attribution cron is required to populate snapshots/touchpoints and is the maintenance heartbeat for multi-touch QA. Recommended schedule (15 minutes past the hour):

   > **Note:** If the hourly attribution cron is not configured or fails to run, snapshots and touchpoints will become stale, attribution data will not reflect recent conversions or model changes, and dashboards/BI exports may show outdated or incomplete results. This can lead to inaccurate reporting and missed attribution events. Ensure the cron is scheduled and monitored for successful completion.
   ```cron
   15 * * * * /usr/bin/php /path/to/prosper202/202-cronjobs/attribution-rebuild.php >> /var/log/prosper202/attribution-cron.log 2>&1
   ```
   CLI options include `--user`, `--start`, and `--end` for targeted reruns.【F:documentation/tutorials-and-guides/14-advanced-attribution-engine.md†L26-L40】

2. After each deployment or incident response, confirm `Dashboard → System Checks` reports a recent run (`202_cronjob_logs.id = 2`) and that attribution widgets/charts ingest new data from `202_attribution_snapshots`/`202_attribution_touchpoints`.【F:documentation/tutorials-and-guides/14-advanced-attribution-engine.md†L22-L64】

3. Validate that marketing dashboards or BI exports consuming snapshots reflect the expected model defaults and totals once the cron run completes.【F:documentation/tutorials-and-guides/14-advanced-attribution-engine.md†L61-L64】

## 6. Rollback & Incident Response

1. **Immediate mitigation:** disable problematic models via API `PATCH /api/v2/attribution/models/{modelId}` with `{"is_active": false}` or flip the default to a safe model by setting `is_default` accordingly. These endpoints respect permissions and provide quick reversions without database access.【F:documentation/api/00-api-integrations.md†L27-L32】
2. **Data restoration:** if journeys were polluted, delete affected `conv_id` rows from `202_conversion_touchpoints` and re-run the backfill script for the impacted window/user. Monitor STDOUT progress and STDERR error summaries.【F:202-config/Attribution/Repository/Mysql/ConversionJourneyRepository.php†L30-L210】【F:202-cronjobs/backfill-conversion-journeys.php†L18-L118】
3. **Snapshot rollback:** reprocess clean data by invoking the rebuild cron with the last known good timeframe (e.g., `php 202-cronjobs/attribution-rebuild.php --start=<good_start> --end=<good_end>`). The script guards against duplicate windows via `202_cronjobs` and logs completion status.【F:202-cronjobs/attribution-rebuild.php†L1-L78】
4. **Audit & escalation:** review `202_attribution_audit` entries for changes to models/settings and attach both CLI logs to your incident report. Escalate to the Attribution Working Group if discrepancies persist after reruns.【F:202-config/functions-install.php†L304-L314】【F:documentation/tutorials-and-guides/14-advanced-attribution-engine.md†L40-L91】

Keep this runbook updated alongside attribution feature releases; revisit schema, cron guidance, and API references whenever the attribution engine evolves.
