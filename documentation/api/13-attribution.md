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

Unknown parameters are refused (422), as is a model that is not the
account's; an inactive or invalid model answers 409 with the reason.

Each row: `key`, `name`, `clicks` and `cost` (the dimension's own clicks in
range), `attributed_conversions` (Σ credit), `attributed_revenue`, `roi`,
`assisted_conversions` (conversions in range whose journey has a touch in the
dimension before the converting click). Money is an exact decimal string. The
credited range is the conversions' `conv_time`; the cost range is the clicks'
`click_time`. `totals.attributed_revenue` is the counted value of the
conversions in range, the same under every model.

## Examples

```bash
curl -H "Authorization: Bearer $KEY" -X POST "$URL/api/v3/attribution/models" \
  -d '{"model_name":"Decay 24h","model_type":"time_decay","weighting_config":{"half_life_hours":24}}'

curl -H "Authorization: Bearer $KEY" "$URL/api/v3/attribution/reports/breakdown?group_by=traffic_source&model_id=2"

curl -H "Authorization: Bearer $KEY" "$URL/api/v3/attribution/conversions/1234/journey"
```

The CLI equivalents are `p202 attribution model …`, `p202 attribution
breakdown`, `p202 attribution journeys`, `p202 attribution journey <id>` and
`p202 attribution queue`.
