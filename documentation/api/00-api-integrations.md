# Prosper202 REST API v3

Prosper202 includes a full REST API (v3) for managing campaigns, tracking clicks, reporting, attribution, user management, server-side sync, and system administration. The API returns JSON and follows standard REST conventions.

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

## Common Headers

| Header | Direction | Description |
| ------ | --------- | ----------- |
| `Authorization: Bearer <key>` | Request | Required for authenticated endpoints. |
| `X-P202-API-Version: v3` | Both | Optional on request; always present in response. |
| `Idempotency-Key: <string>` | Request | Required for bulk-upsert operations. |
| `If-Match: <etag>` | Request | Optional optimistic concurrency on updates. |

## Standard Response Format

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
  "status": "healthy",
  "database": "connected",
  "timestamp": 1709942400,
  "php_version": "8.3.x",
  "api_version": "v3"
}
```

## Legacy API (v1)

The legacy v1 reports API (`/api/v1/reports/`) is still available for backward compatibility but is deprecated. New integrations should use the v3 API. See [Legacy API](99-legacy-api.md) for v1 documentation.
