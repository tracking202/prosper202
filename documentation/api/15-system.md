# System API

System health checks, diagnostics, and administration.

## Endpoints

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| `GET` | `/system/health` | None | Health check |
| `GET` | `/system/version` | Admin | Version information |
| `GET` | `/system/db-stats` | Admin | Database table sizes and row counts |
| `GET` | `/system/cron` | Admin | Cron job status and recent logs |
| `GET` | `/system/errors` | Admin | Recent MySQL errors |
| `GET` | `/system/dataengine` | Admin | Data engine job status and pending work |
| `GET` | `/system/metrics` | Admin | Comprehensive system metrics |

## Health Check

`GET /system/health` — no authentication required.

```json
{
  "data": {
    "status": "healthy",
    "timestamp": 1709942400,
    "api_version": "v3"
  }
}
```

| Field | Type | Description |
| ----- | ---- | ----------- |
| `status` | string | Health status, e.g. `healthy`. |
| `timestamp` | integer | Unix timestamp when the check ran. |
| `api_version` | string | API version this server implements. |

## Version Info

`GET /system/version` — returns Prosper202, PHP, MySQL, and API version strings.

## Database Stats

`GET /system/db-stats` — returns estimated row counts and total database size per table.

## Cron Status

`GET /system/cron` — returns last run times for each cron job and recent log entries.

## Errors

`GET /system/errors` — returns recent MySQL errors.

| Parameter | Type | Default | Description |
| --------- | ---- | ------- | ----------- |
| `limit` | integer | 20 | Number of errors to return (1-100) |

## Data Engine

`GET /system/dataengine` — returns data engine job status and count of pending dirty hours to process.

## Metrics

`GET /system/metrics` — returns comprehensive operational metrics:

```json
{
  "counters": {
    "jobs_started": 150,
    "jobs_succeeded": 145,
    "jobs_failed": 3,
    "jobs_partial": 2,
    "jobs_cancelled": 0,
    "bulk_upsert_created": 500,
    "bulk_upsert_updated": 200,
    "bulk_upsert_skipped": 10,
    "bulk_upsert_errors": 1,
    "conflicts": 0
  },
  "queue": {
    "queued_jobs": 2,
    "running_jobs": 1,
    "queue_lag_seconds": 45
  },
  "tracing": {
    "recent_spans": [ ... ]
  },
  "alerts": {
    "thresholds": { ... },
    "active": [ ... ]
  }
}
```
