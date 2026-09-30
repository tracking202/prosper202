# Go CLI Command Contracts (Build Phases)

This file captures the API paths and payload/query expectations used by upcoming CLI features.

## Existing contracts

- `GET /api/versions` (optional capability probe)
- `GET /api/v3/capabilities` (optional capability probe)
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
