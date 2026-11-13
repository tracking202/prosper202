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
A check is a read (`ad-connections:read`). Connecting, `PATCH`, `DELETE` and
`/setup` handle the key itself, so they also need a key with full access or
global `write`: an area scope is not enough, and an AI connection's key
(which holds area scopes only) can read connections and change campaigns but
never touch the key.
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
never a `422`. Connecting, replacing a key (`PATCH` with `api_key`), a check,
a setup and a real test event (`POST /destinations/{id}/test` with `real`)
answer:

| Status | When | Body |
|--------|------|------|
| `503` with `Retry-After` | No answer from OpenAI, or a 5xx of its own | `details.reason` `unreachable` |
| `429` with `Retry-After` | OpenAI answered 429, or this install is holding to OpenAI's per-minute limits | `details.reason` `rate_limited` |
| `502` | OpenAI answered something its API does not document (an ad account with no id, currency or time zone; a list with no `data`; a create naming nothing it made), or refused the request itself with a 4xx that is not about the key | `details.reason` `unreadable_answer` |

`details.upstream` is `openai_ads`. Nothing is stored by a connect or a key
replacement that ends this way, and no test event was sent. A **check** still
records the try (`last_error`, `checked_at`) and leaves `status` as it was
(so a connection already `key_refused` stays so beside an outage's
`last_error`, until a check that is answered); the connection is in
`details.connection`. A check OpenAI *refuses* was made, and is a `200` with
`status` `key_refused`.

A **setup** answers by this rule. A step *did something* when it came out
`created`, `found` or `given`, or when event settings were made or found
though the step failed. `kept` (what an earlier run made) is not this run
doing something.

| A setup in which | Answer |
|------------------|--------|
| a step did something | `200`, each step's outcome, failed ones included |
| no step did something, and OpenAI refused the stored key (401, 403) | `422` naming `api_key`; the connection's `status` becomes `key_refused` |
| no step did something, OpenAI was not reached or was limiting requests | `503`, or `429` when every such answer was the rate limit; the steps in `details.steps` |
| no step did something, and OpenAI's answers could not be used | `502`; the steps in `details.steps` |
| no call to OpenAI failed (every step kept, skipped or needs input) | `200` |

A refused key also marks the connection `key_refused` in a run that did
something. A real test event that OpenAI refuses (a 400 about the event, a
refused Conversions API key) is OpenAI's verdict, a `200` with `sent` false.
`p202 ad-connection` and `p202 destination test --real` exit 4 (server) or 7
(rate limited) for the 5xx and 429 answers, with a hint to wait and run the
command again, and 1 with the key hint for a refused key.

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

## Campaigns, ad groups and ads

Read from OpenAI and changed there, live, through the connection's key.
Setup › Destinations › ChatGPT Ads › *campaigns* shows the same, with the
pause and activate buttons behind a confirm.

| Method | Path | |
| ------ | ---- | - |
| `GET` | `/ad-connections/{id}/campaigns` | A page: `status`, `serving_issues`, `budget` (micros), `query_string_template`, `conversion_event_setting_ids`; `limit` (1-500), `after` (the answer's `meta.after`) |
| `GET` | `/ad-connections/{id}/ad-groups` | The same for ad groups; `campaign_id` narrows |
| `GET` | `/ad-connections/{id}/ads` | The same for ads, with `review_status` and `review_reason`; `ad_group_id` narrows |
| `GET` | `/ad-connections/{id}/{campaigns,ad-groups,ads}/{objectId}` | One, by OpenAI's id (`cmpn_…`, `adgrp_…`, `ad_…`) |
| `POST` | `/ad-connections/{id}/{campaigns,ad-groups,ads}/{objectId}/pause` | Pause it at OpenAI |
| `POST` | `/ad-connections/{id}/{campaigns,ad-groups,ads}/{objectId}/activate` | Activate it |
| `POST` | `/ad-connections/{id}/{campaigns,ad-groups,ads}/{objectId}/template` | Set `landing_page_configuration.query_string_template`: `template`, or the Get Links ChatGPT Ads template without it |
| `POST` | `/ad-connections/{id}/campaigns/{objectId}/event-settings` | Add `event_setting_ids` (or the ones setup made) to the campaign's own |
| `GET` | `/ad-connections/{id}/spend` | Spend, impressions and clicks by campaign: `since`, `until` (whole days in the ad account's time zone, at most 365); yesterday there without them |

Every write can be proposed with `?staged=1` (a person applies it, as any
other proposal) and previews with `?dry_run=1`: the object is read and the answer
says what would change (`change`, from and to), and nothing is sent. OpenAI's
`conversion_event_setting_ids` replaces the list, so attaching reads the
campaign, adds the ids and writes the list back; `unchanged: true` means
there was nothing to add and nothing was sent. An id that is not shaped like
OpenAI's is refused before it reaches a request.

An object the ad account does not have is a `404`, with OpenAI's words, and
a key OpenAI refuses is a `422` naming `api_key` (replace it with `PATCH`).
OpenAI not reached, its rate limit and an answer that cannot be used are
answered as under *When OpenAI cannot be asked* above: `503` or `429` with
`Retry-After`, or `502`, never a `422`; a write answered that way was not
made, and a read shows nothing rather than an empty account.

The same from the command line: `p202 ad-campaign list|get|pause|activate|set-template|attach-events|spend`,
and, for an AI assistant on this computer (`p202 mcp`), the tools
`ad_campaign_list`, `ad_campaign_pause`, `ad_campaign_activate` and
`ad_campaign_spend`, with `ad_connection_list`, `ad_connection_check` and
`destination_list`: they load on request through `get_more_tools`. The
four campaign tools are also offered to an AI app connected through
Tracking202's MCP server (its key reaches the `ad-connections` area); the
connection and destination tools are local only. A proposal-only
connection proposes a pause for you to apply.

A money-loss alert for spend without conversions names the ChatGPT Ads
campaigns its clicks came from (the `campaign_id` the tracking template puts
on each click, on a traffic source account linked to a connection) and how
to pause them; its webhook payload carries `chatgpt_connection_id` and
`chatgpt_campaign_ids`.
