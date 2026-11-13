# Spend Ledger

What each ad platform billed, per external ad per hour. A platform's puller
or any client writes it; the [reports](11-reports.md#cost-from-the-spend-ledger)
read it as cost.

| Method | Path | Scope | Role permission |
| ------ | ---- | ----- | --------------- |
| `POST` | `/api/v3/spend-ledger` | `spend-ledger:write` | `access_to_update_section` |
| `GET` | `/api/v3/spend-ledger` | `spend-ledger:read` | none; money and counts are `null` with `masked: true` without `access_to_campaign_data` |

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
