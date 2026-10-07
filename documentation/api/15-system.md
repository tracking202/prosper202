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
| `GET` | `/system/info` | Admin + `access_to_settings` | What Account › Settings shows: versions, PHP limits, memcache, clicks, database size, cron, DataEngine |
| `GET` | `/system/login-log` | Admin + `access_to_settings` | Sign-in attempts, newest first |
| `GET` `PUT` | `/system/retention` | Admin + `access_to_settings` | Automatic click-data deletion (days), and any scheduled one-off deletion |
| `POST` | `/system/retention/delete-before` | Admin + `access_to_settings` | Schedule the deletion of click data from before a day (`?dry_run=1` previews) |
| `GET` `PUT` | `/system/isp-lookup` | Admin + `access_to_settings` | MaxMind ISP and carrier lookup |
| `GET` | `/system/integrations` | Admin + `access_to_api_integrations` | The notification URLs to paste into ClickBank, JVZoo, Zaxaa, Slack, PayKickstart |

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

## Administration

The Account › Settings page (`202-account/administration.php`) and the URLs
of Account › API integrations, for this install. Each route asks for an Admin
or Super user key, like the rest of `/system`, **and** for the permission its
page asks for: `access_to_settings` (`access_to_api_integrations` for
`/system/integrations`). A Campaign manager's key is answered `403 Admin access
required.`; an Admin whose role lacks the permission is answered `403 This
account's role does not have the 'access_to_settings' permission.` Reads need
`system:read`, writes `system:write`. No write here can be staged
(`?staged=1` is a `422`), and none makes a network request.

Not available here, because the page reaches a remote Prosper202 service for
them: AutoCron (the page registers the install with the hosted cron service
before it stores the switch) and "update available" (a feed on
my.tracking202.com).

### Install details

`GET /system/info`

```json
{
  "data": {
    "version": "1.9.76",
    "schema_version": "1.9.76",
    "database_upgrade_needed": false,
    "php_version": "8.3.6",
    "mysql_version": "10.11.14-MariaDB",
    "php_safe_mode": false,
    "memcache": { "installed": false, "running": false },
    "php_limits": { "post_max_size": "8M", "upload_max_filesize": "2M", "max_input_time": "60", "max_execution_time": "30" },
    "clicks_recorded": 6,
    "database_size_bytes": 112427008,
    "cron_last_ran_at": 1791357600,
    "cron_last_ran_seconds_ago": 85,
    "dataengine": { "tasks_total": 0, "tasks_done": 0, "percent_done": 0, "minutes_left": 0 },
    "keyword_preference": "searched"
  }
}
```

`version` is the code's, `schema_version` the database's (`202_version`);
`database_upgrade_needed` is true when they differ, which is when
`202-config/upgrade.php` runs. `memcache.running` is whether the server named
by `$mchost` answers. `dataengine.minutes_left` counts one task a minute, as
the page does. `keyword_preference` is the caller's.

### Sign-in log

`GET /system/login-log?limit=50`

| Parameter | Type | Default | Description |
| --------- | ---- | ------- | ----------- |
| `limit` | integer | 50 | 1-500; anything else is a `422` |

Each row is `login_id`, `user_name` (as typed), `ip_address`, `login_time`
(unix seconds) and `login_success` (boolean), newest first. Nothing else from
`202_users_log` is served: it also holds each attempt's request snapshot and,
on installs from before the password was filtered, the password typed.

### Click-data retention

The cron job reads both settings from user 1's preferences, so they are read
and written there, whoever calls; they apply to every account's clicks.

`GET /system/retention`

```json
{ "data": { "auto_delete_days": 180, "scheduled_deletion": { "through_click_id": 940102, "before": "2025-12-31", "clicks_remaining": 1500 } } }
```

`scheduled_deletion` is null when no one-off deletion was scheduled; while one
is, the cron job deletes every click below `through_click_id`, and
`clicks_remaining` counts those still there.

`PUT /system/retention` with `{"auto_delete_days": 180}` sets the automatic
deletion: a whole number of days from 0 (keep every click) to 36500, as a
JSON integer or a string of digits. The answer adds
`previous_auto_delete_days`.

With `auto_delete_days` N, the cron job deletes every click recorded before
midnight that began the day N days ago (the server's time zone): N whole
days and today are kept. A click is deleted when all of its rows are that
old — one a rotator re-click gave a newer row is kept whole until that row
ages out. Click ids are not in time order, so the job selects the old
clicks themselves rather than an id boundary. (It used to delete the ids
below the oldest old click — in practice nothing.)

Both deletions (this one and the one-off below) go through
`Prosper202\Click\ClickRetention`: a batch of 1,000 clicks is deleted from
every click table in one transaction, for up to 20 seconds each cron run,
so a backlog drains over the following runs and no table keeps a row of a
click another has lost. The click tables are `202_clicks`,
`202_clicks_advance`, `202_clicks_record`, `202_clicks_site`,
`202_clicks_spy`, `202_clicks_tracking`, `202_clicks_variable`,
`202_clicks_rotator`, `202_cpa_trackers`, `202_google`, `202_bing`,
`202_facebook`, `202_dataengine` (the Overview's aggregate), and the
identity graph's per-click `202_identity_observations` and
`202_clicks_visitor`. Conversions, their attribution journeys and credits,
the LTV and app records and setup data are kept (`ClickRetention::KEPT`
gives the reason for each). The attribution reports' rollup is marked in
the same transaction, so it is summed again from the clicks that remain
and agrees with the Overview.

`POST /system/retention/delete-before` schedules the page's one-off "Delete
click data from before". It is irreversible, so it has two steps:

1. **Preview** with `?dry_run=1` and `{"before": "2026-01-01"}`. Nothing is
   written. The answer names `through_click_id` — the newest click at or
   before midnight that begins `before`, in the caller's time zone; null when
   no click is that old — and counts what the cron job would delete below it:
   `clicks` (`202_clicks`) and `rows` per click table.
2. **Schedule** with `{"before": "2026-01-01", "through_click_id": 940102}`.
   It is stored only if the day still names that click; otherwise nothing is
   written and the answer is a `409` with `details.current_through_click_id`.
   Without `through_click_id` the write is a `422`.

`before` must be a calendar day written `YYYY-MM-DD`, not after today. A
time zone the server does not know is a `409`, never read as UTC. The cron
job deletes every click below `through_click_id`, and then whatever rows the
other click tables still hold below it, in the batches described above;
`rows` counts each of those tables. Setup data is kept. The marker stays
set when the deletion is done (`clicks_remaining` reads 0), and a Slack
webhook, if set, hears once, from the run that deletes the last of it.

### ISP and carrier lookup

`GET /system/isp-lookup` answers `enabled` (the caller's `maxmind_isp`: the
redirects read it for the trackers the caller owns), `database_file`
(`GeoIP2-ISP.mmdb`, `GeoIPISP.dat`, or null) and `database_dir`
(`P202_GEO_DIR`, or `202-config/geo`).

`PUT /system/isp-lookup` with `{"enabled": true}` or `{"enabled": false}`
(JSON booleans only). Turning it on while no ISP database file is in place is
a `422` naming the directory. Live traffic picks a change up within five
minutes.

### Integration URLs

`GET /system/integrations`

```json
{
  "data": {
    "base_url": "https://track.example.com/",
    "integrations": [
      { "integration": "clickbank", "name": "ClickBank", "label": "INS URL", "url": "https://track.example.com/tracking202/static/cb202.php", "secret_stored": true, "verified": false },
      { "integration": "jvzoo", "name": "JVZoo", "label": "IPN URL", "url": "https://track.example.com/tracking202/static/jvzoo.php", "secret_stored": false },
      { "integration": "zaxaa", "name": "Zaxaa", "label": "ZPN URL", "url": "https://track.example.com/tracking202/static/zpn.php", "secret_stored": false },
      { "integration": "slack", "name": "Slack", "label": "Prosper202 webhook", "url": "https://track.example.com/tracking202/static/slack.php", "secret_stored": false },
      { "integration": "paykickstart", "name": "PayKickstart", "label": "IPN URL", "url": "https://track.example.com/tracking202/static/paykickstart.php", "secret_stored": null }
    ]
  }
}
```

The base is the one tracker links use: user 1's tracking domain (or this
server's name) and the install's path. `secret_stored` says whether the
caller has stored the key each notification is checked with (null: it needs
none); the secrets are never served — `PUT /users/{id}/preferences` sets them.
