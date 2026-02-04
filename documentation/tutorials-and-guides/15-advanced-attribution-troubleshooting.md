# Advanced Attribution Troubleshooting Guide

Use this reference when the Advanced Attribution Engine does not behave as expected. Work through the sections in order—the majority of issues stem from cron job gaps, permission misconfiguration, or invalid model weighting settings.

## 1. Cron & Scheduling Issues
- **Symptom:** System check warns that the attribution cron has not run in the last 24 hours.
  - Verify your cron entry (`202-cronjobs/attribution-rebuild.php`) is scheduled hourly.
  - Run the script manually: `php 202-cronjobs/attribution-rebuild.php --start=$(date -v-1H +%s)` (macOS) or `--start=$(( $(date +%s) - 3600 ))` (Linux).
  - Confirm `202_cronjob_logs` contains a row with `id = 2` and a recent timestamp.
- **Symptom:** Cron output shows database errors.
  - Ensure the CLI user has access to the project path and PHP 8.3+.
  - Check MySQL credentials in `202-config/connect.php`.
  - Review `/202-config/cronjob.log` for stack traces.

## 2. Missing or Incomplete Data
- **Symptom:** Snapshots table stays empty after cron runs.
  - Confirm conversions exist in `202_conversion_logs` for the selected time window.
  - Verify active models (`is_active = 1`) are defined for the owning user (`202_attribution_models`).
  - Confirm weighting config is valid (see Section 3).
- **Symptom:** Snapshots stop updating mid-way through a large backfill.
  - Reduce the window (`--start` / `--end`) and run cron multiple times—batching is additive.
  - Monitor MySQL process list for long-running queries; add indexes if custom filtering is heavy.

## 3. Weighting Configuration Errors
- **Symptom:** API returns “Weighting configuration required” or similar message.
  - Time Decay requires `half_life_hours` > 0.
  - Position Based requires `first_touch_weight`/`last_touch_weight` between 0–1 and sum ≤ 1.
  - Assisted and Last Touch must omit weighting config entirely.
- **Symptom:** PHP errors when updating a model.
  - Ensure JSON payload is an object (`{}`) rather than an array (`[]`).
  - Check for trailing commas or invalid numeric values.

## 4. Permission & Access Problems
- **Symptom:** API returns 401/403.
  - Include `apikey` query parameter or call from an authenticated browser session.
  - Assign the user role the permissions `view_attribution_reports` and/or `manage_attribution_models`.
- **Symptom:** UI elements are missing.
  - User role may lack the new permissions—update under **Administration → User Management**.

## 5. Audit & Governance
- **Symptom:** Need to trace who triggered a rebuild.
  - Check `202_attribution_audit` for `snapshot_rebuild` entries (metadata includes window, totals).
  - Model CRUD requests log `model_create`, `model_update`, `model_delete` actions with context.

## 6. Export & Webhook Issues
- **Symptom:** Export job shows "Failed" status with "exceeds maximum size" error.
  - Export files larger than 10MB cannot be dispatched via webhook to prevent memory exhaustion.
  - Solution: Use the download link instead of webhooks for large exports, or reduce the date range.
- **Symptom:** Webhook delivery fails with timeout or connection errors.
  - Verify the webhook URL is accessible from the server.
  - Check the cron logs (`202-cronjobs/attribution-export.php`) for detailed error messages.
  - Webhook requests timeout after 15 seconds—ensure the receiving endpoint responds quickly.
- **Symptom:** Export job stuck in "Processing" status.
  - Check that `202-cronjobs/attribution-export.php` is running regularly.
  - Review cron logs for PHP errors or database connectivity issues.
  - Verify the `202_attribution_exports` table is not locked or corrupted.

## 7. Performance Considerations
- Limit long backfills to at most 24h windows to keep cron runs under a few minutes.
- Run attribution cron outside peak hours if the database is resource constrained.
- Consider isolating attribution tables into their own database instance for high-volume installs.

## 8. Support
- Share `202_attribution_audit` entries and cron logs when escalating to support.
- Provide details about model configurations and cron schedules to accelerate triage.

For a full walkthrough, see [14-Advanced Attribution Engine](./14-advanced-attribution-engine.md).
