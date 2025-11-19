# Advanced Attribution Engine

This guide walks through enabling and operating the new Advanced Attribution Engine. It covers prerequisites, configuration, job scheduling, reporting, and troubleshooting so teams can roll the feature out with confidence.

## 1. Prerequisites
- Prosper202 version 1.9.56 or higher (installers/upgraders include the attribution schema and cron job).
- PHP 8.3 or higher with PDO/MySQLi extensions enabled.
- Access to the command line for running `php` and scheduling cron tasks.
- Users require the new permissions:
  - `view_attribution_reports` (read only access)
  - `manage_attribution_models` (create/edit models, run sandbox comparisons)
  Super Users and Admins receive both permissions automatically after upgrade; assign as needed under **Administration → User Management**.

## 2. Database & Migration Checks
The installer/upgrade scripts create the following tables as part of the rollout:
- `202_attribution_models`
- `202_attribution_settings`
- `202_attribution_snapshots`
- `202_attribution_touchpoints`
- `202_attribution_audit`

Use the new system check (Dashboard → System Checks) to verify prerequisites:
- PHP version ≥ 8.3.0
- Attribution rebuild cron has logged a recent run (`202_cronjob_logs.id = 2`).

## 3. Scheduling the Rebuild Cron
The core service is executed via `202-cronjobs/attribution-rebuild.php`.

**Recommended schedule**: run hourly.
```
# Example cron entry (run at 15 minutes past every hour)
15 * * * * /usr/bin/php /path/to/prosper202/202-cronjobs/attribution-rebuild.php >> /var/log/prosper202/attribution-cron.log 2>&1
```

### CLI Options
- `--start=<timestamp>`: override start window (defaults to `now - 86400`).
- `--end=<timestamp>`: override end window (defaults to `time()`).
- `--user=<user_id>`: limit processing to a specific user (repeat flag for multiple users).

Successful runs insert/update `202_cronjob_logs.id = 2`. Errors are surfaced in STDERR and written to the specified log file; the system check will warn if no run has occurred within the last 24 hours.

## 4. Managing Attribution Models
1. Navigate to **Setup → Attribution Models** (UI wiring forthcoming – for now manage via API or database).
2. Create models with appropriate weighting:
   - **Last Touch / Assisted** require no weighting config.
   - **Time Decay** accepts `half_life_hours` (positive integer).
   - **Position Based** accepts `first_touch_weight` and `last_touch_weight` (0–1, combined ≤ 1). Remaining weight is auto-assigned to middle touchpoints.
   - **Algorithmic** reserved for future machine-learning integrations; ensure configuration payloads are present.
3. Mark one model as default per user/campaign scope via `202_attribution_settings` or the upcoming UI.

Validation logic rejects invalid weighting payloads. Use PHPUnit suite `tests/Attribution/ModelDefinitionValidationTest.php` if extending rules.

## 5. API Endpoints (Preview)
New REST endpoints are exposed under `/api/v2/attribution`:
- `GET /attribution/models` – list available models (supports `type` filter, requires `manage_attribution_models` or `view_attribution_reports`).
- `GET /attribution/models/{modelId}/snapshots` – retrieve hourly snapshots for a model.
- `GET /attribution/sandbox` – run side-by-side comparisons across selected models.

All endpoints require authentication and will enforce role permissions once UI wiring is complete. Refer to **documentation/api/00-api-integrations.md** for payload details.

## 6. Reporting & Sandbox
- Rebuild jobs populate `202_attribution_snapshots` with hourly aggregates that can be plotted alongside existing charts.
- Touchpoint-level credits are written to `202_attribution_touchpoints` for post-hoc analysis and exports.
- The sandbox comparison (coming soon in the UI) reads from the same tables, allowing marketers to compare multiple models and promote winners.

## 7. Exports & Integrations (Planned)
- CSV/XLS exports are scheduled for a follow-up release; placeholders exist in the export queue tables.
- Webhooks will allow conversion events to fan out with model-specific credit.

## 8. Troubleshooting
See the dedicated [Advanced Attribution Troubleshooting Guide](./15-advanced-attribution-troubleshooting.md) for step-by-step diagnosis. Common issues include:

| Issue | Resolution |
| ----- | ---------- |
| System check warns about cron not running | Verify cron entry, run the script manually, ensure `202_cronjob_logs.id = 2` updates. |
| Strategy returns zero credit | Confirm weighting config, rerun cron with `--start` to backfill window, inspect `202_attribution_touchpoints`. |
| Permission denied errors | Ensure the user’s role includes `view_attribution_reports` and/or `manage_attribution_models`. |
| Large job windows time out | Use multiple cron invocations with smaller `--start/--end` ranges; batch processing is additive. |

## 9. Validation & Testing
- Run `vendor/bin/phpunit tests/Attribution` for strategy and job-runner coverage.
- Consider staging the rollout with sample data and confirming the cron job duration in logs.
- Inspect `error_log` or system logs for entries tagged `attribution_job` to review batches processed, time window, and summary metrics each time the rebuild cron executes.

## 10. Next Steps
- Wire the administration UI to manage models directly.
- Add CSV/webhook integrations once model adoption is confirmed.
- Document sandbox workflows in product tutorials when the UI ships.
- Review [Advanced Attribution Scaling](./16-advanced-attribution-scaling.md) before rolling out to large datasets.

For questions or escalation, contact the Attribution Working Group on Slack (`#prosper202-attribution`).
