# Attribution postbacks (SKAdNetwork)

Prosper202 can act as the measurement endpoint for Apple's SKAdNetwork — the
privacy-preserving install attribution framework for iOS app campaigns. iOS
devices send signed install-validation postbacks directly to a URL the
advertised app (or an ad network) designates. Point that URL at your
Prosper202 install and it will receive the postbacks, verify Apple's ECDSA
signature on each one, store them, decode conversion values into named events
and revenue, and report on the results — the server side of what a mobile
measurement partner (MMP) does.

Supported postback versions: 2.1, 2.2, 3.0, and 4.0 are verified against
Apple's published P-256 key. Retired versions (1.0, 2.0) and versions newer
than 4.0 are stored but flagged `unverifiable`.

## Setup

1. **Confirm the endpoint is reachable.** Postbacks arrive at
   `https://your-domain.com/.well-known/skadnetwork/report-attribution/`
   (shipped as a real directory — no rewrite rules needed, and the bundled
   Apache/nginx configs already keep `/.well-known/` servable). A GET to that
   URL returns `{"data":{"status":"ready",...}}`. The URL must be served over
   HTTPS on port 443 for devices to deliver to it.

2. **Point the app at it.** The app developer adds one key to the app's
   `Info.plist`:

   ```xml
   <key>NSAdvertisingAttributionReportEndpoint</key>
   <string>https://your-domain.com</string>
   ```

   Devices append `/.well-known/skadnetwork/report-attribution/` themselves.
   With this in place the developer receives a copy of every *winning*
   postback for the app. (Registered ad networks can likewise use the same
   URL as their postback endpoint and will also receive non-winning
   postbacks.)

3. **Register the app** so its postbacks belong to your reporting
   (`POST /attribution/apps` with the numeric App Store id). Postbacks that arrive
   before registration are stored unclaimed and are claimed retroactively
   when the app is registered.

4. **Mirror the app's conversion-value schema** as decoding rules
   (`POST /attribution/conversion-values`). The app itself chooses what the 6-bit
   fine value (0–63) and the coarse value (`low`/`medium`/`high`) mean when
   it calls SKAdNetwork's `updatePostbackConversionValue(_:coarseValue:lockWindow:)`
   — Prosper202 cannot set conversion values for the app, it decodes what
   the app encoded. Keep the two sides in sync or reports will decode to the
   wrong events.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `POST` | `/.well-known/skadnetwork/report-attribution/` | Public postback receiver (no auth — devices POST here) |
| `GET` | `/attribution/postbacks` | List received postbacks (filters below, paginated) |
| `GET` | `/attribution/postbacks/{id}` | One postback, including its attribution signature |
| `GET` | `/attribution/report` | Aggregate report with conversion-value decoding |
| `POST` | `/attribution/verify` | Verify a postback payload's signature without storing it |
| `GET/POST` | `/attribution/apps`, `/attribution/apps/{id}` | App registry (CRUD; `PUT`/`DELETE` on `/{id}`) |
| `POST` | `/attribution/apps/{id}/schema-token/rotate` | Mint a new schema token; the old one stops working |
| `GET/POST` | `/attribution/conversion-values`, `/attribution/conversion-values/{id}` | Decoding rules (CRUD; `PUT`/`DELETE` on `/{id}`) |
| `GET` | `/attribution/schema` | Public (schema-token gated): the conversion-value mapping an iOS build fetches at runtime |

All `/attribution` API routes require the `attribution` scope area (`attribution:read` for reads,
`attribution:write` for writes; `POST /attribution/verify` counts as a read — it computes
over the submitted payload without storing anything). App and
conversion-value writes support `?staged=1` proposals and `?dry_run=1`
delete previews like the rest of the operator surface. `GET /capabilities`
reports `features.attribution_postbacks: true` once the server has this feature.

## The receiver

The receiver validates strictly, verifies, and stores — it never drops a
plausible postback silently:

- Structurally broken bodies (bad JSON, missing or mis-typed fields,
  out-of-range values) are rejected with `400` and per-field errors. Genuine
  devices never send these.
- Valid postbacks are stored and answered `200` — including replays (devices
  retry up to nine times over several days when they don't get a `200`;
  replays answer `{"duplicate": true}` and store nothing new). Deduplication
  covers the `transaction-id` namespaced by ad network, the
  postback-sequence-index and did-win legs, **and the exact request body**:
  only a true retry (the device resending the identical postback) dedupes.
  A crafted postback naming a real transaction with different contents
  stores as its own (flagged) row instead of occupying the genuine
  postback's slot.
- The signature verdict is stored per row twice, on purpose.
  `signature_state` is what the verifier established: `valid` (Apple's
  production key), `invalid` (wrong or forged), `unverifiable` (a version or
  key this server cannot check, or no OpenSSL), or `development` (one of
  Apple's AdAttributionKit development keys — see the app registry's
  `accept_development_postbacks`). `signature_valid` is the **trust bit**
  the report keys on: `1` (verified against the production key, or
  development-signed for an app that opted in), `0` (forged), `NULL`
  (nobody vouched for it). Tampered or unverifiable postbacks are stored
  *and flagged* rather than dropped, so integration tests and forensics
  work. The report already excludes them from headline metrics by default
  (see Report below); the postback *list* shows every row, filterable with
  `signature=valid|invalid|unverifiable`.
- Database outages answer `503`/`500` so the device retries later; a
  per-source rate limit (600/min per source IP, fail-open if the limiter
  itself breaks) answers `429` with `Retry-After`.

Because the endpoint is public and unauthenticated, Apple's attribution
signature is the trust boundary. Two things follow. First, treat
`signature_valid = 1` as the ground truth for spend decisions — anyone can
POST a well-formed but unsigned postback. Second, Apple does **not** sign
the conversion values (fine or coarse) in any SKAN version, so even a
signature-valid postback's conversion value is not cryptographically bound —
this is a property of SKAdNetwork itself, worth knowing when you weigh the
numbers.

## Postback fields

Every protocol's postbacks share one table and one row shape, told apart
by `protocol` (`skadnetwork`, `adattributionkit`). The generic columns are
filled for all of them: `ad_network_id`, `transaction_id` (the framework's
unique postback id), `app_id` (the advertised app), `postback_sequence_index`
(0–2, the three conversion windows), `did_win`, `conversion_type`
(`download`, `redownload`, or AdAttributionKit's `re-engagement`),
`ad_interaction_type` (`view` or `click`), `conversion_value` (0–63),
`coarse_conversion_value` (`low`/`medium`/`high`), `source_app_id` (the
publisher app), `country_code`, `signature_state`, `signature_valid`,
`key_id` (the signing key the postback named, AdAttributionKit only), plus
`received_at` and `remote_ip`. The SKAdNetwork-specific columns mirror
Apple's parameters (hyphens become underscores) and are `NULL` on other
protocols' rows: `version`, `source_identifier` (SKAN 4; 1–4 digits,
hierarchical), `campaign_id` (SKAN ≤ 3), `redownload` and `fidelity_type`
(the raw flags behind `conversion_type` and `ad_interaction_type`; 1 =
StoreKit-rendered or web ad, 0 = view-through), and `source_domain` (SKAN 4
web ads); `marketplace_id` is AdAttributionKit's. Fields Apple withheld for
privacy store as `NULL`. The raw request body is retained in the database
for forensics but never served through the API.

### Retention

Because the endpoint is public, rows nobody will ever act on are pruned
opportunistically (piggybacked on receiver traffic, in small batches):
postbacks still **unclaimed** by any app registration after 30 days,
postbacks whose signature verified as **forged** (`signature_valid = 0`)
after 90 days, and postbacks nobody vouched for (`signature_valid IS NULL`:
**unverifiable**, or **development**-signed for an app that did not opt in)
after 90 days. Only a row that verified against Apple's production key
(or is development-signed for an app that opted in) *and* belongs to a
registered app is kept forever.

That third class matters because it is the cheapest row for a stranger to
create: naming a SKAN version this server cannot verify stores
`signature_valid = NULL`, and a body naming a registered App Store id (a
public number) is claimed at the same time — so it belonged to neither of
the other classes and would have lived forever.

Override with the `P202_ATTRIBUTION_RETENTION_DAYS_UNCLAIMED`,
`P202_ATTRIBUTION_RETENTION_DAYS_INVALID` and
`P202_ATTRIBUTION_RETENTION_DAYS_UNVERIFIABLE` environment variables (`0` disables
that class's pruning entirely). A value that is not a whole number of days
is **rejected**, not rounded or defaulted: the class prunes nothing and the
variable is named in the error log, so writing `never` keeps rows rather
than quietly deleting them on the 30-day default.

### List filters

`limit` and `offset` must be integers — a non-numeric value is a 422 naming
the parameter rather than a silent fall back to the default, so a mistyped
limit cannot read as "this account has one postback". A number outside the
allowed range still clamps (`limit` to 1–500).

`GET /attribution/postbacks` accepts `limit`, `offset`, `time_from`/`time_to`
(unix, on `received_at`), and equality filters: `app_id`, `ad_network_id`,
`version`, `transaction_id`, `country_code`, `source_identifier`,
`campaign_id`, `fidelity_type`, `postback_sequence_index`, `did_win`,
`redownload`, `coarse_conversion_value`, and
`signature` (`valid` / `invalid` / `unverifiable`).

## Apps

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `app_id` | integer | Yes | Numeric App Store id of the advertised app |
| `app_name` | string | Yes | Display name for reports (max 255) |
| `notes` | string | No | Free-form notes (max 500) |
| `accept_development_postbacks` | `0`/`1` | No | `1` = postbacks signed with Apple's AdAttributionKit **development** keys store as trusted for this app and count in the report; default `0` stores them flagged `development` and counts them nowhere. Turn it on while integration-testing your own build, and off again before trusting the numbers: any phone in Developer Mode can mint a development-signed postback naming any App Store id. SKAdNetwork has no development key, so this never affects SKAdNetwork rows. |

An App Store id can be registered by exactly one user across the install
(second registration returns `409`) — the receiver resolves postback
ownership by app id alone, so two owners would make attribution ambiguous.
On a multi-user install this is first-come-first-served: whoever registers
the app owns its postbacks until they delete the registration, and there is
no per-user view of another user's claim — coordinate app ownership
between users the way campaigns are divided. Registering (or updating) an
app claims any unclaimed postbacks for it; deleting a registration keeps
already-claimed history with its user (history is never reassigned).

## Conversion values

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `app_id` | integer | No | App this rule applies to; `0` (default) = account-wide fallback |
| `fine_value` | integer | One of | Fine conversion value 0–63 |
| `coarse_value` | string | One of | `low`, `medium`, or `high` |
| `event_name` | string | Yes | Event the value decodes to (max 255) |
| `revenue` | number | No | Revenue attributed per decoded postback (default 0; must not be negative) |

Each rule maps exactly one fine **or** one coarse value (`422` otherwise;
duplicate mappings return `409`). Resolution order at decode time:
app-specific fine rule → default (`app_id = 0`) fine rule; coarse values
resolve the same way. A fine value with no rule stays *undecoded* — it never
falls back to a coarse rule.

To switch a rule between kinds, send the clear and the replacement in one
`PUT`: an explicit `null` empties the old kind while the new value arrives
(`{"fine_value": null, "coarse_value": "high"}` turns a fine rule into a
coarse one). An absent field means "keep"; clearing without a replacement is
rejected, since a rule must always map exactly one value. The CLI spells
this `p202 attribution cv update <id> --clear-fine-value --coarse-value high`.

## Remote-configured conversion values (no app resubmission)

The app does not have to hardcode its conversion-value scheme. Registering
an app mints a **schema token** (returned by `POST /attribution/apps`, shown by
`GET /attribution/apps/{id}`, rotatable via `POST
/attribution/apps/{id}/schema-token/rotate`). A build carrying that token fetches

```
GET /api/v3/attribution/schema
X-P202-Schema-Token: <token>
```

The token travels **only** as that header — a `?token=` query parameter is
rejected-by-omission (the endpoint never reads it), because query strings
land in access logs, proxies, and browser history.

which serves the ENCODE view of the same rules the reports decode with:

```json
{"data": {"app_id": 525463029, "schema_version": "d6eefc…",
  "events": {"purchase": {"fine_value": 63, "coarse_value": "high"},
             "signup":   {"fine_value": 5,  "coarse_value": null}},
  "generated_at": 1725690000}}
```

Edit the rules and every installed build picks the change up on its next
fetch — no App Store resubmission. The endpoint is unauthenticated by
design (an app binary cannot hold an API key); the token gates it, grants
read access to this document only, and the document deliberately excludes
revenue amounts. Because the token is a bearer capability, the server keeps
it out of every side channel: `POST /attribution/apps` does not record replayable
idempotency responses (retries are already safe — a duplicate registration
answers `409`), staged-change apply results and delete previews are served
with the token redacted, and the owner reads it via their own scoped
`GET /attribution/apps/{id}`. The endpoint supports `If-None-Match` (304 until a
rule changes, `ETag` = `schema_version`) and is rate-limited per source IP
(300/min). Precedence
mirrors decoding — app-specific rules beat `app_id = 0` defaults — and when
several values decode to one event, the highest is served, so encode and
decode stay two views of one rule set. `features.attribution_postbacks` in `/capabilities`
advertises the whole SKAN surface, this endpoint included.

The repository ships **P202Attribution** ([`sdk/ios-attribution/`](../../sdk/ios-attribution/)),
a small dependency-free Swift helper that fetches and caches this document
(offline-safe, ETag-aware, token-keyed cache) and maps
`P202Attribution.shared.logEvent("purchase")` to the right
`SKAdNetwork.updatePostbackConversionValue` call — unmapped events are
deliberate no-ops. Verify what devices will receive with
`p202 attribution schema <app-registration-id>`, which performs the same request
the helper makes. The `NSAdvertisingAttributionReportEndpoint` line in
`Info.plist` still ships with the app — iOS does not allow setting it at
runtime.

## Report

`GET /attribution/report?group_by=day|app|ad-network|source|country|version` with
the same filters as the postback list, plus `limit` (max groups, default
100). Days are UTC, listed oldest first. Each group reports:

- `postbacks`, `losses` (`did-win: false`), `installs` (winning
  first-window postbacks excluding redownloads; postbacks without `did-win`
  — SKAN ≤ 2.2 winners — count as wins), `redownloads`
- `signature_valid_count` / `signature_invalid_count` /
  `signature_unverified_count`
- Conversion-value decoding: `measurable` (winning postbacks carrying a
  value, across all three conversion windows — so it can exceed `installs`,
  which counts first-window postbacks only), `decoded`, `undecoded` (value
  present, no matching rule),
  `null_conversion_values` (value withheld by Apple's privacy tier),
  `decoded_revenue`, and `events` (per-event counts and revenue)

**Trust default:** the receiver is public, so unless you pass an explicit
`signature` filter, every headline metric — installs, losses, redownloads,
the whole conversion-value decode — counts **only signature-verified
postbacks**; forged and unverifiable rows stay visible through the
`signature_*_count` columns (which always count all rows in the group) but
cannot move the numbers. `meta.trusted` says which regime produced the
response: `verified-only` (the default) or `as-filtered` (you filtered by
signature state yourself, e.g. `signature=invalid` to study forgeries).
Malformed filter values are rejected with `422` rather than ignored, and
`meta.groups_truncated: true` flags a report that hit the group `limit`
with groups left over — raise `limit` or narrow the time range rather than
treating the visible groups as the whole story.

## Verifying a postback by hand

`POST /attribution/verify` with a postback JSON object as the body returns the
signature verdict (`valid` / `invalid` / `unverifiable`) and
`signed_message_base64` — the exact byte string Apple signed (parameters
joined with U+2063), for diffing against another implementation. Nothing is
stored; the production Apple key is always used.

## Example

```bash
# What a device delivers (SKAN 4.0 winning postback):
curl -X POST https://your-domain.com/.well-known/skadnetwork/report-attribution/ \
  -H "Content-Type: application/json" \
  -d '{
    "version": "4.0",
    "ad-network-id": "example123.skadnetwork",
    "source-identifier": "5239",
    "app-id": 525463029,
    "transaction-id": "6aafb7a5-0170-41b5-bbe4-fe71dedf1e28",
    "redownload": false,
    "source-app-id": 1234567891,
    "fidelity-type": 1,
    "did-win": true,
    "conversion-value": 63,
    "postback-sequence-index": 0,
    "attribution-signature": "MEUCIQ..."
  }'

# Register the app and a conversion-value schema, then report:
curl -X POST https://your-domain.com/api/v3/attribution/apps \
  -H "Authorization: Bearer YOUR_API_KEY" -H "Content-Type: application/json" \
  -d '{"app_id": 525463029, "app_name": "My iOS App"}'

curl -X POST https://your-domain.com/api/v3/attribution/conversion-values \
  -H "Authorization: Bearer YOUR_API_KEY" -H "Content-Type: application/json" \
  -d '{"app_id": 525463029, "fine_value": 63, "event_name": "purchase", "revenue": 49.99}'

curl "https://your-domain.com/api/v3/attribution/report?group_by=day" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

## What SKAN can and cannot tell you

SKAN is aggregate, delayed, and anonymous by design. Expect and plan for:

- **No click-level join.** Postbacks carry no device id, click id, or
  Prosper202 subid — SKAN installs cannot be matched to individual clicks
  or conversions elsewhere in Prosper202. Campaign-level comparison happens
  through the ad network's `source-identifier` (or `campaign-id`).
- **Delays are intentional.** The first postback arrives 24–48+ hours after
  install; SKAN 4's second and third windows arrive days to weeks later.
- **Privacy tiers null things out.** Low-volume campaigns receive postbacks
  with the conversion value, source app, and country withheld, and fewer
  source-identifier digits. `null_conversion_values` in the report makes
  that visible.
- **The app controls the conversion value.** Prosper202 decodes; the app's
  SKAdNetwork calls encode. With the P202Attribution helper (or your own fetch of
  `/attribution/schema`), both sides are driven by the same rules and cannot
  drift. An app that hardcodes its own encoding instead must be kept in
  sync with `/attribution/conversion-values` by hand, or decoded revenue silently
  skews.
- **AdAttributionKit** (Apple's SKAN successor with JWS-signed postbacks
  and re-engagement support) uses a different postback format and is not
  yet supported; SKAN postbacks continue to flow from current iOS versions.
