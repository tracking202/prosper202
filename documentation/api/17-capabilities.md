# Capabilities API

API version and feature detection.

## Endpoints

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| `GET` | `/versions` | None | List supported API versions |
| `GET` | `/capabilities` | Bearer | Full feature and capability matrix |

## Versions

`GET /versions` — no authentication required.

```json
{
  "data": {
    "preferred": "v3",
    "supported": ["v3"]
  }
}
```

## Capabilities Response

`GET /capabilities` — requires `Authorization: Bearer <api_key>`.

```json
{
  "data": {
    "api_version": "v3",
    "entity_support": {
      "aff-networks":   { "list": true, "get": true, "create": true, "update": true, "delete": true, "bulk_upsert": true },
      "ppc-networks":   { "list": true, "get": true, "create": true, "update": true, "delete": true, "bulk_upsert": true },
      "ppc-accounts":   { "list": true, "get": true, "create": true, "update": true, "delete": true, "bulk_upsert": true },
      "campaigns":      { "list": true, "get": true, "create": true, "update": true, "delete": true, "bulk_upsert": true },
      "landing-pages":  { "list": true, "get": true, "create": true, "update": true, "delete": true, "bulk_upsert": true },
      "text-ads":       { "list": true, "get": true, "create": true, "update": true, "delete": true, "bulk_upsert": true },
      "trackers":       { "list": true, "get": true, "create": true, "update": true, "delete": true, "bulk_upsert": true }
    },
    "sync_features": {
      "diff": true,
      "sync_plan": true,
      "async_jobs": true,
      "incremental": true,
      "prune": true,
      "force_update": true,
      "server_fk_remap": true
    },
    "limits": {
      "max_bulk_rows": 500,
      "max_job_concurrency": 5,
      "max_job_events_page": 500,
      "rate_limits": {
        "sync_per_minute": 30,
        "bulk_upsert_per_minute": 60
      }
    },
    "server": {
      "build": "1.9.58",
      "commit": "abc1234",
      "environment": "production",
      "timezone_support": "named-timezone"
    }
  }
}
```

### Fields

| Field | Type | Description |
| ----- | ---- | ----------- |
| `api_version` | string | API version this server implements. |
| `entity_support` | object | Map of entity → object of supported operations. Each value is an object of booleans keyed by `list`, `get`, `create`, `update`, `delete`, and `bulk_upsert`. Entity keys are hyphenated: `aff-networks`, `ppc-networks`, `ppc-accounts`, `campaigns`, `landing-pages`, `text-ads`, `trackers`. |
| `sync_features` | object | Booleans for supported sync capabilities: `diff`, `sync_plan`, `async_jobs`, `incremental`, `prune`, `force_update`, `server_fk_remap`. |
| `limits.max_bulk_rows` | integer | Maximum rows per bulk-upsert request. |
| `limits.max_job_concurrency` | integer | Maximum concurrent async jobs. |
| `limits.max_job_events_page` | integer | Maximum job events returned per page. |
| `limits.rate_limits.sync_per_minute` | integer | Allowed sync requests per minute. |
| `limits.rate_limits.bulk_upsert_per_minute` | integer | Allowed bulk-upsert requests per minute. |
| `server.build` | string | Server build/version string. |
| `server.commit` | string | Git commit the server was built from. |
| `server.environment` | string | Deployment environment (e.g. `production`). |
| `server.timezone_support` | string | Timezone capability, e.g. `named-timezone` or `fallback-only`. |

## Use Cases

- **Client libraries**: Auto-detect available features before making API calls.
- **Sync tools**: Check which entities support sync before building a sync plan.
- **Version negotiation**: Determine the appropriate API version to use.
