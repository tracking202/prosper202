# Go CLI Command Contracts (Build Phases)

This file captures the API paths and payload/query expectations used by upcoming CLI features.

## Existing contracts

- `GET /api/versions` (optional capability probe)
- `GET /api/v3/capabilities` (optional capability probe)
  - `data.features.report_breakdowns`: the dimensions `reports/breakdown` accepts, from `ReportsController::breakdownDimensions()`. A dimension flag (`analytics --group-by`, `report breakdown --breakdown/--group-by`, `report crosstab --cols`, `report losers/winners/breakeven --breakdown`) probes it only for a value missing from the CLI's built-in list: listed means the value is sent, unlisted means refused with the server's list. With no config or no server the built-in list's error stands
- `GET /api/v3/reports/summary`
  - Query: report filter params (`period`, `time_from`, `time_to`, the id filters, `device_type`, `method_of_promotion`, `show`, `keyword`, `ip`, `referer`: `reportFilterFlags`, sent as given and only when set). Every report endpoint refuses a parameter it does not take with a 422 naming it
- `GET /api/v3/rotators/{id}/stats`
  - `rotator stats <id>`: the report window and filters as query params; response `data.rotator`, `data.totals`, `data.rules[]` (`rule_id`, `rule_name`, `status`, `deleted`, metrics), `data.default`. A 404 "Rotator not found" gets a hint naming `p202 rotator list`; the router's bare 404 (a server without the endpoint) gets an upgrade hint; a non-numeric id is refused before any request
- `GET /api/v3/trackers/{id}/url`
  - Response: URL payload for tracker
- `POST /api/v3/users/{id}/api-keys`
  - Response: newly created API key
- `DELETE /api/v3/users/{id}/api-keys/{api_key}`
  - Deletes a specific key by key string
- `PUT /api/v3/rotators/{id}/rules/{ruleId}`
  - Partial rule update (`rule_name`, `splittest`, `status`, `criteria`, `redirects`)
- `GET /api/v3/reports/breakdown`
  - Also used by `analytics` shorthand command
- `GET /api/v3/attribution/models` (`?type=first_touch`), `GET /api/v3/attribution/models/{id}`
  - `attribution model list/get`; `report losers/winners` read them to pick, or check, the first-touch model (`model_type`, `status`)
- `GET /api/v3/attribution/reports/breakdown`
  - Query: `group_by` (campaign, traffic_source, landing_page, keyword, c1-c4, country, device, day), `model_id`, `compare_model_id`, `period` (today, yesterday, last7, last14, last30, last90, thismonth, lastmonth, thisyear, lastyear, alltime) or `time_from`/`time_to` (unix seconds), `limit` (1-1000, default 100), `offset` (0 or more, no leading zeros), `cohort` (`conversion`, the default: sales made in the range; `click`: credit and assists landing on clicks made in the range, whenever they converted), `keys` (1 to 1000 comma-separated row keys, no spaces: only those rows; `meta.groups` then counts the matches). No entity filters; unknown parameters are refused with 422
  - Response: `data` rows (`key`, `name`, `clicks`, `cost`, `attributed_conversions`, `attributed_revenue`, `roi` (percent, null without cost), `assisted_conversions`, and `compare_*` with a compare model) in attributed-revenue order, then key; `totals` over every group; `meta.groups` (all groups), `meta.offset`, `meta.cohort`, `meta.backfill` (null unless the pre-upgrade backfill is running)
  - A server from before attribution paging refuses `offset` with 422 "Unknown parameter(s): offset", and one from before the click cohort refuses `cohort` and `keys` the same way (one 422 names every unknown parameter); `attribution breakdown` names every refused flag in its hint
  - `attribution breakdown` maps `--group-by/--model/--compare-model/--limit/--offset/--cohort/--keys` and the range flags one to one (enum values trimmed as validated)
  - `report losers/winners` (the attribution check): first `GET /attribution/models/{id}` when `--first-touch-model` is given (validated before anything else, so a bad override is refused before any report is read), then the campaign payout (`GET /campaigns/{id}`, with `--aff_campaign_id` and neither `--payout` nor `--max-cpc`; `--payout` makes no request and adds no filter, and an explicit 0, a negative or non-finite value, or one over 1,000,000,000 is refused (exit 1) before any request; it replaces each classic row's `total_net` with `total_leads × payout − total_cost` for classification and output, adds `payout` to each row, and values the check's first-touch ROI as `attributed_conversions × payout` against the attribution row's `cost` instead of the server's `roi`. Because `total_leads` counts a converted click once however many sales it had, `--payout` also sends `compare_model_id=<the first active, non-recomputing last_touch model>` (`GET /attribution/models?type=last_touch`) on the same attribution request: its `compare_attributed_conversions` counts the sales on the row's own clicks, and each row it covers is re-valued with `sales = max(that, total_leads)` (`sales` added to the row) and re-classified. Losers drops a row that covers its break-even per sale (a stderr note counts them); winners also sends the keys of the other converting rows (a loss, break-even, or CUT by break-even per converted click) and lists those that become profitable, and still reads the sales without a First touch model (no `model_id`; the closer check stays off and stderr says so). Without a usable Last touch model, or when the check can't run, rows are valued per converted click and stderr says so) and the classic `reports/breakdown`, then the attribution requests, only when there are CUT (losers) or SCALE (winners) rows. They read those rows by key: `keys=<their classic ids>`, 1000 keys and `limit=1000` a request, because the server computes the whole report for every request and paging would compute it once per page. A server that refuses `keys` is paged through instead: no `offset` on the first page and `offset=<rows read>` while rows < `meta.groups`. The classic dimension maps to `group_by` (campaign, ppc_account → traffic_source, landing_page, keyword, country), whose keys equal the classic `id`. The window is the classic report's: `period` as given, otherwise `time_from` (0 when absent, since the classic report has no lower bound and the attribution report would default to 30 days) and `time_to` when given; a mixed period plus time range skips the check. A bounded range (a period or either bound) adds `cohort=click`, so the attribution rows are the credit and assists landing on clicks made in the range, the classic rows' population; all time sends no cohort (both cohorts hold everything, and the default one is served from the rollup). A 422 refusing `keys` or `cohort` as unknown (a server from before them; it names both at once) is retried once without what it refused, over the same range; without the click cohort stderr notes that clicks near the range's edges can differ, and so does a response whose `meta.cohort` isn't `click`. `--first-touch-model` must name an active `first_touch` model. Only a 422 refusing `offset` keeps a partial read (later rows get `attribution_checked: false`); any other error discards the check. A report filter other than the breakdown itself (any of `reportFilterFlags` set to something that filters: not "", an id of 0, or `show=all`) skips it

## Planned feature contracts

- `dashboard`
  - `GET /api/v3/reports/summary` with default `period=today`
- `campaign clone <id>`
  - `GET /api/v3/campaigns/{id}`
  - `POST /api/v3/campaigns` with cloned mutable fields
- `campaign list --url-contains <text>`
  - paginated `GET /api/v3/campaigns`, filtered client-side on the five offer URL fields
- `campaign list --with-stats [--period p | --days n] [--min-clicks n]`
  - the usual `GET /api/v3/campaigns` (one page, or every page with `--all`/`--url-contains`/`--min-clicks`)
  - then `GET /api/v3/reports/breakdown?breakdown=campaign` with `period` (default `last30`) or `time_from`/`time_to`, as `analytics` sends them; `limit=500` and increasing `offset` until a short page (the server caps at 500 and returns no pagination block); never filtered by network
  - rows merged by `aff_campaign_id` = breakdown `id`: `total_clicks`, `total_leads`, `total_income`, `total_cost`, `total_net`, `0` when absent
  - a 403 on the breakdown is hinted with the `reports:read` scope
- `campaign replace-url`
  - paginated `GET /api/v3/campaigns` (with `filter[aff_network_id]` when `--aff-network-id` is set)
  - one `PUT /api/v3/campaigns/{id}` per matched campaign, carrying only the changed URL fields
- `campaign replace-url --undo <file>`
  - paginated `GET /api/v3/campaigns` (unfiltered), then one `PUT /api/v3/campaigns/{id}` per campaign with a slot to restore
- The Update commands (`click update-cpc`, `conversion mark-subids|delete-subids|reset-subids|upload-revenue`)
  - refuse `--staged` before any request; every request goes through `PostUpdate` (15-minute wait, answers up to 64 MB refused rather than truncated)
  - `click update-cpc`: `POST /api/v3/clicks/cpc?dry_run=1` with `{from, to (YYYY-MM-DD strings), cpc (string, $ dropped), aff_network_id?, aff_campaign_id?, ppc_network_id?, ppc_account_id?, landing_page_id?, text_ad_id? (JSON numbers), method_of_promotion?}`; `data.matching` and `data.through_click_id` are required (missing = server error, exit 4). Then, unless `--dry-run` or `matching` is 0, the confirmation (`--force` skips), then the same POST without `dry_run`, adding `expect_clicks: matching` and `through_click_id`; a `409` (the count moved) is exit 1 with "run the same command again"
  - `conversion mark-subids` / `delete-subids`: the file's lines (blank ones kept, so `line` is the file's line number; a later repeat of a subid sent blank and answered `duplicate_in_list` locally) as `{subids: [...]}`, 1,000 lines a `POST /api/v3/conversions/subids[/delete]`, each answer's `line`/`first_line` offset by its part's start; `delete-subids` sends every part with `?dry_run=1` first (`data.conversions` summed), then confirms, then the parts again without it
  - `conversion reset-subids`: `POST /api/v3/conversions/subids/reset?dry_run=1` with `{aff_network_id, aff_campaign_id?}` (`data.matching`), the confirmation, then without `dry_run` (`data.cleared`)
  - `conversion upload-revenue`: `POST /api/v3/conversions/uploads?dry_run=1` with `{csv: the file as read, file_name: --file-name or the file's base name, subid_column?, amount_column? (a JSON number for a value that is all digits, else the header string)}`; refused before sending when the body is over 8 MB, and after the dry run when `data.would_record` is 0; then the confirmation and the same POST without `dry_run` (`data.batch_id`, `data.recorded`)
- The Setup commands (`landing-page code`, `conversion postback-url|pixel`, `ppc-network variable ...`, `ppc-account pixel ...`; capabilities `features.setup_section`)
  - `landing-page code <id>`: `GET /api/v3/landing-pages/{id}/code`, with `offers=<--offer values joined by ",">` when any are given (each must be `campaign:<id>` or `rotator:<id>`, at most 100, checked before any request). A 404 retries once through `GET /landing-pages?filter[landing_page_id_public]=<id>` and says so on stderr. Table mode prints `data.loader`, then `outbound_link`/`outbound_php`/`outbound_javascript` (simple) or each `offers[]` entry (advanced), then `segments` in the order sent; `--json`/`--ndjson`/`--csv`/`--quiet` render the answer as sent
  - `conversion postback-url` / `conversion pixel`: `GET /api/v3/conversions/postback-code` with `amount`, `subid`, `scheme` as given and `campaign_id` from `--campaign` (which also selects the advanced form; refused with `--type simple`, and `--type universal` belongs to `pixel` only; `--iframe` with universal only — all before any request). Stdout is `data.<type>.postback_url` or the pixel (`data.universal.javascript`, or `.iframe` with `--iframe`) alone; guidance on stderr
  - `ppc-network variable list|create|update|delete <ppc_network_id> [variable_id]`: `GET`/`POST /api/v3/ppc-networks/{id}/variables`, `PUT`/`DELETE .../variables/{variableId}` with `{name, parameter, placeholder}` (all on create, the changed ones on update); the delete is the shared delete (`--dry-run` is `?dry_run=1`, `--ids`, `--force`)
  - `ppc-account pixel list|create|update|delete <ppc_account_id> [pixel_id]`: the same shape under `/api/v3/ppc-accounts/{id}/pixels`, body `{pixel_type_id, pixel_code, correction_url}` from `--type-id`, `--code`, `--correction-url` (`""` sent on update, to remove it)
  - A 404 from a server without `features.setup_section` is hinted as an old server; a 404 naming a record (`Landing page N not found`, `Traffic source account N not found`, `Pixel P not found on traffic source account N`, ...) names the list command for it; a 403 naming `access_to_setup_section` or `remove_traffic_source` (which `ppc-network variable` needs as well) names the roles that have it
- The Administration commands (`system info|login-log|integrations|metrics`, `system retention show|set|delete-before`, `system isp-lookup show|enable|disable`)
  - reads: `GET /api/v3/system/{info,login-log?limit=1-500,integrations,metrics,retention,isp-lookup}`; `--limit` is checked before the request; a 404 is "this server has no Administration API" (`features.administration`); a 403 `Admin access required.` is exit 2 with the role hint
  - writes refuse `--staged` before any request
  - `system retention set --days N`: `GET /api/v3/system/retention` (`data.auto_delete_days` required, else exit 4), the confirmation only when `N > 0` and (`current == 0` or `N < current`) (`--force` skips), then `PUT /api/v3/system/retention` with `{auto_delete_days: N}` (a JSON number)
  - `system retention delete-before --date D`: `POST /api/v3/system/retention/delete-before?dry_run=1` with `{before: D}` through `PostUpdate`; `data.before`, `data.clicks` and `data.rows` are required (missing = exit 4). `--dry-run` stops there; a null `through_click_id` writes nothing; otherwise the confirmation (`--force` skips), then the same POST without `dry_run`, adding `through_click_id`; a `409` is exit 1 with "run the same command again"
  - `system isp-lookup enable|disable`: `PUT /api/v3/system/isp-lookup` with `{enabled: true|false}`; a 422 naming the ISP database is hinted with where to upload it
- `ltv product update <id>`: `PATCH /api/v3/ltv/products/{id}` with only the fields given (`name` trimmed, never blank; `sku` as given, `""` clears; `price` a decimal JSON number, `""` sends null); `ltv product delete` is the shared delete (`DELETE …/ltv/products/{id}`, `?dry_run=1` for `--dry-run`), a 409 hinted with renaming instead
- `ltv webhooks deliveries <id>`: `GET /api/v3/ltv/webhooks/{id}/deliveries` with `limit` (1-100) and `status` (pending, delivered, failed) checked first
- `conversion import <file>`
  - `--dry-run`: no request; with `--check-clicks`, the read below and nothing else
  - one `GET /api/v3/clicks/{id}/conversions` per distinct click of the ready rows (read-only): a 404 whose message starts `Click not found` marks the click's rows `click_not_found`; a bare 404 (`Not found`, no such route) refuses the import before any write; 401/403 or a network failure stops it before any write
  - `data[].transaction_id`, `data[].reverses_conv_id`, `data[].amount` and `click.lead` decide which rows are already recorded; `data[].conv_id` is kept to recognise a create the server answered with an existing conversion
  - then one `POST /api/v3/conversions` per remaining row, sales before negative-payout rows: `{click_id: int, transaction_id?: string, payout?: "decimal string", conv_time?: int}` with `Idempotency-Key: conv-import-v1-<first 20 bytes of sha256("p202 conversion import v1\n" + click_id + "\n" + quoted transaction_id + "\n" + quoted payout + "\n" + conv_time), hex>`; under `--staged` with `staged=1`
  - a `201` carrying `idempotent_replay: true` or `duplicate: true`, or naming a `conv_id` the click already had (servers that do not send `duplicate`), is a duplicate; a 409 with `details.deleted: true` (a deleted conversion's key) is a duplicate naming `details.conv_id`; a `202` staged envelope is `staged`; a 404 `Click not found...` is `click_not_found`
- `landing-page list --url-contains <text>`
  - paginated `GET /api/v3/landing-pages`, filtered client-side on `landing_page_url` and `leave_behind_page_url`
- `campaign check-urls`
  - paginated `GET /api/v3/campaigns` (with `filter[aff_network_id]` when `--aff-network-id` is set); no writes
  - no HTTP request to the offer URLs: DNS, TCP connect and TLS handshake once per unique host
  - with `--http` (after confirmation): one `HEAD` per unique offer URL, then a `GET` if the `HEAD` got 405
- `system health`
  - for an https base URL, first a DNS lookup, TCP connect and verified TLS handshake to its host (no HTTP bytes), through the same probe as `campaign check-urls`
  - then `GET /api/v3/system/health` (unauthenticated), attempted whatever the handshake found; the `tls_*` fields are merged into its `data` object
- `system cron`
  - `GET /api/v3/system/cron`: `data.jobs` (`cronjob_type`, `cronjob_time`, `last_run_human`, every row of `202_cronjobs`) and `data.recent_logs` (`id`, `last_execution_time`, `time_human`, up to 20); times may arrive as numeric strings
  - summarized client-side; `--raw` keeps the server's arrays
- `tracker create-with-url`
  - `POST /api/v3/trackers`
  - `GET /api/v3/trackers/{id}/url`
- `tracker bulk-urls`
  - `GET /api/v3/trackers` (possibly with filters)
  - `GET /api/v3/trackers/{id}/url` for each record
- `export <entity|all>`
  - paginated `GET /api/v3/{entity}` requests
- `import <entity> <file>`
  - repeated `POST /api/v3/{entity}` requests
- `analytics`
  - `GET /api/v3/reports/breakdown` with alias-mapped query params
- `analytics --split-at <YYYY-MM-DD|unix>`
  - the window resolved client-side to inclusive unix bounds: `last7|last14|last30|last90` as now minus N days, `--days N`, `--time_from`/`--time_to` (end defaults to now); default `last90`; the calendar periods (bounds at the account's midnight) and `alltime` (no start) refused
  - two paged `GET /api/v3/reports/breakdown` reads, `time_from=start&time_to=split-1` then `time_from=split&time_to=end` (never `period`, `sort` or the caller's `limit`/`offset`), each with `limit=500` and increasing `offset` until a short page, plus the report filters
  - rows merged by breakdown `id`; sort, `--limit` and `--offset` applied client-side to the merged rows
  - no request when the split, window or sort flags are invalid
- `search <words...>` and `commands [command...]`
  - no requests: both read the CLI's own command tree
- list `--all`
  - paginated `GET /api/v3/{entity}` loop until exhausted
- delete `--ids`
  - repeated `DELETE /api/v3/{entity}/{id}` from a single CLI invocation
- `rotator rule-update`
  - `PUT /api/v3/rotators/{id}/rules/{ruleId}`
- `diff` (capability-enabled path)
  - `POST /api/v3/sync/plan` (preferred when `sync_plan=true`)
  - falls back to paginated `GET /api/v3/{entity}` compare
- `sync/re-sync` (capability-enabled path)
  - `POST /api/v3/sync/jobs` or `POST /api/v3/sync/re-sync` (preferred when `async_jobs=true`)
  - may trigger `POST /api/v3/sync/worker/run` and then `GET /api/v3/sync/jobs/{id}` polling
  - `GET /api/v3/sync/status`, `GET /api/v3/sync/history` (preferred when `async_jobs=true`)
  - falls back to local sync implementation with direct CRUD calls

## Filter mapping convention

For generic CRUD list endpoints, user-friendly flags map to API filter query keys:

- CLI: `--aff_campaign_id 1`
- Query: `filter[aff_campaign_id]=1`
