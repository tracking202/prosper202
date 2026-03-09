# Sync API

Server-side synchronization between Prosper202 instances. Sync operations allow you to replicate campaigns, networks, trackers, and other entities between profiles or servers.

All sync endpoints require admin role and the appropriate sync scope (`sync:read` or `sync:write`).

## Planning Endpoints

| Method | Path | Scope | Description |
| ------ | ---- | ----- | ----------- |
| `POST` | `/sync/plan` | sync:read | Build a sync plan (returns 202) |
| `GET` | `/sync/status` | sync:read | Current sync status between profiles |
| `GET` | `/sync/history` | sync:read | Sync history |

## Job Endpoints

| Method | Path | Scope | Description |
| ------ | ---- | ----- | ----------- |
| `POST` | `/sync/jobs` | sync:write | Create and queue a sync job (returns 202) |
| `GET` | `/sync/jobs/{id}` | sync:read | Get job status |
| `GET` | `/sync/jobs/{id}/events` | sync:read | List job events (paginated) |
| `POST` | `/sync/jobs/{id}/run` | sync:write | Run a specific job |
| `POST` | `/sync/jobs/{id}/cancel` | sync:write | Cancel a running job |
| `POST` | `/sync/worker/run` | sync:write | Run worker to process all queued jobs |
| `POST` | `/sync/re-sync` | sync:write | Incremental re-sync (returns 202) |

## Change Feed

| Method | Path | Scope | Description |
| ------ | ---- | ----- | ----------- |
| `GET` | `/changes/{entity}` | sync:read | List incremental changes for an entity |

## Audit Endpoints

| Method | Path | Scope | Description |
| ------ | ---- | ----- | ----------- |
| `GET` | `/audit/sync-jobs` | sync:read | List sync job audit records |
| `GET` | `/audit/sync-jobs/{id}` | sync:read | Get specific audit record |

## Create Sync Job

```json
{
  "source": {
    "url": "https://source-instance.example.com",
    "api_key": "source_api_key_here"
  },
  "target": {
    "url": "https://target-instance.example.com",
    "api_key": "target_api_key_here"
  },
  "entity": "campaigns",
  "collision_mode": "warn",
  "prune": false,
  "max_attempts": 3,
  "idempotency_key": "sync-campaigns-2024-03-08"
}
```

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `source.url` | string | Yes | Source instance base URL |
| `source.api_key` | string | Yes | Source instance API key |
| `target.url` | string | Yes | Target instance base URL |
| `target.api_key` | string | Yes | Target instance API key |
| `entity` | string | Yes | Entity to sync (e.g., `campaigns`, `aff_networks`, `trackers`) |
| `collision_mode` | string | No | How to handle conflicts: `warn` or `manual` |
| `prune` | boolean | No | Delete target entities not present in source |
| `max_attempts` | integer | No | Retry limit for failed items |
| `idempotency_key` | string | No | Prevent duplicate job creation |

## Job Statuses

| Status | Description |
| ------ | ----------- |
| `queued` | Job created, waiting to be processed |
| `running` | Job currently executing |
| `succeeded` | All items synced successfully |
| `failed` | Job failed (check events for details) |
| `cancelled` | Job was cancelled |
| `partial` | Some items succeeded, some failed |

## Example: Sync Campaigns Between Instances

```bash
# 1. Create and queue a sync job
curl -X POST https://your-domain.com/api/v3/sync/jobs \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "source": { "url": "https://prod.example.com", "api_key": "prod_key" },
    "target": { "url": "https://staging.example.com", "api_key": "staging_key" },
    "entity": "campaigns",
    "collision_mode": "warn"
  }'

# 2. Check job status
curl "https://your-domain.com/api/v3/sync/jobs/1" \
  -H "Authorization: Bearer YOUR_API_KEY"

# 3. View job events
curl "https://your-domain.com/api/v3/sync/jobs/1/events?limit=50" \
  -H "Authorization: Bearer YOUR_API_KEY"
```
