# Go CLI Command Contracts (Build Phases)

This file captures the API paths and payload/query expectations used by upcoming CLI features.

## Existing contracts

- `GET /api/versions` (optional capability probe)
- `GET /api/v3/capabilities` (optional capability probe)
  - `data.features.report_breakdowns`: the dimensions `reports/breakdown` accepts, from `ReportsController::breakdownDimensions()`. A dimension flag (`analytics --group-by`, `report breakdown --breakdown/--group-by`, `report crosstab --cols`, `report losers/winners/breakeven --breakdown`) probes it only for a value missing from the CLI's built-in list: listed means the value is sent, unlisted means refused with the server's list. With no config or no server the built-in list's error stands
- `GET /api/v3/reports/summary`
  - Query: report filter params (`period`, `time_from`, `time_to`, etc.)
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
  - Query: `group_by` (campaign, traffic_source, landing_page, keyword, c1-c4, country, device, day), `model_id`, `compare_model_id`, `period` (today, yesterday, last7, last30, last90) or `time_from`/`time_to` (unix seconds), `limit` (1-1000, default 100), `offset` (0 or more, no leading zeros), `cohort` (`conversion`, the default: sales made in the range; `click`: credit and assists landing on clicks made in the range, whenever they converted). No entity filters; unknown parameters are refused with 422
  - Response: `data` rows (`key`, `name`, `clicks`, `cost`, `attributed_conversions`, `attributed_revenue`, `roi` (percent, null without cost), `assisted_conversions`, and `compare_*` with a compare model) in attributed-revenue order, then key; `totals` over every group; `meta.groups` (all groups), `meta.offset`, `meta.cohort`, `meta.backfill` (null unless the pre-upgrade backfill is running)
  - A server from before attribution paging refuses `offset` with 422 "Unknown parameter(s): offset", and one from before the click cohort refuses `cohort` the same way
  - `attribution breakdown` maps `--group-by/--model/--compare-model/--limit/--offset/--cohort` and the range flags one to one
  - `report losers/winners` (the attribution check): first `GET /attribution/models/{id}` when `--first-touch-model` is given (validated before anything else), then the attribution requests, all before the campaign payout (`GET /campaigns/{id}`, with `--aff_campaign_id`) and the classic `reports/breakdown`, so a bad override is refused before any report is read. One attribution request per page with `limit=1000`, no `offset` on the first page and `offset=<rows read>` while rows < `meta.groups`. The classic dimension maps to `group_by` (campaign, ppc_account → traffic_source, landing_page, keyword, country), whose keys equal the classic `id`. The window is the classic report's: `period` as given, otherwise `time_from` (0 when absent, since the classic report has no lower bound and the attribution report would default to 30 days) and `time_to` when given; a mixed period plus time range skips the check. A bounded range (a period or either bound) adds `cohort=click`, so the attribution rows are the credit and assists landing on clicks made in the range, the classic rows' population; all time sends no cohort (both cohorts hold everything, and the default one is served from the rollup). A 422 refusing `cohort` (a server from before it) is retried once without it over the same range, and stderr notes that clicks near the range's edges can differ; so does a response whose `meta.cohort` isn't `click`. `--first-touch-model` must name an active `first_touch` model. Only a 422 refusing `offset` keeps a partial read (later rows get `attribution_checked: false`); any other error discards the check. An entity filter other than the breakdown itself skips it

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
- `conversion import <file>`
  - `--dry-run`: no request; with `--check-clicks`, the read below and nothing else
  - one `GET /api/v3/clicks/{id}/conversions` per distinct click of the ready rows (read-only): a 404 whose message starts `Click not found` marks the click's rows `click_not_found`; a bare 404 (`Not found`, no such route) refuses the import before any write; 401/403 or a network failure stops it before any write
  - `data[].transaction_id`, `data[].reverses_conv_id`, `data[].amount` and `click.lead` decide which rows are already recorded; `data[].conv_id` is kept to recognise a create the server answered with an existing conversion
  - then one `POST /api/v3/conversions` per remaining row, sales before negative-payout rows: `{click_id: int, transaction_id?: string, payout?: "decimal string", conv_time?: int}` with `Idempotency-Key: conv-import-v1-<first 20 bytes of sha256("p202 conversion import v1\n" + click_id + "\n" + quoted transaction_id + "\n" + quoted payout + "\n" + conv_time), hex>`; under `--staged` with `staged=1`
  - a `201` carrying `idempotent_replay: true`, or naming a `conv_id` the click already had, is a duplicate; a `202` staged envelope is `staged`; a 404 `Click not found...` is `click_not_found`
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
  - the window resolved client-side to inclusive unix bounds: `last7|last30|last90` as now minus N days, `--days N`, `--time_from`/`--time_to` (end defaults to now); default `last90`; `today`/`yesterday` refused
  - two paged `GET /api/v3/reports/breakdown` reads, `time_from=start&time_to=split-1` then `time_from=split&time_to=end` (never `period`, `sort` or the caller's `limit`/`offset`), each with `limit=500` and increasing `offset` until a short page, plus the entity filters
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
