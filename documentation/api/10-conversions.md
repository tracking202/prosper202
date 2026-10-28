# Conversions API

List, inspect, manually create, and delete conversions.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/conversions` | List conversions (paginated) |
| `GET` | `/conversions/{id}` | Get conversion details |
| `GET` | `/conversions/match-methods` | Each campaign's conversions by how their click was found ([below](#how-each-conversions-click-was-found)) |
| `GET` | `/conversions/offline-export` | The conversions as Google Ads' or Microsoft Advertising's offline conversion import CSV ([below](#offline-conversion-files)) |
| `GET` | `/conversions/offline-export/summary` | What that file holds, and why each other conversion is left out |
| `GET` | `/offline-conversions/{key}` | A fetch URL made on Setup › Offline Conversions: the file, with no API key |
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
| `source` | string | — | Only conversions this source produced (`pixel`, `postback`, `universal_pixel`, `api`, `subid_upload`, `revenue_upload`, `legacy_pixel`, `clickbank`, `paykickstart`, `jvzoo`, `app_install`, `goal`, `legacy_baseline`) |
| `match_method` | string | — | Only conversions whose click was found this way (`click_id`, `cookie`, `visitor_id`, `same_ip`, `unknown`; [below](#how-each-conversions-click-was-found)) |
| `goal` | integer | — | Only this goal's outcomes, under every version of it |

A filter value that cannot be read — `click_id=7x`, an unknown `source` or `match_method`, a
`goal` or `campaign_id` that is not a positive id (`campaign_id=0` included,
which used to list every campaign's conversions) — is a `422` naming the
field, never ignored. Deleted conversions are not listed; `GET /clicks/{id}/conversions`
shows them, with whether each row counts toward the click.

## Response Fields

`conv_id`, `click_id`, `transaction_id`, `campaign_id`, `click_payout`, `user_id`, `click_time`, `conv_time`, `deleted`, `aff_campaign_name`, and the row's provenance in the ledger: `source`, `source_ref` (`goal:<id>:<version>`, `batch:<upload>`, `conv:<the conversion a reversal reverses>`, `apikey:<digest of the key that wrote it>`), `match_method` (how its click was found), `event_name`, `payable`, `reverses_conv_id`, `superseded_by`, `superseded_reason`, and for a goal row its `goal_id` and `goal_version`.

A conversion created through this API records `source: "api"` and names the
key that wrote it in `source_ref` as a truncated SHA-256 digest of the key, so
the click breakdown can say which key it was without anyone reading the key
back. Its `match_method` is `click_id`: the body named the click.

## How each conversion's click was found

`source` says what sent a conversion; `match_method` says how the click it
credits was found, so a guess never reads as a match. Since 1.9.79 every
conversion stores one:

| `match_method` | The click was found by | Paths |
| -------------- | ---------------------- | ----- |
| `click_id` | the request naming it | a `subid`/`sid` on `gpx.php`, `upx.php`, `gpb.php` and `pb.php`; ClickBank's tracking code (`cb202.php`); `POST /conversions` and `POST /events`; a line of Update Subids or of a revenue upload; an app install's signed token, and the goals its events reach |
| `cookie` | a tracking cookie in the visitor's browser | `gpx.php` and `upx.php` with no subid (the campaign's `tracking202subid_a_<cid>` cookie, then `tracking202subid`); `px.php` |
| `visitor_id` | the landing-page script's own visitor id (`p202lpid`), on the click it was last seen on | `p202.track()` events (`event.php`) |
| `same_ip` | nothing naming a click: the account's latest click from the visitor's address in the last 30 days, a guess, made only for an account with the [same-IP fallback](14-users.md#same-ip-fallback-user_pref_same_ip_fallback) on and a visitor the privacy setting does not hold back | `gpx.php`, `upx.php` and `px.php` with neither a subid nor a cookie |
| `unknown` | not recorded | a conversion recorded before 1.9.79, the `legacy_baseline` row that carries a pre-ledger value, and a goal reached by an event stored before 1.9.79 |

A goal's conversion carries the method of the event that reached it, whenever
the row is written (a replay or a re-evaluation writes rows for events stored
earlier); a reversal carries its own request's. A retry of a conversion
already recorded changes nothing, its method included. `unknown` is the one
spelling for "not recorded": it is the column's default, so every row from
before 1.9.79 holds it, and no reader takes it for a match.

`GET /conversions?match_method=same_ip` lists the guesses, and
`GET /clicks/{id}/conversions` gives each row's `match_method` with its
label. `GET /conversions/match-methods` counts them per campaign:

| Parameter | Description |
| --------- | ----------- |
| `campaign_id` | Only this campaign (a positive id, or a `422`) |
| `period` | A named window of conversion times (`today`, `last7`, `last30`, `thismonth`, `alltime`, …) |
| `time_from`, `time_to` | Conversion-time bounds, as on the list; with `period`, both apply |

```json
{
  "data": [
    {"campaign_id": 3, "campaign_name": "Summer offer", "match_method": "click_id", "match_method_label": "Click id",
     "conversions": 40, "counted": 38, "income": 190.0},
    {"campaign_id": 3, "campaign_name": "Summer offer", "match_method": "same_ip", "match_method_label": "Same IP",
     "conversions": 6, "counted": 6, "income": 30.0}
  ],
  "totals": [
    {"match_method": "click_id", "match_method_label": "Click id", "conversions": 40, "counted": 38, "income": 190.0},
    {"match_method": "cookie", "match_method_label": "Cookie", "conversions": 0, "counted": 0, "income": 0.0}
  ],
  "match_methods": ["click_id", "cookie", "visitor_id", "same_ip", "unknown"]
}
```

One row per campaign and method that has conversions; `totals` lists every
method over the campaigns `data` covers (every campaign, or the one
`campaign_id` names; shortened above). `conversions` counts the live
conversions that are not reversals, `counted` those that count toward their
click's value, and `income` what the counting rows add up to, reversals
netted (a reversal is under its own request's method). A role without access
to campaign data gets the three figures as `null` and `masked: true`. Group
Overview's **Match method** level shows the same split by click time, with
each group's clicks and cost.

## Offline conversion files

`GET /conversions/offline-export?format=google` answers the account's
conversions as an ad platform's offline conversion import file (`text/csv`),
the one Setup › Offline Conversions downloads and its fetch URLs serve. The
[offline conversions guide](../features/offline-conversions.md) says what
each platform's file holds, where to upload it, and how to have the platform
fetch it on a schedule.

| Parameter | Type | Default | Description |
| --------- | ---- | ------- | ----------- |
| `format` | string | required | `google` (Google Ads Data Manager: `Google Click ID`, `GBRAID`, `WBRAID`), `google_uploads` (Google's Conversions › Uploads template: a `Parameters:TimeZone=` line, `Google Click ID`) or `microsoft` (Microsoft Advertising: `Parameters:TimeZone=+0000`, `Microsoft Click ID`) |
| `days` | integer | 90 | Conversions from the last this many days, by conversion time (1 to 90) |
| `campaign_id` | integer | — | Only this campaign's conversions |
| `conversion_name` | string | — | Every line under this name, whatever its campaign's |
| `ad_user_data`, `ad_personalization` | string | empty | Google's consent columns, the same on every line: `granted` or `denied`. Refused for `microsoft` |

A conversion is a line when all of these hold, and is counted in the summary
under the first that does not:

1. it counts now: not deleted, not refunded in full, not replaced by a later
   conversion on its click, payable (`not_counted`); a partial refund is
   netted from its value;
2. its click was named: `match_method` `click_id` or `cookie`. A click found
   by the landing page's visitor id (`visitor_id`), guessed from the visitor's
   address (`same_ip`) or recorded before 1.9.79 (`match_unknown`) would teach
   the platform's bidding clicks the conversion may not have come from;
3. the click came with an id the platform issues (`no_click_id`; for
   `google_uploads`, a click with only a gbraid or wbraid is
   `gbraid_wbraid_only`), in the form the platform issues
   (`malformed_click_id`: an msclkid is 32 hexadecimal digits, as Microsoft
   documents it; Google documents no form for its ids, which are held to
   base64url characters);
4. the click is at most 90 days old (`click_too_old`), before the conversion
   (`before_click`), this account's (`not_accounts_click`) and at least 6
   hours old (`click_too_recent`: a later fetch whose window still covers it
   sends it);
5. its value is not negative (`negative_value`), and it has a conversion name:
   its campaign's or the account's, set on the Setup page, or `conversion_name`
   (`no_conversion_name`);
6. a Microsoft file stops at Microsoft's 100,000 rows an upload, newest first
   (`over_row_limit`).

Each line's `Conversion Time` is the conversion's in the account's time zone
with the offset in force at that instant (`2026-10-27T02:22:48-04:00`), or in
UTC for Microsoft, whose time zones are its own names. Both platforms count
one conversion per click and second, whatever its Order ID (Google's click
is the line's gclid, or in a `google` file its gbraid or wbraid when it has
no gclid; Microsoft's the msclkid), so of two conversions on one click in one
second, the one recorded first keeps its time and the other is sent at the
next second no conversion on that click holds, the same second on every
download ([the guide](../features/offline-conversions.md#the-files) says
when it moves). `Conversion Value` is
the amount the conversion counts for, as the ledger records it in the
account's currency (`Conversion Currency`), three decimals for Microsoft.
`Order ID` is `P202-` and the conversion's id: the same on every download, so
Google counts a conversion uploaded twice once. A refund or deletion after a
platform has a conversion is not sent to it; adjust it in the platform.

The answer's `X-P202-Rows` and `X-P202-Left-Out` headers count the lines and
the conversions left out. `GET /conversions/offline-export/summary` takes the
same parameters and answers the counts: `conversions` in the window (a
reversal is not one), `rows`, `left_out` by reason (every reason listed),
`left_out_total`, and each reason's sentence under `reasons`. Both need the
`access_to_campaign_data` permission: the file carries every value. An
unknown parameter, or a value not listed, is a 422 naming it.

`GET /offline-conversions/{key}` is a fetch URL made on the Setup page, which
shows it once and keeps only its key's hash. It takes no API key: the 43
characters of the key are the credential. It serves the URL's file (its
format, window, conversion name and consent columns), and answers `404` to a
key of the wrong shape, an unknown, replaced or deleted key, and the key of a
deactivated account or of a role that no longer reads campaign data, alike.

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
| `payout` | decimal | No | Override payout (defaults to the click's campaign payout). At most what one conversion holds, -999999.99999 to 999999.99999 once rounded to five places (`click_payout` is `decimal(11,5)`); a larger one is a `422` naming `payout`, not a value cut to the column |
| `transaction_id` | string | No | Deduplication key: a string of at most 255 bytes, or a JSON integer up to 9223372036854775807 read as its digits. A longer number, a fraction, `true` or an object is a `422` naming it; send a longer number as a string, because JSON decoding makes it a floating-point number, which cannot tell `12345678901234567891` from `12345678901234567892` |
| `conv_time` | integer | No | Unix timestamp (defaults to now) |
| `status` | string | No | Only `"reversed"`: records a reversal of the click's conversion with this `transaction_id` instead of a new conversion (a sale is reversed once; `404` when the click has no such conversion) |
| `reversal_id` | string | No | With `status: "reversed"`, the network's id for the reversal (a non-empty string of at most 255 bytes, or a JSON integer, as `transaction_id`); without that status it is a 422, since the body would otherwise record a new sale |
| `customer_id` | integer | No | The LTV customer the revenue belongs to; it wins over `customer_ref`. Anything but a positive id (`0`, `""`) is a `422` |
| `customer_ref` | string | No | Your id for the customer: resolved to one, or a customer is created for it. `"0"` is an id like any other; a blank one is a `422`, as is anything but a string or a JSON integer (read as its digits), or one past 255 characters |
| `customer_ref_type` | string | No | What `customer_ref` is: `email_md5`, `email_sha256`, `esp_id`, `merchant_id`, `subid` or `custom` (the default); read only with `customer_ref`, and a value not on that list is a `422` with or without one |
| `customer_crm` | object | No | CRM fields (`first_name`, `last_name`, `email`, `phone`, `company`, `address_line1`, `address_line2`, `city`, `region`, `postal_code`, `country`) applied only when this conversion creates the customer. Needs a customer, as `items` does. See [Nested objects](#nested-objects) |
| `items` | array | No | Product line items on the customer's revenue event: each `{external_product_id or sku, name, quantity, unit_price, amount, price}`. Needs a customer: named here (`customer_ref` or `customer_id`), already linked to the click, or found by the account's customer c-param. See [Line items need a customer](#line-items-need-a-customer) and [Nested objects](#nested-objects) |

Creates are idempotent on `transaction_id`: if a conversion with the same
`transaction_id` already exists for the given `click_id`, nothing is written
and the existing conversion is returned, with `"duplicate": true` beside
`data` (the field is absent on a new conversion; the status is `201` either
way, as for an `Idempotency-Key` replay). Without a `transaction_id`, an
`accumulate` campaign records one plain conversion per click (a repeat is a
duplicate), and a `replace` campaign records a row on every request, so a
retried request without one records another conversion there.

A request that matches a recorded conversion this way is that sale again
only when it states what was recorded: the `payout` (compared as the ledger
stores it, so `"10.00"` is `10`), `conv_time`, the customer it names and its
`items`, each compared when the request sends it. A different one is a
`422` naming `transaction_id`, with what differs in the message (`payout
recorded 10.00000, sent 20.00000`); nothing is written and an
`Idempotency-Key` sent with it is not spent. It used to answer `201
duplicate` with the first conversion, so a corrected payout sent under the
same transaction id was dropped. Send it as recorded to get the conversion
back, a different sale with its own `transaction_id`, or take the recorded
one back with `"status": "reversed"`. A postback or pixel keeps the first
statement of a sale, as networks resend theirs.

A deleted conversion keeps its place in the click's ledger, so a request that
repeats one (the same `transaction_id`, the same reversal, or an `accumulate`
click's plain conversion) is refused with `409`, naming it in
`details.conv_id` with `details.deleted: true`. Nothing is written, and an
`Idempotency-Key` sent with it is not spent. A different sale needs its own
`transaction_id`. A `409` without `details` is about the `Idempotency-Key`
instead: a request holding it is still in flight, or one did not finish.

### A click a redirector link re-clicked

A click a visitor came back to through a redirector link has a row for each
visit ([Clicks](09-clicks.md#a-click-a-redirector-link-re-clicked)). A
conversion on it is credited to its latest visit: `campaign_id` is the
campaign that visit was on, whose payout mode applies and whose payout is
the default, and `click_time` is that visit's. Before
1.9.79 it took whichever visit the database read first, usually the first.
The same holds for the pixels and postbacks, Update Subids, revenue uploads,
goals and app installs, and `GET /clicks/{id}/conversions` shows the latest
visit's value.

The per-campaign postback (`pb.php?acip=…&subid=…`, with or without `event=`)
accepts a click any of whose visits was on the campaign it names, when that
campaign is the click's account's, and credits the latest visit: the network
posts back for the offer it sold, which may be on any visit of the click.
Before 1.9.79 only the campaign of the visit the database read first was
accepted. Any other click is answered `404` "Unknown subid for this
campaign", as before.

### Line items need a customer

`items` are stored on the customer's revenue event and `customer_crm` on the
customer record, so both need a customer. One resolves from, in order:
`customer_id`, `customer_ref` (a customer is created for a new one), the
customer already linked to the click (an earlier conversion on it named one),
and the account's customer c-param (LTV settings: which of the click's c1–c4
carries your customer id, `user_ltv_customer_cparam`). When none does, the conversion is refused with `422`
naming `items` (and `customer_crm`, when sent) and saying to send
`customer_ref` or `customer_id`; nothing is written, and an `Idempotency-Key`
sent with it is not spent. They used to be dropped, the conversion recorded
without them and answered `201`.

```json
{"error": true, "status": 422,
 "message": "No customer is linked to this conversion, so its items would be stored nowhere: nothing was recorded",
 "field_errors": {"items": "line items are stored on the customer's revenue event, and no customer is linked to this conversion (the click has none, and none was named): send customer_ref (your id for the customer, with customer_ref_type) or customer_id (`p202 ltv customers`) with it. Nothing was recorded."}}
```

The pixels and postbacks (`gpb.php`, `gpx.php`, `upx.php`) take one product
as `sku` / `product_id` (+ `product_name`, `qty`, `unit_price`) and a
customer as `cust` (+ `cust_type`). They have nobody to answer, and refusing
would lose the conversion as well, so a hit whose product finds no customer
records the conversion without the product and writes a line to the PHP
error log: `p202 conversion <conv_id> on click <click_id>: items not stored,
no LTV customer is linked to the click …`. Send `cust=` on such a pixel.

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
