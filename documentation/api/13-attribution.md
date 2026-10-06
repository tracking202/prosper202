# Attribution API

Multi-touch attribution: for every conversion, how much credit each click in
the visitor's journey gets, under each of the account's models. The
attribution worker computes the credits from the conversion ledger's outbox
(`202-cronjobs/attribution-worker.php`, also run by the minutely cron); these
endpoints manage the models and read what it computed.

Reads need the `view_attribution_reports` role permission and writes
`manage_attribution_models` (the same permissions the session pages check),
on top of the key's `attribution:read` / `attribution:write` scope. A
delete's `?dry_run=1` preview asks for what the delete asks for
(`manage_attribution_models`), and `?staged=1` runs the same role checks
before it records anything: a key without `view_attribution_reports` is
refused `403` there too, so the preview a staged change embeds is never a
way around a read the role does not allow.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/attribution/models` | The account's models (`?type=` filters) |
| `GET` | `/attribution/models/{id}` | One model |
| `POST` | `/attribution/models` | Create a model (honors `Idempotency-Key`; supports `?staged=1`) |
| `PUT` | `/attribution/models/{id}` | Partial update |
| `DELETE` | `/attribution/models/{id}` | Delete a model and its credits (`?dry_run=1` previews); never the default |
| `GET` | `/attribution/reports/breakdown` | Attributed conversions, revenue, cost, ROI and assists by a click dimension |
| `GET` | `/attribution/reports/journeys` | Journey length, time to convert, one-touch share by browser |
| `GET` | `/attribution/conversions/{id}/journey` | One conversion's touches, the signals that linked them, every model's credit |
| `GET` | `/attribution/queue` | The worker's backlog: waiting, failing (with the error), and why each row was queued |
| `GET` | `/attribution/exports` | Export jobs, newest first (`?status=`, `?limit=`) |
| `GET` | `/attribution/exports/{id}` | One export job |
| `POST` | `/attribution/exports` | Queue an export: a breakdown written to CSV, now or at `run_at`, optionally to a webhook (honors `Idempotency-Key`; supports `?staged=1`) |
| `GET` | `/attribution/exports/{id}/download` | The export's CSV (`text/csv`) |
| `POST` | `/attribution/exports/{id}/retry` | Queue a failed export again |
| `DELETE` | `/attribution/exports/{id}` | Delete an export and its file (`?dry_run=1` previews); not while it runs |

## Models

| Type | Credit | `weighting_config` |
| ---- | ------ | ------------------ |
| `last_touch` | The converting click gets 1 | `{}` |
| `first_touch` | The oldest click in the window gets 1 | `{}` |
| `linear` | Every click gets 1/n | `{}` |
| `time_decay` | `2^(-age / half_life)`, normalised | `{"half_life_hours": 48}` (0 < h ≤ 8760) |
| `position_based` | First and last get their weights, the middle shares the rest; with two clicks the ends renormalise | `{"first_weight": 0.4, "last_weight": 0.4}` (each 0–1, together ≤ 1) |

Fields:

| Field | Type | Notes |
| ----- | ---- | ----- |
| `model_name` | string | Required on create; unique per account (by slug) |
| `model_type` | string | Required on create; one of the five above, nothing else |
| `weighting_config` | object | A JSON object, never a string. Unknown keys and non-numbers are refused (422 names the key) |
| `lookback_days` | integer | 1–365, default 30. Clicks older than this before the conversion earn nothing under this model; the converting click always does |
| `status` | string | `active` or `inactive`. `invalid` is set by the engine when a stored definition no longer validates, with `status_reason` |
| `is_default` | boolean | Every account has exactly one default. Setting it on one model moves it; unsetting it is refused, as are deleting or deactivating the default |
| `recompute_pending` | boolean | Read-only: the worker has not yet recomputed this model's credits after its last change |

Every account gets a `last_touch` default when it is created (and on upgrade).

**Conversions from before the upgrade.** An upgraded install's conversions
are brought into attribution after the upgrade, not during it: the upgrade
marks the clicks it found, and the worker (every minute, from
`202-cronjobs/index.php`) walks them in bounded chunks, queuing each lead
click at the value it held with reason `backfill`. A backfilled conversion
reports on its click's day. While the walk runs, `GET /attribution/queue`
(`data.backfill`) and the breakdown and journey reports (`meta.backfill`)
carry `{in_progress, started_at, clicks_examined, baselines_queued, percent,
note}` — reports over dates before the upgrade are incomplete until it
finishes — and the Attribution page says so; the field is `null` once it has
finished, and always on a fresh install.
Changing a model's type without sending `weighting_config` resets the config
to the new type's defaults.

A change to what a model computes (type, config, lookback, activation) is
recomputed by the worker for every attributed conversion of the account,
from the stored journeys; a lookback wider than a journey was built with
rebuilds that journey from the identity graph first. Deactivating a model
deletes its credits in the same write.

## What a journey is

The clicks carrying the converting click's canonical visitor key (the
identity graph: tracking-domain cookie, landing-page id, signed customer id —
never IP or user agent), within the journey lookback before the conversion,
across all campaigns, up to and including the converting click. Bot and
filtered clicks are excluded; at most 25 touches, newest kept (`truncated`
says so). A converting click with no visitor key is a one-touch journey
(`identified: false`).

A conversion counts when its ledger row is payable, not deleted, not
superseded, and not reversed to nothing; its value is its amount net of the
reversals that name it. A row that stops counting loses its credits; one
that starts counting again gets them back.

## Breakdown

```
GET /attribution/reports/breakdown?group_by=campaign&model_id=3&compare_model_id=4&period=last7
```

| Parameter | Default | |
| --------- | ------- | - |
| `group_by` | `campaign` | `campaign`, `traffic_source`, `landing_page`, `keyword`, `c1`–`c4`, `country`, `device`, `day` |
| `model_id` | effective | Without it each conversion is read under its campaign's `attribution_model_id` when that model is active, otherwise the account default |
| `compare_model_id` | | A second model; adds `compare_*` columns |
| `period` | last 30 days | `today`, `yesterday`, `last7`, `last30`, `last90` |
| `time_from`, `time_to` | | Unix seconds; exclusive with `period` |
| `limit` | 100 | 1–1000 |
| `offset` | 0 | Rows to skip. Rows keep the report's order (attributed revenue, highest first, then `key`) and `meta.groups` is how many there are, so a larger `offset` reads the rows past the first `limit` |
| `keys` | | Only these rows: 1–1000 row keys as `data[].key` returns them, comma-separated. Rows keep the report's order, `meta.groups` counts the matches and `totals` still cover the whole report. Every request computes the whole report, so asking for a few rows by key is one computation where paging is one per page |
| `cohort` | `conversion` | `conversion`: the conversions made in the range, credited to clicks of any age. `click`: the credit and assists that land on clicks made in the range, whenever they converted (see below) |

Unknown parameters are refused (422), as is a model that is not the
account's; an inactive or invalid model answers 409 with the reason.

Each row: `key`, `name`, `clicks` and `cost` (the dimension's own clicks in
range), `attributed_conversions` (Σ credit), `attributed_revenue`, `roi`,
`assisted_conversions` (conversions in range whose journey has a touch in the
dimension before the converting click). Money is an exact decimal string. The
credited range is the conversions' `conv_time`; the cost range is the clicks'
`click_time`. `totals.attributed_revenue` is the counted value of the
conversions in range, the same under every model.

With `cohort=click` the credited range is the `click_time` of the click each
credit lands on, and an assist is an assisting touch made in the range, for a
conversion at any time. A row is then what its own clicks in the range earned
against what they cost, which is how the classic reports count: last-touch
credit in the click cohort is the classic row's revenue. Under `group_by=day`
credit falls on the click's day. `totals` covers the credit on clicks in range
(`conversions` counts each conversion with any such credit once), so it
differs by model. A click cohort report is always computed in full, since the
report rollup is kept by conversion hour. `meta.cohort` says which cohort was
read.

## Exports

An export is a job the export runner (`202-cronjobs/attribution-exports.php`,
also run by the minutely cron) picks up once its `run_at` has come: it builds
the breakdown the job names — every group, no row limit; more than 50,000
groups fails the job with the reason rather than cutting it — writes it as
CSV, and, when the job has a `webhook_url`, POSTs the file there. Creating,
reading, retrying and deleting exports needs `view_attribution_reports`.

| Field | Default | |
| ----- | ------- | - |
| `group_by` | `campaign` | A breakdown dimension |
| `model_id` | the account default | Stored as the id, so the job reads a fixed model |
| `compare_model_id` | none | Adds `compare_*` columns |
| `period` or `time_from`/`time_to` | the last 30 days | As in the breakdown; times are JSON numbers |
| `run_at` | now | Unix seconds, at most a year ahead |
| `webhook_url` | none | See below |
| `webhook_secret` | generated | 16–255 printable characters; returned once, in the create response |

The CSV's columns are the breakdown row's: `key, name, clicks, cost,
attributed_conversions, attributed_revenue, roi, assisted_conversions` (and
`compare_attributed_conversions, compare_attributed_revenue, compare_roi`).
Money and credit are exact decimals. A text cell starting with `=`, `+`, `-`
or `@` gets a leading apostrophe, because keywords and c1–c4 come from click
URLs anyone can write.

**Webhooks.** The URL must be `https://host[:port][/path]`, spelled plainly
(no user name, fragment or backslash). The host is resolved and every
address it resolves to must be public: private, loopback, link-local (where
cloud metadata answers), carrier-grade NAT, documentation, benchmarking,
multicast and reserved ranges are refused, IPv6 unique-local and link-local
too, and an IPv4 address carried inside IPv6 (`::ffff:10.0.0.1`,
`64:ff9b::a9fe:a9fe`) is judged as the IPv4 address. Other spellings of an
address (`2130706433`, `0x7f.1`, `0177.0.0.1`) are refused by name. The check
runs when the export is saved (a 422 on `webhook_url`) and again when it is
sent; the connection goes to the address that passed, with the host pinned,
TLS verified against the host name, no proxy and no redirects. An operator
whose receiver is on their own network can allow that network with
`define('P202_WEBHOOK_ALLOW_NETWORKS', '10.20.0.0/16');` in `202-config.php`;
link-local and the metadata endpoints can never be allowed, and an entry that
does not parse stops every webhook until it is fixed.

Each delivery carries `X-P202-Export-Id`, `X-P202-Delivery-Attempt`,
`X-P202-Timestamp` and `X-P202-Signature: sha256=<hex>`, the HMAC-SHA256 of
`<timestamp>.<body>` under the export's secret. Verify it with a constant-time
comparison and refuse a timestamp more than a few minutes old. A 2xx answer
completes the export; no connection, a timeout, 5xx, 408 or 429 is retried
after one minute and then two (three runs in all); a redirect, another 4xx or
a refused address fails the export at once. The file stays downloadable
whatever the webhook did.

```bash
curl -H "Authorization: Bearer $KEY" -X POST "$URL/api/v3/attribution/exports" \
  -d '{"group_by":"traffic_source","model_id":2,"period":"last7","webhook_url":"https://hooks.example.com/p202"}'
curl -H "Authorization: Bearer $KEY" -o export.csv "$URL/api/v3/attribution/exports/7/download"
```

## Examples

```bash
curl -H "Authorization: Bearer $KEY" -X POST "$URL/api/v3/attribution/models" \
  -d '{"model_name":"Decay 24h","model_type":"time_decay","weighting_config":{"half_life_hours":24}}'

curl -H "Authorization: Bearer $KEY" "$URL/api/v3/attribution/reports/breakdown?group_by=traffic_source&model_id=2"

curl -H "Authorization: Bearer $KEY" "$URL/api/v3/attribution/conversions/1234/journey"
```

The CLI equivalents are `p202 attribution model …`, `p202 attribution
breakdown`, `p202 attribution journeys`, `p202 attribution journey <id>`,
`p202 attribution queue` and `p202 attribution export …`. The dashboard,
Account › Attribution, reads and writes through the same controller.
