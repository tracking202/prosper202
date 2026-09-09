# Attribution postbacks (SKAdNetwork and AdAttributionKit)

Prosper202 can act as the measurement endpoint for Apple's two
privacy-preserving attribution frameworks for iOS app campaigns:
**SKAdNetwork** (install attribution for App Store campaigns) and
**AdAttributionKit** (its successor, iOS 17.4+, which also covers alternative
app marketplaces and re-engagement). iOS devices send signed postbacks
directly to URLs the advertised app (or an ad network) designates. Point
them at your Prosper202 install and it will receive the postbacks, verify
Apple's signature on each one — ECDSA over SKAdNetwork's per-version field
composition; an ES256 JWS for AdAttributionKit — store them in one table
with a `protocol` column, decode conversion values into named events and
revenue through one set of rules, and report on the results across both
frameworks — the server side of what a mobile measurement partner (MMP)
does.

Supported SKAdNetwork postback versions: 2.1, 2.2, 3.0, and 4.0 are verified
against Apple's published P-256 key. Retired versions (1.0, 2.0) and
versions newer than 4.0 are stored but flagged `unverifiable`.
AdAttributionKit postbacks are unversioned; their JWS is verified against
the key its `kid` names — Apple's production key (the same key SKAdNetwork
uses) or one of Apple's two development keys, which store as `development`
(see [AdAttributionKit](#adattributionkit) below).

## Setup

1. **Confirm the endpoints are reachable.** Postbacks arrive at
   `https://your-domain.com/.well-known/skadnetwork/report-attribution/`
   (SKAdNetwork) and
   `https://your-domain.com/.well-known/appattribution/report-attribution/`
   (AdAttributionKit) — shipped as real directories, so no rewrite rules are
   needed and the bundled Apache/nginx configs already keep `/.well-known/`
   servable. A GET to either URL returns
   `{"data":{"status":"ready","protocol":...}}`. The URLs must be served
   over HTTPS on port 443 for devices to deliver to them.

2. **Point the app at them.** The app developer adds the endpoint keys to
   the app's `Info.plist` — the SKAdNetwork one, the AdAttributionKit one
   (iOS 17.4+), and, to receive copies of AdAttributionKit re-engagement
   postbacks (iOS 18+), the Boolean opt-in:

   ```xml
   <key>NSAdvertisingAttributionReportEndpoint</key>
   <string>https://your-domain.com</string>
   <key>AttributionCopyEndpoint</key>
   <string>https://your-domain.com</string>
   <key>EligibleForAdAttributionKitReengagementPostbackCopies</key>
   <true/>
   ```

   Devices append the well-known paths themselves. With this in place the
   developer receives a copy of every *winning* postback for the app from
   both frameworks. (Registered ad networks can likewise use the same URLs
   as their postback endpoints and will also receive non-winning
   postbacks.)

3. **Register the app** so its postbacks belong to your reporting
   (`POST /attribution/apps` with the numeric App Store id). Postbacks that arrive
   before registration are stored unclaimed and are claimed retroactively
   when the app is registered.

4. **Mirror the app's conversion-value schema** as decoding rules
   (`POST /attribution/conversion-values`). The app itself chooses what the 6-bit
   fine value (0–63) and the coarse value (`low`/`medium`/`high`) mean when
   it calls SKAdNetwork's `updatePostbackConversionValue(_:coarseValue:lockWindow:)`
   or AdAttributionKit's `Postback.updateConversionValue` (the same value
   space in both) — Prosper202 cannot set conversion values for the app, it
   decodes what the app encoded. Keep the two sides in sync or reports will
   decode to the wrong events.

## Endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| `POST` | `/.well-known/skadnetwork/report-attribution/` | Public SKAdNetwork postback receiver (no auth — devices POST here) |
| `POST` | `/.well-known/appattribution/report-attribution/` | Public AdAttributionKit postback-copy receiver (no auth — devices POST here) |
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
lists the protocols the server receives in `features.attribution_postbacks`
(`["skadnetwork", "adattributionkit"]`).

## The receiver

The receiver validates strictly, verifies, and stores — it never drops a
plausible postback silently:

- Structurally broken bodies (bad JSON, missing or mis-typed fields,
  out-of-range values) are rejected with `400` and per-field errors. Genuine
  devices never send these.
- Valid postbacks are stored and answered `200` — including replays (devices
  retry up to nine times over several days when they don't get a `200`;
  replays answer `{"duplicate": true}` and store nothing new). Deduplication
  covers the postback id (SKAdNetwork `transaction-id`, AdAttributionKit
  `postback-identifier`) namespaced by protocol and ad network, the
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

## AdAttributionKit

An AdAttributionKit postback copy is a JSON envelope:

```json
{
  "jws-string": "<header>.<payload>.<signature>",
  "conversion-value": 24,
  "coarse-conversion-value": "high",
  "ad-interaction-type": "click",
  "country-code": "US"
}
```

Only the `jws-string` is signed. Its payload carries the attribution:
`postback-identifier`, `ad-network-identifier`, `advertised-item-identifier`
(the App Store id), `impression-type`, `did-win`, `postback-sequence-index`
(0–2), `conversion-type` (`download`, `redownload`, or `re-engagement`),
`source-identifier`, and the optional `publisher-item-identifier` and
`marketplace-identifier`. The four fields beside it — the conversion values,
the interaction type and the country — are unsigned, exactly as SKAdNetwork
never signed conversion values either.

The receiver decodes the JWS (three unpadded base64url segments; the header
must name `alg` and `kid`), validates the payload under Apple's field names
(the tier-withheld fields are optional, everything else required), and
verifies the signature over the received segments with the key `kid` names.
`ES256` is required whatever the header claims — the header never chooses
the algorithm — and the key is chosen by `kid` alone:

| `kid` | Key | Stored `signature_state` |
| ----- | --- | ------------------------ |
| `apple-cas-identifier/0` | Apple's production key (the SKAdNetwork key) | `valid` |
| `apple-development-identifier/0`, `apple-development-identifier/1` | Apple's development keys (end-to-end test flows; postbacks generated from Developer settings) | `development` |
| anything else | unknown | `unverifiable` |

A signature that does not check out, or a header naming another algorithm,
stores `invalid`. Malformed JWS strings and payloads that are not postbacks
are `400`s with the offending field named.

**Development signatures are untrusted by default.** "Verified against
Apple's development key" is a true statement that must not count as
verified: any phone in Developer Mode can mint a development-signed
postback naming any App Store id. Such rows store with `signature_state =
development` and no trust bit (`signature_valid` null — the same class as
unverifiable rows, pruned after 90 days), so they count nowhere. Turn on
`accept_development_postbacks` on the app registration while
integration-testing your own build, and the app's development rows —
those already stored and those still to arrive — become trusted
(`signature_valid = 1`); turn it off again and they stop being trusted.
The flag is a live policy, not a receipt-time snapshot. `signature=development`
lists such rows whatever their trust bit, and the report's
`signature_development_count` counts them per group.

Normalization onto the shared row: `postback-identifier` → `transaction_id`,
`advertised-item-identifier` → `app_id`, `publisher-item-identifier` →
`source_app_id`, `marketplace-identifier` → `marketplace_id`, `conversion-type`
→ `conversion_type` and `ad-interaction-type` → `ad_interaction_type`
verbatim, the JWS header's `kid` → `key_id`, and the whole `jws-string` →
`attribution_signature`. SKAdNetwork rows carry the same generic dimensions
derived from their own flags (`redownload` → `conversion_type`,
`fidelity-type` → `ad_interaction_type`), so one report reads both.

**Re-engagement** is the one signal SKAdNetwork never had: an
AdAttributionKit postback with `conversion-type: re-engagement` reports a
conversion by someone who already had the app. It is neither an install nor
a redownload — the report counts it as `reengagements` — and it carries its
own conversion value, which the app sets through
`Postback.updateConversionValue` with `conversionTypes: [.reengagement]`
(iOS 18+; the P202Attribution helper's `logEvent(_:conversionTypes:)`).

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

Because the endpoint is public, rows nobody will ever act on have windows:
postbacks still **unclaimed** by any app registration after 30 days,
postbacks whose signature verified as **forged** (`signature_valid = 0`)
after 90 days, and postbacks nobody vouched for (`signature_valid IS NULL`:
**unverifiable**, or **development**-signed for an app that did not opt in)
after 90 days. Only a row that verified against Apple's production key
(or is development-signed for an app that opted in) *and* belongs to a
registered app is kept forever.

`202-cronjobs/attribution-retention.php` is the pruner to schedule (hourly
or daily): it runs whether or not postbacks are arriving, drains the backlog
rather than nibbling at it, and reports what it removed (`--dry-run` reports
the windows and the backlog without deleting). The receiver also prunes
opportunistically, piggybacked on its own traffic in small batches, but that
is a safety net rather than the policy — it only fires while new postbacks
are still arriving.

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
than quietly deleting them on the 30-day default. They are read by whichever
process prunes, so put them in the crontab line (or the cron user's
environment) — php-fpm's environment only configures the receiver's
opportunistic pass.

### List filters

`limit` and `offset` must be integers — a non-numeric value is a 422 naming
the parameter rather than a silent fall back to the default, so a mistyped
limit cannot read as "this account has one postback". A number outside the
allowed range still clamps (`limit` to 1–500).

`GET /attribution/postbacks` accepts `limit`, `offset`, `time_from`/`time_to`
(unix, on `received_at`), and equality filters: `protocol` (`skadnetwork` /
`adattributionkit`; the shorthands `skan` / `aak` are accepted),
`conversion_type` (`download` / `redownload` / `re-engagement`),
`ad_interaction_type` (`view` / `click`), `app_id`, `ad_network_id`,
`version`, `transaction_id`, `country_code`, `source_identifier`,
`campaign_id`, `fidelity_type`, `postback_sequence_index`, `did_win`,
`redownload`, `coarse_conversion_value`, and `signature`. The `signature`
values `valid` / `invalid` / `unverifiable` select on the **trust bit** the
report keys on (`signature_valid` 1 / 0 / null — so `valid` is exactly
what the default report counts, opted-in development rows included);
`development` selects rows verified against a development key whatever
their trust bit. Unknown values are `422`s naming the choices.

`redownload` and `fidelity_type` are SKAdNetwork's spellings of
`conversion_type` and `ad_interaction_type`, and are matched on those
protocol-neutral columns: `redownload=1` selects `conversion_type =
redownload` and `redownload=0` selects `download`; `fidelity_type=1` selects
`ad_interaction_type = click` and `fidelity_type=0` selects `view`. Only
SKAdNetwork rows carry the raw `redownload` and `fidelity_type` columns —
AdAttributionKit leaves both `NULL` — so matching them literally answered
the same question two different ways depending on which spelling you used.
Prefer `conversion_type` / `ad_interaction_type`: they mean the same thing
across both protocols, and `conversion_type` can also name `re-engagement`,
which `redownload` has no way to express (and which `redownload=0`
therefore excludes).

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
advertises the whole postback surface, this endpoint included.

The repository ships **P202Attribution** ([`sdk/ios-attribution/`](../../sdk/ios-attribution/)),
a small dependency-free Swift helper that fetches and caches this document
(offline-safe, ETag-aware, token-keyed cache) and maps
`P202Attribution.shared.logEvent("purchase")` to the right
`SKAdNetwork.updatePostbackConversionValue` and AdAttributionKit
`Postback.updateConversionValue` calls — unmapped events are deliberate
no-ops, and `logEvent(_:conversionTypes:)` scopes an update to
AdAttributionKit's re-engagement postback. Verify what devices will receive
with `p202 attribution schema <app-registration-id>`, which performs the
same request the helper makes. The `NSAdvertisingAttributionReportEndpoint`
and `AttributionCopyEndpoint` lines in `Info.plist` still ship with the app
— iOS does not allow setting them at runtime.

## Report

`GET /attribution/report?group_by=day|app|ad-network|source|country|version|protocol|conversion-type`
with the same filters as the postback list, plus `limit` (max groups,
default 100). Days are UTC, listed oldest first. Each group reports:

- `postbacks` (unique postbacks, whatever their signature state), `losses`
  (`did-win: false`), `installs` (winning
  first-window `download` postbacks; postbacks without `did-win` — SKAN ≤
  2.2 winners — count as wins), `redownloads`, and `reengagements`
  (AdAttributionKit only)
- `signature_valid_count` / `signature_invalid_count` /
  `signature_unverified_count` (no trust bit, development postbacks
  without the opt-in included) / `signature_development_count` (verified
  against a development key, whatever their trust bit) — each counts
  unique postbacks, and a postback belonging to two classes (an opted-in
  development-signed one is both `valid` and `development`) counts in each
- Conversion-value decoding: `measurable` (unique winning postbacks
  carrying a value, across all three conversion windows — so it can exceed
  `installs`, which counts first-window postbacks only), `decoded`,
  `undecoded` (value present, no matching rule),
  `null_conversion_values` (value withheld by Apple's privacy tier),
  `decoded_revenue`, and `events` (per-event counts and revenue)

**Every metric counts unique postbacks** — protocol, ad network, postback
id and conversion window — and the decode reads each unique postback's
first-received copy. The receiver deliberately stores a replay of a signed
postback whose *unsigned* fields differ (a different conversion value on
the same signed postback) as its own row, so that a forgery can never block
the genuine copy; the report is where such a replay collapses to one.
Without that, whoever holds one genuine postback could resend it with new
conversion values and mint installs and revenue. `postbacks` and the four
`signature_*_count` columns count unique postbacks too, so no column of the
report exposes the stored row count — it answers "how many postbacks",
never "how many rows".

**Trust default:** the receiver is public, so unless you pass an explicit
`signature` filter, every headline metric — installs, losses, redownloads,
the whole conversion-value decode — counts **only signature-verified
postbacks**; forged and unverifiable rows stay visible through the
`signature_*_count` columns (which count every unique postback in the
group, whatever its signature state) but cannot move the numbers.
`meta.trusted` says which regime produced the response: `verified-only`
(the default) or `as-filtered` (you filtered by signature state yourself,
e.g. `signature=invalid` to study forgeries).
Malformed filter values are rejected with `422` rather than ignored, and
`meta.groups_truncated: true` flags a report that hit the group `limit`
with groups left over — raise `limit` or narrow the time range rather than
treating the visible groups as the whole story.

**Which groups come back:** `group_by=day` returns the newest `limit`
populated days — a day no matching postback landed on is not a group at all,
so an account that received postbacks on six days spread over two years gets
six groups, not two years of zeroes. Every other mode returns the `limit`
busiest groups. Neither `time_from` nor `time_to` has a default, so an
unfiltered day report picks its days out of the account's whole retained
history; to reach days older than the newest `limit`, raise `limit` or move
the window with `time_to`.

## Verifying a postback by hand

`POST /attribution/verify` with a postback JSON object as the body returns the
signature verdict. The protocol is detected from the body: a `jws-string`
key is verified as AdAttributionKit — the JWS alone is enough — and the
response carries `protocol`, the verdict (`valid` / `invalid` /
`unverifiable` / `development`), the `key_id`, the decoded `header` and
`payload`, and the key ids the server knows; anything else is verified as
SKAdNetwork, returning `signed_message_base64` — the exact byte string Apple
signed (parameters joined with U+2063), for diffing against another
implementation. The `jws-string` decides on its own, so a body carrying the
SKAdNetwork fields *and* a `jws-string` — a postback copied from one
receiver into the other's envelope — is verified as AdAttributionKit.
Nothing is stored; Apple's published keys are always used.

A `422` means an empty body, or a `jws-string` that is not a decodable
compact JWS. A body without a `jws-string` is never a validation error: it
is verified as SKAdNetwork and comes back `unverifiable` when its `version`
is not one this server can check, `invalid` when it claims a version whose
signed fields it does not carry.

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

# What a device delivers for AdAttributionKit (a postback copy; the signed
# attribution is inside the JWS, the four other fields are unsigned):
curl -X POST https://your-domain.com/.well-known/appattribution/report-attribution/ \
  -H "Content-Type: application/json" \
  -d '{
    "jws-string": "eyJraWQiOiJhcHBsZS1jYXMtaWRlbnRpZmllclwvMCIsImFsZyI6IkVTMjU2In0.eyJwb3N0YmFjay1pZGVudGlmaWVyIjoi...",
    "conversion-value": 24,
    "ad-interaction-type": "click",
    "country-code": "US"
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

## What these postbacks can and cannot tell you

Both frameworks are aggregate, delayed, and anonymous by design. Expect and
plan for:

- **No click-level join.** Postbacks carry no device id, click id, or
  Prosper202 subid — installs cannot be matched to individual clicks or
  conversions elsewhere in Prosper202. Campaign-level comparison happens
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
- **Both frameworks flow side by side** on current iOS versions, and the
  system picks one winner per conversion across them — so a device reports
  a given install through SKAdNetwork *or* AdAttributionKit, never both.
  The report tells them apart by `protocol`; only AdAttributionKit rows can
  carry `conversion_type: re-engagement`, and only for apps that ship the
  re-engagement `Info.plist` key.
