# Scaling the Advanced Attribution Engine

This note summarises infrastructure and operational guidance for high-volume Prosper202 deployments using the Advanced Attribution Engine.

## Database Tuning
- **Indexes:**
  - `202_attribution_snapshots` – ensure composite index on (`model_id`, `date_hour`, `scope_type`, `scope_id`) is present (installed by default). Add `created_at` index if snapshot purges lag.
  - `202_attribution_touchpoints` – verify indexes on `snapshot_id` and `click_id` to speed lookup/deletion.
- **Buffer pool:** Size InnoDB buffer pool to fully cache snapshot/touchpoint working sets during cron runs.
- **Partitioning:** For very high volumes, consider partitioning `202_attribution_snapshots` and `202_attribution_touchpoints` by `date_hour` or `model_id`.

## Cron Scheduling
- Run `202-cronjobs/attribution-rebuild.php` hourly. Stagger across environments to avoid overlapping with DataEngine heavy jobs.
- For multi-day backfills, run multiple invocations with explicit `--start/--end` windows (≤ 24h each) to keep runtime manageable.

## Batching & Memory
- Current batches pull 5,000 conversions at a time. Adjust `AttributionJobRunner::BATCH_LIMIT` if you consistently process smaller/larger datasets.
- Monitor MySQL connections for `max_allowed_packet` issues; a 32MB packet size is safe for most deployments.

## Caching
- Implement snapshot caching (see checklist) using Memcached/Redis once reports are wired. Recommended TTL: 5 minutes, invalidated after rebuild job completes.

## Monitoring & Logs
- Each rebuild logs a `prosper_log('attribution_job', ...)` entry containing conversions processed, revenue, cost, and window.
- Audit entries in `202_attribution_audit` provide a durable history of rebuilds and model changes for compliance.
- Add MySQL slow query logging around cron windows to catch regressions.

## Resource Sizing
- **Cron host:** PHP CLI 8.3+, 512MB memory, ability to run hourly without throttling.
- **Database:** anticipate additional write load—snapshots and touchpoints roughly mirror conversion volume. Ensure enough IOPS if using cloud databases.

## High-Availability Considerations
- Take regular backups of all `202_attribution_*` tables.
- If running multi-node web tier, ensure cron executes on a single leader node to avoid overlap.

Refer back to the [Advanced Attribution Engine Setup](./14-advanced-attribution-engine.md) for installation steps and the troubleshooting guide for operational support.
