# Mobile app measurement: Android install tracking, on a core shared with iOS

Status: **proposal, not implemented.**

This plans native Android install tracking. It also **reshapes the iOS
implementation shipped in 1.9.76** (SKAdNetwork / AdAttributionKit,
`documentation/api/19-attribution-postbacks.md`) so that both platforms are
built from the same parts.

The reshape is allowed because nothing depends on the current shape. The iOS
feature has no users and no installs, and **the release stays 1.9.76**: the
reshaped schema is what the existing 1.9.75 → 1.9.76 rung creates.

Sources:

- Android platform facts were checked against Google's and Meta's
  documentation on 2026-09-25.
- Codebase facts cite `main` at `99972c2`.

---

## 1. What "like iOS" can and cannot mean

Android has no counterpart to the iOS mechanism, so the two platforms can
share a product surface but not a pipeline.

| | iOS | Android |
|---|---|---|
| Mechanism | The OS sends a **signed, aggregate postback** to a well-known URL | Our SDK reads the **Google Play Install Referrer** on first launch and reports it |
| Timing | 24–48 h+ after install, deliberately delayed | Seconds after first open |
| Trust anchor | Apple's ECDSA signature | None from the platform. Trust comes from a signed token we put in the store link, click plausibility checks, and optionally Play Integrity |
| Granularity | No click, no device; a 6-bit conversion value | **Click-level**: the referrer carries our click id |
| What it becomes in Prosper202 | Report rows that can never join a click | **A real conversion on the originating click**, visible in every existing campaign report |

Google's SKAdNetwork analogue, the Privacy Sandbox Attribution Reporting API
on Android, was **retired on 17 October 2025**
(<https://privacysandbox.google.com/blog/update-on-plans-for-privacy-sandbox-technologies>).
No replacement postback exists.

So the two platforms share **everything around the signal**:

- the app registry and ownership;
- the app token and the SDK contract;
- the event catalogue and revenue;
- the public-endpoint plumbing;
- the trust vocabulary and retention;
- the report surface.

They differ only in **how a signal is received and judged**. The reshape makes
that boundary the architecture.

---

## 2. Target architecture

```
                         ┌──────────────── shared core ────────────────┐
  store link / SDK /     │ AppRegistry     one registration per app,   │
  Setup page / CLI  ───► │                 keyed (platform, app_key)   │
                         │ AppIdentity     parse + validate an app id  │
                         │                 or store link, per platform │
                         │ AppToken        the credential the SDK      │
                         │                 ships; header-only; rotate  │
                         │ EventCatalog    event → revenue, per app    │
                         │                 or account-wide             │
                         │ Verdict         source state → trust bit    │
                         │                 under the app's policy      │
                         │ PublicIntake    probe, 405/413, DB bring-up,│
                         │                 peer rate limit, body read  │
                         │ Retention       per-source prune classes,   │
                         │                 one cron                    │
                         │ ReportSource    shared metric set; one      │
                         │                 report across platforms     │
                         └──────────▲───────────────────▲──────────────┘
                                    │                   │
               ┌────────────────────┴───┐   ┌───────────┴─────────────────┐
               │ Apple signal source     │   │ Android signal source        │
               │ SkadnetworkProtocol     │   │ InstallReferrerIntake        │
               │ AdAttributionKitProtocol│   │ InstallToken (HMAC)          │
               │ PostbackVerifier / JWS  │   │ ReferrerParser               │
               │ SignatureState: Verdict │   │ MatchState: Verdict          │
               │ SkanEncoding (6-bit CV) │   │ ClickConversionBridge        │
               │ 202_app_postbacks       │   │ 202_app_installs,            │
               │                         │   │ 202_app_install_events       │
               └─────────────────────────┘   └──────────────────────────────┘
```

The rule for the boundary: **a class goes in the core only when both sources
call it.** A class goes in a source only when it knows something about one
platform. For example, `SkanEncoding` is Apple's 6-bit value space, so it is
not in the core, even though the event catalogue it points into is.

---

## 3. The reshape of the Apple implementation (phase 0)

Phase 0 changes no user-visible iOS behaviour except the renames listed here.
It is finished when every existing iOS test has been **ported, not deleted**,
and passes; the SKAN and AAK signature vectors are byte-identical; and the
existing live passes (`tests/live/setup-mobile-apps.sh`,
`analyze-mobile-apps.sh`) and browser specs are green on the new code.

### 3.1 Tables: new names, new shape

The three iOS tables are replaced by tables under a new `202_app_` prefix.
There are two reasons to rename rather than reshape in place.

- **The reconciler cannot reshape.** `SchemaReconciler` only adds columns and
  indexes and relaxes `NOT NULL`; it never drops, renames or retypes anything
  (`202-config/Database/SchemaReconciler.php`, class docblock). A branch or
  dev deployment already at 1.9.76 would keep, for example, the old
  `app_id bigint NOT NULL`. Every insert that no longer supplies it would then
  fail with errno 1364, which is the exact failure that docblock records from
  the first 1.9.76 reshape. New names are created fresh, so nothing old is
  ever half-converged.
- **The prefix is already taken.** `202_attribution_*` also holds the
  multi-touch attribution engine (`202_attribution_models`, `_snapshots`,
  `_touchpoints`, `_settings`, `_audit`, `_exports`; `TableRegistry.php:90-95`).
  App measurement is a different feature, and sharing a prefix already made
  user deletion miss it (§3.8).

| Old | New | What changes |
|---|---|---|
| `202_attribution_apps` | `202_app_registrations` | Identity becomes `(platform, app_key)`, per-app policy fields (below) |
| `202_attribution_postbacks` | `202_app_postbacks` | Adds `registration_id`; `signature_valid` → `trusted` (§3.4) |
| `202_attribution_conversion_values` | `202_app_events` + `202_app_skan_encodings` | Split: *what an event is worth* vs *how iOS encodes it* (§3.5) |
| — | `202_app_installs`, `202_app_install_events` | Android (§4) |

**The legacy guard is extended, not duplicated.**
`_upgrade_attribution_tables()` already halts the upgrade when pre-release
`202_skan_*` tables hold rows (`functions-upgrade.php:307-350`). The same guard
takes a list of legacy generations. The first-cut `202_attribution_postbacks`,
`_apps` and `_conversion_values` become the second entry, named exactly,
because the prefix is shared with live multi-touch tables. The rules are:

- empty legacy tables → proceed;
- rows present → halt with the same "Upgrade paused" contract;
- probe failed → do nothing, and retry.

`AttributionUpgradeStepTest` and RELEASING.md's branch-deployment repair are
updated to name the new tables.

### 3.2 App registry: one identity for both platforms

`202_app_registrations`:

| Column | Notes |
|---|---|
| `registration_id` int PK | **The key everything else uses.** No other table stores a raw app id as its link to a registration |
| `user_id` | owner |
| `platform` varchar(16) | `ios` or `android` |
| `app_key` varchar(255) | Apple: the App Store item id as canonical decimal. Android: the application id (package name). `UNIQUE (platform, app_key)` |
| `app_name`, `notes` | |
| `app_token` char(64) | Renamed from `schema_token`. `UNIQUE` |
| `accept_test_signals` tinyint | Renamed from `accept_development_postbacks`. One policy covers AAK development-key postbacks **and** Android test installs (§3.4) |
| `attribution_window_days` smallint | Android only; `NULL` on iOS |
| `count_install_as_conversion` tinyint | Android only; `NULL` on iOS |
| `created_at`, `updated_at` | |

The Android identity is the **package**, not the store listing. The same
package can ship through Play, Galaxy Store and AppGallery, and it is one app.
The store an install came from is a property of the install row, not of the
registration.

**`AppIdentity`** is the one implementation of "is this a valid app id, and
what does this store link name". Today that logic is split three ways:

- `AttributionAppsController::assertUsableAppId()`, on the raw payload per
  error pattern #18;
- `MobileAppsController::appStoreId()`, the round-trip that catches `(int)`
  saturation;
- `MobileAppsController::parseStoreReference()`, the link parser that
  recognises and then refuses `play.google.com`.

It becomes one class with a per-platform rule:

- **Apple:** digits, positive, round-trip exact, no leading zero.
- **Android:** dot-separated segments `[A-Za-z][A-Za-z0-9_]*`, at least two,
  case-sensitive.

It also has one link parser: `apps.apple.com/…/id123`, a bare id,
`play.google.com/store/apps/details?id=com.x`, `market://details?id=com.x`, or
a bare package. The API, the Setup page and a new CLI `--store-link` flag all
call it, so a link is read the same way everywhere (error pattern #5).

**The `app_id = 0` sentinel disappears.** Today `0` means "account-wide" in the
conversion-value rules, and an Android app with no numeric id would have cast
to that same `0`. With `registration_id` as the key, account-wide is
`registration_id = 0`. That value can never be a real registration, because
auto-increment starts at 1, and no code casts an app identifier into it. It
stays `NOT NULL DEFAULT 0` rather than `NULL` on purpose: MySQL's `UNIQUE`
admits any number of `NULL`s, so `NULL` would silently allow duplicate
account-wide rules.

**API shape** (`/attribution/apps`): `platform` + `app_key` on write and read.
The request also accepts `store_link`, which `AppIdentity` expands to both, and
a mismatch between an explicit value and the link is a 422 naming both. The
old `app_id` field is gone, which is safe with zero users.

### 3.3 App token and the SDK contract

- **The token is renamed.** `schema_token` becomes `app_token`, and the header
  `X-P202-Schema-Token` becomes `X-P202-App-Token`. It is still minted with
  `bin2hex(random_bytes(32))`, still header-only (never a query string), still
  rotatable, and still redacted from idempotency replays, staged-change
  results and delete previews (`SchemaTokenHygieneTest` is ported).
- **It is documented as an identifier, not a secret.** It ships in every app
  binary. On iOS it gates a read-only document. On Android it also gates the
  install intake, where it identifies the app and never authenticates a device
  (§6.1).
- **One wire contract, two SDKs.** `documentation/api/21-app-sdk-contract.md`
  defines `GET /attribution/schema`, the intake, the events route, retry
  semantics (400/413 terminal, 429/5xx retry), the test flag and the token
  header once.
- **Cross-language vectors.** Test vectors in
  `tests/fixtures/app-sdk-contract/` (requests, responses, referrer strings,
  schema documents) are read by the PHP tests, the Swift tests and the Kotlin
  tests. This is the pattern `t202ctx-vectors.json` already uses for
  `CtxToken`, and it keeps three implementations from drifting apart.
- **`GET /attribution/schema` becomes platform-shaped:**
  - iOS: `events: {name: {fine_value, coarse_value}}`, as today;
  - Android: `events: {name: {}}`, the list of mapped event names, so the SDK
    can refuse unmapped names locally as the iOS helper already does.

  Revenue is withheld from both.
- **The two SDKs share an API:** `configure(endpoint, appToken)` and
  `logEvent(name, …)`. A later React Native or Flutter wrapper can then be a
  thin shim over the two.

### 3.4 Trust: one vocabulary, per-source verdicts

`SignatureState` already separates *what was established* from *what it is
worth* (`trustBit()`). That idea becomes the core interface:

```php
interface Verdict            // implemented by backed enums
{
    public function trustBit(AppPolicy $policy): ?int;   // 1 trusted, 0 refuted, null unvouched
    public function isTest(): bool;                       // governed by accept_test_signals
}
```

- **Apple:** `SignatureState implements Verdict`. It is unchanged, except
  that `DEVELOPMENT` reports `isTest()`.
- **Android:** `MatchState implements Verdict` (§4.3).
  - `attributed` → 1.
  - `bad_token`, `foreign_click`, `implausible` → 0 (refuted).
  - `organic`, `third_party`, `unavailable`, `pending_click` → `NULL`.
  - Test installs → 1 only under `accept_test_signals`.
- Both signal tables store `trusted` (renamed from `signature_valid`, because on
  Android nothing is a signature) plus their source's own state column
  (`signature_state`, `match_state`).
- **Reports use one rule for both:** headline numbers count `trusted = 1`, and
  every other class is visible in its own column. This is today's
  `meta.trusted`, applied to both sources.
- `AppPolicy` is read once per request from the registration. A value that
  cannot be read resolves to *untrusting*, which is today's `resolveOwner()`
  rule (error pattern #11), now in one place.

### 3.5 Events and revenue: separate the catalogue from iOS's encoding

A conversion-value rule currently does two jobs: it says what an event is
worth, and it says how iOS encodes it into 6 bits. Android needs only the
first. The split:

- **`202_app_events`**
  - Columns: `(user_id, registration_id /* 0 = account-wide */, event_name, revenue)`.
  - Key: `UNIQUE (user_id, registration_id, event_name)`.
  - Shared by both platforms. It is the only place revenue is configured.
- **`202_app_skan_encodings`**
  - Columns: `(user_id, registration_id, fine_value | coarse_value, event_name, revenue_override NULL)`.
  - Keys: `UNIQUE (user_id, registration_id, fine_value)` and `UNIQUE (…, coarse_value)`.
  - Apple only.
  - `revenue_override` keeps today's ability to decode tiered values, for
    example fine values 10–20 as "purchase" at different amounts.
- **Decode**, unchanged in effect: `value → encoding` (app-specific, then
  account-wide) `→ revenue_override ?? event revenue`. A fine value with no
  encoding stays undecoded and never falls back to a coarse encoding, which is
  today's rule.
- **Encode** (`/attribution/schema`): the same encodings read in the other
  direction, with the same highest-value-wins tie-break.
- **API.** `/attribution/events` is the catalogue on both platforms.
  `/attribution/conversion-values` survives as the iOS encoding resource, so
  the familiar name keeps its meaning.
- **CLI.** `p202 attribution cv …` keeps working. `p202 attribution event …` is
  added.
- **Behaviour change, said plainly:** an encoding must now name a registered
  app or be account-wide. Today a rule can be scoped to an App Store id nobody
  registered, which decodes nothing until someone does. Nothing is lost, and
  the Setup page already offers to register first.

### 3.6 Public intake plumbing

`PostbackEndpoint` becomes `PublicIntake`. It keeps the same responsibilities:

- GET probe answered from constants;
- 405/413 before any database;
- 503 on database bring-up failure;
- `softIpRateLimit` keyed on `REMOTE_ADDR` (error pattern #16);
- bounded body read;
- a single JSON error envelope.

What changes is that it dispatches to an `IntakeHandler` rather than a
`PostbackProtocol`.

- **Apple** keeps its Apple-dictated physical entry points under
  `/.well-known/`, which are two lines each.
- **Android's intake and the schema document** are pre-auth routes in
  `api/v3/index.php`. They call the same class instead of the inline copy the
  schema route has today (`index.php:131-153`). The rate-limit bucket names
  stay per surface.

### 3.7 Retention: one mechanism, per-source classes

`PostbackReceiver::PRUNE_CLASSES` moves into `Retention` as data that each
source registers:

```php
Retention::register('202_app_postbacks', [
    'unclaimed'    => ['P202_APP_RETENTION_DAYS_POSTBACK_UNCLAIMED', 30, 'user_id = 0'],
    'refuted'      => ['P202_APP_RETENTION_DAYS_POSTBACK_REFUTED',   90, 'trusted = 0'],
    'unvouched'    => ['P202_APP_RETENTION_DAYS_POSTBACK_UNVOUCHED', 90, 'trusted IS NULL'],
]);
Retention::register('202_app_installs', [
    'refuted'      => ['P202_APP_RETENTION_DAYS_INSTALL_REFUTED',    90, 'trusted = 0'],
    'unvouched'    => ['P202_APP_RETENTION_DAYS_INSTALL_UNVOUCHED', 180, "trusted IS NULL AND match_state <> 'pending_click'"],
]);
```

- `202-cronjobs/app-retention.php` (renamed from `attribution-retention.php`)
  prunes every registered source, drains the backlog, and prints each resolved
  window. The existing rules carry over:
  - a malformed override prunes nothing and is named in the log;
  - `0` disables that class.
- The environment variables are renamed with the tables. With zero installs,
  nobody's overrides are lost.
- The opportunistic 1-in-100 prune stays on the receiver path for both sources.

### 3.8 Things the reshape fixes along the way

- **Deleting a user leaves their app data behind.** The purge list in
  `202-account/user-management.php:287-296` names only the multi-touch tables.
  A deleted user's registrations therefore keep their global
  `UNIQUE (platform, app_key)` slot, and nobody can register that app again.
  The reshape adds every `202_app_*` table to the purge. Postbacks are
  released to unclaimed (`user_id = 0`, `registration_id = NULL`) rather than
  deleted, consistent with "history is never reassigned, unclaimed rows
  expire".
- **The Analyze page stops reading raw app ids.** `MobileAppsReportController`
  and the report API filter on `app_id`/`app_ids`. They become
  `registration_id`/`registration_ids`. The postback's own `app_id` (what
  Apple said) stays as a column and a filter for forensics, as does
  `app_key`.

### 3.9 Blast radius

The files phase 0 touches, from a sweep of every reference to the three tables,
`schema_token`, `/attribution/apps` and `/attribution/conversion-values`:

- **PHP core:**
  - `api/v3/Attribution/*` (receiver, endpoint, protocols, `SignatureState`);
  - `api/v3/Controllers/Attribution{Apps,ConversionValues,Postbacks,Schema}Controller.php`,
    `StagedChangesController.php`, `SystemController.php`;
  - `api/v3/index.php`;
  - `202-config/Database/Tables/AttributionPostbackTables.php`,
    `TableRegistry.php`, `functions-upgrade.php`;
  - `202-cronjobs/attribution-retention.php`;
  - `202-account/user-management.php`.
- **UI:** `tracking202/setup/MobileAppsController.php` + template,
  `tracking202/analyze/MobileAppsReportController.php` + template,
  `202-account/ui-kit.php`.
- **Go CLI:** `go-cli/cmd/attribution_postbacks.go` + tests,
  `go-cli/internal/eval/grade.go` + tests, `internal/api/client.go`.
- **iOS SDK:** header rename in `P202Attribution.swift` and `Schema.swift`;
  tests, including the live suite.
- **Tests:** everything under `tests/Attribution/Postbacks/` (21
  files), `tests/live/*mobile-apps*.sh`, `tests/browser/specs/*mobile-apps*`,
  `StaticSqlSchemaTest`, and the scope and auth-path structural tests.
- **Docs:** `documentation/api/19-attribution-postbacks.md`,
  `docs/openapi.yaml`, `documentation/cli/10-go-cli.md`,
  `sdk/ios-attribution/README.md`, `RELEASING.md`, `README.md`.

---

## 4. Android on the core (phase 1)

### 4.1 Getting the click into the store link

A new token, **`[[p202_install_token]]`**, is added to `replaceTokens()` /
`replaceTrackerPlaceholders()` (`202-config/connect2.php:486-680, 2192-2255`).
It expands to `<click_id>.<mac>`, where
`mac = first 12 bytes of HMAC-SHA256(K_install, "p202-install-v1|" . click_id)`,
encoded as base64url without padding.

**Why not `[[subid]]`:** click ids are sequential integers. An unsigned
referrer lets anyone who can reach the intake credit an install, and a payout,
to any click. That is error pattern #16: the identity must be what the
attacker cannot choose. With the MAC, only referrers our redirect minted
verify.

**The key.** `K_install` is a random 32-byte install-wide secret. It is
generated by the 1.9.76 rung and never served. `CtxToken`'s key cannot be
reused, because it derives from the LPO webhook secret, which most installs
never set. The key must be readable on the redirect hot path without a query,
as `CtxToken`'s cached `ctx_key` is. **If it is missing, the token fails
closed**: it expands to empty, and the intake records the install as
unattributed rather than accepting an unsigned id.

**Encoding.** The referrer is itself a query string, so it is percent-encoded
as a whole: `…&referrer=p202%3D[[p202_install_token]]`. The token alphabet
`[0-9A-Za-z._-]` survives `rawurlencode202()` unchanged. The Setup page's link
builder writes the whole URL (§4.6).

**Timing.** In `dl.php` the click row is written *after* the redirect for
non-cloaked links (`:518` vs `:626-629`), so only first-pass tokens are
reliable, and this one must be computed in the first pass from `$click_id`. The
fallback path used while MySQL is down (`dl.php:106-190`) substitutes the
literal `p202` for `[[subid]]`. It must substitute **empty** for this token.

**Parameters that never reach the app.** `getPrePopVars()` appends passthrough
parameters to the destination URL at the top level, beside `referrer=`, not
inside it. Play ignores them, so they never reach the app. The docs say so.

### 4.2 Intake: `POST /api/v3/attribution/installs`

It is served by `PublicIntake` (§3.6) and gated by `X-P202-App-Token`. The body
is JSON, capped at 16 KB:

```json
{
  "install_uuid": "8d7c…",            // SDK-generated v4 UUID
  "app_key": "com.example.app",        // must equal the token's registration
  "store": "google_play",              // google_play | huawei | samsung | xiaomi | unknown
  "referrer": {
    "status": "ok",                    // ok | feature_not_supported | service_unavailable | permission_error
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

1. **Validate strictly** (error pattern #4): 400 naming the field.
2. **Resolve the registration** by token (404 if unknown). A mismatched
   `app_key` is a 422 naming both values.
3. **Insert the install row** (idempotent on
   `(registration_id, install_uuid)`). A replay answers 200 with the stored
   outcome and `duplicate: true`.
4. **Classify** into `MatchState` (§4.3).
5. **For `attributed`, record a conversion** through `ClickConversionBridge` →
   `MysqlConversionRepository::record()`
   (`202-config/Conversion/MysqlConversionRepository.php:120-298`):
   - `transaction_id = 'p202-install'`. The existing
     `UNIQUE KEY uniq_click_transaction (click_id, transaction_id)` makes
     **one install conversion per click** a database fact.
   - `pixel_type = 4`, a new value. Values 0–3 are in use.
   - `conv_time` is Google's server install-begin time when present.
   - Payout is the campaign's; the click-side update is the one
     `p202RecordConversion()` applies.
   - The conversion is skipped when `count_install_as_conversion = 0`.
6. **Fire the traffic source's postback.** The `202_ppc_account_pixels` logic
   in `gpb.php:166-240` is extracted into one function that both call, so an
   ad network receives an S2S install postback.
7. **A failure after the commit is still a success** (error pattern #13). The
   row is committed, the response is 200 with `match: "pending"`, and cron
   finishes the job. The SDK is never invited to retry something that already
   landed.

**Probe.** `GET` on the same path answers `{"status":"ready"}` for the Setup
page's reachability check. The SDK refuses `http://` endpoints outside debug
builds.

**What the response may contain.** It never returns click data the referrer
did not already carry, because the token is public.

### 4.3 `MatchState`

| State | Trust | Meaning |
|---|---|---|
| `attributed` | 1 | Token MAC verified, click found and owned, timing plausible, inside the window |
| `organic` | null | Play's organic referrer (`utm_source=google-play&utm_medium=organic`) or none |
| `third_party` | null | A referrer we did not mint: Google Ads `gclid`, Meta's `utm_content` envelope, another tracker's. Parsed fields are stored |
| `unavailable` | null | The referrer API returned FEATURE_NOT_SUPPORTED, SERVICE_UNAVAILABLE or PERMISSION_ERROR |
| `pending_click` | null | Token valid, click row not written *yet* (the `dl.php` post-redirect write). Settled by cron within 24 h |
| `bad_token` | 0 | `p202=` present, MAC fails or malformed, or the click was never recorded |
| `foreign_click` | 0 | The click belongs to another user, or to a campaign linked to a different registration |
| `implausible` | 0 | Timing contradicts the click (§6.1) |
| `outside_window` | null | Install later than `attribution_window_days` after the click |
| `duplicate_click` | null | The click already has an install conversion |

Every row also carries a `match_reason` sentence, which the UI shows as-is.

### 4.4 Tables

**`202_app_installs`:**

- `install_row_id`, `user_id`, `registration_id`, `install_uuid`
  (`UNIQUE (registration_id, install_uuid)`), `store`;
- `click_id` NULL, `conversion_id` NULL;
- `match_state`, `match_reason`, `trusted`, `is_test`;
- `referrer_raw` varchar(2048), truncated with a flag and never rejected,
  because Google documents no limit; parsed `utm_*` and `gclid`;
- the four referrer timestamps (device and Google-server);
- `install_version`, `app_version`, `sdk_version`, `os_version`;
- `integrity_state`;
- `first_open_at`, `received_at`, `settled_at`;
- `raw_payload`, `remote_ip`.

Indexes: `(user_id, received_at)`, `(registration_id, received_at)`,
`(click_id)`, `(match_state, received_at)`.

**`202_app_install_events`** (phase 2): `UNIQUE (install_row_id, event_id)`,
`event_name`, catalogue revenue, `client_revenue`, `conversion_id`.

**`202_aff_campaigns.registration_id`** NULL: the optional campaign → app link
used by `foreign_click` and the link builder. The reconciler adds it as a
nullable column, and a column it can add is a column it handles.

The two signal tables stay separate even with the freedom to merge them.
Postback identity is Apple's (`ad_network_id`, `transaction_id`, conversion
window, signature). Install identity is ours (`install_uuid`, `click_id`).
Merging would make every identity column nullable, and weaken the uniqueness
that deduplication rests on. The composition belongs above the tables, in
§3.4, §3.7 and §4.5.

### 4.5 In-app events (phase 2)

`POST /attribution/installs/{install_uuid}/events` accepts
`{event_id, event_name, occurred_at, revenue?, currency?}`.

- Each event becomes a conversion on the install's click with
  `transaction_id = 'p202-evt:' . event_id`, deduplicated per click by the
  same unique key. Only `attributed` installs produce conversions.
- Revenue comes from **`202_app_events`**, the same catalogue iOS decodes
  through, because anyone with the app token can post an event.
  Client-reported revenue is stored in its own column and used only under the
  per-registration `trust_client_revenue` flag (default 0). Reports say which
  regime produced a number.
- Unmapped names are stored and counted as unmapped. They are never credited
  and never dropped.

### 4.6 UI

- **Setup › Mobile Apps.** Pasting a Play link registers an Android app
  (`AppIdentity`). There is no public Play lookup API, so the name comes from
  a best-effort fetch of the listing's `og:title`, falling back to asking for
  that one field. The Android app page offers:
  - the app token with Reveal, Copy and Rotate;
  - Gradle and init snippets filled in with this install's URL;
  - an intake reachability check;
  - **a store-link builder**: pick a campaign, get the exact
    `aff_campaign_url`, and optionally write it to the campaign and set its
    `registration_id`. The URL is `https://play.google.com/…`, which the
    campaign form's http(s) rule accepts;
  - the newest ten installs with their reason;
  - the `accept_test_signals` opt-in.

  The shared parts (token panel, reachability, recent signals, test opt-in)
  become one partial that both platform pages render.
- **Analyze › Mobile Apps** reads the cross-platform report (§4.7):
  - a platform filter;
  - per-source tabs: **Postbacks** for iOS, **Installs** for Android;
  - the **Verify** tab stays Apple-only, because there is nothing to verify on
    Android. The Android equivalent is a row's `match_reason`.

### 4.7 One report across both platforms

`GET /attribution/report` asks each registered `ReportSource` for the shared
metric set over the same filters and group:

- `installs`, `reengagements` (iOS only), `redownloads` (iOS only);
- `events {name: {count, revenue}}`, `revenue`;
- `trusted` / `refuted` / `unvouched` / `test` counts.

- **Shared dimensions:** `day`, `registration`, `platform`, `country`.
- **Source-specific dimensions:** iOS has `ad-network`, `source`, `version`
  and `conversion-type`; Android has `campaign`, `store`, `match-state` and
  `ctit-bucket`. These require the matching `platform` filter. Without it the
  request is a 422 with a hint (`group_by=version applies to iOS postbacks;
  add platform=ios`). Excluding the other source silently is not allowed:
  that would be a report quietly missing half its rows.
- `data.totals` is per platform plus a combined figure. The combined figure is
  labelled, because iOS numbers are delayed, aggregate and privacy-thresholded,
  and Android numbers are neither.
- **Campaign reports need no change.** Attributed Android installs *are*
  conversions, so EPC, ROI and every click breakdown include them.

### 4.8 Testing without the Play Store

A real referrer exists only for builds installed from Play; the internal
testing track works. For faster loops:

- **Test installs.** A debug build of the SDK sends `test: true` with a
  developer-supplied referrer. It goes through the same pipeline and is stored
  with `is_test = 1`, but counts and converts only under
  `accept_test_signals`. This is the same live-policy semantics as AAK
  development postbacks, and now literally the same flag.
- **`p202 attribution install simulate <registration> --click <id>`**
  (`attribution:write`) mints a real token for a real click and posts it as
  the SDK would. It drives the live test and the agent-eval case.

### 4.9 Android SDK (`sdk/android-attribution/`)

- **Language and dependencies:** Kotlin, `minSdk 21`. The only dependency is
  `com.android.installreferrer:installreferrer:2.2`, the latest release
  (January 2021).
- **Referrer:** read once on first launch and persisted. Google keeps it for
  90 days, which leaves ample time to retry.
- **Retries:** exponential backoff. 400 and 413 are terminal; 429 and 5xx are
  retried.
- **Events** queue until the install has been acknowledged.
- **`install_uuid`** lives in SharedPreferences **excluded from Auto Backup**.
  Otherwise a restore onto a new phone would dedupe a genuine new install as a
  replay.
- **No advertising ID.** Without it the app needs no `AD_ID` permission and
  has a minimal Play Data safety declaration.
- **Tests:** JVM unit tests over the shared contract vectors (§3.3), and a
  live integration test against a running instance, mirroring the iOS suite.

### 4.10 Phase 3 extensions, each independent

- **Play Integrity.** The SDK sends a standard token with
  `requestHash = SHA-256(canonical body)`. Standard tokens can be decoded only
  through Google's `decodeIntegrityToken`, which requires the **app owner's**
  Google Cloud service account, stored per registration and encrypted at rest.
  The default quota is 10,000 requests and 10,000 decodes per app per day.
  Decoding runs in cron, off the request path. A registration can require
  `integrity_state = valid` to attribute.
- **Meta install referrer.** AES-256-GCM, decrypted with the per-app 64-hex key
  from Meta's dashboard via `openssl_decrypt` with the 16-byte tag split off
  the end. It yields campaign, ad set and ad ids for `third_party` rows.
- **Other stores** (Huawei, Samsung, Xiaomi): SDK modules plus a `store`
  value. Huawei's timestamps are in milliseconds.
- **Deferred deep links.** The intake response returns a per-campaign path.
  `/.well-known/assetlinks.json` is optional and needs the Play App Signing
  fingerprint.

---

## 5. API, CLI and docs, after both phases

- **API:**
  - `/attribution/apps` (`platform`, `app_key`, `store_link`, policy fields);
  - `/attribution/events` (catalogue);
  - `/attribution/conversion-values` (iOS encodings);
  - `/attribution/postbacks`;
  - `/attribution/installs` (list and get);
  - `/attribution/report`;
  - `/attribution/verify` (Apple);
  - public `/attribution/schema`, `POST /attribution/installs` and
    `/attribution/installs/{uuid}/events`.

  All sit in the existing `attribution` scope area. Every new route goes
  through `ScopeCoverageTest` and `ApiKeyAuthPathScopeTest`.
  `features.app_measurement` in `/capabilities` lists the platforms and
  sources.
- **Go CLI:**
  - `p202 attribution app create --store-link <url>` (or
    `--platform/--app-key`);
  - `p202 attribution event …`, `cv …`, `postbacks …`,
    `install list|get|simulate`, `report`.

  All of it follows the agent-actionable error contract in `CLAUDE.md`,
  including hints naming `p202 attribution app list` for an unknown token or
  key.
- **Docs:**
  - `19-attribution-postbacks.md` becomes the iOS source page;
  - new `20-android-install-tracking.md`;
  - new `21-app-sdk-contract.md`;
  - OpenAPI, CLI docs, both SDK READMEs.

---

## 6. Non-functional requirements

### 6.1 Security

The Android intake is public, and its gate ships in every APK. The model
assumes the attacker has the app token.

| Threat | Mitigation |
|---|---|
| Forge an install for an arbitrary click (CPI payout, EPC poisoning) | HMAC install token. `bad_token` is refuted and counts nowhere |
| Replay one genuine referrer | `uniq_click_transaction`: one install conversion per click, enforced by the database |
| **Click spamming**: harvest valid tokens from cheap clicks, report fake installs | The inherent residual risk of any referrer scheme. Mitigations: (a) Google's **server** click time must be within minutes of our `click_time` for that click; (b) the CTIT distribution is reported, and short (under ~10 s) or long tails are flagged; (c) per-registration, per-peer caps on the intake; (d) Play Integrity, the only control that proves a real app on a real device |
| Click injection | Google's server click time after its server install-begin time → `implausible` |
| Row-minting DoS | 16 KB cap; rate limit on `REMOTE_ADDR` (#16) with injective bucket names (#17); a retention class for every untrusted state |
| Token lifted into another app | The `app_key` mismatch is a visible 422, and the token can be rotated. Rotation needs a release, and the page says so |
| Malformed values resolving permissively | A missing HMAC key, an unparseable referrer, an unknown store or an unreadable policy all resolve to untrusted (#11) |

### 6.2 Privacy

- No device identifiers are collected. The only personal link is the click
  Prosper202 already holds.
- The docs tell operators to keep personal data out of `utm_*` in the store
  link.
- Retention is configurable.
- The SDK READMEs give developers the exact Data safety (Android) and privacy
  manifest (iOS) answers.

### 6.3 Performance

- The Android intake makes indexed lookups and one short transaction, the same
  shape as `gpb.php`. The target is p95 < 100 ms.
- Nothing external runs on the request path: the pixel fires after
  `fastcgi_finish_request` where available, and Integrity decoding is queued.
- The redirect hot path gains one HMAC and no query.
- The reshape must not slow the iOS receiver. It is the same statements under
  new names, and the verdict lookup is one read, as today.

### 6.4 Reliability

- Exactly-once conversions come from database keys: `install_uuid` per
  registration, and `(click_id, transaction_id)`.
- Delivery is at least once, with SDK retries and a 503 on database outage.
- Post-commit failures are completed by cron (#13).
- Every fallible call is checked, including `get_result`, `store_result` and
  `prepare` (#1).
- `uniq_click_transaction` also blocks soft-deleted rows, so an install
  conversion an operator deletes can never be re-recorded by the same click.
  That is intended and documented.

### 6.5 Compatibility

- **Release and upgrade:** 1.9.76, with no new rung. The 1.9.75 → 1.9.76 rung
  creates the `202_app_*` tables, and the legacy guard covers both
  pre-release generations (§3.1).
- **Runtimes:** PHP 8.3 (CI) and 8.4 (sandbox); MySQL 5.7 and 8, and
  MariaDB.
- **Devices:** iOS 14+ behaviour is unchanged. Android needs API 21+ and Play
  Store app 8.3.73+; older devices report `unavailable`.

### 6.6 Verification (what "done" means per phase)

**Phase 0:**

- every existing iOS test is ported and green;
- the SKAN and AAK vectors are unchanged;
- the upgrade-step tests are green against a fresh database, against one
  holding each legacy generation (empty and populated), and against a branch
  deployment already at 1.9.76;
- the iOS live and browser passes are green on the new code;
- the iOS SDK tests are green, including the live suite, with the renamed
  header.

**Phase 1:**

- **Unit tests:** referrer parser (encoded, double-encoded, organic, gclid,
  Meta envelope, truncated, hostile); token mint and verify (including the
  missing key); timing rules; `AppIdentity` for both platforms, on the raw
  payload.
- **Contract vectors:** exercised by PHP, Swift and Kotlin.
- **Live pass against a running instance:**
  - a real click through `dl.php`, with the Play URL read from `Location`
    and the token's encoding asserted;
  - an SDK-shaped POST, with the `202_conversion_logs` row and
    `click_lead = 1` asserted;
  - a replay → `duplicate: true`;
  - a second `install_uuid` on the same click → `duplicate_click` with no
    second conversion;
  - a tampered MAC → `bad_token`;
  - the pixel fired.

  Every negative case asserts the specific state *and* reason ("not
  succeeded" is not "refused").
- **Agent-eval case:** register an Android app from a store link, build the
  campaign link, simulate an install, read the report.

---

## 7. Phasing

| Phase | Scope | Exit criterion |
|---|---|---|
| **0: reshape** | Core extraction (§3), new tables, API/CLI/UI/iOS SDK renames, the user-deletion purge fix. No Android code | §6.6 phase 0: iOS behaves as before, on the core |
| **1: Android installs** | Install token, intake, `MatchState`, installs table, conversion bridge, traffic-source postback, pending-click cron, Setup page and link builder, cross-platform report, `simulate`, Android SDK (installs), contract doc | §6.6 phase 1 |
| **2: events and fraud signals** | Events route and catalogue revenue, CTIT reporting and flags, `third_party` breakdowns | Live pass extended to events |
| **3: extensions** | Play Integrity, Meta decryption, other stores, deferred deep links | Each independently |

Phase 0 lands first and alone, so any iOS regression can be bisected to a
change with no Android code in it.

## 8. Decisions needed before implementation

1. **Is an install a conversion by default?** The plan says yes, per
   registration, because CPI is the common Android deal.
2. **Fire traffic-source postbacks on install?** The plan says yes, reusing
   the `gpb.php` logic.
3. **Play Integrity in phase 1 or phase 3?** It is the only real defence
   against click spamming, but it needs each app owner's Google Cloud
   credentials.
4. **GAID: never, or opt-in?** The plan collects none.
5. **Naming:** the `202_app_*` prefix; `app_key`; `app_token` /
   `X-P202-App-Token`; `accept_test_signals`. These are cheap to change now
   and expensive after release.
