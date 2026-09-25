# Measurement rewrite: app measurement (iOS + Android) and multi-touch attribution

Status: **proposal, not implemented.**

## Scope

This plan covers three things:

- **Native Android install tracking.**
- **A reshape of the iOS app measurement shipped in 1.9.76.** That feature is
  SKAdNetwork / AdAttributionKit, documented in
  `documentation/api/19-attribution-postbacks.md`.
- **A rewrite of the multi-touch attribution (MTA) engine.** It was first
  shipped in 1.9.56 and is documented in
  `documentation/features/advanced-attribution-engine.md` and `ATTRIBUTION_SETUP.md`.

## Constraints

- **No install exists above 1.9.55.** Every real database is at 1.9.55 or
  older, and nobody sits at any version from 1.9.56 to 1.9.76.
  - Any schema created by the 1.9.55 → 1.9.76 rungs may therefore change
    **in place**: its table names, its columns and the rungs themselves.
  - The upgrade path that matters is **≤ 1.9.55 → 1.9.76**, and the only
    upgrade behaviour to preserve is that path's.
  - Development and branch databases above 1.9.55 are disposable and are
    reinstalled, not repaired.
- Neither the app-measurement feature (added in 1.9.76) nor MTA (added in
  1.9.56) has users, so both may be rewritten freely.
- **The release stays 1.9.76.**
- Nothing outside these two features changes shape. The click pipeline, the
  conversion writer and the pixel endpoints are touched only where these
  features attach to them.
- The same freedom would allow other cleanups in the 1.9.56–1.9.75 rungs, for
  example the guarded repeat of the `202_api_keys.scope` repair. Those are out
  of scope here and are listed only so they are not forgotten.

## Sources

- Android platform facts were checked against Google's and Meta's
  documentation on 2026-09-25.
- Codebase facts cite `main` at `99972c2` and were read, not executed, unless
  marked otherwise.

## Parts

| Part | Covers |
|---|---|
| A (§1–2) | What joins the two features, and what keeps them apart |
| B (§3–5) | App measurement: the iOS reshape, then Android |
| C (§6) | The MTA rewrite |
| D (§7–9) | Non-functional requirements, phasing, decisions |

---

# Part A: how the pieces fit

## 1. Two features that meet at exactly one point

Both features call themselves "attribution", share the `202_attribution_*`
table prefix, the `/attribution` route group, the `attribution` API scope area
and the `p202 attribution` CLI parent. They answer different questions:

| | App measurement | Multi-touch attribution |
|---|---|---|
| Question | Did an ad produce an install or an in-app event, and what was it worth? | Given a conversion, how much credit does each earlier touch deserve? |
| Unit | A platform signal (iOS postback, Android install) | A conversion and the journey of clicks behind it |
| Needs a click? | iOS: never has one. Android: yes | Always |

**The one place they meet is the conversion.** An attributed Android install,
and each Android in-app event, becomes a row in `202_conversion_logs` on a
real click. A conversion is what MTA distributes credit for. So:

```
 iOS postback ─► app measurement (aggregate report; never a conversion; never in MTA)

 Android install/event ─► app measurement ─► ConversionRecorder ─► 202_conversion_logs
 web pixel / postback / API / subid upload ──────────┘                 │
                                                        outbox row (same transaction)
                                                                       ▼
                                                              MTA: journey + credits
```

**Rule:** the features share the conversion writer and *nothing else*. Sharing
anything more would couple two things that change for different reasons.
That includes a table prefix, a scope area, a CLI parent and a report.

**Consequence:** they are separated by name. MTA is the attribution engine, so
it keeps the `attribution` names. App measurement moves out to `app` names.

| | App measurement | MTA |
|---|---|---|
| Tables | `202_app_*` | `202_attribution_*` |
| API routes | `/apps/*` | `/attribution/*` |
| API scope area | `apps` | `attribution` |
| CLI | `p202 app …` | `p202 attribution …` |

A scoped key that can read install reports can no longer delete attribution
models, and today it can: one `attribution` area covers both
(`api/v3/Auth.php:142`).

## 2. The shared seam: `ConversionRecorder` and an outbox

`MysqlConversionRepository::record()`
(`202-config/Conversion/MysqlConversionRepository.php:120-298`) is already the
single transactional writer. Every path that writes `202_conversion_logs` goes
through it: gpb, gpx, upx, subid upload, `POST /conversions`, and after this
plan the Android intake. It already emits `conversion.recorded` after the
commit for the LTV/LPO bridge (`:278-295`).

MTA attaches through an **outbox written inside that same transaction**. A
row in `202_attribution_pending (conv_id PK, enqueued_at)` goes in beside the
conversion, and the MTA worker consumes it. The alternative, a post-commit
callback, is rejected:

- **An outbox is exactly-once, in-transaction.** A conversion either exists
  with its pending row or does not exist at all. A post-commit hook can die
  between the commit and the hook, and that journey is silently never built.
  That is error pattern #13's shape.
- **Today's inline hooks are the defect this replaces.** gpb, gpx and upx each
  call `isMultiTouchEnabled()` outside their `try` (`gpb.php:302`,
  `gpx.php:165`, `upx.php:300`). A missing or unreadable settings table
  therefore 500s the request *after* the conversion committed. gpx, upx and
  subid upload also run a synchronous 24-hour rebuild of every model inside
  the pixel request (`gpx.php:188`, `upx.php:323`, `subids.php:118`). The
  rewrite deletes all of this. The conversion paths no longer know MTA
  exists.
- **Paths not covered today are covered for free.** The v3 conversions API
  and subid upload persist no journey today. They go through `record()`, so
  the outbox covers them.

Recording is unaffected by MTA's state. The outbox insert is one row on a
table the same upgrade step creates. If MTA is disabled or broken, rows
accumulate and are processed when it is fixed. That is a backlog, not a lost
journey.

---

# Part B: app measurement

## 3. What "like iOS" can and cannot mean for Android

| | iOS | Android |
|---|---|---|
| Mechanism | The OS sends a **signed, aggregate postback** to a well-known URL | Our SDK reads the **Google Play Install Referrer** on first launch and reports it |
| Timing | 24–48 h+ after install, deliberately delayed | Seconds after first open |
| Trust anchor | Apple's ECDSA signature | None from the platform: a signed token we put in the store link, click plausibility, optionally Play Integrity |
| Granularity | No click, no device, a 6-bit conversion value | **Click-level**: the referrer carries our click id |
| What it becomes in Prosper202 | Report rows that can never join a click | **A real conversion on the originating click**, visible in every campaign report and in MTA |

Google's SKAdNetwork analogue, the Privacy Sandbox Attribution Reporting API
on Android, was **retired on 17 October 2025**
(<https://privacysandbox.google.com/blog/update-on-plans-for-privacy-sandbox-technologies>).
No replacement postback exists.

Both platforms share **everything around the signal**: registry and
ownership, app token and SDK contract, event catalogue and revenue, public
intake plumbing, trust vocabulary, retention and the report surface. They
differ only in **how a signal is received and judged**, and the architecture
makes that boundary explicit:

```
                         ┌──────────── app measurement core ───────────┐
  store link / SDK /     │ AppRegistry     one registration per app,   │
  Setup page / CLI  ───► │                 keyed (platform, app_key)   │
                         │ AppIdentity     parse + validate an app id  │
                         │                 or a store link             │
                         │ AppToken        header-only; rotatable      │
                         │ EventCatalog    event → revenue             │
                         │ Verdict         source state → trust bit    │
                         │ PublicIntake    probe, 405/413, DB, peer    │
                         │                 rate limit, bounded body    │
                         │ Retention       per-source prune classes    │
                         │ ReportSource    shared metric set           │
                         └──────────▲───────────────────▲──────────────┘
               ┌────────────────────┴───┐   ┌───────────┴─────────────────┐
               │ Apple signal source     │   │ Android signal source        │
               │ Skadnetwork/AAK protocol│   │ InstallReferrerIntake        │
               │ PostbackVerifier / JWS  │   │ InstallToken (HMAC)          │
               │ SignatureState: Verdict │   │ ReferrerParser               │
               │ SkanEncoding (6-bit CV) │   │ MatchState: Verdict          │
               │ 202_app_postbacks       │   │ ConversionRecorder (§2)      │
               │                         │   │ 202_app_installs, _events    │
               └─────────────────────────┘   └──────────────────────────────┘
```

A class belongs in the core only if both sources call it.

## 4. Phase 0: reshape the iOS implementation onto the core

Phase 0 changes no user-visible iOS behaviour except the renames below. It is
done when:

- every existing iOS test is **ported, not deleted**, and green;
- the SKAN and AAK signature vectors are byte-identical;
- the live passes (`tests/live/setup-mobile-apps.sh`,
  `analyze-mobile-apps.sh`) and the browser specs are green on the new code.

### 4.1 Tables: new names, new shape

The tables are renamed by meaning, not by necessity. No database holds the
1.9.76 tables (see Constraints), so they could be reshaped under their old
names. The `attribution` names belong to MTA (§1), though, so app measurement
moves to `202_app_*`.

| Old | New | What changes |
|---|---|---|
| `202_attribution_apps` | `202_app_registrations` | Identity becomes `(platform, app_key)`; per-app policy |
| `202_attribution_postbacks` | `202_app_postbacks` | Adds `registration_id`; `signature_valid` becomes `trusted` |
| `202_attribution_conversion_values` | `202_app_events` + `202_app_skan_encodings` | The catalogue is split from the iOS encoding |
| — | `202_app_installs`, `202_app_install_events` | Android (§5) |

**The legacy guard is deleted, not extended.**
`_upgrade_attribution_legacy_skan_state()` and the "Upgrade paused" halt in
`_upgrade_attribution_tables()` (`functions-upgrade.php:307-350`) exist to
protect postbacks in the pre-release `202_skan_*` tables. Only a branch
deployment above 1.9.55 could hold those, and none exists.

- The 1.9.75 rung keeps one job: create the app tables from their installer
  definitions.
- Its test pins what matters now: the ≤ 1.9.55 path, and the rung's shape
  rules (the version is written only on success).
- RELEASING.md's branch-deployment repair section is replaced by one line:
  databases above 1.9.55 from before this change are reinstalled.

### 4.2 One registry for both platforms

`202_app_registrations` columns:

- `registration_id` PK. It is **the key everything else uses**; no table links
  to a registration through a raw app id.
- `user_id`.
- `platform` (`ios` | `android`).
- `app_key` varchar(255), with `UNIQUE (platform, app_key)`:
  - Apple: the App Store item id as canonical decimal;
  - Android: the application id (package name). The package is the identity,
    not the listing, because one package can ship through Play, Galaxy Store
    and AppGallery.
- `app_name`, `notes`.
- `app_token`, renamed from `schema_token`.
- `accept_test_signals`, renamed from `accept_development_postbacks`. It covers
  AAK development-key postbacks and Android test installs.
- Android only: `attribution_window_days`, `count_install_as_conversion`,
  `trust_client_revenue`.
- Timestamps.

**`AppIdentity`** is the one implementation of "is this a valid app id, and
what does this store link name". It replaces three split copies:

- `AttributionAppsController::assertUsableAppId()`, the raw-payload check per
  error pattern #18;
- `MobileAppsController::appStoreId()`, the saturation round-trip;
- `MobileAppsController::parseStoreReference()`.

Per-platform rules:

- **Apple:** digits, positive, exact round-trip, no leading zero.
- **Android:** dot-separated `[A-Za-z][A-Za-z0-9_]*` segments, at least two,
  case-sensitive.

It has one link parser, for `apps.apple.com/…/id123`, a bare id,
`play.google.com/store/apps/details?id=…`, `market://details?id=…` and a bare
package. The API (`store_link`), the Setup page and the CLI (`--store-link`)
all call it.

**The `app_id = 0` sentinel disappears.** Account-wide rules become
`registration_id = 0`, which can never be a real registration and is never
reached by casting an app identifier. It stays `NOT NULL DEFAULT 0` rather
than `NULL` on purpose: MySQL's `UNIQUE` admits any number of `NULL`s, so
`NULL` would allow duplicate account-wide rules.

### 4.3 App token and the SDK contract

- **Rename.** `schema_token` becomes `app_token`, and `X-P202-Schema-Token`
  becomes `X-P202-App-Token`. Minting, header-only transport, rotation and
  redaction are unchanged (`SchemaTokenHygieneTest` is ported). The token is
  documented as **an identifier, not a secret**, because it ships in every
  binary.
- **One wire contract** (`documentation/api/21-app-sdk-contract.md`) covers
  the schema document, the intake, the events route, the retry semantics
  (400/413 terminal, 429/5xx retry), the test flag and the header.
- **Cross-language vectors** in `tests/fixtures/app-sdk-contract/` are read by
  the PHP, Swift and Kotlin tests. This is the pattern
  `t202ctx-vectors.json` already uses.
- **`GET /apps/schema`** is platform-shaped. iOS gets fine/coarse encodings;
  Android gets the mapped event names, so the SDK refuses unmapped names
  locally. Revenue is withheld from both.
- **Both SDKs** expose `configure(endpoint, appToken)` and
  `logEvent(name, …)`.

### 4.4 Trust: one vocabulary, per-source verdicts

```php
interface Verdict   // implemented by backed enums
{
    public function trustBit(AppPolicy $policy): ?int;  // 1 trusted, 0 refuted, null unvouched
    public function isTest(): bool;                      // governed by accept_test_signals
}
```

- `SignatureState` (Apple) implements it unchanged, and `DEVELOPMENT` reports
  `isTest()`.
- `MatchState` (Android, §5.3) implements it too.
- Both signal tables store `trusted` plus their own state column.
- Reports count `trusted = 1` by default and show every other class beside
  it. That is today's `meta.trusted`, applied to both sources.
- An unreadable policy resolves to *untrusting* (error pattern #11), in one
  place.

### 4.5 Event catalogue vs iOS encoding

A conversion-value rule currently does two jobs: *what an event is worth*,
and *how iOS encodes it in 6 bits*. Android needs only the first.

- **`202_app_events`**
  - Columns: `(user_id, registration_id /* 0 = account-wide */, event_name, revenue)`.
  - Key: `UNIQUE (user_id, registration_id, event_name)`.
  - Both platforms use it. It is the only place revenue is configured.
- **`202_app_skan_encodings`**
  - Columns: `(user_id, registration_id, fine_value | coarse_value, event_name, revenue_override NULL)`.
  - Apple only. `revenue_override` keeps tiered decoding.
- **Decode** keeps today's resolution: app-specific before account-wide, and
  no fallback from fine to coarse. **Encode** keeps the highest-value tie-break.
- **Behaviour change:** an encoding must name a registration or be
  account-wide. Today a rule can target an App Store id nobody registered,
  and it decodes nothing until someone does.

### 4.6 Shared plumbing, retention, and fixes

- **`PublicIntake`**, which replaces `PostbackEndpoint`, serves:
  - the Apple `/.well-known/` entries, which Apple dictates;
  - the pre-auth routes `GET /apps/schema` and `POST /apps/installs`, instead
    of the inline copy at `api/v3/index.php:131-153`.
- **Retention** is data that each source registers. It replaces
  `PostbackReceiver::PRUNE_CLASSES`:

  | Table | Class | Days |
  |---|---|---|
  | `202_app_postbacks` | unclaimed | 30 |
  | `202_app_postbacks` | refuted | 90 |
  | `202_app_postbacks` | unvouched | 90 |
  | `202_app_installs` | refuted | 90 |
  | `202_app_installs` | unvouched (not pending) | 180 |

  The cron is `202-cronjobs/app-retention.php`. Environment variables become
  `P202_APP_RETENTION_DAYS_<TABLE>_<CLASS>`. A malformed value prunes nothing
  and is named in the log; `0` disables that class.
- **User deletion purges app data.** `202-account/user-management.php:287-296`
  names only the MTA tables today. A deleted user's registration therefore
  keeps its global `UNIQUE` slot forever, and nobody can register that app
  again. The purge covers every `202_app_*` table. Postbacks are released to
  unclaimed rather than deleted.
- **The Analyze page and report filters** move from raw `app_id(s)` to
  `registration_id(s)`. The postback's own `app_id` stays as a forensic column
  and filter.

## 5. Phase 1: Android on the core

### 5.1 The click id in the store link

**The new token.** `[[p202_install_token]]` is added to `replaceTokens()` /
`replaceTrackerPlaceholders()` (`202-config/connect2.php:486-680,
2192-2255`). It expands to `<click_id>.<mac>`:

- `mac` = the first 12 bytes of
  `HMAC-SHA256(K_install, "p202-install-v1|" . click_id)`, in base64url.
- `[[subid]]` is not enough: sequential click ids let anyone credit an
  install, and a payout, to any click (error pattern #16).

**The key.**

- `K_install` is a random 32-byte secret generated by the 1.9.76 rung and never
  served.
- `CtxToken`'s key derives from the LPO webhook secret, which most installs
  never set, so it cannot be reused.
- The key is readable on the hot path without a query.
- **If it is missing, the token expands empty**, and the install is recorded
  unattributed (error pattern #11).

**Encoding.** The link is `…&referrer=p202%3D[[p202_install_token]]`. The
token alphabet survives `rawurlencode202()` unchanged.

**Timing.** For non-cloaked links `dl.php` writes the click row after the
redirect (`:518` vs `:626-629`), so the token is computed in the first
substitution pass from `$click_id`. The fallback used while MySQL is down
(`dl.php:106-190`) substitutes `p202` for `[[subid]]`; it must substitute
**empty** for this token.

**Passthrough parameters.** `getPrePopVars()` puts them at the top level of
the Play URL, where Play ignores them, so they never reach the app. The docs
say so.

### 5.2 Intake: `POST /api/v3/apps/installs`

It is served by `PublicIntake` and gated by `X-P202-App-Token`. The body is
JSON, capped at 16 KB:

```json
{
  "install_uuid": "8d7c…",
  "app_key": "com.example.app",
  "store": "google_play",
  "referrer": {
    "status": "ok",
    "install_referrer": "p202=…&utm_source=…",
    "referrer_click_timestamp_seconds": 1727200000,
    "install_begin_timestamp_seconds": 1727200042,
    "referrer_click_timestamp_server_seconds": 1727200001,
    "install_begin_timestamp_server_seconds": 1727200043,
    "install_version": "3.2.0",
    "google_play_instant": false
  },
  "first_open_at": 1727200100,
  "app_version": "3.2.0", "sdk_version": "1.0.0", "os_version": "15",
  "test": false,
  "integrity_token": null
}
```

The steps:

1. **Validate strictly** (error pattern #4).
2. **Resolve the registration** by token. A mismatched `app_key` is a 422
   naming both values.
3. **Insert the install row**, idempotent on `(registration_id, install_uuid)`.
   A replay answers 200 with `duplicate: true`.
4. **Classify** into `MatchState`.
5. **Record the conversion** for `attributed` rows, via `ConversionRecorder`
   (§2):
   - `transaction_id = 'p202-install'`. The existing
     `UNIQUE KEY uniq_click_transaction (click_id, transaction_id)` makes one
     install conversion per click a database fact.
   - `pixel_type = 4`, which is unused; 0–3 are taken.
   - `conv_time` is Google's server install-begin time.
   - The conversion is skipped when `count_install_as_conversion = 0`.
   - The outbox row means MTA picks it up (§6).
6. **Fire the traffic source's postback.** `gpb.php:166-240`'s
   `202_ppc_account_pixels` logic is extracted into one function that both
   call.
7. **A failure after commit still answers 200.** The response says
   `match: "pending"` and cron finishes the work (error pattern #13).

The `GET` probe answers `ready`. The response never carries click data the
referrer did not already have.

### 5.3 `MatchState`

| State | Trust | Meaning |
|---|---|---|
| `attributed` | 1 | MAC verified, click found and owned, timing plausible, inside the window |
| `organic` | null | Play's organic referrer, or no referrer |
| `third_party` | null | `gclid`, Meta's envelope, another tracker's referrer; parsed fields are stored |
| `unavailable` | null | The referrer API was not available |
| `pending_click` | null | Token valid, click row not written yet; cron settles it within 24 h |
| `bad_token` | 0 | MAC fails, malformed, or the click was never recorded |
| `foreign_click` | 0 | The click belongs to another user, or to a campaign linked to another registration |
| `implausible` | 0 | Timing contradicts the click (§7.1) |
| `outside_window` | null | Later than `attribution_window_days` |
| `duplicate_click` | null | The click already has an install conversion |

Every row carries a `match_reason` sentence, shown as-is in the UI.

### 5.4 Tables

**`202_app_installs`**

- Identity and links: `install_row_id`, `user_id`, `registration_id`,
  `install_uuid` (UNIQUE per registration), `store`, `click_id`,
  `conversion_id`.
- State: `match_state`, `match_reason`, `trusted`, `is_test`.
- Referrer: `referrer_raw` varchar(2048), truncated with a flag and never
  rejected; parsed `utm_*` and `gclid`; the four referrer timestamps.
- Versions: `install_version`, `app_version`, `sdk_version`, `os_version`.
- Other: `integrity_state`, `first_open_at`, `received_at`, `settled_at`,
  `raw_payload`, `remote_ip`.

**`202_app_install_events`** (phase 2): `UNIQUE (install_row_id, event_id)`.

**`202_aff_campaigns.app_registration_id`** NULL, used by `foreign_click` and
the link builder. It is added in the 1.9.75 rung, next to the app tables.

**Why the two signal tables stay separate.** Postback identity is Apple's
(network, transaction, window, signature). Install identity is ours
(`install_uuid`, click). Merging them would make every identity column
nullable and weaken the uniqueness that deduplication rests on.

### 5.5 In-app events (phase 2)

`POST /apps/installs/{install_uuid}/events` accepts
`{event_id, event_name, occurred_at, revenue?, currency?}`.

- Each event is a conversion on the install's click, with
  `transaction_id = 'p202-evt:' . event_id`, so it enters MTA too.
- Revenue comes from `202_app_events`. Client revenue is stored separately
  and used only under `trust_client_revenue`.
- Unmapped names are counted as unmapped. They are never credited and never
  dropped.

### 5.6 UI, report, testing, SDK

**Setup › Mobile Apps.** Pasting a Play link registers the app. The name
comes from a best-effort fetch of the listing's `og:title`, falling back to
asking for it. The Android app page has:

- the token panel;
- the Gradle and init snippet;
- an intake reachability check;
- **a store-link builder** that writes the campaign URL and sets
  `app_registration_id`;
- recent installs with their reasons;
- the test opt-in.

The token panel, reachability check, recent signals and test opt-in are
shared partials with the iOS page.

**Analyze › Mobile Apps / `GET /apps/report`.**

- Each `ReportSource` returns the shared metrics: `installs`, `reengagements`
  and `redownloads` (iOS), `events`, `revenue`, and the trust-class counts.
- **Shared dimensions:** `day`, `registration`, `platform`, `country`.
- **Platform-specific dimensions** require the matching `platform` filter;
  without it the request is a 422 with a hint, never a silent exclusion.
- **Totals** are per platform plus a labelled combined figure. iOS numbers are
  delayed, aggregate and privacy-thresholded; Android numbers are not.
- **Campaign reports and MTA need no change to include attributed Android
  installs.**

**Testing.**

- A debug build of the SDK can send `test: true`. Those installs count only
  under `accept_test_signals`, the same flag AAK development postbacks use.
- `p202 app install simulate <registration> --click <id>` mints a real token
  for a real click and posts it as the SDK would.

**SDK** (`sdk/android-attribution/`):

- Kotlin, `minSdk 21`. The only dependency is
  `com.android.installreferrer:installreferrer:2.2`, the latest release
  (January 2021).
- The referrer is read once and persisted. It stays available for 90 days.
- Backoff: 400 and 413 are terminal; 429 and 5xx are retried.
- `install_uuid` is excluded from Auto Backup.
- No advertising ID and no `AD_ID` permission.
- Tests: JVM tests over the contract vectors, plus a live integration test.

**Phase 3, each part independent:**

- Play Integrity: standard tokens decoded via the app owner's Google Cloud
  service account, queued to cron; the default quota is 10,000 per day per app.
- Meta referrer decryption: AES-256-GCM with the per-app key.
- Huawei, Samsung and Xiaomi stores. Huawei reports milliseconds.
- Deferred deep links, and `assetlinks.json`.

---

# Part C: the MTA rewrite

## 6. MTA

### 6.1 What is there today, and why it is rewritten rather than repaired

The engine is about 15,500 lines of PHP, JS and Go, not counting tests. There are two API
surfaces: a Slim v2 app used by the dashboard, and v3 used by the CLI.

The defects below were found by reading the code. The four that decide
whether the engine produces a meaningful number were confirmed at the cited
lines.

1. **A journey is not a person's journey.** `fetchCandidateTouches()`
   (`202-config/Attribution/Repository/Mysql/ConversionJourneyRepository.php:185-238`)
   selects the account's 25 most recent clicks **on the same campaign** in the
   30 days before the conversion. `user_id` there is the account owner. No
   visitor, cookie, IP or customer id narrows it, and bot or filtered clicks
   are not excluded. A conversion's "journey" is therefore made of strangers'
   clicks, and every downstream number inherits that.
2. **Every model reports the same totals.** Credit always sums to 1, and
   snapshots are only ever written at global scope
   (`AttributionJobRunner.php:161,191,281`). The hourly revenue and
   conversion totals are identical whichever model is chosen. The campaign and
   landing-page scopes the docs, API and CLI advertise always come back empty.
   Revenue is the converting click's payout and cost the converting click's
   cost, so assist clicks never contribute their own cost.
3. **It sits on the conversion path in the unsafe order** (§2): an uncaught
   settings check after commit, and synchronous rebuilds inside pixel
   requests.
4. **The API accepts models the engine cannot load.** v3 allows
   `first_touch` and `linear` but not `assisted`
   (`api/v3/Controllers/AttributionController.php:14`). The engine's enum has
   no `first_touch` or `linear` (`ModelType.php:12-16`). One such row makes
   `ModelType::from` throw on load, which breaks rebuilds, the dashboard and
   the setup page for that user. `algorithmic` silently runs last-touch.
5. **Other defects:**
   - Export webhooks POST to any URL the caller supplied, with no scheme or
     host validation (`202-cronjobs/attribution-export.php:288`, from
     `AttributionController.php:308`). That is server-side request forgery
     for any key holder.
   - One malformed export row fatals every export run and jams the queue.
   - The download bridge reads columns that exist only in a dead DDL.
   - There are three model-CRUD implementations, two export stacks, two
     dashboards, and two migration runners, neither of which works.
   - The campaign's `attribution_model_id` is written but never read.
   - The snapshot key is not unique, so concurrent rebuilds double-count.
   - The GDPR purge misses exports and journeys.
   - Most repository, cron and v3 paths have no tests.

Items 1 and 2 are the design, not bugs in it. Fixing them changes the
journey source, the storage and the reports, which is all of the engine.

### 6.2 Identity: what a journey is built from

A journey needs an identity that belongs to one person. Prosper202 has none
at click time today: its cookies are per-click (`tracking202subid*`,
`connect2.php:695-718`). The rewrite adds a ranked set of identities:

| Identity | Source | Strength | Limits |
|---|---|---|---|
| `customer_id` | The LTV customer the conversion resolves to (`cust` param) | Deterministic, cross-device | Only known **at conversion**, so it links a customer's conversions, not their earlier clicks, unless a click also carried `cust` |
| `visitor_id` (new) | A first-party cookie `p202vid` (128-bit random) set by `dl.php`, `rtr.php` and `lp.php` on the tracking domain, and stored with the click | Links clicks in one browser | Browser-bound; see below |
| IP address | — | **Never used.** Carrier-grade NAT and offices merge strangers, which is today's defect in another form | |

**Where it is stored.** `visitor_id` goes in a new
`202_clicks_visitor (click_id PK, user_id, visitor_id BINARY(16), click_time)`
with `KEY (user_id, visitor_id, click_time)`. It is not a column on the hot
`202_clicks`, and it is written in the same `recordClick()` transaction.

**Journey rule.** A journey is the clicks with the converting click's
`visitor_id`, within the model's lookback (default 30 days), **across all
campaigns**. Crossing campaigns is the point: a journey limited to one
campaign cannot tell models apart at the campaign level. Clicks flagged bot or
filtered are excluded. A click with no `visitor_id` makes a one-touch journey,
labelled as such.

**Honest limits, stated in the product and not discovered by users:**

- **Browsers erode a redirect domain's cookies.** Safari's tracking
  prevention, and Chrome's bounce-tracking mitigations where third-party
  cookies are blocked, clear or cap state for domains a user only passes
  through. So journeys undercount on those browsers. The report shows the
  share of conversions whose journey is one touch, by browser, so the
  undercount is visible.
- **Landing-page JavaScript clicks** (`record_simple.php`, `record_adv.php`)
  load the tracking domain as a third-party script. The cookie is partitioned
  or blocked there. Those clicks get a visitor id only when the LP domain and
  the tracking domain are the same site. The docs say so.
- **No cross-device.** Cross-device linking comes only from `customer_id`.
- **Nothing before the upgrade.** Clicks recorded before an install runs the
  release containing visitor capture have no visitor id and cannot be
  backfilled. Every install upgrading from 1.9.55 or older starts with
  one-touch journeys, and they fill in as new clicks arrive. That is why
  visitor capture must be in the first release that goes out (§8, phase M0).

### 6.3 Engine

**Trigger.** The outbox (§2). The worker `202-cronjobs/attribution-worker.php`, run
every minute with overlap protection, claims pending rows in batches. For each
conversion it builds the journey once and computes credits for each active
model. It is idempotent: it deletes and reinserts the conversion's credits in
one transaction.

**Models.** One enum is the only list. The API, CLI, UI and engine all read
it, and a structural test fails if any surface lists a value the enum lacks
(error pattern #5):

| Model | Credit |
|---|---|
| `last_touch` | The last touch gets 1 |
| `first_touch` | The first touch gets 1 |
| `linear` | Every touch gets 1/n |
| `time_decay` | `2^(-Δt / half_life)` normalised; `half_life_hours` is configurable, default 48 (as today) |
| `position_based` | `first`/`last` weights (default 0.4/0.4), the middle shares the rest; with one or two touches the weights renormalise |

- `algorithmic` is **removed**, not aliased: a model that claims to be one
  thing and computes another is worse than an absent one. It can come back
  when it exists.
- `assisted` stops being a model. "Assisted conversions" is a report on
  non-last touches under any model.
- Weighting configs are validated by the same class on write and on load. A
  stored config that fails validation marks that model `invalid` with the
  reason. It never throws in a way that takes other models down.

**Storage.** `202_attribution_credits`:

- Columns: `(conv_id, model_id, click_id, position, credit decimal(9,8), revenue decimal(11,5))`.
- Key: `PRIMARY KEY (conv_id, model_id, click_id)`.
- Index: `KEY (model_id, click_id)`.

`revenue` is the conversion's `click_payout` × credit, so the credits of a
conversion sum exactly to its revenue. The rounding remainder is assigned to
the last touch, and a test pins the sum. The journey itself is in
`202_attribution_journeys (conv_id, position, click_id, click_time)`, and it is what
reports explain.

**Reports** are grouped over credits, joined to the clicks' own dimensions.
This is where models differ:

- **Dimensions:** campaign, traffic source (PPC account), keyword, c1–c4,
  landing page, country, device, day.
- **Metrics:** attributed conversions (Σ credit), attributed revenue
  (Σ revenue), and **cost from the dimension's own clicks**, so ROI per
  source is real.
- **Model comparison:** the same grouping under two models side by side. It
  replaces the sandbox stub.
- **Journey metrics:** length distribution, time to convert, share of
  one-touch journeys by browser (§6.2), and assisted conversions per
  dimension.
- **Performance:** grouped queries run over indexed credits for the requested
  range. An hourly rollup table is added only if measurement shows the query
  path is too slow (§7.3). A cache that is not needed is a cache that can be
  wrong.

**Model choice** per account, with a per-campaign override that is actually
read (today `attribution_model_id` is written and never read). The account
default is used in the campaign reports' "attributed" columns. Changing a
model or its config marks it for recomputation, and the worker re-derives its
credits from the stored journeys. Nothing needs the raw clicks again.

**Exports.**

- One pipeline, CSV only. The "xls" is tab-separated text today, so the claim
  is dropped.
- A webhook destination must be `https`. It is resolved and refused if it
  resolves to a private, loopback or link-local address, and checked again at
  send time to catch DNS rebinding. It is signed with HMAC as today.
- A malformed job row fails only that job.

**One API surface.** v3 only. The Slim v2 app (`api/v2/`) and the unlinked
dashboard (`202-account/attribution/index.php`) are deleted. The dashboard
page is rebuilt on the v2 UI shell (`ui-standard.md`) against v3.
Permission checks are the same on every surface (error pattern #5): v3 today
checks none of the `view_attribution_reports` / `manage_attribution_models`
permissions that v2 enforces.

### 6.4 Schema and upgrade

The MTA schema is created by the 1.9.56 – 1.9.58 rungs, which no install has
run. So it is **redefined in place and its rungs are rewritten**. Nothing is
left behind to guard or to drop.

**Tables.**

| Today | After | Notes |
|---|---|---|
| `202_attribution_models` | `202_attribution_models`, reshaped | `model_type` constrained to the enum (§6.3); `weighting_config` validated JSON; `status` (`active`/`invalid`) plus `status_reason`; one default per account |
| `202_attribution_snapshots`, `202_attribution_touchpoints` | `202_attribution_credits` | Per-conversion credit rows replace hourly global snapshots |
| `202_conversion_touchpoints` | `202_attribution_journeys` | Journeys built from visitor identity, not from the account's campaign clicks |
| `202_attribution_settings` | *(gone)* | The multi-touch toggle existed to keep the engine off the pixel path. The outbox takes it off that path permanently, so the toggle has nothing left to protect. Per-campaign model choice uses `202_aff_campaigns.attribution_model_id` |
| — | `202_attribution_pending` | The outbox (§2) |
| — | `202_clicks_visitor` | `(click_id PK, user_id, visitor_id BINARY(16), click_time)`, `KEY (user_id, visitor_id, click_time)`. A click attribute, so it takes the click tables' prefix |
| `202_attribution_exports` | `202_attribution_exports`, reshaped | One column set. Today there are two conflicting DDLs, in the 1.9.56 and 1.9.58 rungs |
| `202_attribution_audit` | unchanged | |

**Where the definitions live.** They move into
`AttributionTables::getDefinitions()`. `202_conversion_logs`, a core table
whose definition sits in `AttributionTables` today, moves out to its own
`ConversionTables`. Rewriting MTA's definitions then cannot touch the
conversion table.

**Rungs.**

- The 1.9.56 rung creates every MTA table from those definitions: the same
  "installer definitions, advance only on success" shape the 1.9.75 rung uses
  for app tables.
- The 1.9.57 and 1.9.58 rungs lose their MTA DDL (settings columns, the second
  exports DDL, `202_conversion_touchpoints`) and keep only their version
  advance. Their other work stays exactly as it is.
- The 1.9.56 rung's `202_aff_campaigns.attribution_model_id` column and the
  permission rows 22 and 23 are kept. They are now read.

**No seeded models.** Today upgraded installs get a "last-touch-default" model
row per user, and fresh installs get none (error pattern #5). After the
rewrite neither path seeds anything. An account with no model row reports
under an implicit `last_touch` default, so "no rows" is a state the engine
defines instead of one that depends on how the database was created.
- **Deletions.** The dead code is deleted outright: `MysqlAttributionRepository`,
  `InMemoryAttributionRepository`, the `Attribution\Export\*` stack,
  `MysqlExportRepository`, both migration runners and their `.sql`, v2, the
  second dashboard, and the `purge-disabled-journeys.php` and
  `backfill-conversion-journeys.php` crons. Their tests go with them. Every
  deleted test is listed in the PR with the reason its subject no longer
  exists; no test is deleted merely because it fails.

---

# Part D: requirements, phasing, decisions

## 7. Non-functional requirements

### 7.1 Security

| Threat | Mitigation |
|---|---|
| Forged Android installs for arbitrary clicks | HMAC install token; `bad_token` counts nowhere |
| Replayed referrer | `uniq_click_transaction`, one install conversion per click |
| **Click spamming** (harvest real tokens from cheap clicks) | The inherent residual risk. Mitigations: (a) Google's server click time must be within minutes of our `click_time`; (b) CTIT distribution with short and long tails flagged; (c) per-registration, per-peer caps; (d) Play Integrity |
| Click injection | Server click time after server install-begin → `implausible` |
| Row minting on public intakes | Body caps; rate limit on `REMOTE_ADDR` (#16) with injective bucket names (#17); a retention class for every untrusted state |
| App token lifted into another app | `app_key` mismatch is a visible 422; the token rotates |
| SSRF through MTA export webhooks | `https` only, private-range refusal at schedule time and at send time |
| MTA journey poisoning (a crafted `p202vid` cookie joins someone else's journey) | The cookie is random and 128-bit, so guessing one is infeasible. A user can only pollute journeys in their own browser. Credits are bounded by conversions, which MTA never creates |
| Permission drift between surfaces | One permission check per operation, and a structural test over the routes |
| Malformed values resolving permissively | Missing HMAC key, unparseable referrer, unreadable policy and invalid model config all resolve to the non-trusting or disabled state (#11) |

### 7.2 Privacy

- The only new persistent identifier is `p202vid`, a random first-party cookie
  on the tracking domain. It carries no information and is never sent to third
  parties.
- Operators must cover it in their consent flow where their jurisdiction
  requires consent for analytics cookies. `dl.php` honours a `p202_consent=0`
  parameter or a per-campaign setting that suppresses it; the journey is then
  one touch.
- No device identifiers are collected on Android, and no advertising ID.
- User deletion purges every `202_app_*` and `202_attribution_*` table and `202_clicks_visitor`, plus export
  files on disk.

### 7.3 Performance

**Redirect hot path.** It gains one cookie read or write, one HMAC for the
install token, and one row in the existing click transaction. It gains no
query.

**Pixel and postback paths.** They gain one outbox insert and lose MTA's
inline settings lookup, journey queries and 24-hour rebuilds. Conversion
latency goes down.

**MTA worker.** Per conversion it runs one indexed journey query (at most 25
rows) and writes credits for (models × touches) rows. The target is 1,000
conversions per minute per worker on modest hardware. That target is measured
on a seeded instance before release, not asserted.

**MTA reports.** They are grouped queries over `(model_id, click_id)` joined to
click dimensions. The target is under 2 s for 30 days at 1M conversions. If
measurement misses it, the fix is an hourly rollup keyed on
(model, dimension, hour), recomputed from credits for dirty hours only.

**Android intake.** p95 under 100 ms. It makes no external calls on the
request path.

**App measurement reshape.** It must not slow the iOS receiver: the same
statements under new names.

**Before any performance fix ships,** it must be shown to return the same
answer as the unoptimised path on a sparse and a dense dataset (CLAUDE.md,
"A performance fix must be shown to return the same answer").

### 7.4 Reliability

- **Exactly-once conversions** come from database keys:
  - `install_uuid` per registration;
  - `(click_id, transaction_id)`.
- **Exactly-once MTA processing** comes from the in-transaction outbox, plus
  idempotent credit rewrites.
- **Post-commit failures** complete via cron (#13). No response invites a
  duplicating retry.
- **Isolation.** A broken MTA (missing table, bad config) never affects
  conversion recording. The outbox simply accumulates.
- **Every fallible call is checked,** including `get_result`, `store_result`
  and `prepare` (#1).

### 7.5 Compatibility

- The release stays 1.9.76. The ≤ 1.9.55 → 1.9.76 path creates the final
  schema directly. No database holds an intermediate shape, so no legacy
  guard or drop is needed.
- PHP 8.3 (CI) and 8.4; MySQL 5.7 and 8, and MariaDB.
- iOS 14+ SDK behaviour is unchanged apart from the header rename. Android
  needs API 21+ and Play Store app 8.3.73+.
- CLI and API renames (`p202 app`, `/apps/*`, the `apps` scope area) have no
  compatibility shims, because there are no users.

### 7.6 Verification: what "done" means

**Phase 0 (iOS reshape):**
- iOS tests are ported and green, and the signature vectors are unchanged.
- **Upgrade equals install.** The test goes in three steps:
  1. Build a 1.9.55 database by running the installer from the last commit
     whose `202-config/version.php` reads 1.9.55. That needs a full-history
     clone; this sandbox's is shallow.
  2. Upgrade it with the new code.
  3. Compare `SHOW CREATE TABLE` for every table the 1.9.56–1.9.76 rungs touch
     against a fresh 1.9.76 install. They must match, apart from the
     normalisation differences the reconciler docblock lists.

  This one test covers the only real upgrade path. It runs for MTA's rungs
  as well as the app tables'.
- The iOS live, browser and SDK suites are green.

**Phase 1 (Android):**
- **Unit tests:** referrer parser, token (including the missing-key case),
  timing rules, `AppIdentity` on raw payloads.
- **Contract vectors:** exercised in PHP, Swift and Kotlin.
- **Live pass:**
  1. a real `dl.php` click;
  2. the token read from `Location`;
  3. an SDK-shaped POST;
  4. the conversion row and `click_lead` checked;
  5. a replay answers duplicate;
  6. a second device answers `duplicate_click`;
  7. a tampered MAC answers `bad_token`;
  8. the traffic-source pixel fires.

  Negative cases assert the state and the reason ("not succeeded" is not
  "refused").

**MTA:**
- **Strategy unit tests:** credit sums to 1, revenue sums to payout exactly,
  and the one- and two-touch edge cases.
- **A structural test** that every surface's model list equals the enum.
- **Live pass:**
  1. three clicks from one browser (cookie jar) across two campaigns;
  2. one click from another browser;
  3. convert via `gpb.php`;
  4. run the worker;
  5. assert the journey is exactly the three same-browser clicks;
  6. assert the credits under each model;
  7. assert the report grouped by campaign **differs between `first_touch`
     and `last_touch`** (the property today's engine cannot produce);
  8. assert the stranger's click has no credit.
- **Isolation test:** drop an MTA table, fire a conversion, and assert the
  conversion records with a 200 and the outbox row is kept.
- **SSRF test:** a webhook to `http://127.0.0.1`, to a private address, and to
  a hostname resolving to one. Each is refused at schedule time and at send
  time.

**Cross-feature:** an attributed Android install appears in the MTA report on
the install click's campaign, with that browser's earlier web clicks in its
journey.

**Agent-eval cases:**
- register an Android app from a store link;
- build a campaign link;
- simulate an install;
- read both reports.

## 8. Phasing

| Phase | Scope | Depends on |
|---|---|---|
| **M0: visitor capture** | `p202vid` cookie, `202_clicks_visitor`, consent switch. Nothing reads it yet | — |
| **0: app reshape** | Part B §4: registry, catalogue, verdicts, plumbing, retention, renames to `/apps` and `p202 app`, user-deletion purge | — |
| **M1: MTA engine** | Outbox in `record()`; removal of the inline pixel hooks; worker; models; credits; reports API; deletion of v2, dead code and the old journey crons | M0 |
| **1: Android installs** | §5.1–5.4, §5.6 | 0, plus the `record()` outbox from M1 if MTA should see installs |
| **M2: MTA UI and exports** | Dashboard on the v2 shell, model comparison, journey metrics, hardened exports | M1 |
| **2: Android events and fraud signals** | §5.5, CTIT, third-party breakdowns | 1 |
| **3: extensions** | Play Integrity, Meta decryption, other stores, deep links | 1 |

The phases are **merge order**. Nothing has been released since 1.9.55, so
they can all go out in one 1.9.76 release or be split across releases.

**M0 must be in the first release that goes out, whatever else is.** Journeys
cannot be backfilled: every click an install records before it has M0 is a
one-touch journey forever.

**0 and M1 are independent** and can proceed in parallel. They meet only at
`record()`.

## 9. Decisions needed before implementation

1. **Is an install a conversion by default?** The plan says yes, per
   registration.
2. **Fire traffic-source postbacks on install?** The plan says yes, reusing the
   `gpb.php` logic.
3. **Play Integrity in phase 1 or phase 3?** It needs each app owner's Google
   Cloud credentials.
4. **GAID: never, or opt-in?** The plan collects none.
5. **The visitor cookie.** Is a first-party `p202vid` cookie on the tracking
   domain acceptable as the journey identity, with the consent switch in §7.2?
   Without it, a journey can only be built from `customer_id`, which only
   links conversions to each other, or it cannot be built at all.
6. **One release or several?** Everything can ship as a single 1.9.76, or
   phase 0 + M0 + M1 can ship first with Android following. The plan works
   either way; it changes only what the first release notes promise.
7. **Naming:**
   - app measurement: `202_app_*`, `/apps/*`, scope `apps`, `p202 app`;
   - MTA: `202_attribution_*`, `/attribution/*`, scope `attribution`;
   - `app_key`, `app_token`, `accept_test_signals`.
