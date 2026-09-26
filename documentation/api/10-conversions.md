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
| `click_id` | integer | — | Only this click's conversions |
| `source` | string | — | Only conversions this source produced (`pixel`, `postback`, `universal_pixel`, `api`, `subid_upload`, `revenue_upload`, `legacy_pixel`, `clickbank`, `app_install`, `goal`, `legacy_baseline`) |
| `goal` | integer | — | Only this goal's outcomes, under every version of it |

A filter value that cannot be read — `click_id=7x`, an unknown `source`, a
`goal` that is not a positive id — is a `422` naming the field, never
ignored. Deleted conversions are not listed; `GET /clicks/{id}/conversions`
shows them, with whether each row counts toward the click.

## Response Fields

`conv_id`, `click_id`, `transaction_id`, `campaign_id`, `click_payout`, `user_id`, `click_time`, `conv_time`, `deleted`, `aff_campaign_name`, and the row's provenance in the ledger: `source`, `source_ref` (`goal:<id>:<version>`, `batch:<upload>`, `conv:<the conversion a reversal reverses>`, `apikey:<digest of the key that wrote it>`), `event_name`, `payable`, `reverses_conv_id`, `superseded_by`, `superseded_reason`, and for a goal row its `goal_id` and `goal_version`.

A conversion created through this API records `source: "api"` and names the
key that wrote it in `source_ref` as a truncated SHA-256 digest of the key, so
the click breakdown can say which key it was without anyone reading the key
back.

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

Creates are idempotent on `transaction_id`: if a conversion with the same
`transaction_id` already exists for the given `click_id`, the existing
conversion is returned instead of recording a duplicate. Omitting
`transaction_id` skips this check, so retried requests without one will
record multiple conversions.

## Example

```bash
curl -X POST https://your-domain.com/api/v3/conversions \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "click_id": 12345, "payout": 4.50, "transaction_id": "txn-abc-123" }'
```
