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
| `write` | Every endpoint (`write` implies `read`). |
| `<area>:read` / `<area>:write` | One route family; `<area>:write` implies `<area>:read`. |

Areas: `campaigns`, `aff-networks`, `ppc-networks`, `ppc-accounts`,
`trackers`, `landing-pages`, `text-ads`, `forecast-events`, `clicks`,
`conversions`, `reports`, `ltv`, `rotators`, `attribution`, `users`,
`system`, `sync` (the `changes` and `audit` routes fall under `sync`).

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

## Common Headers

| Header | Direction | Description |
| ------ | --------- | ----------- |
| `Authorization: Bearer <key>` | Request | Required for authenticated endpoints. |
| `X-P202-API-Version` | Request | Optional version negotiation; defaults to `v3` if omitted. |
| `X-P202-API-Version-Resolved: v3` | Response | Always present; reports the resolved API version. |
| `Idempotency-Key: <string>` | Request | Required for bulk-upsert operations. Optional on single POST creates (CRUD entities, conversions, rotators + rules, attribution models + exports, users): a retry with the same key and payload replays the recorded response (`idempotent_replay: true` in the body) instead of creating a duplicate. Not honored on API-key creation (secret responses are never stored) or LTV writes (they have their own upsert/dedup semantics). |
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
attribution models, users, API keys, and role assignments. The parameter is
fail-closed: an endpoint without a preview (currently the LTV deletes)
rejects `dry_run` with `422` instead of falling through to the real delete,
and an unrecognized `dry_run` value (anything other than `1/true/yes` or
`0/false/no`) is a `422`, never a delete. `features.delete_dry_run` in
[capabilities](17-capabilities.md) advertises support.

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
| Reports | Summary, breakdown, timeseries, daypart, weekpart | [Reports](11-reports.md) |
| Rotators | CRUD + nested rules, criteria, redirects | [Rotators](12-rotators.md) |
| Attribution | Models, snapshots, exports | [Attribution](13-attribution.md) |
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
