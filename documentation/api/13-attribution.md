# Attribution API

Manage multi-touch attribution models, view snapshots, and schedule exports.

## Model Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/attribution/models` | List attribution models (paginated) |
| `GET` | `/attribution/models/{id}` | Get a single model |
| `POST` | `/attribution/models` | Create a model |
| `PUT` | `/attribution/models/{id}` | Update a model |
| `DELETE` | `/attribution/models/{id}` | Delete model and all related data (cascading) |

## Snapshot Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/attribution/models/{id}/snapshots` | List snapshots for a model |

## Export Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/attribution/models/{id}/exports` | List exports for a model |
| `POST` | `/attribution/models/{id}/exports` | Schedule an export |

## Model Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `model_name` | string | Yes | Model name |
| `model_type` | string | Yes | One of: `first_touch`, `last_touch`, `linear`, `time_decay`, `position_based`, `algorithmic` |
| `model_slug` | string | No | URL-safe slug (auto-generated) |
| `weighting_config` | object | No | Type-specific weighting configuration (defaults to `{}`) |
| `is_active` | integer | No | Active status (default 1) |
| `is_default` | integer | No | Set as default model (default 0) |

## Snapshot Fields

| Field | Type | Description |
| ----- | ---- | ----------- |
| `snapshot_id` | integer | Snapshot ID |
| `model_id` | integer | Parent model ID |
| `user_id` | integer | User who generated the snapshot |
| `scope_type` | string | Scope: `global`, `campaign`, `landing_page` |
| `scope_id` | integer | ID of the scoped entity (0 for global) |
| `date_hour` | integer | Hour timestamp for the snapshot |
| `attributed_revenue` | float | Attributed revenue for this hour |
| `attributed_cost` | float | Attributed cost for this hour |

## Schedule Export

```json
{
  "scope_type": "global",
  "scope_id": 0,
  "start_hour": 1709856000,
  "end_hour": 1709942400,
  "format": "csv",
  "webhook_url": "https://hooks.example.com/attribution-ready"
}
```

| Field | Type | Default | Description |
| ----- | ---- | ------- | ----------- |
| `scope_type` | string | `global` | Export scope: `global`, `campaign`, `landing_page` |
| `scope_id` | integer | 0 | Entity ID for the scope |
| `start_hour` | integer | — | Start timestamp |
| `end_hour` | integer | — | End timestamp |
| `format` | string | `csv` | Export format: `csv` or `json` |
| `webhook_url` | string | — | URL to notify when export completes |

Export statuses: `queued`, `processing`, `completed`, `failed`.

## Attribution Model Types

| Type | Description |
| ---- | ----------- |
| `first_touch` | 100% credit to the first touchpoint |
| `last_touch` | 100% credit to the last touchpoint |
| `linear` | Equal credit across all touchpoints |
| `time_decay` | More credit to touchpoints closer to conversion |
| `position_based` | Configurable weight for first, last, and middle touchpoints |
| `algorithmic` | Data-driven attribution using historical patterns |

## Example: Create and Query a Model

```bash
# Create a time-decay model
curl -X POST https://your-domain.com/api/v3/attribution/models \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "model_name": "7-Day Time Decay",
    "model_type": "time_decay",
    "weighting_config": { "half_life_hours": 168 }
  }'

# List snapshots
curl "https://your-domain.com/api/v3/attribution/models/1/snapshots?scope_type=campaign&limit=100" \
  -H "Authorization: Bearer YOUR_API_KEY"
```
