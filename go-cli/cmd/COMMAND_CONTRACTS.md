# Go CLI Command Contracts (Build Phases)

This file captures the API paths and payload/query expectations used by upcoming CLI features.

## Existing contracts

- `GET /api/v3/reports/summary`
  - Query: report filter params (`period`, `time_from`, `time_to`, etc.)
- `GET /api/v3/trackers/{id}/url`
  - Response: URL payload for tracker
- `POST /api/v3/users/{id}/api-keys`
  - Response: newly created API key
- `DELETE /api/v3/users/{id}/api-keys/{api_key}`
  - Deletes a specific key by key string

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

## Filter mapping convention

For generic CRUD list endpoints, user-friendly flags map to API filter query keys:

- CLI: `--aff_campaign_id 1`
- Query: `filter[aff_campaign_id]=1`
