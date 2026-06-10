# Conversions API

List, inspect, manually create, and delete conversions.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/conversions` | List conversions (paginated) |
| `GET` | `/conversions/{id}` | Get conversion details |
| `POST` | `/conversions` | Manually log a conversion |
| `DELETE` | `/conversions/{id}` | Delete a conversion |

## Query Parameters (List)

| Parameter | Type | Default | Description |
| --------- | ---- | ------- | ----------- |
| `limit` | integer | 50 | Results per page (1-500) |
| `offset` | integer | 0 | Pagination offset |
| `campaign_id` | integer | — | Filter by campaign |
| `time_from` | integer | — | Unix timestamp start |
| `time_to` | integer | — | Unix timestamp end |

## Response Fields

`conv_id`, `click_id`, `transaction_id`, `campaign_id`, `click_payout`, `user_id`, `click_time`, `conv_time`, `deleted`, `aff_campaign_name`.

## Create Conversion

```json
{
  "click_id": 12345,
  "payout": 4.50,
  "transaction_id": "txn-abc-123",
  "conv_time": 1709942400
}
```

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `click_id` | integer | Yes | The click to attribute this conversion to |
| `payout` | decimal | No | Override payout (defaults to the click's campaign payout) |
| `transaction_id` | string | No | Deduplication key |
| `conv_time` | integer | No | Unix timestamp (defaults to now) |

## Example

```bash
curl -X POST https://your-domain.com/api/v3/conversions \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "click_id": 12345, "payout": 4.50, "transaction_id": "txn-abc-123" }'
```
