# Prosper202 REST API v3

Prosper202 includes a full REST API (v3) for managing campaigns, tracking clicks, reporting, attribution, forecasting, user management, server-side sync, and system administration. The API returns JSON and follows standard REST conventions.

## API Endpoint

The base URL depends on your tracking domain:

```
https://[[your-Prosper202-domain]]/api/v3/
```

## Authentication

All endpoints (except `/system/health` and `/versions`) require a Bearer token:

```
Authorization: Bearer <api_key>
```

API keys are managed through **My Account > Personal Settings** in the Prosper202 UI, or programmatically via the `/users/{id}/api-keys` endpoints (see [Users](14-users.md)).

Create a separate API key for each integration so you can revoke access individually.

### API Key Scopes

A key may carry a **scope** that attenuates what it can do, independent of
the owning user's roles — an admin holding a `read` key still cannot write.
Keys without a stored scope are full-access (`*`), exactly as before scoping
existed.

Scope grammar (comma-separated tokens):

| Token | Grants |
| ----- | ------ |
| `*` | Everything (the default for keys created without a scope). |
| `read` | Every GET/HEAD endpoint. |
| `write` | Every endpoint (`write` implies `read` and `stage`). |
| `stage` | Staging writes for approval only (see [Staged Writes](#staged-writes)); implies neither reads nor writes. |
| `<area>:read` / `<area>:write` / `<area>:stage` | One route family; `<area>:write` implies `<area>:read` and `<area>:stage`. |

`read,stage` is the propose-only shape for an agent: it can read everything
and stage writes, but a person's key performs them.

Areas: `campaigns`, `aff-networks`, `ppc-networks`, `ppc-accounts`,
`trackers`, `landing-pages`, `text-ads`, `forecast-events`, `clicks`,
`conversions`, `reports`, `ltv`, `rotators`, `attribution`, `apps`, `users`,
`system`, `sync` (the `changes` and `audit` routes fall under `sync`),
`staged-changes`.

Enforcement is central: GET/HEAD requires `<area>:read`, everything else
`<area>:write` (`POST /sync/plan` counts as a read — it computes a diff
without applying it). `/capabilities`, `/versions`, and the API root stay
readable by any valid key so clients can discover what they may do. A denied
request returns `403` with a message naming the required scope. A scoped key
cannot mint a key broader than itself, and the legacy v1/v2 APIs refuse
scoped keys outright rather than over-granting. Requires the `scope` column
on `202_api_keys` (fresh installs have it; the 1.9.75 upgrade backfills it —
`features.api_key_scopes` in [capabilities](17-capabilities.md) reports
whether scoped keys can be minted).

### Role Permissions

A key acts as its user, and the user's **role** limits it as the web pages
limit that user — a scope can narrow a key further, never past its role:

| Routes | The role needs | As the page that does it |
| ------ | -------------- | ------------------------ |
| `POST`/`PUT` on `/campaigns`, `/aff-networks`, `/ppc-networks`, `/ppc-accounts`, `/trackers`, `/landing-pages`, `/text-ads`, `/rotators` (and rotator rules), and their `bulk-upsert` | `access_to_setup_section` | every Setup page |
| `DELETE` of each of those | its own `remove_*` — `remove_campaign`, `remove_campaign_category`, `remove_traffic_source`, `remove_traffic_source_account`, `remove_tracker`, `remove_landing_page`, `remove_text_ad`, `remove_rotator`, `remove_rotator_rule` — and `access_to_setup_section` | the Setup pages' remove buttons, `delete_tracker.php` |
| The Update routes (`/clicks/cpc`, `/conversions/subids…`, `/conversions/uploads`) | `access_to_update_section`; deleting subids also `delete_individual_subids` | the Update section ([Update](26-update.md)) |
| The Setup code and an account's pixels, reads included: `GET /landing-pages/{id}/code`, `GET /conversions/postback-code`, `/ppc-accounts/{id}/pixels` | `access_to_setup_section` | Get LP Code, Postback / Pixel, Traffic Sources ([Setup](27-setup.md)) |
| A traffic source's custom variables, reads included: `/ppc-networks/{id}/variables` | `remove_traffic_source` and `access_to_setup_section` | Traffic Sources' variables dialog, shown only to a role with both |
| Attribution reports and models | `view_attribution_reports`, `manage_attribution_models` | Attribution |

The check runs before the handler, so a `?dry_run=1` preview and a
`?staged=1` proposal are refused exactly as the write is, and a staged change
is applied with the applier's permissions. Reads are not gated by role,
except the Setup code, pixels and variables above, which only the Setup pages
show. A refusal is `403` naming the permission: `This account's role does not
have the 'remove_campaign' permission.` (`p202` adds a hint naming `p202 user
role`).
Forecast events have no Setup page and are not gated.

### Linked Records

A Setup record links only to the caller's own live records, as the Setup pages
require. `aff_network_id` on a campaign, `ppc_network_id` on a traffic source
account, `aff_campaign_id` on a landing page, `aff_campaign_id` and
`landing_page_id` on a text ad, and every id on a tracker (`aff_campaign_id`,
`ppc_account_id`, `landing_page_id`, `text_ad_id`, `rotator_id`) must name a
record of the key's user that has not been removed; otherwise the write is a
`422` whose `field_errors` names the field (`category 12 is not one of yours,
or it was removed (GET /aff-networks lists them)`). `0` means "none" for a link
the record may go without (a tracker's landing page, a text ad's campaign) and
is refused for one it requires (a campaign's category, a tracker's campaign).
An update that re-sends the value a record already holds is not checked again,
so a record whose campaign was removed since can still be saved. In
`bulk-upsert`, a refused row is an `error` row carrying the same `field_errors`;
the other rows are written.

## Common Headers

| Header | Direction | Description |
| ------ | --------- | ----------- |
| `Authorization: Bearer <key>` | Request | Required for authenticated endpoints. |
| `X-P202-API-Version` | Request | Optional version negotiation; defaults to `v3` if omitted. |
| `X-P202-API-Version-Resolved: v3` | Response | Always present; reports the resolved API version. |
| `Idempotency-Key: <string>` | Request | Required for bulk-upsert operations. Optional on single POST creates (CRUD entities, conversions, rotators + rules, attribution models + exports, users): a retry of the same request replays the recorded response (`idempotent_replay: true` in the body) instead of creating a duplicate. A key is scoped to the caller and identifies one request, so reusing it for anything else — a changed body, or a different endpoint — is refused with `422`; mint a new key for a different create. A retry sent while the first request is still running gets `409`; if the first request died outright without recording a response, whether it created the record is unknown, so the key is spent — later retries get `409` telling you to check state and use a new key rather than risking a duplicate. Not honored on API-key creation (secret responses are never stored) or LTV writes (they have their own upsert/dedup semantics). |
| `If-Match: <etag>` | Request | Optional optimistic concurrency on updates. |

## Delete Dry-Run

Every operator-surface DELETE accepts `?dry_run=1` and then returns `200`
with a preview of what the delete would remove — the record, whether the
delete is `soft` or `hard`, and cascade counts — without removing anything:

```json
{
  "data": {
    "dry_run": true,
    "action": "delete",
    "resource": "rotators",
    "mode": "hard",
    "record": { "id": 3, "name": "Geo Split", "rules": [ ... ] },
    "cascade": [
      { "resource": "rotator-rules", "count": 2 },
      { "resource": "rotator-rule-criteria", "count": 3 },
      { "resource": "rotator-rule-redirects", "count": 4 }
    ]
  }
}
```

Covered: the CRUD entities, conversions, rotators and their rules,
attribution models and exports, apps and SKAN encodings, goals, users, API
keys, role assignments, and the LTV deletes — a customer's erasure (`action:
erase`, `mode: anonymize`, with what it deletes and what it keeps per table),
customer aliases, companies (with `refused` naming why a company with
customers attached would not delete), custom fields (with the values that go
with them), webhooks (with their queued deliveries) and integrations. The
parameter is fail-closed: an endpoint without a preview rejects `dry_run`
with `422` instead of falling through to the real delete,
and an unrecognized `dry_run` value (anything other than `1/true/yes` or
`0/false/no`) is a `422`, never a delete. `features.delete_dry_run` in
[capabilities](17-capabilities.md) advertises support.

## Staged Writes

The model proposes; a person applies. `?staged=1` on an operator-surface
write (the CRUD entities, conversions, rotators and rules, attribution
models and exports, users, roles, preferences — not LTV, not bulk-upsert)
records the operation as a **staged change** with a server-issued id instead
of executing it, returning `202`:

```json
{
  "data": {
    "change_id": "chg_9f2c4e1a0b7d653e8a1f0c2d4",
    "status": "staged",
    "method": "DELETE",
    "path": "/campaigns/42",
    "payload": {},
    "resource_area": "campaigns",
    "preview": { "dry_run": true, "record": { "...": "..." }, "cascade": [] },
    "created_by": 1,
    "expires_at_epoch": 1767312000
  }
}
```

Staged DELETEs embed their dry-run preview when one is available. Staging
runs the route's role checks first, exactly as the write and a dry run do:
a key whose role the route refuses (an attribution route without
`view_attribution_reports`, for instance) gets that route's `403` and
nothing is recorded. A check the delete itself makes (`requireAdmin` on a
user delete, `manage_attribution_models` on a model delete) gates only the
preview: a proposer without it records the change with `"preview": null`
for someone who holds it to apply. The lifecycle endpoints:

| Method | Path | Scope | Description |
| ------ | ---- | ----- | ----------- |
| `GET` | `/staged-changes` | `staged-changes:read` | Your staged changes (`?status=`, `?all=1` for every user's — admin only) |
| `GET` | `/staged-changes/{id}` | `staged-changes:read` | One change, payload and preview included |
| `POST` | `/staged-changes/{id}/apply` | the write scope of the change's area (e.g. `campaigns:write`) | Perform the recorded write, as the user who proposed it |
| `POST` | `/staged-changes/{id}/discard` | `staged-changes:stage` | End the proposal without performing it |

The contract, in the terms of Anthropic's commerce-agents reference:

- **Server-issued ids.** Change ids come only from staging or the list
  endpoints; malformed ids are rejected before lookup.
- **Guards re-check at apply.** Applying re-dispatches the recorded write
  through the real route — validation, route authorization, and scope
  checks re-run against *current* state and the **applier's** credentials.
  Staging authorizes nothing: a non-admin can stage a user delete, but only
  an admin's apply performs it.
- **Propose-only keys.** Staging a write requires `<area>:stage`, which the
  `stage` scope grants without any write access — so an agent key can be
  physically incapable of the write it proposes.
- **One apply.** The staged→applied transition is atomic; a concurrent
  second apply gets `409`. An apply that fails *before* its write returns the
  change to `staged` with `last_error` recorded, so it can be corrected or
  discarded; one that fails *after* its write landed does not, because
  applying it again would perform the write twice — it is closed as
  `apply_interrupted` like the case below. An apply whose process dies
  mid-dispatch is the third case: the claim is taken before
  the write and resolved after it, so the record cannot say whether the
  write landed. After 15 minutes such a change is closed as
  `apply_interrupted` — never re-dispatched (that could duplicate a create
  that did land) and never discarded (that would file an audit record saying
  an executed write was abandoned). Check state, then stage it again.
- **No secrets in the ledger.** A staged change is stored as JSON and shown
  to every reviewer, so a write carrying a credential is refused at staging
  time rather than silently redacted — a redacted proposal could not be
  applied faithfully. A payload key counts as a credential when it matches a
  known name (`user_pass`, `api_key`, `token`, …) *or contains* one of
  `api_key`, `apikey`, `password`, `passwd`, `secret`, `token`,
  `private_key`, `webhook`, `credential` — so `ipqs_api_key`,
  `user_slack_incoming_webhook`, and `webhook_url` are refused too, even
  though none of them matches a bare name. Paths that *are* the credential
  are refused on the same grounds: `DELETE /users/{id}/api-keys/{key}`
  addresses the key by its own value, so it cannot be staged. Perform those
  writes directly.
- **Expiry.** Changes expire (24h by default;
  `P202_STAGED_CHANGE_TTL_SECONDS` overrides) so a stale proposal cannot
  fire against a world that moved on. Applied and discarded changes remain
  listed as the audit trail, actor-stamped, and are pruned oldest-first past
  a per-user cap — as are expired proposals, which are equally unusable and
  would otherwise grow the ledger without bound.
- **A bounded queue.** Pruning only reclaims records that are finished or
  expired, so the *live* queue is capped separately: past 1000 proposals
  awaiting a decision (`P202_STAGED_CHANGE_MAX_PENDING` overrides), staging
  returns `409` naming what to resolve first. Without it a propose-only key
  could grow one user's ledger for a whole TTL, and every later stage and
  list has to read and rewrite that file.
- **Fail-closed.** `staged=1` on a write outside the allowlist is a `422`,
  never a silent immediate write; `staged` and `dry_run` together are a
  `422`.

`features.staged_writes` in [capabilities](17-capabilities.md) advertises
support. On the CLI, the global `--staged` flag stages any write command
and `p202 change list|show|apply|discard` is the approval surface.

## Standard Response Format

The envelope fields below (full `pagination` metadata, and `version`/`etag` on single resources) apply to standard CRUD resources served by the generic controller. Some endpoints (for example, attribution models) return a simpler shape and may omit `cursor`/`total` pagination or the `version`/`etag` fields.

### List Response

```json
{
  "data": [ { ... }, { ... } ],
  "pagination": {
    "total": 142,
    "limit": 50,
    "offset": 0,
    "cursor": null,
    "cursor_expires_at": null
  }
}
```

### Filtering a List

The standard CRUD lists take `filter[<field>]=<value>` for any field the
resource declares, its id included (`GET /campaigns?filter[aff_network_id]=3`,
`GET /campaigns?filter[aff_campaign_id_public]=148`); several filters must all
match. A filter the list cannot apply is a `422` naming it — a field the
resource does not have, text for a whole-number field, a list instead of one
value — never an unfiltered answer: a misspelled filter used to return every
row with a `200`.

### Single Resource

```json
{
  "data": {
    "id": 1,
    "field": "value",
    "version": "<hash>",
    "etag": "<string>"
  }
}
```

### Create (201 Created)

```json
{
  "_status": 201,
  "data": { ... }
}
```

### Delete (204 No Content)

Empty response body.

### Error Response

```json
{
  "error": true,
  "message": "Descriptive error message",
  "status": 422,
  "field_errors": {
    "field_name": "Validation message"
  }
}
```

## Status Codes

| Code | Meaning |
| ---- | ------- |
| 200 | Success |
| 201 | Created (POST) |
| 202 | Accepted (async operations like sync jobs) |
| 204 | No Content (DELETE) |
| 400 | Bad Request |
| 401 | Unauthorized (missing or invalid API key) |
| 409 | Conflict (version/etag mismatch on update) |
| 422 | Unprocessable Entity (validation errors) |
| 429 | Too Many Requests (rate limit exceeded) |
| 503 | Service Unavailable |

## Rate Limits

- Sync operations: **30 requests per minute** (per user)
- Bulk-upsert operations: **60 requests per minute** (per user)

## Resource Endpoints Overview

| Resource | Endpoints | Documentation |
| -------- | --------- | ------------- |
| Campaigns | CRUD + bulk-upsert | [Campaigns](02-campaigns.md) |
| Networks | CRUD + bulk-upsert | [Networks](03-affiliate-networks.md) |
| PPC Networks | CRUD + bulk-upsert | [PPC Networks](04-ppc-networks.md) |
| PPC Accounts | CRUD + bulk-upsert | [PPC Accounts](05-ppc-accounts.md) |
| Trackers | CRUD + bulk-upsert + URL generation | [Trackers](06-trackers.md) |
| Landing Pages | CRUD | [Landing Pages](07-landing-pages.md) |
| Text Ads | CRUD | [Text Ads](08-text-ads.md) |
| Clicks | Read-only (list + detail) | [Clicks](09-clicks.md) |
| Conversions | List, get, create, delete | [Conversions](10-conversions.md) |
| Update | Past clicks' CPC, mark/delete/reset subids, revenue report upload (with `?dry_run=1`) | [Update](26-update.md) |
| Setup | Landing-page code, conversion pixels and postback URLs, a traffic source's custom variables, an account's pixels | [Setup](27-setup.md) |
| Reports | Summary, breakdown, timeseries, daypart, weekpart | [Reports](11-reports.md) |
| Rotators | CRUD + nested rules, criteria, redirects | [Rotators](12-rotators.md) |
| Attribution | Models, snapshots, exports | [Attribution](13-attribution.md) |
| Apps | The app registry (iOS and Android), Apple SKAdNetwork and AdAttributionKit postbacks, SKAN encodings, report | [App measurement](19-app-measurement.md) |
| Forecast Events | CRUD + bulk-upsert | [Forecast Events](18-forecast-events.md) |
| Users | CRUD + roles, API keys, preferences | [Users](14-users.md) |
| System | Health, version, cron, errors, metrics, db-stats | [System](15-system.md) |
| Sync | Jobs, planning, change feed, audit | [Sync](16-sync.md) |
| Capabilities | Version and feature discovery | [Capabilities](17-capabilities.md) |

## Discovery Endpoints (No Auth Required)

### GET /versions

Returns supported API versions.

### GET /system/health

Returns system health status:

```json
{
  "data": {
    "status": "healthy",
    "timestamp": 1709942400,
    "api_version": "v3"
  }
}
```

## Legacy API (v1)

The legacy v1 reports API (`/api/v1/reports/`) is still available for backward compatibility but is deprecated. New integrations should use the v3 API. See [Legacy API](99-legacy-api.md) for v1 documentation.
