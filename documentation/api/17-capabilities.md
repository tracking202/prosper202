# Capabilities API

API version and feature detection. Requires Bearer token authentication.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/versions` | List supported API versions |
| `GET` | `/capabilities` | Full feature and capability matrix |

## Capabilities Response

```json
{
  "api_version": "v3",
  "entity_support": {
    "campaigns": ["list", "get", "create", "update", "delete", "bulk_upsert"],
    "aff_networks": ["list", "get", "create", "update", "delete", "bulk_upsert"],
    "ppc_networks": ["list", "get", "create", "update", "delete", "bulk_upsert"],
    "ppc_accounts": ["list", "get", "create", "update", "delete", "bulk_upsert"],
    "trackers": ["list", "get", "create", "update", "delete", "bulk_upsert"],
    "landing_pages": ["list", "get", "create", "update", "delete"],
    "text_ads": ["list", "get", "create", "update", "delete"],
    "clicks": ["list", "get"],
    "conversions": ["list", "get", "create", "delete"],
    "rotators": ["list", "get", "create", "update", "delete"],
    "attribution_models": ["list", "get", "create", "update", "delete"],
    "users": ["list", "get", "create", "update", "delete"]
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
    "max_job_concurrency": 3,
    "max_job_events_page": 200,
    "rate_limits": {
      "sync": "30/min",
      "bulk_upsert": "60/min"
    }
  },
  "server": {
    "build": "1.9.58",
    "environment": "production",
    "timezone_support": true
  }
}
```

## Use Cases

- **Client libraries**: Auto-detect available features before making API calls.
- **Sync tools**: Check which entities support sync before building a sync plan.
- **Version negotiation**: Determine the appropriate API version to use.
