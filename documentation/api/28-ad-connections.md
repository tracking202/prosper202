# Ad connections API: ChatGPT Ads through its Advertiser API

An ad connection is a ChatGPT Ads (OpenAI Ads) ad account reached with its
**Advertiser API key**, pasted once. Prosper202 checks the key with OpenAI,
reads the account's name, currency and time zone, and can then set up the
pixel, the Conversions API key, the ChatGPT Ads destination and the event
settings in that account for you. Setup › Destinations › ChatGPT Ads does the
same in the web UI, and `p202 ad-connection` from the command line.

| Method | Path | |
| ------ | ---- | - |
| `GET` | `/ad-connections` | List: the account each reaches, `status`, `credentials_set`, what setup made |
| `POST` | `/ad-connections` | Connect: `api_key` (and `ppc_account_id`, the traffic source account it is for) |
| `GET` | `/ad-connections/{id}` | One |
| `PATCH` | `/ad-connections/{id}` | `api_key` (must reach the same ad account), `ppc_account_id` (`null` unlinks) |
| `DELETE` | `/ad-connections/{id}` | Delete: its keys are erased here; nothing is removed at OpenAI (`?dry_run=1` previews) |
| `POST` | `/ad-connections/{id}/check` | Read the account again |
| `POST` | `/ad-connections/{id}/setup` | Find or make the pixel, Conversions API key, destination and event settings |

Every route asks for the role permission `access_to_setup_section` and the
scope area `ad-connections` (`ad-connections:read`, `ad-connections:write`).
None can be staged: a body carries a key, and a check or setup is a call to
OpenAI. `dry_run` previews only the delete; anywhere else it is a `422`.

## Keys are write-only

`api_key` and `conversions_api_key` are taken and never served: answers say
only `credentials_set`, the keys are stored encrypted (AES-256-GCM, bound to
the connection and its owner) and taken out of every message, and the CLI
reads them from a file or piped stdin and never prints them.

## Connecting

```
POST /api/v3/ad-connections
{"api_key": "<Advertiser API key>", "ppc_account_id": 4}
```

The key is checked with OpenAI's `GET /ad_account` first. A key OpenAI
refuses (401, 403) is answered `422` naming `api_key`, and nothing is stored.
One connection per ad account. `currency_matches_account` is false when the ad account bills in
another currency than this account's: conversions are sent as they are,
and spend is not converted.

`status` is `connected`, `key_refused` (the last check was refused: replace
the key) or `credentials_unreadable`. A check that got no answer keeps the
status and says why in `last_error` (see below for its HTTP answer).

The keys are sealed with one credential key per install, the row of
`202_deployment_secrets` whose `secret_name` is `ad_connection_credential`.
When that row is there and is not 64 lower-case hex characters, nothing can
be sealed or opened: connecting, replacing a key, a check and a setup answer
`422` naming `api_key` with the mend, which is for someone with database
access to delete that one row and then replace each connection's Advertiser
API key (a new credential key is made with the first one).

## When OpenAI cannot be asked

An outage or a rate limit at OpenAI says nothing about the key, so it is
never a `422`. Connecting, replacing a key (`PATCH` with `api_key`), a check
and a setup answer:

| Status | When | Body |
|--------|------|------|
| `503` with `Retry-After` | No answer from OpenAI, or a 5xx of its own | `details.reason` `unreachable` |
| `429` with `Retry-After` | OpenAI answered 429, or this install is holding to OpenAI's per-minute limits | `details.reason` `rate_limited` |
| `502` | OpenAI answered something its API does not document (an ad account with no id, currency or time zone) | `details.reason` `unreadable_answer` |

`details.upstream` is `openai_ads`. Nothing is stored by a connect or a key
replacement that ends this way. A **check** still records the try
(`last_error`, `checked_at`) and leaves `status` as it was; the connection is
in `details.connection`. A check OpenAI *refuses* was made, and is a `200`
with `status` `key_refused`. A **setup** is one of these only when OpenAI was
not reached (or was limiting) and no step came out `created`, `found`, `given`
or `kept`: the run set nothing up, and its steps are in `details.steps`
(`429` when every such failure was the rate limit, else `503`). A run in which
any step did or kept something is a `200` with each step's outcome, failed
ones included. `p202 ad-connection` exits 4 (server) or 7 (rate limited) for
these, with a hint to wait and run the command again.

## Setup

```
POST /api/v3/ad-connections/1/setup
{"event_types": ["order_created", "lead_created"]}
```

Four steps run in order, each answered in `steps` with an `outcome` and a
`detail`:

| Step | Does | Outcomes |
| ---- | ---- | -------- |
| `pixel` | Finds the account's web pixel, or makes one named Prosper202; with several, asks which (`pixel_source_id`) | `created`, `found`, `given`, `kept`, `needs_input`, `failed` |
| `conversions_key` | Makes a Conversions API key and stores it encrypted | `created`, `given`, `kept`, `needs_input`, `failed` |
| `destination` | Makes the `openai_ads` destination with the pixel and key (scope `oppref`, the first event type) | `created`, `kept`, `needs_input`, `unavailable`, `failed` |
| `event_settings` | Finds or makes one event setting per event type on the pixel's source, 30-day click window | `created`, `found`, `needs_input`, `failed` |

`skip` leaves steps out (`["destination"]`). What a step made is kept, so
setup run again makes nothing it already recorded: the pixel and event
settings are looked for in the ad account first, and the destination is made
and recorded under one lock. An enabled `openai_ads` destination of the
account that already sends to the pixel (one made by hand, or by a connection
since deleted) is used instead of a second one: the step is `kept`, and its
Conversions API key is replaced with this connection's. One that is switched
off is left as it is and the step is `needs_input`: switch it on or delete
it, then run setup again. Every create carries an `Idempotency-Key` built
from this install, the connection, the ad account and what is made, but
OpenAI documents that header for campaigns, ad groups and ads, not for
pixels, Conversions API keys or event settings, so do not rely on it there.
If the answer to making a Conversions API key is lost (a timeout), the next
run makes another, and the first stays in Ads Manager unused: delete it there.

Pixel management and Conversions API key creation are switched on per ad
account by OpenAI. Where they are off, OpenAI answers `404 Not found`, and
the step is `needs_input` and says so: make the pixel or key in Ads Manager
and give `pixel_id` with `source_id` (the pixel's `clidsrc_…` id), or
`conversions_api_key`.

Requests to OpenAI are held under its limits (600 a minute per endpoint,
1,200 in all, per ad account) and a 429 is never retried by itself.
