# Spend Ledger

What each ad platform billed, per external ad per hour. A platform's puller
or any client writes it; the [reports](11-reports.md#cost-from-the-spend-ledger)
read it as cost.

| Method | Path | Scope | Role permission |
| ------ | ---- | ----- | --------------- |
| `POST` | `/api/v3/spend-ledger` | `spend-ledger:write` | `access_to_update_section` |
| `GET` | `/api/v3/spend-ledger` | `spend-ledger:read` | none; money and counts are `null` with `masked: true` without `access_to_campaign_data` |
| `GET` | `/api/v3/spend-ledger/conversions` | `spend-ledger:read` | none; masked as above |
| `GET` | `/api/v3/spend-ledger/sync` | `spend-ledger:read` | `access_to_setup_section` |
| `PUT` | `/api/v3/spend-ledger/sync/{id}` | `spend-ledger:write` | `access_to_setup_section` |
| `POST` | `/api/v3/spend-ledger/sync/{id}/run` | `spend-ledger:write` | `access_to_setup_section` |

## Writing

```json
POST /api/v3/spend-ledger
{"rows": [
  {"platform": "openai_ads", "account_ref": "adacct_demo", "campaign_ref": "cmp_123",
   "ad_group_ref": "adgrp_45", "ad_ref": "ad_678", "hour_start": 1794297600,
   "impressions": 1200, "clicks": 31, "spend": 18.42, "currency": "USD"}
]}
```

- `hour_start` is the unix second the UTC hour begins (a multiple of 3600),
  for an hour that has started.
- `spend` is in the currency's major units (18.42 dollars), with up to six
  decimals: a number, or a string to be exact. A number with more decimals is
  refused, never rounded.
- `currency` must be the account's: nothing here converts currencies, so
  another one is refused naming both.
- `campaign_ref` and `ad_group_ref` may be `null` or left out when the
  platform names none. `clicks` is the platform's own count.
- A row is stored under its platform, ad account, ad and hour. Sending an
  hour again replaces it (re-send the trailing hours while spend settles);
  sending the same rows again changes nothing, so retrying a write that
  may have failed is safe. The last write of an hour wins: send hours in the
  order you read them from the platform.
- An ad id names one ad: a row whose `ad_ref` the account already holds under
  another platform or ad account is refused, since clicks carry only the ad id.
- On an account with a traffic source marked as ChatGPT Ads (Setup › Traffic
  Sources › Add ChatGPT Ads marks it), rows of the platform `openai_ads`
  (exactly that name) are matched only to the clicks of such sources;
  otherwise, and for any other platform name, to the ad id on any traffic
  source of the account
  ([Cost from the spend ledger](11-reports.md#cost-from-the-spend-ledger)).
- An id must not start or end with a space, and `hour_start` is from 2000 on.
  `ad_ref` must be ASCII, as ad platforms' ids are: a click's ad id is matched
  whatever its case, which only ASCII does exactly.
- Up to 2000 rows per request, written in one transaction. Any refused row
  (an unknown field, a value that is not exact, one key twice) is a `422`
  naming it (`rows.3.spend`), and nothing is written.
- `?dry_run=1` answers the same counts and writes nothing.

The answer: `{"data": {"dry_run": false, "received": 1, "inserted": 1, "replaced": 0, "unchanged": 0}}`.

## Reading

`GET /api/v3/spend-ledger` takes `period`, `time_from`, `time_to` (as the
reports do), `interval` (`day`, in the account's time zone with each hour
converted at its own instant, or `hour`), `platform` and `limit` (1 to 5000,
default 500). Each row is one period of one ad, newest first, with
`impressions`, `platform_clicks`, `spend`, `matched_clicks` (this account's
clicks carrying the ad in those hours: for `openai_ads` on an account with a
marked ChatGPT Ads source, those sources' clicks only) and `unallocated_spend`
(the spend of the hours no click carried it in). `reaches` is the end of the latest hour
the ledger holds.

## Platform conversions

`GET /api/v3/spend-ledger/conversions` takes `period`, `time_from`,
`time_to`, `platform` and `limit` (1 to 5000, default 500; `truncated` says
more rows matched). Each row is one ad on one day in the ad account's time
zone, as the platform reports it: `platform_conversions`, `click_through`,
`view_through`, `sales` and `sales_currency`, beside
`prosper202_conversions`, the conversions of this account's clicks that
carried the ad that day, tied to the ad as its spend is (an ad id matches
whatever its case and trailing spaces). The ChatGPT Ads spend sync fills it.

## The ChatGPT Ads spend sync

Each ChatGPT Ads connection ([ad connections](28-ad-connections.md)) can pull
what OpenAI billed per ad per hour into this ledger every hour. It is off
until turned on.

- `GET /api/v3/spend-ledger/sync` lists each connection's sync: `enabled`,
  `backfilled_at` (when a run first read through the 30 days to now; null until then),
  `last_run_at`, `complete` (whether the last run was), `last_success_at` (the last complete run), `last_rows`,
  `last_error` (a failed run), `last_note` (what the last run noted),
  `left_out`, `pending_from` and `retry_after` (when the hourly run tries
  again after a rate limit or a refused key).
- `PUT /api/v3/spend-ledger/sync/{id}` with `{"enabled": true}` or `false`.
  `id` is the ad connection's. Turning it on clears `left_out`.
- `POST /api/v3/spend-ledger/sync/{id}/run` runs it now. It takes no body.

The first run reads the last 30 days (what OpenAI keeps hourly); later runs
re-read the trailing 72 hours, since spend settles after the clicks, and so
does any run that reaches now. After a pause (the sync turned off, or no run
for a while), the next run starts 72 hours before where the last one read to,
so the gap is read; hours a pause left unread past OpenAI's 30 days are named
in `left_out`. A run reads a day at a time (an hour at a time when it has
less than 15 seconds) and stops between pages at its budget (500 pages, and
30 seconds for a run asked for here, PHP's time limit raised to cover its last
request where it may be; the hourly run's two minutes for all connections).
What it read stands, recorded after each day or hour together with the hours
it left out, and `pending_from` is the first hour it did not reach: every
later run starts there, so a long backfill, a stopped or killed run or an
outage loses nothing OpenAI still keeps. Conversions a run could not read are
read by the next.

Spend with more than six decimals is rounded half up from its decimal text.
**A row the ledger still refuses** (negative, null, not a number, too large)
is left out and the rest of its page is written, but the run is **not
complete** (nor is any run while `left_out` says something): `last_success_at` is not set, and `left_out` names the rows until
a run reads those hours again without leaving one out. Such an hour does not
hold the run back: it goes on past it, and once a run has reached now it reads
the left-out hours again, oldest first, at most three windows of a day a run
within its budget, one still left out going behind the rest. Setup › Traffic Sources shows it as a warning. Once those hours are older than the 30 days
OpenAI keeps, `left_out` says so and stays until the sync is turned off and
on; enter that spend with `POST /api/v3/spend-ledger`.

A run's answers (the run is recorded either way):

| Status | When | Body |
|--------|------|------|
| `200` | The run got through | `data.run`: `rows`, `from`, `to` (how far it read), `complete`, `note`; beside the sync's state |
| `404` | No ChatGPT Ads connection by that id | |
| `422` | The sync is off | `field_errors.enabled` |
| `422` | The ad account bills in another currency than the account (nothing is converted) | `field_errors.connection` |
| `422` | The ledger refused a page OpenAI sent; the pages before it stand | `field_errors.spend` |
| `422` | OpenAI refused the request itself (a 4xx other than 401, 403, 413 and 429): sending it again gets the same answer | `field_errors.request`, in OpenAI's words with its status |
| `422` | OpenAI refused the connection's Advertiser API key (401, 403) | `field_errors.api_key`; the connection is marked `key_refused` as a check marks it, and the hourly run waits an hour, doubling to a day with each refusal in a row, until the key is replaced (`PATCH /ad-connections/{id}`); a later run OpenAI answers with the key marks it `connected` again |
| `422` | A body was sent | `field_errors` names its keys |
| `429` with `Retry-After` | OpenAI answered 429, or this install is holding to its per-minute limits | `Retry-After` is OpenAI's own wait (60 when it named none): a run asked for here may go again after it. `details.reason` `rate_limited`, `details.retry_after` (when the hourly run tries again: an hour, doubling to a day with each 429 in a row, or OpenAI's Retry-After when longer, up to a week) |
| `503` with `Retry-After` | No answer from OpenAI, a 5xx of its own, or the request was not sent | `details.reason` `unreachable`; `Retry-After` is OpenAI's, else 60 |
| `502` | OpenAI's answer cannot be used: not JSON, cut off, not a page of insights, or a cursor it gave before | `details.reason` `unreadable_answer` |

`details` also carries `upstream` (`openai_ads`), `connection_id` and
`rows_written`: a run that fails after writing rows keeps them, and its message
says how many (the ledger replaces each hour, so running it again adds nothing
twice).
`p202 spend sync run` exits 7 (rate limited) for the `429`, 4 (server) for
the `503` and `502`, and 1 for each `422`, with a hint for what to change.
