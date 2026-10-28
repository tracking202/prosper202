# Sync API

Server-side synchronization between Prosper202 instances. Sync operations allow you to replicate campaigns, networks, trackers, and other entities between profiles or servers.

All sync endpoints require admin role and the appropriate sync scope (`sync:read` or `sync:write`).

## Planning Endpoints

| Method | Path | Scope | Description |
| ------ | ---- | ----- | ----------- |
| `POST` | `/sync/plan` | sync:read | Build a sync plan (returns 200) |
| `GET` | `/sync/status` | sync:read | Current sync status between profiles |
| `GET` | `/sync/history` | sync:read | Sync history |

## Job Endpoints

| Method | Path | Scope | Description |
| ------ | ---- | ----- | ----------- |
| `POST` | `/sync/jobs` | sync:write | Create and queue a sync job (returns 202) |
| `GET` | `/sync/jobs/{id}` | sync:read | Get job status |
| `GET` | `/sync/jobs/{id}/events` | sync:read | List job events (paginated) |
| `POST` | `/sync/jobs/{id}/run` | sync:write | Run a specific job |
| `POST` | `/sync/jobs/{id}/cancel` | sync:write | Cancel a running job |
| `POST` | `/sync/worker/run` | sync:write | Run worker to process all queued jobs |
| `POST` | `/sync/re-sync` | sync:write | Incremental re-sync (returns 202) |

## Change Feed

| Method | Path | Scope | Description |
| ------ | ---- | ----- | ----------- |
| `GET` | `/changes/{entity}` | sync:read | List incremental changes for an entity |

## Audit Endpoints

| Method | Path | Scope | Description |
| ------ | ---- | ----- | ----------- |
| `GET` | `/audit/sync-jobs` | sync:read | List sync job audit records |
| `GET` | `/audit/sync-jobs/{id}` | sync:read | Get specific audit record |

Both take `format=json` (the default) or `csv`, in either case; the list
also filters by `actor`, `source`, `target`, `from_epoch`, `to_epoch` and
`status` — a job's terminal status: `succeeded`, `partial`, `failed` or
`cancelled`. Any other `format` or `status` is a `422` naming it and its
values: an unknown format was answered as json, and an unknown status as an
empty list that read as "no such jobs".

## Create Sync Job

Pass an `Idempotency-Key` header to prevent duplicate job creation on
retries. The key covers the whole request: retry it with the same source,
target, entity and options and the recorded response replays
(`idempotent_replay: true`). Reusing the key for a different sync is refused
with `422` rather than queueing a second job — mint a new key instead.

```bash
curl -X POST https://your-domain.com/api/v3/sync/jobs \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: sync-campaigns-2024-03-08" \
  -d '...'
```

### Request Body

```json
{
  "source": {
    "url": "https://source-instance.example.com",
    "api_key": "source_api_key_here"
  },
  "target": {
    "url": "https://target-instance.example.com",
    "api_key": "target_api_key_here"
  },
  "entity": "campaigns",
  "prune": false,
  "max_attempts": 3
}
```

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `source.url` | string | Yes | Source instance base URL |
| `source.api_key` | string | Yes | Source instance API key |
| `source.name` | string | No | What the job and its audit record call the source (default: its URL) |
| `target.url` | string | Yes | Target instance base URL |
| `target.api_key` | string | Yes | Target instance API key |
| `target.name` | string | No | What the job and its audit record call the target (default: its URL) |
| `entity` | string | Yes | Entity to sync. One of: `aff-networks`, `ppc-networks`, `ppc-accounts`, `campaigns`, `landing-pages`, `text-ads`, `rotators`, `trackers`, or `all` |
| `prune` | boolean | No | Delete target entities not present in source |
| `prune_allowlist` | array | No | With `prune`, prune only these entities: a list of the entity names above |
| `prune_denylist` | array | No | With `prune`, never prune these entities: a list of the entity names above |
| `max_attempts` | integer | No | Retry limit for failed items |

`collision_mode` (`warn` or `manual`) is read by `POST /sync/plan` only; a
job body carrying it is a `422`, as is any key the table does not list.

### What a profile `url` may name

This server calls both profiles' URLs with their API keys and reads what
they answer, so it calls only what the export webhooks and postbacks may
call (the same guard, `WebhookGuard`):

- `https://host[:port][/path]`: a directory (`https://example.com/tracker`)
  and any port are fine; a query, a fragment, a user name or password, and
  a host spelled other than as a name, a dotted IPv4 address or a bracketed
  IPv6 address are refused.
- Public addresses only. A private, loopback, carrier-grade NAT or other
  non-public address, a single-label or `.local`/`.internal` name, and
  `localhost` are refused. Two installs on one machine or one private
  network sync once the operator of the install that runs the sync admits
  the other's address in its `202-config.php`:
  `define('P202_WEBHOOK_ALLOW_NETWORKS', '127.0.0.1/32');` (comma- or
  space-separated networks; the same setting the export webhooks and
  postbacks obey). Write such an install by its address
  (`http://127.0.0.1:8001`), not `localhost`.
- `http://` only to an address that setting names: the key crosses the
  operator's own network in plain text, never the internet.
- Link-local addresses (169.254.0.0/16, fe80::/10) and the cloud metadata
  endpoints are never called, whatever the setting says.
- Redirects are not followed: the key is sent only to the URL named. A
  remote that answers with one fails the sync, naming where it pointed.

`POST /sync/jobs`, `/sync/re-sync` and `/sync/plan` refuse what they can
tell without a DNS lookup with a `422` on `source.url` or `target.url`
(the field error says why, and names the setting when it would admit the
address). Every request a sync makes is checked again when it is sent: the
name is resolved, every address checked, and the connection pinned to the
address checked, with no proxy. A job whose URL is refused then fails at
once (`error_code: sync_url_refused`) and is not retried.

On a Docker stack, `P202_URL_MAP` (`localhost:8000=web:80,…`) sends a URL
to a service name; that name's one label is the operator's and is accepted,
and its address is held to the rules above, so the Docker network
(`172.16.0.0/12` by default) goes in `P202_WEBHOOK_ALLOW_NETWORKS`.

`source` and `target` (or their other names `from` and `to`; send one name
for each side) are objects of `url`, `api_key` and `name`, each a string: any
other key is a `422` naming it (`source.nmae`), as it is on `POST /sync/plan`
and in the `source[...]`/`target[...]` query of `GET /sync/status` and
`/sync/history`. `prune_allowlist` and `prune_denylist` are lists of entity
names: a string instead of a list, or a name that is not an entity
(`prune_denylist.0`), is a `422`. Both used to be read as an empty list,
so `"prune_denylist": "campaigns"` protected no campaign from the prune.

## Job Statuses

| Status | Description |
| ------ | ----------- |
| `queued` | Job created, waiting to be processed |
| `running` | Job currently executing |
| `succeeded` | All items synced successfully |
| `failed` | Job failed (check events for details) |
| `cancelled` | Job was cancelled |
| `partial` | Some items succeeded, some failed |

### Failures and conflicts

Each entity's results count `synced`, `skipped`, `failed`, `pruned`,
`created`, `updated` and `conflicted`, and `errors` names each failed record
and why: `campaigns[Offer A]: update: 409 from PUT campaigns/20: Version
mismatch`. A refusal from the other instance is reported with the status and
message it answered; it used to read `Internal server error` whatever it was.

A **conflict** is a write the target refused with `409`: a `force_update`
whose target record changed after the sync read it (the `If-Match` the sync
sends is stale), or a create or delete the target's own state refused. It is
listed in `conflicts` with the entity, the record's key, the operation, the
target id, the target's reason and `"written": false` — the target refused
it, so nothing was written. A conflict is **never retried by itself**: a
retry would re-read the target and force the source over the change the 409
protected. Re-run the sync when you have looked (`POST /sync/plan` shows the
difference).

With `skip_errors` a failed record is counted and the run goes on (`partial`
when anything else was synced). Without it the run stops at the first one:
the job's `error` names the record, and an outage (a `5xx`, a network
failure) is retried up to `max_attempts` with backoff, while a conflict
fails the job at once, with the record in the job's `conflict`.

### Caps, and a sync that stops

What a remote can make this server read is bounded. A sync reads at most
8 MiB (8,388,608 bytes) of one answer, 200 pages of 500 records (100,000)
of one list, and takes at most 900 seconds in all, both remotes and every
request together (one request at most 60). A remote that answers more, or
more slowly, fails the sync with the cap and the remote's origin named; it
never reads as a shorter list, which a prune would act on. Neither does an
answer that is not a page of records (no `data` list, a record that is not
an object, more records than asked for, a `total` that is not a number).

A URL refused, a cap reached, or a host that does not resolve stops the
whole sync, with `skip_errors` too: the next record would meet it again.
The job then says why in `error` and which kind in `error_code`:

| `error_code` | Meaning | Retried by the job |
| ------------ | ------- | ------------------ |
| `sync_url_refused` | A profile URL this server does not call, a redirect, or a connection that did not stay on the checked address | No |
| `sync_url_unresolved` | A profile's host did not resolve from this server | Yes |
| `sync_limit` | A cap above was reached | No |
| `sync_key_unreadable` | The job's stored key could not be opened; queue the sync again | No |
| `sync_remote_failed` | A remote did not answer, or answered something no Prosper202 API does | Yes |

`error_code` is `null` for any other failure. When a sync stops after it
began writing, `written` lists what the target had confirmed by then, by
entity (`{"campaigns": {"created": 3, "updated": 1}}`), and `error` ends
with the same in words ("Writes the target confirmed before it stopped:
…"); a stop while still reading ends "Nothing was written." Records synced
are found by their natural key the next time, so running the same sync
again finishes it.

### The profiles' API keys

A queued job keeps both profiles' API keys encrypted (AES-256-GCM, under a
key this install keeps in its database, bound to the profile's side and
URL), and a job that has ended keeps none. Jobs are files in the API's
state directory; a job queued by a version before 1.9.79 held its keys as
sent: it still runs, its keys are encrypted when it does, and the next
worker run removes the keys of every job that has ended. A job's stored
key that does not open (its database key is gone, or the file was altered)
fails it with `sync_key_unreadable`: queue the sync again.

## Example: Sync Campaigns Between Instances

```bash
# 1. Create and queue a sync job
curl -X POST https://your-domain.com/api/v3/sync/jobs \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: sync-campaigns-2024-03-08" \
  -d '{
    "source": { "url": "https://prod.example.com", "api_key": "prod_key" },
    "target": { "url": "https://staging.example.com", "api_key": "staging_key" },
    "entity": "campaigns",
    "collision_mode": "warn"
  }'

# 2. Check job status
curl "https://your-domain.com/api/v3/sync/jobs/1" \
  -H "Authorization: Bearer YOUR_API_KEY"

# 3. View job events
curl "https://your-domain.com/api/v3/sync/jobs/1/events?limit=50" \
  -H "Authorization: Bearer YOUR_API_KEY"
```
