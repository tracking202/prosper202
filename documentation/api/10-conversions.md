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
`transaction_id` already exists for the given `click_id`, nothing is written
and the existing conversion is returned, with `"duplicate": true` beside
`data` (the field is absent on a new conversion; the status is `201` either
way, as for an `Idempotency-Key` replay). Without a `transaction_id`, an
`accumulate` campaign records one plain conversion per click (a repeat is a
duplicate), and a `replace` campaign records a row on every request, so a
retried request without one records another conversion there.

## Example

```bash
curl -X POST https://your-domain.com/api/v3/conversions \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "click_id": 12345, "payout": 4.50, "transaction_id": "txn-abc-123" }'
```

## Delete Conversion

`DELETE /conversions/{id}` soft-deletes the conversion, recomputes its click's
value, voids its revenue event (a compensating adjustment) and corrects the
customer's LTV rollups, in one transaction.

A reversal nets only while the sale it reverses counts, so deleting a sale
that has a live reversal leaves the reversal row in place but no longer
counting, and voids the reversal's revenue event in the same transaction: a
$10 sale and its -$10 reversal are $0 in the click's value and in the
customer's LTV before the delete, and still $0 after it. Deleting the
reversal afterwards changes nothing further. The traffic-source postbacks
queued for the conversion are settled in the same transaction, as the goals
engine settles an outcome it retires: one not yet sent is cancelled, one
that may have gone out gets a retraction (sent to the pixel's correction URL
where one is set, recorded `suppressed` otherwise). `?dry_run=1` answers what the
delete will do without doing it; a sale with live reversals lists them under
`cascade` (`{"resource": "conversions", "count", "ids", "effect":
"reversal_stops_netting"}`) and says so in `note`.
 Its postbacks are listed as two `notifications` entries, `effect` `postback_cancelled` and `postback_retracted`, each with its `count`.
