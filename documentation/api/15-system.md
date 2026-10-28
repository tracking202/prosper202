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
| `GET` | `/system/login-log` | Admin + `access_to_settings` | Sign-in attempts, newest first: passed, failed or not completed |
| `GET` `PUT` | `/system/retention` | Admin + `access_to_settings` | Automatic click-data deletion (days), and any scheduled one-off deletion |
| `POST` | `/system/retention/delete-before` | Admin + `access_to_settings` | Schedule the deletion of click data from before a day (`?dry_run=1` previews) |
| `GET` `PUT` | `/system/isp-lookup` | Admin + `access_to_settings` | MaxMind ISP and carrier lookup |
| `GET` | `/system/integrations` | Admin + `access_to_api_integrations` | The notification URLs to paste into ClickBank, JVZoo, Zaxaa, Slack, PayKickstart |
| `GET` | `/system/traffic-source-owners` | Admin + `access_to_settings` | Traffic sources another user's traffic source accounts name (taken by Setup's rename before 1.9.79); Account › Settings shows the same in a notice and a panel |
| `POST` | `/system/traffic-source-owners/repair` | Admin + `access_to_settings`, and a user who may act on both users | Move one back, or every one the report names a user for |
| `POST` | `/system/traffic-source-owners/split` | Admin + `access_to_settings`, and a user who may act on each user it names | Give each user whose accounts name a source a copy of their own: what clears a source accounts of more than one user name |

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

`GET /system/dataengine` — returns data engine job status, the count of pending dirty hours to process, and `reclick_backfill`: how far the cron job has brought the clicks re-clicked before 1.9.79 to one visit each (`percent`, `reclicked_clicks`, and a `note` saying what reports show meanwhile), or `null` when there is nothing left to bring over. When a click fails, the walk stops at it and every run tries it again: `stalled_at_click_id` names it, `failed_runs` counts the runs it failed with an error of its own and `failing_since` says when the first failure was, and the `note` says the walk is stopped; all three clear once a run gets past it. A lock wait, a deadlock, a timeout, a lost connection or a server that is full or read-only stops the run the same way and counts in none, however long it lasts. A click that has failed with an error of its own in 10 runs over at least an hour is stepped over, so it cannot hold back the clicks after it: `skipped_clicks` counts those, `last_skipped_click_id` names the latest, the server's error log names each (`p202 re-click backfill: click … stepped over`), and reports count each at every one of its visits. A walk that finished with a click stepped over still answers, with `in_progress` false, rather than `null`.

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

`cron_last_ran_at` is the last run a scheduler started: a cron job's or
AutoCron's, as Settings' "Cron job last ran" tile reads it. Every signed-in
page pings the cron as it loads, and from 1.9.78 that run does the cron's
work without being counted, so an install with neither a cron job nor
AutoCron answers `null`, or an old time, however busy its pages are.
`GET /system/cron`'s `recent_logs` are the same record; its `jobs` are the
tiers that ran, whoever started them.

### Sign-in log

`GET /system/login-log?limit=50&status=not_completed`

| Parameter | Type | Default | Description |
| --------- | ---- | ------- | ----------- |
| `limit` | integer | 50 | 1-500; anything else is a `422` |
| `status` | string | every attempt | `passed`, `failed` or `not_completed`: that outcome's attempts only; anything else is a `422` |

Each row is `login_id`, `user_name` (as typed), `ip_address`, `login_time`
(unix seconds), `status` and `login_success`, newest first:

```json
{ "data": [ { "login_id": 812, "user_name": "ana", "ip_address": "198.51.100.7", "login_time": 1792039194, "login_success": false, "status": "not_completed" } ], "meta": { "limit": 50, "status": null } }
```

`status` is how the attempt ended: `passed`, `failed` (the user name or
password was wrong or left out, or the database failed before the password
was checked), or
`not_completed` — the password was right, but a database failure stopped the
sign-in (recording it, starting the session, or storing a re-hashed legacy
password) and the user was asked to try again. The sign-in throttle (10
failures per user name, 50 per address, in 15 minutes) counts `failed` alone,
so a database hiccup never locks anyone out. `login_success` is true for
`passed` alone. A row holding a value no version of Prosper202 writes answers
`status: "unknown"`, which no `status` filter selects. `meta.status` is the
filter asked for, or null.

Nothing else from `202_users_log` is served: it also holds each attempt's
request snapshot and, on installs from before the password was filtered, the
password typed.

### Click-data retention

The cron job reads both settings from user 1's preferences, so they are read
and written there, whoever calls; they apply to every account's clicks.

`GET /system/retention`

```json
{ "data": { "auto_delete_days": 180, "scheduled_deletion": { "before": "2026-01-01", "cutoff_time": 1767225600, "through_click_id": null, "clicks_remaining": 1500 } } }
```

`scheduled_deletion` is null when no one-off deletion was scheduled; while one
is, the cron job deletes every click all of whose rows were recorded before
`cutoff_time` (`before` is the day it falls on in your time zone), and
`clicks_remaining` counts those still there. An install that scheduled one
with an earlier version shows `through_click_id` instead, with `cutoff_time`
null: the cron job deletes the clicks below that id, as it was scheduled,
`before` is the day of the click the id names, and `clicks_remaining` counts
the clicks below it.

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

Both deletions (this one and the one-off below) select the clicks the same
way, and go through
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
   written. The answer names `cutoff_time` — midnight that begins `before`,
   in the caller's time zone — and counts what the cron job would delete
   now, by the selection it deletes by: `clicks`, and their rows per click
   table (`rows`).
2. **Schedule** with `{"before": "2026-01-01", "cutoff_time": 1767225600}`.
   It is stored only if the day still begins at that time in the caller's
   zone (the account's zone can change in between); otherwise nothing is
   written and the answer is a `409` with `details.current_cutoff_time`.
   Without `cutoff_time` the write is a `422`. When no click is from before
   the day, nothing is written (`scheduled: false`).

`before` must be a calendar day written `YYYY-MM-DD`, not after today. A
time zone the server does not know is a `409`, never read as UTC. The cron
job deletes every click all of whose rows were recorded before
`cutoff_time`, by the same rule as the automatic deletion: a click visited
again on or after the day (a rotator re-click gives it a new row) is kept
whole. A new schedule replaces the one before it. Setup data is kept. The
time stays set when the deletion is done (`clicks_remaining` reads 0), and
a Slack webhook, if set, hears once, from the run that deletes the last of
it.

This deletion used to store a click id — the newest click at or before
the day — and delete every click below it. Ids are not in time order, so
that deleted a click re-clicked after the day and kept the newest click
from before it. `through_click_id` is no longer part of the request or the
preview; an id an install scheduled that way is still carried out (it is
all that was stored, so the day cannot be recovered from it), and
`GET /system/retention` shows it until a new schedule replaces it.

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
none); the secrets are never served — `PUT /users/{id}/preferences` sets them,
and its `GET` answers each only as `{"stored": true}` or `{"stored": false}`.

## Traffic sources taken before 1.9.79

Before 1.9.79, renaming a traffic source on Setup › Traffic Sources wrote
`UPDATE 202_ppc_networks SET user_id = <the renamer>, … WHERE ppc_network_id =
<the posted id>` and checked nothing: a user who could open Setup could rename
another user's source and take it into their own account. Its traffic source
accounts stayed with their user, whose `GET /ppc-networks/{id}` then answered
`404`, and whose reports put each click those accounts recorded from then on
under no traffic source: a report row takes the source only from the click's
own account. 1.9.79 refuses the
rename of another user's source; these routes find the sources taken
before, and move one back, or split one, on an operator's say-so. Nothing is
moved by itself, and the upgrade neither looks nor moves.

`GET /system/traffic-source-owners`

```json
{
  "data": {
    "sources": [
      {
        "ppc_network_id": 7, "ppc_network_name": "Google Ads", "removed": false, "ppc_network_time": 1100000000,
        "owner": { "user_id": 3, "user_name": "taker", "user_deleted": false, "user_active": true, "ppc_accounts": 0, "removed_ppc_accounts": 0,
                   "ppc_account_ids": [], "last_ppc_account_time": null, "clicks": 0, "conversions": 0 },
        "named_by": [
          { "user_id": 2, "user_name": "owner", "user_deleted": false, "user_active": true, "ppc_accounts": 2, "removed_ppc_accounts": 1,
            "ppc_account_ids": [4, 5, 6], "last_ppc_account_time": 1000000000, "clicks": 1200, "conversions": 14 }
        ],
        "repair_to_user_id": 2,
        "ambiguous": [],
        "custom_variables": 2
      }
    ],
    "repairable": 1,
    "ambiguous": 0
  }
}
```

A source is listed when traffic source accounts (live or removed) of a user
other than its own name it: `owner` is the user it is in now, `named_by` each
user whose accounts name it. For each, the accounts, the clicks recorded on
them (each click once, whatever its visits) and the conversions that count on
those clicks (a reversal is not counted again). `custom_variables` is how
many live custom variables the source has, which a split copies. The takeover
changed nothing else, so a source taken with no account under it leaves no
sign and is not listed.

The same rows have other origins: before 1.9.77 the API put a traffic source
account under whatever source it was sent (an account a user made on a source
an admin set up for them), and an admin may have moved a source on purpose.
`repair_to_user_id` is the one user a repair takes the source to without being
told; it is null, and `ambiguous` lists why, when:

- `owner_has_accounts`: the user it is in has accounts on it too;
- `several_users`: accounts of more than one other user name it;
- `saved_after_rename`: an account of another user was saved after the source
  was last saved, or in the same second (the times are whole seconds). The
  rename stamped `ppc_network_time`, so the accounts of the user a source was
  taken from were all saved before it, while an account the API put on another
  user's source was saved after that source. (A source renamed by its own user
  after that reads as taken.)
- `no_such_user`: a user it names has no user record;
- `deleted_user`: a user it names is deleted. Nobody signs in as a deleted
  user, and a source moved into that account would leave the report for good,
  so no repair moves a source there, named or not (`to_user_id` naming one is
  a `422`).

A switched-off user (`user_active: false`, Account › Users' Active switch) can
be switched on again, and the source is still theirs, so it is shown and does
not stop a repair.

`POST /system/traffic-source-owners/repair`

- `{"ppc_network_id": 7}` moves the source to its `repair_to_user_id`.
- `{"ppc_network_id": 9, "to_user_id": 4}` moves it to a user you name, who
  must be one of its `named_by` users; the only way to move an ambiguous one.
- `"owner_user_id"` and `"repair_to_user_id"` send back the report's
  `owner.user_id` and `repair_to_user_id` as you read them (send
  `repair_to_user_id` only without `to_user_id`). The report can change
  between your read and the request: another admin's repair, an
  account added or moved. With them, a source that is in another account by
  now, or would go to another user, is a `422` naming the field rather than a
  move from or to a user you were never shown:
  `{"ppc_network_id": 7, "owner_user_id": 3, "repair_to_user_id": 2}`. The
  CLI always sends them.
- `{"all": true}` moves every source the report names a `repair_to_user_id`
  for as the server reads it then, and answers the others under `skipped`
  with a `reason`: `ambiguous`, `not_permitted` (the caller may not act on one
  of the users) or `changed`. It takes neither field above, so it can move a
  source that came into the report after you read it; the CLI's `--all` sends
  one request per source it showed you instead.

```json
{ "data": { "repaired": [ { "ppc_network_id": 7, "ppc_network_name": "Google Ads", "removed": false, "from_user_id": 3, "to_user_id": 2, "ppc_accounts": 3, "queued_ppc_accounts": 3 } ], "skipped": [] } }
```

Each source moves in a transaction of its own: the two users, then the source,
are locked, everything is read again, and nothing is written unless it still
leads to the same two users. The users are locked first, as a user's deletion
locks the user first, so a repair waits for a user being deleted and then
reads them as deleted. The accounts naming the source are read, not locked: a
click's report rollup share-locks the click's account and then its source, so
a repair holding the source and waiting for an account deadlocked with it.
Only the source's own user can add an account to it, and one added meanwhile
leaves the source in the report. Only the source's `user_id` changes: its name stays
as the rename left it, its custom variables go with it, and a removed source
stays removed. Every account naming it is queued for the cron job's report
rollup, so the report rows take the source from it again on the cron job's
next run. Each move is written to the PHP error log: `Prosper202 traffic
source repair: traffic source 7 ("Google Ads") moved from user 3 to user 2,
whose 3 traffic source account(s) name it, at user 1's request`.

A source the report does not list, an ambiguous one without `to_user_id`, a
`to_user_id` that is not one of its `named_by` users, an `owner_user_id` or
`repair_to_user_id` the report no longer gives, and a source that changed
after it was read are each a `422` naming the field; nothing is moved.
With `all`, a failure after some sources moved is a `500` naming them; sending
the request again moves only what the report still names a user for.

`POST /system/traffic-source-owners/split`

No move clears a source marked `owner_has_accounts` or `several_users`:
whichever user it is in, another user's accounts still name it, and the
report lists it again. The split leaves it where it is and gives each other
user a copy of their own.

- `{"ppc_network_id": 9}` leaves source 9 in the account it is in, untouched,
  and gives each of its `named_by` users a new source in their own account,
  with its name, its removed mark and its live custom variables (a removed
  variable is not copied). That user's traffic source accounts naming it,
  removed ones too, move onto their copy; a report filter of theirs set to the
  source (`user_pref_ppc_network_id`) is set to the copy; and those accounts
  are queued for the cron job's report rollup. The source's own user's
  accounts stay on it.
- `"owner_user_id"` and `"copy_to_user_ids"` send back the report's
  `owner.user_id` and its `named_by` user ids as you read them:
  `{"ppc_network_id": 9, "owner_user_id": 1, "copy_to_user_ids": [4, 6]}`. A
  source that is in another account by now, or would give copies to other
  users, is a `422` naming the field rather than copies for users you were
  never shown. The CLI always sends them.
- There is no `to_user_id` and no `all`. To have the source end up with
  another user, move it there first (`repair` with `to_user_id`); the user it
  leaves, if their accounts name it, is then listed under `named_by`, and a
  split gives them a copy.

```json
{ "data": { "ppc_network_id": 9, "ppc_network_name": "Shared", "removed": false, "owner_user_id": 1,
  "copies": [ { "user_id": 4, "ppc_network_id": 21, "ppc_account_ids": [10], "custom_variables": 2 },
              { "user_id": 6, "ppc_network_id": 22, "ppc_account_ids": [11], "custom_variables": 2 } ],
  "queued_ppc_accounts": 2 } }
```

The clicks already on the moved accounts are reported under the copy once the
cron job has re-rolled them: until the split, a report row took no source for
them (it takes the source only from the click's own account, and the source
was another user's), so the breakdown by traffic source left them out. Pages
that look a click's source up through its account (the Overview, Visitors,
`GET /clicks`) show the copy at once. The custom variable values recorded on
those clicks before the split stay recorded against the source's own
variables, which the copy does not share: Analyze › Variables lists them under
the copy with the names those variables have, beside the values recorded
against the copy's own from then on.

It is one transaction: the users (a user being deleted is waited for, then
read as deleted), then the accounts that move, by id, then the source are
locked, in the order a click's report rollup takes an account and then its
source, so the two do not deadlock; everything is read again, and nothing is
written unless it still resolves the same way. A failure part way leaves
nothing written. A rebuild of an hour that reads a click of the source's own
user and then one of a user who gets a copy takes the rows in that order
instead, and can deadlock with a split: measured, the server rolled the
rebuild back, and the cron job takes its hour again on its next run. If the
split is the one rolled back, it answers `500` with nothing written, and can
be sent again. Each split is written to the PHP error log: `Prosper202
traffic source split: traffic source 9 ("Shared") stays in user 1's account;
user 4's 1 traffic source account(s) now name its copy, traffic source 21;
user 6's 1 traffic source account(s) now name its copy, traffic source 22; at
user 1's request`.

A source the report does not list, one a `named_by` user of which has no user
record or is deleted (nothing is copied into a deleted account), an
`owner_user_id` or `copy_to_user_ids` the report no longer gives, and a source
that changed after it was read are each a `422` naming the field; nothing is
copied.

All three routes need an Admin or Super user key whose role has
`access_to_settings`, like the rest of Account › Settings: the report names
every user's sources. A repair moves a source out of one user's account into
another's, so the key's user must also be one that may act on both, as
Account › Users allows: only user 1 moves a source into or out of user 1's
account, and an Admin's or the Super user's account needs
`add_edit_delete_admin` (a `403` otherwise). A split asks the same of the user
the source is in and of each user who gets a copy. Reads need `system:read`,
the repair and the split `system:write`. None can be staged, and neither the
repair nor the split has a preview (`?dry_run=1` is a `422`): the report is
what they would do. The CLI is `p202 system traffic-source-owners show`,
`repair` and `split`.

### On Account › Settings

An owner who uses neither the API nor the CLI finds the same report on
Account › Settings. The page shows it to the users these routes answer: an
Admin or the Super user whose role has `access_to_settings`, asked through the
same checks, in the same order. When the report lists a source, a notice above
the tiles says how many are listed and how many need a user chosen; its
**Review** link opens the panel (`administration.php?traffic_sources=review`,
landing on `#traffic-source-owners`), which lists each source as `GET` does:
the user it is in, each user whose accounts name it with their accounts,
clicks and conversions, and the user it goes to or why you choose. The notice
reads only whether anything is listed; the clicks and conversions are counted
when the panel is open.

Each row's **Move back…** opens a confirmation that names the source, the user
it leaves and the user it goes to, or, for an ambiguous source, asks which of
the users whose accounts name it, as `to_user_id` does. Saying yes posts what
`POST /system/traffic-source-owners/repair` takes, with the `owner_user_id`
and `repair_to_user_id` the panel showed, and the page runs the same code: the
same check that you may act on both users (a user you may not is not offered),
the same locks, and the same refusal, said in the panel the page lands on,
when the source has changed since the page was read. The form carries the
session's token, and a post without it moves nothing. A source both users have
traffic source accounts on stays listed wherever it is: whichever user it is
in, the other's accounts name it.

For such a source (`owner_has_accounts` or `several_users`) the row also has
**Split…**, whose confirmation names the user it stays with, each user who
gets a copy with the accounts that move onto it, and what is copied. Saying
yes posts what `POST /system/traffic-source-owners/split` takes, with the
`owner_user_id` and `copy_to_user_ids` the panel showed, and the page answers
as it does a move: what was copied, or why nothing was. The split is not
offered when it would be refused for a user it names (one you may not act on,
or one who is deleted or has no record), and the row says why.
