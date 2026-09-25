# App measurement: the app registry and Apple's postbacks

`/api/v3/apps` is the app-measurement surface. It has two parts:

- **The registry** — one registration per app you advertise, for both
  platforms: an app is `(platform, app_key)`, where `app_key` is the App Store
  id for iOS and the application id (package name) for Android. Every other
  app table links to a registration by `registration_id`. See [Apps](#apps).
- **Apple's signal source** — SKAdNetwork and AdAttributionKit postbacks,
  the rest of this guide.

Android installs arrive on the same registry, through the SDK's
`POST /apps/installs` — see [24-android-installs.md](24-android-installs.md).
The SDK wire contract is [21-app-sdk-contract.md](21-app-sdk-contract.md).

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

## Registering an app in the web interface

**Prosper202 CS › Setup › Mobile Apps** does everything this guide describes
through the API, for people who would rather not use it.

Paste the app's App Store link into the one field on that page. The App Store
id, the platform and the app's name are read from the link, and the
registration is one click; what was derived is then shown at the top of the
app's page with a **change** link, so nothing is assumed silently. If the name
cannot be looked up, the form comes back asking for that one field rather than
registering the app under a placeholder.

The page also:

- fetches both receiver URLs **from your browser** and reports what came back,
  with the exact origin to paste into `Info.plist`. An install whose URL is not
  HTTPS is marked `Apple requires HTTPS` rather than fetched, because Apple
  calls the receiver over public HTTPS on port 443 and nothing else. Read a
  green `Ready` as "your browser reached it", not as "Apple can": a receiver on
  `localhost` or behind a VPN answers you and not Apple;
- offers a starter conversion-value schema (install, trial, purchase on fine
  values 1, 10 and 40 and the three coarse buckets) when an app has no rules,
  and edits rules one at a time afterwards;
- shows the app token masked, with Reveal, Copy and Rotate, and fills the
  `Info.plist` keys and the Swift snippet in with this install's URL;
- lists the newest ten postbacks the app has received, whatever their
  signature state;
- offers the test-signal policy where it matters: when development-signed
  postbacks have arrived for an app that does not trust them, the app's row
  says so and accepts them in one click.

The page manages iOS registrations. A Google Play link pasted there is named
back as an Android app and pointed at `p202 app create --store-link` or
`POST /api/v3/apps`, which register it; the Android page arrives with Android
install tracking.

Everything on that page goes through the same v3 controllers as the API and
the CLI, so the validation and the error sentences are identical. Registering
an app there is the same write as `POST /api/v3/apps`.

## Reading the report in the web interface

**Prosper202 CS › Analyze › Mobile Apps** is `GET /apps/report`,
`GET /apps/postbacks` and `POST /apps/verify` rendered, as three
tabs over one set of filters:

- **Report** — the totals, and the same numbers grouped by day, app, ad
  network, source, country, protocol, version or conversion type. Six tiles
  head it (postbacks, installs, re-downloads, re-engagements, losses, decoded
  revenue), and below the table a **Decoded events** panel adds up the named
  events the SKAN encodings produced. When the range holds postbacks
  that are not trusted, a line says so and gives the count for each trust
  class, because installs, losses and revenue count trusted
  postbacks only unless the signature filter says otherwise — the same
  `meta.trusted` the API reports. **Download to CSV** takes the grouped table
  as it stands, with revenue as a bare number a spreadsheet can add up.
- **Postbacks** — the individual rows behind those totals, fifty to a page,
  for the moment a total looks wrong and the question becomes which ones. Each
  row carries its transaction id, so a disagreement about one postback can be
  taken to the ad network that claims it, and its conversion window, because
  the report's install counts only the first one.
- **Verify** — paste a postback and the page says whether its signature is
  genuine, without storing anything. A SKAdNetwork body comes back with the
  exact bytes Apple signs, in base64, for diffing against another
  implementation; an AdAttributionKit body (the object holding `jws-string`)
  comes back decoded, with the key id that signed it. When the answer is that
  nothing could be checked, the page says why: a field the version requires is
  missing, the version is not one this install verifies, or the key id is not
  one it knows.

The range picker offers the same preset windows as the click reports (Today,
Yesterday, Last 7/14/30/90 Days, This Month, Last Month) plus Custom Date,
which is what makes the two date fields live. **The dates on this page are
UTC**, unlike the click reports, which use your account timezone: postbacks
are grouped into whole UTC days by the report itself, so a window anchored to
a local midnight would split its first and last day across groups the report
does not show.

Every filter is in the URL, so a report someone is looking at is a link they
can send. The app filter is `registration_id`. A filter the page cannot use — a non-numeric registration id, an unknown
signature state, a date that is not `YYYY-MM-DD` or does not exist — is
dropped with a line saying so, and the rest of the report is still shown.

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
   (`POST /apps` with its App Store link or `app_key`). Postbacks that arrive
   before registration are stored unclaimed and are claimed retroactively
   when the app is registered.

4. **Mirror the app's conversion-value schema** as SKAN encodings
   (`POST /apps/skan-encodings`). The app itself chooses what the 6-bit
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
| `GET` | `/apps/postbacks` | List received postbacks (filters below, paginated) |
| `GET` | `/apps/postbacks/{id}` | One postback, including its attribution signature |
| `GET` | `/apps/report` | Aggregate report with SKAN decoding |
| `POST` | `/apps/verify` | Verify a postback payload's signature without storing it |
| `GET/POST` | `/apps`, `/apps/{id}` | App registry, both platforms (CRUD; `PUT`/`DELETE` on `/{id}`) |
| `POST` | `/apps/{id}/app-token/rotate` | Mint a new app token; the old one stops working |
| `GET/POST` | `/apps/skan-encodings`, `/apps/skan-encodings/{id}` | SKAN encodings (CRUD; `PUT`/`DELETE` on `/{id}`) |
| `GET` | `/apps/schema` | Public, selected by the `X-P202-App-Token` header: the document an app build fetches at runtime |
| `POST` | `/apps/installs`, `/apps/installs/{install_uuid}/events` | Public, by app token: the Android SDK's intake ([24-android-installs.md](24-android-installs.md)) |
| `GET` | `/apps/{id}/installs`, `/apps/{id}/installs/{install_uuid}` | An Android registration's installs, with their match state |
| `GET` | `/apps/{id}/install-token?click_id=N` | The install token and store link for one click (a read) |

All `/apps` API routes require the `apps` scope area (`apps:read` for reads,
`apps:write` for writes; `POST /apps/verify` counts as a read — it computes
over the submitted payload without storing anything). The `attribution`
scope area is multi-touch attribution's alone, so a key scoped to one cannot
touch the other. Registration and encoding writes support `?staged=1`
proposals and `?dry_run=1` delete previews like the rest of the operator
surface. `GET /capabilities` lists the platforms the registry takes in
`features.app_platforms` (`["ios", "android"]`) and the postback protocols
the server receives in `features.app_postbacks`
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
  Apple's AdAttributionKit development keys — see the registration's
  `accept_test_signals`). `trusted` is the **trust class** the report keys
  on, in the vocabulary every signal source shares: `1` trusted (verified
  against the production key, or development-signed for a registration that
  accepts test signals), `0` refuted (forged), `NULL` unvouched (nobody
  vouched for it). Tampered or unverifiable postbacks are stored
  *and flagged* rather than dropped, so integration tests and forensics
  work. The report already excludes them from headline metrics by default
  (see Report below); the postback *list* shows every row, filterable with
  `signature=valid|invalid|unverifiable`.
- Database outages answer `503`/`500` so the device retries later; a
  per-peer rate limit (600/min per TCP peer address — never a forwarded
  header, fail-open if the limiter itself breaks) answers `429` with
  `Retry-After`. A method other than GET or POST is `405`, and a body that
  declares itself over 32 KB is `413` before anything connects to the
  database. The public app routes share this plumbing
  (`Api\V3\Apps\PublicIntake`).

Because the endpoint is public and unauthenticated, Apple's attribution
signature is the trust boundary. Two things follow. First, treat
`trusted = 1` as the ground truth for spend decisions — anyone can
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
development` and no trust bit (`trusted` null, unvouched — the same class
as unverifiable rows, pruned after 90 days), so they count nowhere. Turn on
`accept_test_signals` on the registration while integration-testing your
own build, and the app's development rows — those already stored and those
still to arrive — become trusted (`trusted = 1`); turn it off again, or
delete the registration, and they stop being trusted. The flag is a live
policy, not a receipt-time snapshot, and a policy that cannot be read
counts as off. `signature=development` lists such rows whatever their trust
bit, and the report's `test_count` counts them per group.

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
filled for all of them: `registration_id` (the registration that claimed
it; `NULL` while unclaimed or after that registration is deleted),
`ad_network_id`, `transaction_id` (the framework's unique postback id),
`app_id` (the App Store id the postback named — kept as the forensic
record), `postback_sequence_index`
(0–2, the three conversion windows), `did_win`, `conversion_type`
(`download`, `redownload`, or AdAttributionKit's `re-engagement`),
`ad_interaction_type` (`view` or `click`), `conversion_value` (0–63),
`coarse_conversion_value` (`low`/`medium`/`high`), `source_app_id` (the
publisher app), `country_code`, `signature_state`, `trusted`,
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

Because the endpoint is public, rows nobody will ever act on have windows,
registered by the Apple source with `Api\V3\Apps\AppRetention`:
postbacks still **unclaimed** by any registration after 30 days (including
the ones a deleted user's purge released), **refuted** postbacks
(`trusted = 0`, forged) after 90 days, and **unvouched** postbacks
(`trusted IS NULL`: unverifiable, or development-signed for a registration
that does not accept test signals) after 90 days. Only a trusted row that
belongs to a registration is kept forever.

`202-cronjobs/app-retention.php` is the pruner to schedule (hourly
or daily): it runs whether or not postbacks are arriving, drains the backlog
rather than nibbling at it, and reports what it removed (`--dry-run` reports
the windows and the backlog without deleting). The receiver also prunes
opportunistically, piggybacked on its own traffic in small batches, but that
is a safety net rather than the policy — it only fires while new postbacks
are still arriving.

That third class matters because it is the cheapest row for a stranger to
create: naming a SKAN version this server cannot verify stores
`trusted = NULL`, and a body naming a registered App Store id (a
public number) is claimed at the same time — so it belonged to neither of
the other classes and would have lived forever.

Override with the `P202_APP_RETENTION_DAYS_POSTBACKS_UNCLAIMED`,
`P202_APP_RETENTION_DAYS_POSTBACKS_REFUTED` and
`P202_APP_RETENTION_DAYS_POSTBACKS_UNVOUCHED` environment variables — the
pattern is `P202_APP_RETENTION_DAYS_<TABLE>_<CLASS>` (`0` disables
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

`GET /apps/postbacks` accepts `limit`, `offset`, `time_from`/`time_to`
(unix, on `received_at`), and equality filters: `registration_id`,
`protocol` (`skadnetwork` /
`adattributionkit`; the shorthands `skan` / `aak` are accepted),
`conversion_type` (`download` / `redownload` / `re-engagement`),
`ad_interaction_type` (`view` / `click`), `app_id` (the App Store id the
postback named — the forensic filter; prefer `registration_id`), `ad_network_id`,
`version`, `transaction_id`, `country_code`, `source_identifier`,
`campaign_id`, `fidelity_type`, `postback_sequence_index`, `did_win`,
`redownload`, `coarse_conversion_value`, and `signature`. The `signature`
values `valid` / `invalid` / `unverifiable` select on the **trust bit** the
report keys on (`trusted` 1 / 0 / null — so `valid` is exactly
what the default report counts, accepted development rows included);
`development` selects rows verified against a development key whatever
their trust bit. Unknown values are `422`s naming the choices.

`registration_ids` names SEVERAL registrations at once, as a comma-separated
list (`registration_ids=3,7`); `registration_id` can only ever name one. Use it
whenever you want a per-app answer about apps you already know — grouping
over everything and reading the first N groups is not the same question,
because groups come back busiest first and the apps you asked about can be
cut. At most 500 ids. Each must be a whole number, checked the same way
`registration_id` is: a value an integer cast would change (`99999999999999999999`,
`1.5`) is a `422` rather than a filter about some other app. Duplicates
collapse to one; a blank element (`1,,2`, or a trailing comma) is a `422`,
because dropping it would quietly widen the filter you asked for. Combining
`registration_id` and `registration_ids` applies both, so they must agree
for any row to match.

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

`POST /apps` registers an app. Name it one of two ways:

- `store_link` — an App Store link (`https://apps.apple.com/us/app/summit-run/id990077001`),
  a Google Play link (`https://play.google.com/store/apps/details?id=com.example.app`),
  a `market://details?id=…` link, a numeric App Store id, or a package name.
  The server reads it (the CLI's `--store-link` sends it as is), so every
  client resolves a link the same way. It is not stored.
- `app_key` — the App Store id (digits, positive, no leading zero, and the
  exact number an integer holds) or the Android application id (at least two
  dot-separated segments, each a letter followed by letters, digits or
  underscores; case-sensitive). `platform` is read from its shape when you
  leave it out.

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `store_link` or `app_key` | string | One of | The app, as above |
| `platform` | `ios`/`android` | No | Read from the key or the link; a value contradicting the link is `422` |
| `app_name` | string | Yes | Display name for reports (max 255) |
| `notes` | string | No | Free-form notes (max 500) |
| `attribution_window_days` | integer | No | Android: days after its click an install may begin and still be attributed (1–365, default 7) |
| `trust_client_revenue` | `0`/`1` | No | Android: `1` lets a revenue the app reports be paid by a goal valued from it; default `0` stores it, never credits it |
| `accept_test_signals` | `0`/`1` | No | `1` = test signals count as trusted for this app: postbacks signed with Apple's AdAttributionKit **development** keys, and installs the Android SDK marks `test`. Default `0` stores them flagged and counts them nowhere. Turn it on while integration-testing your own build, and off again before trusting the numbers: any phone in Developer Mode can mint a development-signed postback naming any App Store id. SKAdNetwork has no development key, so this never affects SKAdNetwork rows. |

The response carries `registration_id`, `platform`, `app_key` and the
`app_token`. `(platform, app_key)` can be registered by exactly one user
across the install (a second registration returns `409`) — signals resolve
their owner by the app they name, so two owners would make attribution
ambiguous. On a multi-user install this is first-come-first-served: whoever
registers the app owns its signals until they delete the registration or
their user is deleted, and there is no per-user view of another user's
claim — coordinate app ownership between users the way campaigns are
divided.

The app a registration names is fixed. `PUT /apps/{id}` changes the name,
the notes and `accept_test_signals`; sending `platform`, `app_key` or
`store_link` naming the same app is accepted and changes nothing, and naming
a different app is a `422` telling you to register it separately.

Registering (or updating) an iOS app claims its unclaimed postbacks.
Deleting a registration deletes its SKAN encodings, keeps the postbacks it
had claimed with their owner but unlinks them (`registration_id` becomes
`NULL`) and withdraws the test-signal trust it granted, all in one
transaction; registering the same app again re-links the owner's unlinked
rows.

**Deleting a user** purges their app data in the same transaction as the
delete itself, whether it is done from Account › User Management or with
`DELETE /api/v3/users/{id}`: their registrations, encodings, Android installs
and queued traffic-source postbacks are deleted —
freeing each `(platform, app_key)` for someone else to register — and their
postbacks are **released** (`user_id` 0, `registration_id` `NULL`, and a
development row's trust withdrawn) rather than deleted, because a postback
is Apple's record that an install happened, not the user's data. Released
rows are unclaimed, so the 30-day unclaimed window prunes them, and whoever
registers the app within it claims them.

## SKAN encodings

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `registration_id` | integer | No | The iOS registration this encoding applies to; `0` (default) = account-wide |
| `fine_value` | integer | One of | Fine conversion value 0–63 |
| `coarse_value` | string | One of | `low`, `medium`, or `high` |
| `goal_id` | integer | Yes | The [goal](22-goals.md) the value means: a live goal of that registration, or an account goal, that a device can reach (below) |
| `revenue_override` | number | No | Revenue per decoded postback instead of the goal's own fixed value (tiered decoding; `null` = the goal's value) |
| `effective_at` | integer | Read-only | Unix time the encoding's current meaning started to apply; every create and update sets it |

An encoding says which value means which **goal** was reached; what reaching
it is worth is the goal's (plan §4.5), unless the encoding overrides it. An
account-wide encoding (`registration_id` 0) names an account goal. The iOS
SDK evaluates goals **on the device** (Apple's postback carries only the
value, so nothing else could decide which value to set), so an encoding can
name any goal — predicates, counts, sums, `after` funnels, install windows,
repeats — with one exception: a goal that counts `within` `"from":
"click"`, or waits in `after` for one that does, is refused with a `422` on
`goal_id`. SKAdNetwork never tells an app which click it came from, so on a
device such a goal is always ineligible (`no_click`) and the value would
never be set. A goal an encoding depends on (directly or through `after`)
cannot be edited into that shape, or archived. The old fields `event_name`
and `revenue` are refused by name. The report decodes to each goal's name.
Setup › Mobile Apps still asks for an event and a revenue: it finds (or
creates) the app's plain goal for the event and stores the revenue as the
override; any other goal can be encoded through the API or
`p202 app encoding create --goal-id`.

**Encodings are versioned, and an edit is ambiguous for 35 days.** A device
sets a value with the schema document it last fetched, and Apple delivers
the postback up to 35 days later (the third conversion window closes on
day 35), so an encoding changed today is still being applied by devices
that hold the old document. Every update and delete first keeps the meaning
it replaces, with the time it stopped applying, and the report decodes each
postback under every meaning its value had in the 35 days before it arrived:

- one meaning — the postback decodes to it;
- two that disagree (another goal, or another `revenue_override`) — it is
  counted as `ambiguous_encoding` and credited to neither;
- none — the first meaning the value was given after the postback arrived
  decodes it, so encodings added once postbacks are already arriving still
  read them; a value never given one is `undecoded`.

So after an edit the report is exact again 35 days later (Setup › Mobile
Apps says so when you save one). Re-saving the same goal and override is not
an edit, and deleting an encoding keeps decoding the postbacks that were set
under it for those 35 days.

Each encoding maps exactly one fine **or** one coarse value (`422`
otherwise; duplicate mappings return `409`). A non-zero `registration_id`
must be one of your own **iOS** registrations — anything else is a `422` on
that field — because an encoding for an app nobody registered would decode
nothing. Resolution order at decode time: the claiming registration's fine
encoding → the account-wide (`registration_id = 0`) fine encoding; coarse
values resolve the same way; a postback no registration claims decodes
through the account-wide set only. A fine value with no encoding stays
*undecoded* — it never falls back to a coarse one.

To switch an encoding between kinds, send the clear and the replacement in
one `PUT`: an explicit `null` empties the old kind while the new value
arrives (`{"fine_value": null, "coarse_value": "high"}` turns a fine
encoding into a coarse one). An absent field means "keep"; clearing without
a replacement is rejected, since an encoding must always map exactly one
value. The CLI spells this
`p202 app encoding update <id> --clear-fine-value --coarse-value high`.

## Remote-configured conversion values (no app resubmission)

The app does not have to hardcode its conversion-value scheme. Registering
an app mints an **app token** (returned by `POST /apps`, shown by
`GET /apps/{id}`, rotatable via `POST /apps/{id}/app-token/rotate`). A build
carrying that token fetches

```
GET /api/v3/apps/schema
X-P202-App-Token: <token>
```

The token travels **only** as that header — a `?token=` query parameter is
rejected-by-omission (the endpoint never reads it), because query strings
land in access logs, proxies, and browser history.

which serves, for an iOS registration, the goals its encodings name and
the ENCODE view of the same encodings the reports decode with:

```json
{"data": {"platform": "ios", "app_key": "525463029", "app_id": 525463029, "schema_version": "d6eefc…",
  "goals": [{"goal_id": 7, "starts_at": 0, "ends_at": null,
             "versions": [{"version": 1, "effective_at": 1725600000,
                           "definition": {"name": "Reached level 3",
                             "trigger": {"event": "level_reached", "where": [{"prop": "level", "op": "gte", "value": 3}]},
                             "threshold": {"count": 1}, "after": [], "within": null, "repeat": {"mode": "once"}}}]}],
  "encodings": [{"goal_id": 7, "fine_value": 40, "coarse_value": "high"}],
  "generated_at": 1725690000}}
```

`goals` is an **evaluation-only** view: every goal an encoding names, every
goal it waits for in `after`, every version with the time it took effect,
and each definition with its `value` removed. The SDK evaluates them on the
device against the events the app logs, and sets the value `encodings` gives
the goals an event reaches (the highest, when one event reaches several).

Edit the goals or the encodings and every installed build picks the change
up on its next fetch — no App Store resubmission. An Android registration's
document carries its `platform` and `app_key` and no goals or encodings
(Android goals are evaluated on the server). The endpoint is unauthenticated by design (an app binary
cannot hold an API key); the token selects the registration and the
document deliberately excludes revenue amounts. **The token is an
identifier, not a secret**: it ships inside every copy of the app, so anyone
can extract it. It is still kept out of every side channel — `POST /apps`
does not record replayable idempotency responses (retries are already safe —
a duplicate registration answers `409`), staged-change apply results and
delete previews are served with the token redacted, and the owner reads it
via their own scoped `GET /apps/{id}` — and it rotates, so a build can be
cut off from the next release on. The endpoint supports `If-None-Match`
(304 until an encoding changes, `ETag` = `schema_version`), answers `405`
to anything but GET, and is rate-limited per peer address (300/min).
Precedence mirrors decoding — a registration's own encodings beat the
account-wide ones, per goal and per kind, and an account-wide value the
registration has given its own meaning is not served for the account goal
(the report would read it as the registration's) — and when several values
name one goal, the highest is served, so encode and decode stay two views
of one set.

The repository ships **P202Attribution** ([`sdk/ios-attribution/`](../../sdk/ios-attribution/)),
a small dependency-free Swift helper that fetches and caches this document
(offline-safe, ETag-aware, token-keyed cache), evaluates the goals on the
device with the same evaluator the server runs (both are held to the
vectors in `tests/fixtures/app-sdk-contract/goals/`), and turns
`try P202Attribution.shared.logEvent("level_reached", properties: ["level": .int(3)])`
into the right `SKAdNetwork.updatePostbackConversionValue` and
AdAttributionKit `Postback.updateConversionValue` calls — an event that
reaches no encoded goal is a deliberate no-op, and
`logEvent(_:properties:revenue:conversionTypes:)` scopes an update to
AdAttributionKit's re-engagement postback. `setCustomerId(_:signature:type:)`
records a customer id your server signed (see the
[SDK contract](21-app-sdk-contract.md)). Verify what devices will receive
with `p202 app schema <registration-id>`, which performs the
same request the helper makes. The `NSAdvertisingAttributionReportEndpoint`
and `AttributionCopyEndpoint` lines in `Info.plist` still ship with the app
— iOS does not allow setting them at runtime.

## Report

`GET /apps/report?group_by=day|registration|ad-network|source|country|version|protocol|conversion-type`
with the same filters as the postback list, plus `limit` (max groups,
default 100). Days are UTC, listed oldest first. Each group reports:

- `postbacks` (unique postbacks, whatever their signature state), `losses`
  (`did-win: false`), `installs` (winning
  first-window `download` postbacks; postbacks without `did-win` — SKAN ≤
  2.2 winners — count as wins), `redownloads`, and `reengagements`
  (AdAttributionKit only)
- `trusted_count` / `refuted_count` / `unvouched_count` (no trust bit,
  development postbacks of a registration that does not accept test signals
  included) / `test_count` (verified against a development key, whatever
  their trust) — each counts unique postbacks, and a postback belonging to
  two classes (an accepted development-signed one is both trusted and a
  test signal) counts in each
- `group_by=registration` groups carry `registration_id`, `app_name`,
  `platform` and `app_key`
- Conversion-value decoding: `measurable` (unique winning postbacks
  carrying a value, across all three conversion windows — so it can exceed
  `installs`, which counts first-window postbacks only), `decoded`,
  `undecoded` (value present, no matching encoding), `ambiguous_encoding`
  (the value's encoding changed within the 35 days before the postback
  arrived and its meanings disagree — credited to no goal; see SKAN
  encodings above),
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
`_count` columns count unique postbacks too, so no column of the
report exposes the stored row count — it answers "how many postbacks",
never "how many rows".

**`data.totals`** holds the same metrics over the whole window, ungrouped —
the same keys a group carries, minus the conversion-value decode. Read the
headline numbers from there rather than summing `data.groups`, which gives a
different answer for two reasons: every metric counts *unique* postbacks
per group, so one postback stored twice (a replay whose unsigned fields
differ) counts once in each day it landed in; and a truncated report is
missing whole groups besides. `totals` is computed by one ungrouped query
over the same filters, so neither applies to it.

**Trust default:** the receiver is public, so unless you pass an explicit
`signature` filter, every headline metric — installs, losses, redownloads,
the whole conversion-value decode — counts **only trusted postbacks**
(`trusted = 1`); refuted and unvouched rows stay visible through their
`_count` columns (which count every unique postback in the group, whatever
its class) but cannot move the numbers. `meta.trusted` says which regime
produced the response: `trusted-only` (the default) or `as-filtered` (you filtered by signature state yourself,
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

`POST /apps/verify` with a postback JSON object as the body returns the
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

# Register the app from its store link (the response carries its
# registration_id and app_token), add an encoding, then report:
curl -X POST https://your-domain.com/api/v3/apps \
  -H "Authorization: Bearer YOUR_API_KEY" -H "Content-Type: application/json" \
  -d '{"store_link": "https://apps.apple.com/us/app/my-ios-app/id525463029", "app_name": "My iOS App"}'

curl -X POST https://your-domain.com/api/v3/apps/skan-encodings \
  -H "Authorization: Bearer YOUR_API_KEY" -H "Content-Type: application/json" \
  -d '{"registration_id": 1, "fine_value": 63, "goal_id": 12, "revenue_override": 49.99}'

curl "https://your-domain.com/api/v3/apps/report?group_by=registration" \
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
  `/apps/schema`), both sides are driven by the same encodings and cannot
  drift. An app that hardcodes its own encoding instead must be kept in
  sync with `/apps/skan-encodings` by hand, or decoded revenue silently
  skews.
- **Both frameworks flow side by side** on current iOS versions, and the
  system picks one winner per conversion across them — so a device reports
  a given install through SKAdNetwork *or* AdAttributionKit, never both.
  The report tells them apart by `protocol`; only AdAttributionKit rows can
  carry `conversion_type: re-engagement`, and only for apps that ship the
  re-engagement `Info.plist` key.
