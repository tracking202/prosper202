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
