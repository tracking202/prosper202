# Android install tracking — design plan

Status: **proposal, not implemented.** This document plans native Android
install tracking alongside the iOS attribution feature shipped in 1.9.76
(SKAdNetwork / AdAttributionKit, `documentation/api/19-attribution-postbacks.md`).
Facts about the Android platform were checked against Google's and Meta's
documentation on 2026-09-25; facts about this codebase cite files as they are
on `main` at `99972c2`.

## 1. The one thing that makes Android different

"Like iOS" can only mean *like iOS in the product surface*, not in the
mechanism, because Android has no equivalent of what iOS uses.

| | iOS (shipped) | Android (this plan) |
|---|---|---|
| Mechanism | The OS sends a **signed, aggregate postback** (SKAdNetwork / AdAttributionKit) to a well-known URL | The app **reads the Google Play Install Referrer** on first launch and reports it to us |
| Who sends | The device OS, 24–48 h+ after install, privacy-delayed | Our SDK inside the app, seconds after first open |
| Trust anchor | Apple's ECDSA signature | None from the platform. Trust comes from a signed token we put in the referrer, click plausibility checks, and optionally Play Integrity |
| Granularity | No click id, no device, conversion value 0–63 | **Deterministic, click-level**: the referrer carries whatever we put in the store link, including the Prosper202 click id |
| Result in Prosper202 | Rows in `202_attribution_postbacks`, reported separately, never joined to clicks | **A real conversion on the originating click** in `202_conversion_logs`, so it shows up in every existing campaign report |

Google's privacy-preserving counterpart to SKAdNetwork, the Privacy Sandbox
Attribution Reporting API on Android, was **retired on 17 October 2025**
(together with SDK Runtime, Protected Audience and Topics on Android;
<https://privacysandbox.google.com/blog/update-on-plans-for-privacy-sandbox-technologies>).
No replacement postback exists. Building a receiver for it would be building for
a dead API, so this plan does not.

The consequence runs through every section below. On iOS the hard problem was
*verifying a signature and decoding six bits*. On Android the hard problems are:

1. **joining an install to a click correctly**, and
2. **refusing forged installs** on an endpoint that has no platform signature to lean on.

## 2. How it works end to end

```
 ad click ──► tracking202/redirect/dl.php?t202id=…      (click_id allocated, :486)
                │  campaign URL (aff_campaign_url) is a Play link:
                │  https://play.google.com/store/apps/details?id=com.example.app
                │      &referrer=p202%3D[[p202_install_token]]
                ▼
           Google Play ── user installs ── Play keeps the referrer for 90 days
                ▼
           first app launch: P202Attribution (Android SDK)
             • InstallReferrerClient → install_referrer, click / install-begin
               timestamps (device and Google-server variants), install_version
             • install_uuid (random, generated once, excluded from backup)
                ▼
           POST /api/v3/attribution/installs      X-P202-App-Token: <app token>
                ▼
           InstallReceiver
             1. validate body, resolve app by token (package must match)
             2. store the install row (idempotent on app + install_uuid)
             3. parse the referrer → verify the p202 token's HMAC → click_id
             4. plausibility: click belongs to the app's owner, Google's server
                click time is close to our click_time, install-begin is after
                the click, and the install falls inside the attribution window
             5. MysqlConversionRepository::record(click_id,
                transaction_id = 'p202-install') → one install conversion per click
             6. fire the traffic source's postback (the same one gpb.php fires)
                ▼
           200 {"data":{"install_id":…, "match":"attributed"|"organic"|…}}
```

Later in-app events (`logEvent("purchase")`) go to
`POST /api/v3/attribution/installs/{install_uuid}/events` and become further
conversions on the same click (phase 2).

## 3. Functional design

### 3.1 App registry: Android apps

`202_attribution_apps` already anticipates Android. The `platform` column
exists "so an Android receiver can be added without a migration"
(`AttributionAppsController.php:61-66`), and `android` is refused with a
sentence (`:40-44`). The Setup page recognises `play.google.com` links and
refuses them by name (`MobileAppsController.php:481-487`).

What the registry is missing is an identity for an Android app. `app_id` is a
`bigint NOT NULL` with a global `UNIQUE` key, but an Android app is identified
by its **package name** (`com.example.app`), a string. The plan:

- Add `package_name varchar(255) NULL` with `UNIQUE KEY package_name`.
- Change `app_id` to `NULL`-able. MySQL's `UNIQUE` admits many `NULL`s, and
  storing `0` for every Android app would collide on the first two
  registrations.
- Controller invariant, checked on the **raw** payload before `validatePayload()`
  casts it (error pattern #18):
  - `platform=ios` requires `app_id` and forbids `package_name`;
  - `platform=android` requires `package_name` and forbids `app_id`.
- Package names are validated against Android's grammar: dot-separated
  segments, each `[A-Za-z][A-Za-z0-9_]*`, at least two segments. Case is
  preserved, and a package name is compared exactly because it is
  case-sensitive.
- **Hazard to close in the same change.** Every iOS path reads
  `(int)$row['app_id']`, and in `202_attribution_conversion_values`
  `app_id = 0` means "account-wide fallback". An Android row read through an
  iOS path would therefore turn `NULL` into `0` and silently adopt the
  account-wide rules (error pattern #11). Every iOS query on the registry must
  filter `platform = 'ios'` (or `app_id IS NOT NULL`), and a structural test
  should pin that. The conversion-value rules and `GET /attribution/schema`
  stay iOS-only: Android has no 6-bit value to decode.
- The `schema_token` capability generalises to an **app token**. It is the same
  column, the same rotation, and the same header discipline (header only, never
  a query string). For Android it gates the install intake instead of the
  schema document. It is *not* a secret: it ships in the APK and identifies the
  app, it does not authenticate a device. The plan never treats it as more than
  that (see §4.1).
- New per-app settings:

  | Field | Default | Meaning |
  |---|---|---|
  | `attribution_window_days` | 7 | Latest install, counted from the click, that is still credited |
  | `count_install_as_conversion` | 1 | Off for apps whose campaigns pay on an in-app event, not the install |
  | `accept_test_installs` | 0 | The Android analogue of `accept_development_postbacks`, see §3.6 |

- **Setup › Mobile Apps.** Pasting a Play link reads `id=` as the package and
  sets platform `android`. There is no public Play lookup API, so the name
  lookup is a best-effort fetch of the listing's `og:title` with the existing
  fallback of asking for that one field (UI standard: the app finds what it
  can and says what it derived).

### 3.2 Getting the click id into the store link

A new token, **`[[p202_install_token]]`**, is added to `replaceTokens()` /
`replaceTrackerPlaceholders()` (`202-config/connect2.php:486-680, 2192-2255`).
It expands to `<click_id>.<mac>`, where
`mac = first 12 bytes of HMAC-SHA256(K_install, "p202-install-v1|" . click_id)`,
base64url with no padding.

Why not just `[[subid]]`:

- Click ids are sequential integers. Anyone can type one.
- An unsigned referrer lets anyone who can reach the intake endpoint credit an
  install, and a payout, to any click. That is error pattern #16 exactly: the
  identity must be *what the attacker cannot choose*.

With the MAC, only a referrer minted by our own redirect verifies. That proves
the install came through a link we generated for that click. It does **not**
prove a real install happened; that is §4.1.

Details:

- `K_install` is a random 32-byte install-wide secret, generated by the upgrade
  step and never served. `CtxToken`'s key is not reusable because it is derived
  from the LPO webhook secret, which most installs never set. Where the key is
  stored (an existing settings table or a new one) is decided in
  implementation, and must satisfy two conditions:
  - it is readable on the redirect hot path without an extra query, as
    `CtxToken`'s cached `ctx_key` is;
  - a missing key **fails closed**: the token expands empty and the intake
    rejects, it never falls back to an unsigned id (error pattern #11).
- **Encoding.** The referrer value is itself a query string, so it must be
  percent-encoded as a whole: `referrer=p202%3D<token>`. The token alphabet
  (`[0-9A-Za-z._-]`) needs no escaping, so a campaign URL written as
  `…&referrer=p202%3D[[p202_install_token]]` survives `rawurlencode202()`
  unchanged. Operators who add their own `utm_*` inside the referrer write them
  encoded (`%26utm_source%3D…`). The link builder in §3.7 writes this for them.
- **The token must be the first thing substituted.** In `dl.php` the click row
  is written *after* the redirect for non-cloaked links (`dl.php:518` vs
  `:626-629`), so the second-pass substitution, which reads the click back from
  the database, finds nothing. `[[subid]]` survives this because the first pass
  sets it from `$click_id`, and `[[p202_install_token]]` must be computed in
  that same first pass. The fallback path that runs with MySQL down
  (`dl.php:106-190`) substitutes the literal `p202` for `[[subid]]`. It must
  expand the install token to the **empty string**, so the install is
  recorded as unattributed instead of crediting a fake click.
- `getPrePopVars()` appends unknown query parameters to the destination URL at
  the top level (`connect2.php:2447-2510`). Those land beside `referrer=`, not
  inside it, and Play ignores them. That is harmless, but it means a
  passthrough parameter never reaches the app. The docs must say so.
- **Other stores.** Huawei AppGallery, Samsung Galaxy Store and Xiaomi GetApps
  each have their own install-referrer API. Huawei, for one, reports
  timestamps in **milliseconds**. They are phase 3. The same token works in
  their links because the SDK, not the store, reports it.

### 3.3 The intake endpoint

`POST /api/v3/attribution/installs` is public (pre-auth) and gated by
`X-P202-App-Token`. It is routed next to `GET /attribution/schema`
(`api/v3/index.php:131-153`) and rate-limited the same way: `softIpRateLimit`,
keyed on `REMOTE_ADDR`, never on `X-Forwarded-For`. It is not under
`/.well-known/`, because no platform dictates this URL: our SDK chooses it.

A `GET` on the same path answers `{"status":"ready"}` from constants, like the
postback receivers' probe, so the Setup page can check reachability from the
browser. Unlike Apple, Google imposes no HTTPS or port rule, but the SDK
refuses `http://` outside debug builds.

Request body (JSON, capped at 16 KB):

```json
{
  "install_uuid": "8d7c…",          // SDK-generated v4 UUID, required
  "package_name": "com.example.app", // must equal the token's app
  "store": "google_play",            // google_play | huawei | samsung | xiaomi | unknown
  "referrer": {
    "install_referrer": "p202=…&utm_source=…",   // may be absent (sideload, non-Play)
    "referrer_click_timestamp_seconds": 1727200000,
    "install_begin_timestamp_seconds": 1727200042,
    "referrer_click_timestamp_server_seconds": 1727200001,
    "install_begin_timestamp_server_seconds": 1727200043,
    "install_version": "3.2.0",
    "google_play_instant": false,
    "status": "ok"                   // ok | feature_not_supported | service_unavailable | permission_error
  },
  "first_open_at": 1727200100,
  "app_version": "3.2.0",
  "sdk_version": "1.0.0",
  "os_version": "15",
  "test": false,
  "integrity_token": null            // phase 3
}
```

What the endpoint does:

1. **Validate strictly** (error pattern #4). Malformed JSON, a wrong type or an
   unknown `store` is a 400 naming the field. The SDK treats 400 and 413 as
   terminal and 429 and 5xx as retry.
2. **Resolve the app** by token. Unknown token → 404. `package_name` ≠ the
   app's package → 422 that names both values. A leaked token pointed at a
   different package is then visible as a rejection, not stored.
3. **Store the install row first** (§3.5), deduped on
   `(attribution_app_id, install_uuid)`. A replay answers 200 with the stored
   outcome and `duplicate: true`, as the iOS receiver does.
4. **Classify the referrer** into `match_state`:

   | `match_state` | Meaning |
   |---|---|
   | `attributed` | Token verified, click found, plausible → conversion recorded |
   | `organic` | Play's organic referrer (`utm_source=google-play&utm_medium=organic`) or no referrer from Play |
   | `third_party` | A referrer we did not mint: Google Ads `gclid`, Meta's `utm_content` envelope, another tracker. Stored with its parsed fields, no conversion |
   | `bad_token` | `p202=` present but the MAC fails, or the click id is malformed |
   | `foreign_click` | Token valid, but the click belongs to another user, or to a campaign not linked to this app (§3.7) |
   | `implausible` | The timing checks failed (§4.1) |
   | `outside_window` | Install later than the app's attribution window |
   | `duplicate_click` | The click already has an install conversion (a second device installed from one click) |
   | `pending_click` | Token valid but the click row does not exist *yet*, see step 6 |
   | `unavailable` | The referrer API answered FEATURE_NOT_SUPPORTED, SERVICE_UNAVAILABLE or PERMISSION_ERROR |

5. **Record the conversion** for `attributed`, through the one transactional
   writer, `MysqlConversionRepository::record()`
   (`202-config/Conversion/MysqlConversionRepository.php:120-298`):
   - `transaction_id = 'p202-install'`. The existing `UNIQUE (click_id, transaction_id)`
     then guarantees **one install conversion per click** at the database,
     whatever the SDK or a replayer sends.
   - `pixel_type = 4` (new, "app install"), so reports can tell installs from
     pixel and postback conversions.
   - `conv_time` is Google's `install_begin_timestamp_server_seconds` when
     present, otherwise the time we received the install.
   - Payout is the campaign's, and the click-side update is the same one
     `p202RecordConversion()` applies: `click_lead`, `click_filtered` and CPA.
   - The conversion id is written back onto the install row.
6. **The race with the click writer.** For non-cloaked links the click row is
   inserted after `fastcgi_finish_request()`, so an install reported
   quickly, or a click writer that failed, can find no row. That is not a
   rejection. The row stores as `pending_click`, and the reconciliation cron
   (§3.8) retries it for a bounded period (default 24 h) before settling it as
   `bad_token` with the reason "click never recorded".
7. **Fire the traffic source's postback** after the conversion commits. This
   uses the same `202_ppc_account_pixels` logic `gpb.php` runs (`:166-240`),
   extracted into one shared function so the two cannot drift (error pattern
   #5). This is what lets Prosper202 send server-to-server *install* postbacks
   to ad networks, which is the main thing a CPI buyer needs.
8. **A failure after a commit is reported as committed** (error pattern #13).
   If the install row committed but the conversion or pixel step threw, the
   response is still 200 with `match: "pending"`, and the cron completes it.
   The SDK must never be told to retry something that already landed.

Response: `{"data":{"install_id":…,"match":"attributed","duplicate":false}}`.
Phase 3 adds deferred-deep-link fields here (§3.9). The response never returns
click data the referrer did not already carry, because the token is public.

### 3.4 In-app events (phase 2)

`POST /api/v3/attribution/installs/{install_uuid}/events` accepts
`{event_id, event_name, occurred_at, revenue?, currency?}`.

- Each event becomes a conversion on the install's click with
  `transaction_id = 'p202-evt:' . event_id`. It deduplicates per click at the
  database and is recorded only for `attributed` installs.
- **Revenue is untrusted.** Anyone holding the app token can post an event.
  The server-side event map decides revenue, which is the Android form of the
  iOS conversion-value rules: a per-app `event_name → revenue` table, with
  revenue kept server-side exactly as `/attribution/schema` withholds it.
  Client-reported revenue is stored in its own column and used only when the
  app opts in (`trust_client_revenue`, default 0). Reports show which regime
  produced each number.
- Unmapped event names are stored and counted as "unmapped", never
  discarded and never credited.

### 3.5 Data model

New table `202_attribution_installs`, one row per reported install:

| Column | Notes |
|---|---|
| `install_row_id` bigint PK | |
| `user_id`, `attribution_app_id` | owner resolved via the app token |
| `install_uuid` char(36) | `UNIQUE (attribution_app_id, install_uuid)` |
| `store` varchar(16) | |
| `click_id` bigint NULL | only once the token verifies |
| `conversion_id` bigint NULL | → `202_conversion_logs.conv_id` |
| `match_state` varchar(16), `match_reason` varchar(255) | the reason sentence is what the UI shows |
| `referrer_raw` varchar(2048) | Google documents no length limit; longer values are truncated with a flag, never rejected |
| `utm_source` … `utm_content`, `gclid` | parsed out of the referrer for `third_party` reporting |
| `click_ts_device`, `install_begin_ts_device`, `click_ts_server`, `install_begin_ts_server` | int NULL; the **server** pair is what the checks use |
| `install_version`, `app_version`, `sdk_version`, `os_version` | |
| `first_open_at`, `received_at`, `created_at`, `settled_at` | |
| `is_test` tinyint, `integrity_state` varchar(16) | `not_provided` / `valid` / `invalid` / `unverifiable` (phase 3) |
| `raw_payload` text, `remote_ip` varchar(45) | forensics; never served by the API, as with postbacks |

Why not reuse `202_attribution_postbacks`: its identity columns are Apple's
(`ad_network_id` and `transaction_id NOT NULL`, `app_id bigint NOT NULL`,
`attribution_signature NOT NULL`). An install is click-level and signature-less,
so fitting it into that table would mean either faking those values or
loosening every one, and the iOS report's trust arithmetic reads those columns.
Keeping the two tables apart keeps both reports honest. The Mobile Apps page
presents them together (§3.7).

Also:

- `202_attribution_install_events` (phase 2):
  `(install_row_id, event_id) UNIQUE`, name, revenue fields, `conversion_id`.
- `202_aff_campaigns.attribution_app_id` NULL, the optional campaign → app
  link used by the `foreign_click` check and the link builder.
- The upgrade rung is `1.9.76 → 1.9.77`. It follows the 1.9.76 pattern
  exactly: installer definitions reused, reconcile-then-advance,
  version persisted only on full success, and pinned by an
  `AttributionUpgradeStepTest`-style test. `app_id` becoming `NULL`-able is a
  `MODIFY COLUMN` on a table that can already hold rows, so the step must be
  tested against a populated 1.9.76 schema, not only a fresh one.

### 3.6 Testing installs without the Play Store

A real referrer exists only for a build installed *from Play* (the internal
testing track works). Developers need a faster loop:

- **Test installs.** A debug build of the SDK can send `test: true` with a
  referrer the developer supplies. It is the same pipeline, stored
  `is_test = 1`. With `accept_test_installs = 0` it is classified and shown
  on the app page but records **no conversion** and counts nowhere, which is
  the same live-policy semantics as `accept_development_postbacks`. Anyone can
  send `test: true`, which is exactly why it is untrusted by default.
- **`p202 attribution install simulate <app-id> --click <click_id>`** mints a
  real token for a real click (authenticated, `attribution:write`) and posts it
  as the SDK would. It exercises the real path end to end (error pattern #9),
  and it is what the live integration test and the agent-eval case use.

### 3.7 Reporting and UI

- **Campaign reports need no change to show installs.** An attributed install
  is a row in `202_conversion_logs`, so EPC, ROI, conversion rate and every
  breakdown (c1–c4, keyword, GEO, device) already include it. This is the
  largest functional gain over iOS, where postbacks can never join a click.
- **Analyze › Mobile Apps** gains a platform switch. The Android view reports:
  - installs by `match_state`, with the default counting only `attributed`
    (§4.1; the same "report counts only the trusted class, the rest are visible
    beside it" rule as `meta.trusted`);
  - click-to-install time (CTIT) distribution. It is both a funnel metric and
    the main click-injection signal;
  - organic vs attributed, the `third_party` sources (gclid, Meta), and events
    with revenue by regime.
  - The postbacks tab has an **Installs** equivalent: individual rows with
    their `match_reason`.
- **Setup › Mobile Apps (Android app page)**:
  - the app token with Reveal, Copy and Rotate;
  - the Gradle and init snippet filled in with this install's URL;
  - a reachability check of the intake URL;
  - the **store-link builder**: pick a campaign, get the exact
    `aff_campaign_url` with the encoded referrer and token, and optionally
    write it to the campaign and set its `attribution_app_id`. The campaign form
    accepts only `http(s)://` URLs (`aff_campaigns.php:72-76`), which a
    `https://play.google.com/...` link satisfies. `market://` stays refused
    because the web redirect must work on desktop too;
  - the newest ten installs with their match state;
  - the test-install opt-in.
- Markup is copied from `202-account/ui-kit.php` per the UI standard.
  `ComponentClassIsConsumedTest` and the browser pass cover it.

### 3.8 Cron

`202-cronjobs/attribution-installs.php`, run hourly, does three things:

1. Settles `pending_click` rows, and retries rows whose conversion or pixel
   step failed after commit.
2. Prunes. The retention classes mirror `PostbackReceiver::PRUNE_CLASSES`:
   - `bad_token`, `foreign_click`, `implausible` and untrusted test rows after
     90 days;
   - `organic`, `third_party` and `unavailable` rows after a configurable
     window (default 180 days, matching Google Ads' gclid guidance);
   - `attributed` rows are kept for as long as their click is.

   Malformed overrides prune nothing and are named in the log, as
   `retentionDays()` already does.
3. (Phase 3) Decodes queued Play Integrity tokens off the request path.

### 3.9 Phase 3 extensions, each separable

- **Play Integrity.** The SDK requests a *standard* integrity token with
  `requestHash = SHA-256(canonical install body)`. Verifying it needs the
  **app developer's** Google Cloud project: standard tokens decrypt only
  through Google's `decodeIntegrityToken`, which needs a service account.
  Google's default quota is 10,000 requests and 10,000 decodes per app per
  day. Prosper202 would hold that credential per app, encrypted at rest and
  redacted like the app token. Verdicts (`PLAY_RECOGNIZED`, `MEETS_DEVICE_INTEGRITY`)
  set `integrity_state`, and an app can require `valid` to attribute.
- **Meta install referrer.** Meta's `utm_content` carries AES-256-GCM
  ciphertext. It is decrypted with the per-app 64-hex key from Meta's
  dashboard, via `openssl_decrypt('aes-256-gcm', …)` with the 16-byte tag split
  off the end. That yields campaign, ad set and ad ids for `third_party` rows,
  so Meta-driven installs can be reported by campaign without a click on our side.
- **Other stores** (Huawei, Samsung, Xiaomi): SDK modules plus a `store`
  value. Huawei's timestamps must be converted from milliseconds.
- **Deferred deep links.** The intake response returns a deep-link path the
  operator set on the campaign, so the app can route a new user to the
  advertised content on first open. Prosper202 could also serve
  `/.well-known/assetlinks.json` for App Links. That is optional and
  per-domain, and needs the Play App Signing fingerprint.

### 3.10 API, CLI, docs

- **API:**
  - `/attribution/apps` accepts `platform=android` and `package_name`;
  - new `GET /attribution/installs` (filters: `app_id` or `package_name`,
    `match_state`, `store`, `time_from`/`time_to`, `is_test`) and
    `GET /attribution/installs/{id}`;
  - `GET /attribution/installs/report?group_by=day|app|match-state|store|campaign|ctit-bucket`;
  - public `POST /attribution/installs` and its events route;
  - scope area `attribution` (reads `attribution:read`, simulate
    `attribution:write`);
  - `GET /capabilities` adds `features.android_install_referrer`.

  Every new route goes through `ScopeCoverageTest` and `ApiKeyAuthPathScopeTest`.
- **CLI (Go):** `p202 attribution app create --platform android --package com.x --name …`,
  and `p202 attribution install list|get|report|simulate`. All of it follows the
  agent-actionable error contract in `CLAUDE.md`: validation category, `%w`
  wrapping, and a hint naming `p202 attribution app list` when a package or
  token is unknown.
- **Docs:** `documentation/api/20-android-install-tracking.md`, the SDK README,
  `docs/openapi.yaml`, `documentation/cli/10-go-cli.md`, and a cross-link from
  `19-attribution-postbacks.md`, whose "Android is not supported" text goes
  away.

### 3.11 The Android SDK (`sdk/android-attribution/`)

A small Kotlin library, the counterpart of `sdk/ios-attribution/`.

- **Dependencies:** `com.android.installreferrer:installreferrer:2.2`
  only. That is the latest release, from January 2021, and the only way to
  read the referrer. No Google Play Services, no WorkManager.
- **API:**

  ```kotlin
  P202Attribution.configure(context, endpoint, appToken)
  P202Attribution.logEvent(name, eventId = UUID, revenue = null)
  ```

  `configure` does the rest on first launch.
- **Behaviour:**
  - Reads the referrer **once**, as Google instructs, and persists the
    result. The referrer is available for 90 days, so a failed report can be
    retried on later launches.
  - Posts with exponential backoff. 400 and 413 are terminal and logged;
    429 and 5xx are retried.
  - Events queue locally until the install report has been acknowledged.
- **`install_uuid`** lives in a SharedPreferences file **excluded from Auto
  Backup** (`dataExtractionRules` / `fullBackupContent`). Otherwise a restore
  onto a new phone carries the old id, and a genuine new install dedupes away
  as a replay.
- **No advertising ID.** The SDK does not read GAID. It therefore needs no
  `AD_ID` permission, adds nothing to the app's Play Data safety declaration
  beyond "app interactions / diagnostics", and does not depend on consent flows.
  Attribution does not need GAID when the referrer carries the click.
- `minSdk 21`. JVM unit tests cover referrer parsing, backoff and queueing. A
  live-server integration test, like the iOS one, runs against a running
  instance using a test install.

## 4. Non-functional requirements

### 4.1 Security (the threat model drives the design)

The endpoint is public, and the token that gates it ships in every APK. So
**the app token is an identifier, not a credential.** Everything below holds
on the assumption that an attacker has it.

| Threat | Mitigation |
|---|---|
| Forge an install for an arbitrary click, to collect a CPI payout or poison EPC | The HMAC install token: only referrers our redirect minted verify. `bad_token` rows count nowhere |
| Replay one genuine referrer many times | `UNIQUE (click_id, 'p202-install')`: at most one install conversion per click, enforced by the database, not by code |
| **Click spamming**: generate many cheap clicks, harvest their tokens, report fake installs | The residual risk, and it is inherent to the referrer model: whoever can click can obtain a valid token. Mitigations, in order of cost: (a) Google's **server** click timestamp must be within minutes of our `click_time` for that click, so a token harvested from our redirect cannot be paired with a fabricated referrer time; (b) the report shows the CTIT distribution and flags implausibly short (under ~10 s, click injection) and long tails; (c) per-app, per-IP caps on the intake; (d) phase 3 Play Integrity (`PLAY_RECOGNIZED` + device integrity), which is the only control that proves a real app on a real device |
| Click injection (a malicious app on the device fires a click just before install completes) | Compare Google's server `referrer_click_timestamp` with `install_begin_timestamp`. A click after install-begin is `implausible` |
| Row-minting DoS on the public endpoint | 16 KB body cap, `softIpRateLimit` keyed on `REMOTE_ADDR` (error pattern #16), retention classes for every untrusted state, and the same injective bucket naming the postback receiver needed (error pattern #17) |
| Token leaked to a competitor's app | `package_name` must match (a 422, visible to the operator) and the token can be rotated. Rotation is a release-coupled step for the developer, and the Setup page says so |
| Malformed or unknown values resolving permissively | A missing HMAC key, an unparseable referrer or an unknown store never attribute (error pattern #11). They store with a reason and count nowhere |

The trust default in reports is: headline install and revenue numbers count
`attributed` rows with `is_test = 0` (or opted-in), and everything else is
visible in separate columns. That is the same stance `meta.trusted` takes for
postbacks.

### 4.2 Privacy and compliance

- No device identifiers are collected. The only link to a person is the
  click, which Prosper202 already holds with its IP, as it does today.
- `referrer_raw` can contain whatever an operator put in the link. The docs
  tell operators not to put personal data in `utm_*`.
- Retention windows are configurable (§3.8). The SDK README gives developers
  the exact Data safety answers.
- Play policy: the SDK uses only the Install Referrer API and no advertising ID,
  so the advertising-ID policy does not apply.

### 4.3 Performance and capacity

- The intake does indexed lookups (token → app, click by PK) and one short
  transaction, the same shape as `gpb.php`. The target is p95 < 100 ms. No
  external call is made on the request path: pixel firing to traffic
  sources follows the same pattern `gpb.php` uses today and moves after the
  response where `fastcgi_finish_request` exists; Integrity decoding is
  queued to cron.
- Volume is about one request per install plus one per event, orders of
  magnitude below click volume. The redirect hot path gains only one HMAC in
  the first substitution pass (microseconds) and no query.
- Indexes: `(user_id, received_at)`, `(attribution_app_id, received_at)`,
  `(click_id)`, `(match_state, received_at)` for the cron and pruning.

### 4.4 Reliability and correctness

- **Exactly-once install conversions** come from database uniqueness at two
  levels, not from retry logic: install rows per `install_uuid`, and
  conversions per click.
  `uniq_click_transaction` also covers soft-deleted rows. If an operator
  deletes an install conversion, the same install can never re-record it. That
  is intended, and the docs have to say so.
- **At-least-once delivery.** The SDK retries until a 2xx. A database outage
  answers 503 and the SDK comes back. The 90-day referrer lifetime gives ample
  room.
- **Post-commit failures** complete via cron, and the response never invites
  a duplicating retry (error pattern #13).
- Every fallible call is checked, including the ones CLAUDE.md lists as
  silent: `get_result`, `store_result`, `prepare` (error pattern #1).

### 4.5 Compatibility

- **iOS behaviour unchanged.** Every existing attribution test must stay green,
  and a new structural test pins that iOS queries filter by platform
  (§3.1 hazard).
- **Runtimes:** PHP 8.3 (CI) and 8.4 (sandbox); MySQL 5.7 and 8, and MariaDB,
  for the `NULL`-able `UNIQUE` column.
- **Android:** API 21+ and Play Store app 8.3.73+, the referrer library's
  floor. Older devices report `unavailable`.

### 4.6 Observability and operability

- There is a `GET` probe on the intake, and a reachability check on the Setup
  page.
- Every non-attributed row carries a `match_reason` sentence. That answers
  "why didn't my install count" without log access.
- The cron prints its resolved retention windows and backlog, as
  `attribution-retention.php` does.

### 4.7 Verification plan (what "done" means)

This follows CLAUDE.md error patterns #9 and #10: the seam is the product, so
the seam gets exercised.

1. Unit tests:
   - referrer parser (encoded, double-encoded, organic, gclid, Meta envelope,
     truncated, hostile);
   - token mint and verify (including the fail-closed missing key);
   - plausibility rules;
   - package-name grammar;
   - the raw-payload `app_id`/`package_name` invariant.
2. Structural tests:
   - `StaticSqlSchemaTest` covers every new statement;
   - scope coverage for the new routes;
   - iOS queries filter by platform.
3. **A live pass against a running instance**, using `tests/live/` and the
   agent-eval fixture:
   - create a click through the real `dl.php`, and read the Play URL from the
     `Location` header;
   - check the token is in it, correctly encoded;
   - POST it as the SDK would;
   - assert the `202_conversion_logs` row, `click_lead = 1`, a second POST
     answering `duplicate: true`, a second `install_uuid` for the same click
     answering `duplicate_click` with no second conversion, a tampered MAC
     answering `bad_token`, and the traffic-source pixel fired.

   Every negative case asserts the specific state and reason, not merely "no
   conversion" (the "'Not succeeded' is not 'refused'" rule).
4. An agent-eval case under `tests/fixtures/agent-eval/cases/`: register an
   Android app, build a store link, simulate an install, and read the report.
5. SDK: JVM unit tests, plus a live integration test against the same
   instance.

## 5. Phasing

| Phase | Scope | Why this order |
|---|---|---|
| **1** | Registry (`package_name`, `NULL`-able `app_id`, platform guards), the signed install token, the intake endpoint and install table, conversion recording, the traffic-source postback, the pending-click cron, the Setup page with store-link builder, the minimal Mobile Apps report, API/CLI/docs, the SDK (install only), test installs and `simulate` | The smallest thing that makes a CPI campaign measurable end to end with payouts in ordinary campaign reports |
| **2** | In-app events and the server-side event → revenue map; CTIT reporting and fraud flags; `third_party` breakdowns (gclid) | Most app campaigns optimise on a post-install event, not the install |
| **3** | Play Integrity, Meta referrer decryption, Huawei/Samsung/Xiaomi, deferred deep links and `assetlinks.json` | Each needs credentials the app owner holds, or a separate store SDK, and each is independently shippable |

## 6. Decisions needed from you before implementation

1. **Is an install a conversion by default?** The plan says yes, per app
   (`count_install_as_conversion`), because CPI is the common Android deal. If
   your users mostly pay out on a purchase, the default should flip.
2. **Fire traffic-source postbacks on install?** The plan says yes, and reuses
   the `gpb.php` pixel logic. This is the feature ad networks will ask for, but
   it means an install reaches third parties automatically.
3. **Play Integrity in phase 1 or phase 3?** It is the only real defence
   against click spamming, but it needs each app owner to set up a Google Cloud
   service account and hand Prosper202 its credentials.
4. **GAID: never, or opt-in?** The plan collects none. Some ad networks want a
   device id in the install postback, and adding it later means an `AD_ID`
   permission and consent handling in the SDK.
