# Conversions API

List, inspect, manually create, and delete conversions.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/conversions` | List conversions (paginated) |
| `GET` | `/conversions/{id}` | Get conversion details |
| `POST` | `/conversions` | Manually log a conversion |
| `DELETE` | `/conversions/{id}` | Delete a conversion |
| `GET` | `/conversions/postback-code` | The conversion pixels and postback URLs Setup › Postback / Pixel shows ([Setup API](27-setup.md)) |

Marking or deleting subids in bulk, resetting a campaign's subids and
uploading a revenue report — the UI's Update section — are in the
[Update API](26-update.md).

## Query Parameters (List)

| Parameter | Type | Default | Description |
| --------- | ---- | ------- | ----------- |
| `limit` | integer | 50 | Results per page (1-500) |
| `offset` | integer | 0 | Pagination offset |
| `campaign_id` | integer | — | Filter by campaign |
| `time_from` | string | — | Start, inclusive: unix seconds, a date (`2026-10-01`, from its first second in the account's timezone) or a time with its offset (`2026-10-01T09:30:00Z`) |
| `time_to` | string | — | End, inclusive: the same forms; a date runs through its last second |
| `click_id` | integer | — | Only this click's conversions |
| `source` | string | — | Only conversions this source produced (`pixel`, `postback`, `universal_pixel`, `api`, `subid_upload`, `revenue_upload`, `legacy_pixel`, `clickbank`, `app_install`, `goal`, `legacy_baseline`) |
| `goal` | integer | — | Only this goal's outcomes, under every version of it |

A filter value that cannot be read — `click_id=7x`, an unknown `source`, a
`goal` or `campaign_id` that is not a positive id (`campaign_id=0` included,
which used to list every campaign's conversions) — is a `422` naming the
field, never ignored. Deleted conversions are not listed; `GET /clicks/{id}/conversions`
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
| `status` | string | No | Only `"reversed"`: records a reversal of the click's conversion with this `transaction_id` instead of a new conversion (a sale is reversed once; `404` when the click has no such conversion) |
| `reversal_id` | string | No | With `status: "reversed"`, the network's id for the reversal (a non-empty string) |
| `customer_id` | integer | No | The LTV customer the revenue belongs to; it wins over `customer_ref`. Anything but a positive id (`0`, `""`) is a `422` |
| `customer_ref` | string | No | Your id for the customer: resolved to one, or a customer is created for it. `"0"` is an id like any other; a blank one is a `422` |
| `customer_ref_type` | string | No | What `customer_ref` is: `email_md5`, `email_sha256`, `esp_id`, `merchant_id`, `subid` or `custom` (the default); read only with `customer_ref` |
| `customer_crm` | object | No | CRM fields (`first_name`, `last_name`, `email`, `phone`, `company`, `address_line1`, `address_line2`, `city`, `region`, `postal_code`, `country`) applied only when this conversion creates the customer. See [Nested objects](#nested-objects) |
| `items` | array | No | Product line items on the customer's revenue event: each `{external_product_id or sku, name, quantity, unit_price, amount, price}`. Recorded only when the conversion resolves to a customer — named here, or already linked to the click — and dropped without an error otherwise. See [Nested objects](#nested-objects) |

Creates are idempotent on `transaction_id`: if a conversion with the same
`transaction_id` already exists for the given `click_id`, nothing is written
and the existing conversion is returned, with `"duplicate": true` beside
`data` (the field is absent on a new conversion; the status is `201` either
way, as for an `Idempotency-Key` replay). Without a `transaction_id`, an
`accumulate` campaign records one plain conversion per click (a repeat is a
duplicate), and a `replace` campaign records a row on every request, so a
retried request without one records another conversion there.

A deleted conversion keeps its place in the click's ledger, so a request that
repeats one (the same `transaction_id`, the same reversal, or an `accumulate`
click's plain conversion) is refused with `409`, naming it in
`details.conv_id` with `details.deleted: true`. Nothing is written, and an
`Idempotency-Key` sent with it is not spent. A different sale needs its own
`transaction_id`. A `409` without `details` is about the `Idempotency-Key`
instead: a request holding it is still in flight, or one did not finish.

### Nested objects

`customer_crm` and each entry of `items` are read as strictly as the body
itself: a key they do not take is a `422` naming it with its place in the
body (`customer_crm.frist_name`, `items.0.unit_pirce`), every refused field
is listed in one answer, and nothing is written. They used to be read for
the keys the server knew, so a misspelled key was dropped and the
conversion answered `201` without it.

| Field | Rule |
| ----- | ---- |
| `customer_crm.*` | A string (a whole number is read as its digits) or `null`, at most as long as its column: `first_name`, `last_name`, `city`, `region` 100; `phone` 50; `postal_code` 20; `country` 2 (a two-letter code, e.g. `US`); the rest 255. `email` must be an email address |
| `items.N.external_product_id`, `items.N.sku` | One is required: the product is found, or created, by it. A string (or whole number) of at most 191 characters; a `sku` with no `external_product_id` becomes the key `sku:<sku>`, so it has at most 187 |
| `items.N.name` | A string of at most 255 characters |
| `items.N.quantity` | A number from 0.001 to 999999999.999 (default 1) |
| `items.N.unit_price` | A number from -999999999.99999 to 999999999.99999 |
| `items.N.amount` | A number from -99999999999.99999 to 99999999999.99999; default `unit_price × quantity`, which must fit too |
| `items.N.price` | The product's list price: a number from 0 to 999999999.99999 |

A number is a JSON number or a string holding one (`"25"`); anything else
(`"abc"`, `true`) is refused, never stored as 0. The lines of a negative
payout (an adjustment) are stored negative, amount and quantity, whichever
sign is sent. `POST /ltv/revenue` reads its `items` and `customer_crm` by the
same rules.

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
