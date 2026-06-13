# Forecast Events API

Manage a calendar of known events — holidays, promotions, seasonal peaks — that
influence performance. Stored events power event-aware forecasting in the Go CLI
(`p202 forecast --events`), which shifts predicted metrics around each event's
date using its expected impact, lead, and lag windows.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/forecast-events` | List forecast events (ordered by `event_date`, paginated) |
| `GET` | `/forecast-events/{id}` | Get a single forecast event |
| `POST` | `/forecast-events` | Create a forecast event |
| `POST` | `/forecast-events/bulk-upsert` | Bulk create/update (requires `Idempotency-Key`) |
| `PUT` | `/forecast-events/{id}` | Update a forecast event |
| `DELETE` | `/forecast-events/{id}` | Delete a forecast event |

## Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `event_name` | string | Yes | Event label (max 255) |
| `event_date` | string | Yes | Start date `YYYY-MM-DD` (max 10) |
| `end_date` | string | No | End date `YYYY-MM-DD` for multi-day events (max 10) |
| `recurrence` | string | No | One of `none`, `monthly`, `yearly`, `custom` (default `none`) |
| `impact_type` | string | No | One of `boost`, `suppress`, `neutral` (default `neutral`) |
| `expected_impact_pct` | number | No | Expected percentage impact on the metric (decimal) |
| `lead_days` | integer | No | Days before the event that impact begins (default 0) |
| `lag_days` | integer | No | Days after the event that impact persists (default 0) |
| `tags` | string | No | Comma-separated tags, e.g. `us-holidays,retail` (max 500) |
| `notes` | string | No | Free-form notes (max 500) |

Auto-generated on write: `created_at` and `updated_at` (unix timestamps).

## Filtering

List results can be filtered with `filter[field]` query parameters. The `tags`
filter matches by membership in the comma-separated list (tolerating spaces
after commas), so `filter[tags]=us-holidays` returns every event tagged
`us-holidays`.

## Example

```bash
curl -X POST https://your-domain.com/api/v3/forecast-events \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "event_name": "Black Friday",
    "event_date": "2026-11-27",
    "recurrence": "custom",
    "impact_type": "boost",
    "expected_impact_pct": 200,
    "lead_days": 3,
    "lag_days": 2,
    "tags": "us-holidays,retail"
  }'
```

Once events are stored, drive an event-aware forecast from the Go CLI:

```bash
p202 forecast --metric revenue --events --horizon 14
p202 forecast --metric clicks --events --event-tag us-holidays
```
