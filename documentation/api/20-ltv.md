# Customer lifetime value (LTV)

LTV follows a *customer* — a person or account identified by your own id —
across every sale, renewal and refund, rather than a click. Its revenue
ledger is fed three ways:

| From | How the customer is named | Endpoint |
|---|---|---|
| A conversion on a click | `customer_ref` / `customer_id` on the conversion, the customer already linked to the click, or the account's customer c-param | `POST /conversions` ([Conversions](10-conversions.md)), the pixels and postbacks with `cust=` |
| Revenue from another system (an ESP order, a membership charge) | `customer_ref` or `customer_id`, required | `POST /ltv/revenue` |
| A subscription and its renewals | `customer_ref` or `customer_id`, required | `POST /ltv/subscriptions`, `POST /ltv/subscriptions/{ref}/events` |

Everything else on this page reads that ledger (totals, cohorts, MRR,
predictions), keeps the customer record (CRM fields, aliases, custom
fields, companies), or sends it on (webhooks).

## Rules that hold for every LTV route

- **Scopes.** Reads need `ltv:read`; writes and deletes `ltv:write`.
- **Strict bodies.** A key a route does not take is a `422` naming it and
  listing what it does take; so is a key inside a nested object
  (`items.0.qty`, `aliases.0.tpye`, `customer_crm.frist_name`). Numbers are
  read, never cast: `"abc"` is refused, not stored as 0; a time is unix
  seconds (`"2026-10-07"` is refused, not dated 1970). Several refusals come
  back in one answer, and nothing is written.
- **Flags** (`is_required`) are a JSON `true`/`false`, or `1`/`0` and the
  strings `"true"`/`"false"`/`"1"`/`"0"`; anything else (`"no"`) is a `422`.
  They used to make every non-empty string true.
- **Not stageable.** `?staged=1` on an LTV write is a `422` ("This write
  cannot be staged"); `p202 ltv …` refuses `--staged` before it sends
  anything.
- **No `Idempotency-Key`.** LTV writes do not honor the header. Their own
  keys make a retry safe: `idempotency_key` on revenue, `transaction_id` or
  `idempotency_key` on a subscription's renewal or refund, the reference on
  an upsert (customers, subscriptions, products).
- **Deletes preview.** `DELETE …?dry_run=1` on any LTV delete answers what
  it would remove and keep (`"dry_run": true`, the record, the cascade) and
  writes nothing.
- **Money is the account's currency.** A `currency` other than the
  account's is a `422`; multi-currency is not supported.
- **Every `422` names its field.** `field_errors` holds the body or query
  field to fix, by its place in the body (`amount`, `items.0.quantity`,
  `custom_fields.tier`, `aliases.1.value`, `source_customer_id`). Refusals
  the repositories made used to come back with the message alone ("amount
  must not be negative for purchase events", an unknown custom field, a
  reserved `idempotency_key`). A refusal by the state of a record rather
  than by a field (a company with customers attached, a merge another
  request got to first) is a `409`, and a failure of the server a `500`,
  never a `422`.

## Naming the customer

The writes that need a customer take the same four keys:

| Key | |
|---|---|
| `customer_id` | The customer's internal id (`GET /ltv/customers`, `p202 ltv customers`); it wins over `customer_ref`. Another account's id is a `404` |
| `customer_ref` | Your id for the customer. The customer holding it is found, or one is created for it |
| `customer_ref_type` | What `customer_ref` is: `email_md5`, `email_sha256`, `esp_id`, `merchant_id`, `subid` or `custom` (the default). Email digests are checked for their form |
| `customer_crm` | An object of CRM fields applied only when this call **creates** the customer (`first_name`, `last_name`, `email`, `phone`, `company`, `address_line1`, `address_line2`, `city`, `region`, `postal_code`, `country`). Change an existing customer's with `PATCH /ltv/customers/{id}` |

A write that needs a customer and names none is a `422`
(`customer_id or customer_ref is required`).

## Reads

| Method | Path | |
|---|---|---|
| `GET` | `/ltv/summary` | Customers, total revenue, refunds, orders, average LTV, AOV, repeat rate, MRR, active subscriptions |
| `GET` | `/ltv/customers` | Customers with their rollups, paginated |
| `GET` | `/ltv/customers/{id}` | One customer in full: CRM, aliases, custom fields, recent revenue events, subscriptions |
| `GET` | `/ltv/customers/{id}/engagement` | Browsing and instrumented events (`days`, 1–365, default 90) |
| `GET` | `/ltv/customers/{id}/next-offer` | The customer's next-offer recommendation, with why |
| `GET` | `/ltv/breakdown` | LTV by acquisition dimension (`by`: `campaign`, `ppc_account`, `landing_page`, or `product`) |
| `GET` | `/ltv/cohorts` | Revenue by acquisition month and months since first seen (`months`, 1–24, default 6), in the account's timezone |
| `GET` | `/ltv/mrr` | Active MRR/ARR, status counts, monthly churn with its inputs |
| `GET` | `/ltv/predict` | A deterministic LTV projection with its guards and inputs (`by` as for breakdown) |
| `GET` | `/ltv/products` | The product catalog, newest first |
| `GET` | `/ltv/subscriptions` | Subscriptions joined to customers (`status`: `trialing`, `active`, `past_due`, `paused`, `canceled`) |
| `GET` | `/ltv/companies` | Companies (ABM accounts) with live rollups |
| `GET` | `/ltv/abm`, `/ltv/abm/company?name=` | Companies ranked by engagement; one company's detail (`days`, 1–365) |
| `GET` | `/ltv/fields` | Custom field definitions |
| `GET` | `/ltv/webhooks`, `/ltv/webhooks/{id}/deliveries` | Webhooks (never their secrets); one webhook's delivery log |
| `GET` | `/ltv/integrations` | Integration records with their config |

**The window** (summary, customers, breakdown, predict) is the customers'
acquisition: `period` — `today`, `yesterday`, `last7`, `last14`, `last30`,
`last90`, `thismonth`, `lastmonth`, `thisyear`, `lastyear`, `alltime` —
counted from the account's midnight, or `time_from`/`time_to` as the
reports take them (unix seconds, a date in the account's timezone, or a
time with its offset). Anything else is a `422` with the list: `period=0`
and `period=last7d` used to answer over all time as though they were the
window asked for.

**Custom-field filters** (the same four reads): up to three `cf.<key>=value`,
or `cf.<key>.min=` / `cf.<key>.max=` for a number or date field. A key that
is not a field is a `422` naming it (`cf.nope`).

**Lists** take `limit` (1–500, default 50) and `offset`; `GET
/ltv/customers` also takes `sort` (`total_revenue`, `order_count`,
`last_activity_time`, `first_seen_time`, `mrr`), `dir` (`ASC`/`DESC`), `q`
(reference, email, company or name contains) and `segment` (`repeat`: two or
more orders; `subscribers`: an active subscription; `at_risk`: a past-due
one). A value off a list, or a `limit` that is not a whole number in range
(`abc`, `1000`), is a `422` naming the parameter and its range — never one
row, never the first 500 read as all of them.

```bash
p202 ltv summary --period last30
p202 ltv customers --search acme --segment repeat --sort total_revenue
p202 ltv customers --cf plan=pro --cf score.min=50 --all
p202 ltv customers 42
p202 ltv breakdown --by campaign --period thismonth
p202 ltv cohorts --months 12
p202 ltv predict --by ppc_account
p202 ltv subscriptions --status past_due
```

## Revenue from another system: `POST /ltv/revenue`

```json
{"customer_ref": "CUST-77", "amount": 49.00, "idempotency_key": "ORD-1001",
 "external_ref": "ORD-1001", "items": [{"sku": "PRO-1", "name": "Pro plan", "quantity": 1, "unit_price": 49}]}
```

| Field | |
|---|---|
| `amount` | Required, a number |
| `event_type` | `purchase` (default), `one_time`, `refund`, `chargeback` or `adjustment`. Renewals go through subscriptions |
| `currency` | The account's (the default); any other is a `422` |
| `occurred_at` | Unix seconds (default now) |
| `idempotency_key` | Your id for the event: the same key again records nothing and answers `200` with the first event and `"duplicate": true` |
| `external_ref`, `transaction_id` | Your references, kept on the event |
| `items` | Line items, read as a conversion's are ([Nested objects](10-conversions.md#nested-objects)) |
| the customer keys | Required; see [Naming the customer](#naming-the-customer) |

A refund or chargeback is sent positive and stored negative. A negative
`purchase` or `one_time` is a `422`: negative money is a refund, chargeback
or adjustment (a negative purchase would count an order while draining
revenue). `201` with `{event_id, customer_id, duplicate: false}`; the same
`idempotency_key` again answers `200` with `duplicate: true`, even when the
body changed (the key names the event). A customer created for a write that
is then refused (a bad line item) is rolled back with it.

```bash
p202 ltv revenue record --customer-ref CUST-77 --amount 49.00 --idempotency-key ORD-1001 \
    --item '{"sku":"PRO-1","quantity":1,"unit_price":49}'
p202 ltv revenue record --customer-id 42 --amount 49 --event-type refund --idempotency-key ORD-1001-R
```

## Subscriptions

`POST /ltv/subscriptions` creates or updates a subscription keyed by
`external_sub_id`, your billing system's id; sending it again updates it
(and may move it to another customer). MRR is `amount` normalized to a month
(a trial carries none).

| Field | |
|---|---|
| `external_sub_id` | Required, at most 191 characters |
| `amount` | **Required**: what one billing period charges, 0 to 999999999.99999 (0 for a free plan). The upsert replaces the row, so an update without it stored 0 and took the subscription's MRR with it |
| `billing_interval` | `day`, `week`, `month` (default) or `year`; `billing_interval_count` intervals per charge (default 1; 3 with `month` is quarterly) |
| `status` | `trialing`, `active` (default), `past_due`, `paused` or `canceled` |
| `plan_name`, `currency`, `grace_days` | `grace_days`: days past the period end before it is past due |
| `started_at`, `current_period_start`, `current_period_end` | Unix seconds; the period end must be after its start |
| the customer keys | Required |

`POST /ltv/subscriptions/{external_sub_id}/events` records what happened to
it: `event_type` `renewal` (adds a renewal to the ledger — `amount`, or the
subscription's own — extends the paid-through period to
`current_period_end` or one interval on, and reactivates it), `refund`
(negative revenue) or `cancel` (no money moves). `transaction_id` or
`idempotency_key` makes a retried renewal or refund a replay, answered with
`"changed": false`. An unknown subscription is a `404`. Percent-encode an id
that holds a character a path escapes.

```bash
p202 ltv subscription upsert --external-sub-id sub_123 --customer-ref CUST-77 --amount 29 --interval month
p202 ltv subscription event sub_123 --type renewal --transaction-id ch_889
p202 ltv subscription event sub_123 --type cancel
```

## Customers

| Method | Path | |
|---|---|---|
| `POST` | `/ltv/customers` | Find the customer by `customer_id`, or by `customer_ref` (+ `customer_ref_type`), creating it when no customer holds the reference; then apply the record fields |
| `PATCH` | `/ltv/customers/{id}` | Change the record fields of the customer the path names |
| `POST` | `/ltv/customers/{id}/merge` | `{"source_customer_id": N}`: move the source's aliases, revenue, subscriptions and field values onto `{id}` and retire the source. Not reversible |
| `POST` | `/ltv/customers/{id}/aliases` | `{"type", "value"}`: another identifier that resolves to the customer. One already mapped to another customer is a `422` pointing at merge |
| `DELETE` | `/ltv/customers/{id}/aliases/{aliasId}` | Remove one identifier; the customer and its revenue stay |
| `DELETE` | `/ltv/customers/{id}` | **Erase** (GDPR): CRM fields, aliases (and their identity-graph signals) and field values go, the reference becomes `erased:<id>`; the customer row, its revenue and its subscriptions stay, so totals do not change. Not reversible; `?dry_run=1` shows what goes and what stays |
| `POST` | `/ltv/customers/{id}/next-offer/impression` | Record that you delivered the next offer through a channel the tracker cannot see; `campaign_id` (default: the current recommendation; a `422` when there is none) |
| `POST` | `/ltv/events` | An engagement event (`event`, `value` a number, `occurred_at`) on a customer named by the customer keys; it pays nothing |

The record fields are the CRM fields above, `aliases` (a list of `{type,
value}`) and `custom_fields` (an object keyed by your field keys; a key with
no field definition is a `422` — define it first with `POST /ltv/fields`).
Each CRM field is a string at most as long as its column (`country` is a
two-letter code; `email` must be an address); `null` or `""` clears it.
`PATCH` refuses `customer_id`, `customer_ref` and `customer_ref_type`: the
path names the customer, and another reference is an alias. Every write
answers with the customer in full.

```bash
p202 ltv customer upsert --customer-ref CUST-77 --email ada@example.com --first-name Ada --field plan=pro
p202 ltv customer update 42 --phone "" --alias esp_id=K-991
p202 ltv customer merge 42 --from 57 --force
p202 ltv customer erase 42 --dry-run
p202 ltv customer alias add 42 --type merchant_id --value M-77
```

## Catalog, fields, companies

| Method | Path | |
|---|---|---|
| `POST` | `/ltv/products` | Create or update a product keyed by `external_product_id`, or by `sku` when there is none (stored as `sku:<sku>`); `name`, `price` (0 or more) |
| `PATCH` | `/ltv/products/{id}` | `name` (not blank), `sku` and `price` (`null`/`""` clears either); the key does not change. Past line items keep the name they sold under |
| `DELETE` | `/ltv/products/{id}` | Refused (`409`, with the count) while an order line item names the product |
| `POST` | `/ltv/fields` | `field_key` (1–64 of `a-z`, `0-9`, `_`; fixed), `field_type` (`text`, `number`, `date`, `boolean`, `select`, `email`, `url`; fixed), `label`, `options` (a `select` field's choices, refused on any other), `is_required` (a flag), `sort_order` |
| `PATCH` | `/ltv/fields/{id}` | `label`, `options`, `is_required`, `sort_order`; `field_key` and `field_type` are refused. Answers with every field |
| `DELETE` | `/ltv/fields/{id}` | The field and every customer's value for it |
| `POST` | `/ltv/companies` | `name` (required, unique), `domain` (customers with that email domain join automatically); a duplicate is a `409` |
| `PATCH` | `/ltv/companies/{id}`, `POST /ltv/companies/{id}/merge`, `DELETE /ltv/companies/{id}` | Rename or change the domain; merge `source_company_id` in; delete one with no customers (one with customers is merged instead) |

```bash
p202 ltv product upsert --sku PRO-1 --name "Pro plan" --price 49
p202 ltv fields create --key plan --type select --option free --option pro
p202 ltv fields update 3 --required=false
p202 ltv company create --name Acme --domain acme.com
```

## Webhooks

`POST /ltv/webhooks` registers an https endpoint (`url`; not a private,
loopback or metadata address) for signed events. The response holds the
webhook's `secret` once; it is never shown again. Each delivery is `POST
{event, occurred_at, data}` with `X-P202-Signature: sha256=<HMAC-SHA256 of
the body with the secret>` (and `X-P202-Event`, `X-P202-Delivery`),
retried with backoff and failed after 6 attempts, which marks the webhook
dead.

`events` is a list of the events this install sends — `customer.updated`,
`revenue.recorded`, `subscription.changed`, `conversion.recorded`,
`engagement.recorded` — or `["*"]` alone, which also takes the events later
versions add. Left out (or `[]`) it is every event this version sends. Any
other name is a `422` naming it by position (`events.0`) with the list: a
misspelled `revenue.recoded` used to make a webhook that received nothing,
answered `201`. A lone string is refused too (it was read as no list, and
subscribed the hook to every event).

`GET /ltv/webhooks/{id}/deliveries` is the delivery log, newest first:
`limit` 1–100 (default 25), `status` `pending`, `delivered` or `failed`,
each with its attempts, next retry, last HTTP status and the last response
or error. An unknown webhook is a `404`, never an empty log.

```bash
p202 ltv webhooks create --url https://hooks.example.com/p202 --events revenue.recorded,subscription.changed
p202 ltv webhooks deliveries 3 --status failed
p202 ltv webhooks delete 3 --dry-run
```

`POST /ltv/integrations` records an inbound integration: `provider`
(lowercase `a-z`, `0-9`, `-`, `_`), `name`, and `config` — the provider's
own settings, a JSON object stored as sent (a list is a `422`).

## Refusals an agent meets

| Answer | Means | Next step |
|---|---|---|
| `422` `customer_ref`: "Identify the customer this revenue belongs to" | A revenue, subscription or engagement write named no customer | Send `customer_ref` (your id) or `customer_id` (`p202 ltv customers`) |
| `422` `items` on `POST /conversions` | The conversion's line items found no customer (none named, the click linked to none) | Send `customer_ref` or `customer_id` with the conversion |
| `422` `items.0.qty`, `aliases.0.tpye`, `customer_crm.frist_name` | A key the nested object does not take | Use a key from the list in the message |
| `422` `amount`: "is required: what one billing period charges" | A subscription upsert without its amount | Send the amount (0 for a free plan) |
| `422` `started_at`: "a unix time in seconds" | A date where a time is read | Send unix seconds |
| `422` `period`, `sort`, `limit`, `cf.<key>` | A read parameter off its list or range | Use a listed value |
| `422` `events.0` on a webhook | An event this install never sends | One of the five names, or `*` |
| `422` `is_required` | A flag that is not one | `true` or `false` |
| `422` `staged` | An LTV write sent with `?staged=1` | Send it without |
| `422` `amount`: "Must not be negative for purchase" | A negative `purchase` or `one_time` | Send it as `refund`/`chargeback` (positive amount) or `adjustment` |
| `422` `custom_fields.<key>` | A custom field with no definition, a value of the wrong type, or a required one left out | `p202 ltv fields list`; define it first with `POST /ltv/fields` |
| `422` `customer_ref` / `customer_ref_type` | Not a string, a malformed email digest, or a type off the list | Your id as a string; a type from the list |
| `422` `source_customer_id` / `source_company_id` | Merging a record into itself, or a source the account does not have | Pick the other record (`p202 ltv customers`, `p202 ltv companies`) |
| `422` `idempotency_key` | A reserved prefix (`void:`, `sub:`, …) | Your own id for the event |
| `404` | A customer, subscription, product, field, webhook or company of another account, or none | List them first (`p202 ltv customers`, `subscriptions`, `products`, …) |
| `409` | A product an order line item names; a company name or domain in use; a company with customers attached (it was a `422`); a merge another request changed first | Leave the product; merge the companies, or pick another name; retry the merge |
