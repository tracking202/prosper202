# Update API (CPC, subids, revenue reports)

The UI's **Update** section as REST: set what a set of past clicks cost,
mark subids converted, delete subids, reset a campaign's subids, and upload a
network's revenue report. Each endpoint runs the page's own code (the same
classes behind `tracking202/update/`), so a request here and a form there do
the same thing.

## Endpoints

| Method | Path | The page | Writes |
| ------ | ---- | -------- | ------ |
| `POST` | `/clicks/cpc` | Update CPC | `click_cpc` of the clicks a check counted |
| `POST` | `/conversions/subids` | Update Subids | a `subid_upload` conversion per click, once |
| `POST` | `/conversions/subids/delete` | Delete Subids | clears each click's conversions |
| `POST` | `/conversions/subids/reset` | Reset Campaign Subids | clears a category's or campaign's converted clicks |
| `POST` | `/conversions/uploads` | Upload Revenue Reports | a `revenue_upload` conversion per CSV line, as a new batch |

## What every one of them shares

- **Permission.** The role permission the pages ask for:
  `access_to_update_section` on every endpoint, and
  `delete_individual_subids` as well on `/conversions/subids/delete` (a
  Campaign manager has the Update section but not that). A role without it is
  a `403` naming the permission — on a dry run too.
- **Scope.** `clicks:write` for `/clicks/cpc`, `conversions:write` for the
  rest. A preview needs the write scope, as a `DELETE ?dry_run=1` does.
- **Preview.** `?dry_run=1` answers what the write would do
  (`"dry_run": true`) and writes nothing. It goes in the query string; in the
  body it is refused. `dry_run=tru` (any value but `1/true/yes/0/false/no`)
  is a `422`, never the write.
- **Strict input.** An unknown field is a `422` naming it (a misspelled
  filter must not widen an update to every click); an id that is not a whole
  number, a subid list item that is not a string or integer, a CPC with more
  than five decimals — each is refused under its field, never cast.
- **Not stageable.** `?staged=1` is a `422`. Run the dry run, then the write.
- **Safe to send again.** Marking a converted subid adds nothing, clearing a
  cleared one clears nothing, a CPC confirm that no longer counts the same
  writes nothing, and a report uploaded twice records it as a newer batch
  that replaces the first's values.
- **Stopped part-way.** The subid and upload endpoints write one click (one
  line) per transaction. If one fails, those before it stay written, and the
  answer is a `500` whose message says how many, and that sending the same
  request again is safe:

  ```json
  {"error": true, "status": 500, "message": "Marking stopped at line 13 (subid 940013): 12 subids were marked before it and stay marked. Sending the same list again is safe: a subid already marked is left as it is, so only the rest are marked."}
  ```

## Update CPC — `POST /clicks/cpc`

Sets the cost of past clicks: every click of the account between `from`
00:00:00 and `to` 23:59:59 **in the account's time zone** (`user_timezone`,
answered as `timezone`), narrowed by any filter. Two steps.

**1. Check** (`?dry_run=1`): count the clicks, and name the highest click id
among them.

```bash
curl -X POST "$P202/api/v3/clicks/cpc?dry_run=1" -H "Authorization: Bearer $KEY" \
  -d '{"from": "2026-10-01", "to": "2026-10-07", "cpc": "0.25", "aff_campaign_id": 12}'
```

```json
{
  "data": {
    "dry_run": true,
    "matching": 418,
    "through_click_id": 940418,
    "cpc": "0.25000",
    "from": "2026-10-01", "to": "2026-10-07",
    "from_time": 1790827200, "to_time": 1791431999,
    "timezone": "America/New_York",
    "filters": {
      "aff_network_id": {"id": 0, "name": "Every category"},
      "aff_campaign_id": {"id": 12, "name": "Hosting offer"},
      "ppc_network_id": {"id": 0, "name": "Every traffic source"},
      "ppc_account_id": {"id": 0, "name": "Every account"},
      "landing_page_id": {"id": 0, "name": "Every landing page"},
      "text_ad_id": {"id": 0, "name": "Every text ad"},
      "method_of_promotion": {"value": "", "name": "Direct links and landing pages"}
    }
  }
}
```

**2. Update**: the same body plus `expect_clicks` (the check's `matching`) and
`through_click_id`.

```bash
curl -X POST "$P202/api/v3/clicks/cpc" -H "Authorization: Bearer $KEY" \
  -d '{"from": "2026-10-01", "to": "2026-10-07", "cpc": "0.25", "aff_campaign_id": 12,
       "expect_clicks": 418, "through_click_id": 940418}'
```

In one transaction, the clicks up to `through_click_id` are counted again
under a lock. Only if they still number `expect_clicks` does every one of them
get the new CPC, with the data engine's dirty hours and the attribution
rollup marked. The answer adds `updated`: the clicks whose cost changed (one
that already cost this much is not counted). A click recorded after the check
has a higher id and is never changed, however long the confirmation took.

When the count moved — a click edited into or out of the selection — nothing
is written and the answer is `409`, with the check done again:

```json
{"error": true, "status": 409, "message": "The clicks in this selection changed after they were counted: 418 were confirmed and 417 match now. Nothing was changed. ...",
 "details": {"expect_clicks": 418, "matching": 417, "through_click_id": 940418}}
```

Confirm the new numbers to go ahead. A write without `expect_clicks` and
`through_click_id` is a `422`.

| Field | Type | Description |
| ----- | ---- | ----------- |
| `from`, `to` | string | Required. Days, `YYYY-MM-DD`, in the account's time zone |
| `cpc` | string or number | Required. Dollars, up to 99.99999 and five decimals. A JSON number is read only when it is exactly a five-decimal value; send a string to be exact |
| `aff_network_id`, `aff_campaign_id`, `ppc_network_id`, `ppc_account_id`, `landing_page_id`, `text_ad_id` | integer | Optional filters; `0` or absent is every one. Each must be the account's (`422` otherwise, never widened) |
| `method_of_promotion` | string | `directlink`, `landingpage`, or `""` for both (default) |
| `expect_clicks`, `through_click_id` | integer | Required to write: what the check answered |

## Update Subids — `POST /conversions/subids`

Records each subid's click as converted at its campaign's payout, through the
conversion ledger (`source: "subid_upload"`), once per click. A converting
click is no longer filtered.

```bash
curl -X POST "$P202/api/v3/conversions/subids" -H "Authorization: Bearer $KEY" \
  -d '{"subids": ["940001", "940002", "abc", "940001"]}'
```

```json
{
  "data": {
    "dry_run": false,
    "marked": 2, "already_converted": 0, "not_found": 0, "not_a_subid": 1, "duplicate_in_list": 1,
    "lines": [
      {"line": 1, "subid": "940001", "click_id": 940001, "status": "marked"},
      {"line": 2, "subid": "940002", "click_id": 940002, "status": "marked"},
      {"line": 3, "subid": "abc", "click_id": null, "status": "not_a_subid"},
      {"line": 4, "subid": "940001", "click_id": 940001, "status": "duplicate_in_list", "first_line": 1}
    ]
  }
}
```

`subids` is a list of strings or integers, at most 5,000; items are trimmed
and blank ones skipped (but `line` counts them, so sending a file's lines
gives back the file's line numbers). Statuses: `marked` (`would_mark` in a dry
run), `already_converted` (the click is already a lead: nothing is added),
`not_found` (no click of this account), `not_a_subid` (a subid is a click id:
digits, no leading zero), `duplicate_in_list`. Every status is counted, 0
included.

A dry run reads each click's lead flag. The ledger can still answer
`already_converted` for a click the dry run called `would_mark`: one in an
accumulate-mode campaign whose conversion was deleted, because a deleted
conversion keeps its place in the click's ledger.

## Delete Subids — `POST /conversions/subids/delete`

The reverse: every live conversion of each subid's click is cleared through
the ledger — soft-deleted, its revenue voided, the click's value recomputed —
so the click is no longer a lead, and it is no longer filtered. The click
itself stays. A subid of another account is left alone (`not_found`).

Needs `delete_individual_subids` as well as `access_to_update_section`. Same
body and statuses as Update Subids, with `cleared` (`would_clear`). A dry run
also counts each click's live conversions (`conversions` per line and in
total), which is what the delete removes.

## Reset Campaign Subids — `POST /conversions/subids/reset`

Clears every conversion of a category, or of one campaign in it, so a report
uploaded by mistake can be uploaded again. Each converted click (a lead, or
one holding a live conversion) is cleared through the ledger, and the data
engine is told to rebuild the hours from the earliest of them to now.

```bash
curl -X POST "$P202/api/v3/conversions/subids/reset?dry_run=1" -H "Authorization: Bearer $KEY" \
  -d '{"aff_network_id": 3, "aff_campaign_id": 12}'
# {"data": {"dry_run": true, "matching": 57, "aff_network": {"id": 3, "name": "Hosting"}, "aff_campaign": {"id": 12, "name": "Hosting offer"}}}
```

`aff_network_id` is required and must be the account's; `aff_campaign_id` is
optional (absent or `0`: every campaign in the category) and must be a
campaign of that category. The write answers `cleared`.

## Upload Revenue Reports — `POST /conversions/uploads`

Records a network's commission report: every CSV line is a conversion of a new
upload batch on its subid's click (`source: "revenue_upload"`), at the line's
amount. A click's lines in one report are summed, and **the newest report
replaces** what earlier reports and conversions set for that click — so
uploading a corrected report fixes the old one. Upload lines write no LTV
purchase and emit no conversion event, as the page's never did.

```bash
curl -X POST "$P202/api/v3/conversions/uploads" -H "Authorization: Bearer $KEY" \
  -d "$(jq -n --rawfile csv march.csv '{file_name: "march.csv", csv: $csv}')"
```

```json
{
  "data": {
    "dry_run": false,
    "batch_id": 7,
    "recorded": 2,
    "skipped": 1,
    "columns": {"subid": {"index": 0, "header": "Sub ID"}, "amount": {"index": 2, "header": "Commission"}, "guessed": ["subid_column", "amount_column"]},
    "totals": [{"click_id": 940001, "total": "3.75000"}],
    "lines": [
      {"line": 1, "subid": "Sub ID", "amount": "Commission", "status": "header", "reason": "read as the header row (not a subid)"},
      {"line": 2, "subid": "940001", "amount": "1.50000", "status": "recorded", "reason": ""},
      {"line": 3, "subid": "940001", "amount": "2.25000", "status": "recorded", "reason": ""},
      {"line": 4, "subid": "940002", "amount": "abc", "status": "skipped", "reason": "the commission is not a number"}
    ]
  }
}
```

| Field | Type | Description |
| ----- | ---- | ----------- |
| `csv` | string | Required. The report's text: comma-separated, double-quoted fields, a header line |
| `file_name` | string | The name the batch is listed under (default `api-upload.csv`) |
| `subid_column`, `amount_column` | integer or string | The column by 0-based index, or by header (case ignored). Either may be left out when the header names it plainly — subid: `sub id`, `click id`, `t202…`, `aff_sub`, `sid`; commission: `commission`, `payout`, `revenue`, `amount`, `earning`, `sale`, `income` — and `columns.guessed` says which were |

Every line is reported: `recorded` (`would_record` in a dry run), `skipped`
with the reason (not a subid, the commission is not a number, no click with
this subid in your account), or `header` (line 1 when its subid cell is not a
click id). Amounts may carry `$`, thousands separators and spaces. A header
name that matches no column or more than one, an index past the header, and
the same column for both are `422`s that list the header's columns.

The request body may be up to **8 MB** (every other endpoint takes 1 MB): a
larger one is a `413` with `max_bytes`. PHP's `post_max_size` (8M by
default) and the web server in front may set their own, lower limits.
Measured on `php -S`: a 7 MB report (440,000 lines) previews in about 8
seconds.

## From the CLI

```bash
p202 click update-cpc --from 2026-10-01 --to 2026-10-07 --cpc 0.25 --aff-campaign-id 12
p202 conversion mark-subids subids.txt
p202 conversion delete-subids subids.txt --dry-run
p202 conversion reset-subids --aff-network-id 3 --aff-campaign-id 12
p202 conversion upload-revenue march.csv
```

See [the Go CLI guide](../cli/10-go-cli.md#update-cpc-subids-and-revenue-reports).
