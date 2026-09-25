# Measurement rewrite: app measurement (iOS + Android) and multi-touch attribution

Status: **in progress.** PR 0 (legacy endpoints) and PR 1 (the conversion ledger) are built; the rest is proposal.

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
| E (§10) | Moving the whole app onto the v2 UI shell |

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

 Android install/goal  ─► app measurement ─► ConversionRecorder ─► 202_conversion_logs
 every web path (pixels, postbacks, API,  ───────────┘                 │
 uploads, ClickBank, web goals)                                        │
                                                        outbox row (same transaction)
                                                                       ▼
                                                              MTA: journey + credits
```

**Rule:** the features share three core things owned by neither: the
conversion writer (§2), the goals engine (§2.2) and the identity graph
(§6.2). Nothing else is shared. A table prefix, a scope area, a CLI parent or
a report shared between them would couple two things that change for
different reasons.

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
row in `202_attribution_pending (conv_id PK, enqueued_at, reason)` goes in beside the
conversion, and the MTA worker consumes it. The alternative, a post-commit
callback, is rejected:

- **An outbox is atomic and at-least-once.** A conversion either exists
  with its pending row or does not exist at all, so no conversion is ever
  missed. A worker can still die after claiming a row and process it twice,
  which is why the worker's credit and journey writes are idempotent (§6.3):
  the exactly-once *effect* comes from those, not from the outbox. A
  post-commit hook has neither property: it can die between the commit and
  the hook, and that journey is silently never built (error pattern #13).
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

**The outbox table is part of the conversion schema, not of MTA.**
`202_attribution_pending` is defined in `ConversionTables` beside
`202_conversion_logs` and created by the same rung; an insert into it failing
fails the conversion transaction like any other schema failure would, and is
reported the same way. What recording is isolated from is the MTA *engine*:
a worker that is down, a credits table that is missing, a model whose config
is invalid. In every one of those cases rows accumulate in the outbox and
are processed when the engine is fixed. That is a backlog, not a lost
journey.

### 2.1 The conversion ledger: every amount, where it came from, and how it rolls up

**The requirement.** A click showing $10 must be explainable as, for example:

| Amount | Counted in the $10? | Source | Linked to |
|---|---|---|---|
| $5.00 | yes | Android install | goal "Install" v1, campaign "Summit CPI" |
| $3.00 | yes | App event `level_reached` | goal "Reached level 3" v2 |
| $2.00 | yes | Global postback | transaction `A-7731` |
| $0.00 | no, unpaid | App event `tutorial_complete` | goal "Tutorial" v1 (tracked, not paid) |

Paid and unpaid outcomes are both visible, and each one is linked to whatever
generated it.

**What exists today, from reading the code:**

- **A click can hold several `202_conversion_logs` rows.** Each is keyed by a
  distinct transaction id (`UNIQUE (click_id, transaction_id)`), and each
  keeps its own amount, `pixel_type`, IP, user agent and time. That is the
  basis of the documented **Transactions ID** funnel feature
  (`documentation/setting-up-prosper202-pro/999-transactions-id.md`): each
  funnel step posts its own conversion with a transaction id.
- **Nothing is called a "sub-conversion" or "sub-subid".** Nothing rolls rows
  up into the click. The click's income is one value that the latest
  conversion overwrites (§5.5).
- **Several paths wrote no row at all**, so their amounts could never be
  broken down:
  - the revenue CSV upload (`tracking202/update/upload.php:128-165`) summed per
    subid within a file and wrote only the total. **Done (PR 1):** every line
    is a ledger row of its upload batch (`RevenueUploadImporter`); see
    point 3.
  - the legacy `px.php` / `pb.php` pixels only flagged the click, and the
    ClickBank endpoint (`cb202.php`) overwrote `click_payout` with the order
    total. **Done (PR 0, 2026-09-25):** all three now record through
    `p202RecordLegacyConversion()` in `202-config/static-endpoint-helpers.php`,
    with the gpx one-conversion-per-click gate when no id is present, pb's
    campaign scope and px's owner check applied before any write, and the
    ClickBank receipt as the transaction id so repeated INS deliveries
    de-duplicate and two sales are two rows. `tests/live/legacy-pixels.sh`
    drives all three against a running instance.
- **A row cannot say what produced it.** `pixel_type` distinguishes only
  pixel (1), postback (2), universal pixel (3) and "other" (0). The V3 API
  and the manual subid upload are told apart only by an empty user agent
  versus the string `subid-upload`.
- **No per-click view exists.** Group Overview's **Transaction ID** level
  joins conversion rows to the click (`202-config/ReportSummaryForm.class.php:907-909`)
  but adds up the click's single income figure. So each transaction row shows
  the click's last payout, and a click with N transactions is counted N times.
  That is a live defect, fixed below. The LTV customer panel does list
  revenue events, but per customer, not per click, and only for conversions
  linked to a customer.

**The design: `202_conversion_logs` becomes the ledger, and the click total
becomes a cache of it.**

1. **Every path writes a row.** `px.php`, `pb.php` and ClickBank do since
   PR 0 (one row per receipt, the receipt as the transaction id, so duplicate
   INS deliveries de-duplicate). PR 1 adds the last one: the CSV upload writes
   one row per CSV line, tagged with its upload batch, and gives the PR 0 rows
   their `source` value (`legacy_pixel`, `clickbank`).
2. **Provenance columns** on every row:

   | Column | Values |
   |---|---|
   | `source` | `pixel`, `postback`, `universal_pixel`, `api`, `subid_upload`, `revenue_upload`, `legacy_pixel`, `clickbank`, `app_install`, `goal`, `legacy_baseline` (below) |
   | `source_ref` | What generated it: goal id and version, upload batch id, API key id, app install row. Resolved by the UI into a name and a link |
   | `event_name` | The event that reached the goal, or the postback's `event=` value |
   | `payable` | `1` counts toward income and leads. `0` is a tracked outcome on a click: an unpaid goal, or an event reported for visibility. Outcomes on a subject with no click (an organic install) live only in `202_goal_outcomes`, §5.5 |
   | `superseded_by` | Set in `replace` mode when a later payable row replaced this one's value, so the breakdown can say *why* a row is not in the total |
   | `superseded_reason` | Why (PR 1 added it, because "superseded" has owners): `replace` and `batch` are derived by the recompute and cleared by it when the row that replaced this one is deleted; `pre_ledger`, `replay` and `reevaluation` are decisions made once elsewhere, which the recompute never touches |
   | `reverses_conv_id` | On a reversal, the row it reverses (indexed lookups; `source_ref` also names it as `conv:<id>`) |

   A reversal (below) is a row with a negative amount whose `source` is the
   path it arrived by and whose `source_ref` names the row it reverses.

   `pixel_type` is kept as it is, for compatibility.
3. **The click total is derived from the ledger, never written on its own.**
   `record()` and `softDelete()` recompute the click's cached `click_payout`
   and `click_lead` from its rows under the click lock they already hold.
   Reversal rows (below) never compete for "latest": they net against the
   row they reverse.
   - `accumulate` campaigns: the sum of payable, non-deleted rows, reversals
     included.
   - `replace` campaigns: the latest payable, non-deleted, non-reversal row,
     **plus the reversals that name it**. A $3 sale reversed is $0, not −$3.
     Earlier rows are marked `superseded_by`. For a click without reversals
     the number is the same one those campaigns show today.
   - **The CSV upload's unit is the batch, not the line.** A file with three
     lines for one click writes three rows carrying one batch id, and the
     click's uploaded value is the **sum of the newest batch's rows** for
     that click, which supersedes earlier batches' rows and earlier plain
     rows. That is today's "sum within the file, replace across files",
     kept exactly, with the lines now visible.
   - **A click converted before the upgrade holds its value in its cache,
     not in its rows.** Its rows (if any) were overwritten by later writes
     or undercount a revenue upload that left none, so they cannot be
     re-added into the value; and a click cleared before the upgrade still
     has its old rows. So (as built in PR 1) the upgrade marks **every
     pre-existing row** `superseded_reason = pre_ledger`, and the first
     ledger write on a click that is still a lead with no ledger-managed row
     inserts a **`legacy_baseline`** row (amount = the cached
     `click_payout`, `dedupe_key = legacy`, payable) under the same lock and
     points the old rows at it. The recompute then preserves the income, the
     breakdown shows where it came from, and a conversion cleared before the
     upgrade cannot come back. Such clicks are never recomputed on their
     own. Historical amounts are not otherwise backfilled, because their
     individual parts were never stored.

   The earlier objection to summing rows was that uploads write none. It
   disappears once every path writes rows.
4. **Default payouts come from the goal or the campaign, never from the
   cached `click_payout`.** Today an amount-less conversion reads that field
   (`MysqlConversionRepository.php:165`), which in `accumulate` mode is a
   running total.
5. **The breakdown is a first-class read:**
   - `GET /api/v3/clicks/{id}/conversions` returns every row with its amount,
     `payable`, `source` and resolved `source_ref` (goal name and version,
     batch, key), its transaction id and time, and whether it is counted,
     with the reason when it is not (`unpaid`, `superseded`, `deleted`,
     `duplicate`);
   - the click row in the Visitors / click-history views opens that
     breakdown;
   - `GET /conversions` gains `click_id`, `source` and `goal` filters and
     returns the provenance columns.
6. **Reports can group by what generated the value.** Group Overview gains a
   **Goal / source** level whose income sums **ledger rows**. The broken
   **Transaction ID** level is fixed the same way: it sums the rows' own
   amounts, so a click with three transactions shows three amounts that add
   up to the click's income.
7. **Leads stay "converting clicks".** A click is a lead if it has at least
   one payable, non-deleted row, whatever its net value after reversals.
   Unpaid outcomes are counted separately, as events.

**Built in PR 1** (the paths, and what each now does):

- `MysqlConversionRepository::record()` builds the row's key
  (`DedupeKey`), carries a pre-ledger click in (`ensureManaged`), inserts,
  recomputes the click from its rows (`MysqlConversionLedger`, rules in
  `ClickValueCalculator`) and writes the MTA outbox row, in one
  transaction. `softDelete()` and the new `clearClicks()` do the same.
- `ClickValueWritersTest` fails on any UPDATE of `click_lead` or
  `click_payout` outside the ledger. The one other writer it allows is the
  offer redirects' routing seed (`off.php`, `offrtr.php`), which now only
  touches a click that has not converted (`AND click_lead = 0`).
- The static endpoints' click update (`p202ApplyConversionUpdate`) became
  `p202ApplyConversionClickSide` (CPA cost and the filtered flag only).
  The subid-upload, delete-subids and clear-subids pages record and clear
  through the ledger, and carry and check the session token; the revenue
  upload applies a report by token-checked POST (it ran from a GET) and
  reads the whole file (it read the first 100,000 bytes).
- The traffic-source pixel (`p202FireTrafficSourcePixels`, used by gpb and
  upx) fires after recording, only for a newly recorded conversion that is
  not a reversal, for every pixel row of the account, with
  `[[transactionid]]` and the conversion's own `[[payout]]`. It used to fire
  before recording, on replays and failed writes too, and read only the
  account's first pixel row. gpb answers 500 when recording fails.
- gpx, upx and gpb read click ids with the exact parser PR 0 gave px and pb;
  a present-but-malformed value is refused, never cast or sent to the IP
  fallback.
- Deferred to PR 1b: `source_ref` naming the API key that wrote an API row
  (the key id is not available to controllers today).

**Compatibility.**

- In `replace` mode (the default), income per click is unchanged for every
  existing campaign.
- **One real change, stated as such:** the legacy pixels, ClickBank and the
  CSV upload start writing conversion rows. On upgraded installs those
  conversions appear from the upgrade on in conversion lists, the API and MTA.
  Before, they changed the click and left no trace. Historical clicks are not
  backfilled, because their individual amounts were never stored.

#### Transaction ids in the ledger

Transaction ids stay, and matter more than before. They do three jobs today,
mixed into one column. The ledger separates them.

| Job | Today | After |
|---|---|---|
| **Stopping duplicates** | `UNIQUE (click_id, transaction_id)`. A blank id is stored as `NULL` and never deduplicated (`MysqlConversionRepository.php:127-130`), so every retry of a blank-id postback adds a row | Its own column, **`dedupe_key`**, which is never empty. `UNIQUE (click_id, dedupe_key)` |
| **Linking to the network's records** (disputes, reversals, reconciliation) | The same column | **`transaction_id`** keeps exactly this job: the id the network or merchant sent, shown and searchable, `NULL` when none was sent |
| **Telling funnel steps apart** (the Transactions ID recipe) | One campaign copy and one id per step | Goals and events (§2.2). The id is no longer the thing that says *which step* this was |

**Why a separate `dedupe_key`.**

- **Goal and install rows need ids the network never sent.** The earlier
  draft of this plan put synthetic values such as `p202-goal:12:1` into
  `transaction_id`. That shares a namespace with network ids: a network
  that happens to send `p202-install` would collide with ours (error pattern
  #17), and the column would stop meaning "the network's id".
- **Every source gets a namespaced key** instead, built by one function so the
  prefixes cannot drift:

  | Source | `dedupe_key` |
  |---|---|
  | Network or merchant id (a ClickBank receipt is one) | `tx:<id>` |
  | Goal | `goal:<goal_id>:<goal_version>:<n>:<event_id>` (the version is in the key so a re-evaluation under a new version writes its own rows; the event is, so a replay that moves the *n*th outcome to an earlier event writes a new row instead of colliding with the one it supersedes, §5.5) |
  | Install (the built-in `install` goal's row, never a second `goal:` row) | `install` |
  | App or web event | `evt:<subject_type>:<subject_id>:<event_id>` (an event id is unique only within its subject, §2.2) |
  | Reversal | `rev:<original conv_id>:<reversal ref>` (below) |
  | Legacy baseline | `legacy` |
  | CSV upload | `up:<batch>:<line>` |

  A prefix before the colon cannot occur inside another source's key, so two
  sources can never produce the same key.

**The blank-id rule, which the ledger forces.** In `replace` mode a
duplicated blank-id postback adds a row but does not change the click's
value, so today it is mostly harmless. In `accumulate` mode it **doubles the
money**. So:

- **In `accumulate` mode, a payable row with no id of its own** (no
  transaction id, goal, event id, receipt or upload line) is treated as the
  campaign's single plain "conversion". Its key is `conversion`, so it can
  happen **once per click**.
  That is the rule `gpx.php:94` already applies to image pixels today, made
  uniform.
- **To record several amounts on one click, send something that tells them
  apart:** a transaction id, an `event=`, or an event id. The breakdown then
  names each one.
- `replace` campaigns keep today's behaviour exactly: blank-id rows still
  record, with a per-row key (`row:<conv_id>`), and the latest wins.
- **Existing rows** are keyed on upgrade by the same function:
  `tx:<transaction_id>` where one was sent, `row:<conv_id>` where it was
  blank. Nothing merges and nothing is lost.

**Reversals by transaction id** (new, and only possible because the id keeps
its meaning).

- A postback carrying `status=reversed` or a negative `amount`, together with
  the transaction id of an earlier row, records a **reversal row**: negative
  amount, `transaction_id` = the original's (that is the linkage), `source_ref`
  = the original conversion, and its own dedupe key
  `rev:<original conv_id>:<reversal ref>`, where the reversal ref is the
  network's reversal id when it sends one and `1` otherwise. The key cannot
  collide with the original's `tx:<id>`, a replay of the same reversal is a
  duplicate, and a second distinct reversal of one sale is refused with a
  422 naming the first. The breakdown then shows "$3.00 sale, reversed
  −$3.00".
- The click's value recomputes from the rows, netting the reversal against
  the row it names (point 3 above), in both payout modes.
- Today such a postback is either answered as a duplicate and ignored, or
  recorded as an unrelated row.
- The LTV ledger already records negative payouts as adjustments
  (`MysqlConversionRepository.php:222-227`), so this makes the click side
  agree with it.

**Passing the id on to traffic sources.** The `[[transactionid]]` /
`[[t202txid]]` token exists in `replaceTokens()`, but nothing fills it.

- `gpb.php`'s traffic-source postback does not include it
  (`gpb.php:146-163`).
- The helper that would, `getTokens()` (`connect2.php:2918`), has no callers.

With several conversions per click, a network needs the id to tell them apart
and to dedupe on its side. The shared pixel-firing function (PR 1) therefore
fills `[[transactionid]]` / `[[t202txid]]` with the row's `transaction_id`,
falling back to its `dedupe_key`, next to `[[p202_goal]]` and
`[[p202_goal_value]]`.

### 2.2 Goals are a core feature, for web campaigns as well as apps

Goals (§5.5 has the full definition) evaluate **events** into **outcomes**.
Nothing in that design is specific to apps. What differs is the *subject*
whose progress is tracked:

| | Subject | Events come from |
|---|---|---|
| App campaign | The install (and through it, its click) | The SDK's `logEvent` |
| Web campaign | **The click** | A pixel or postback carrying `event=` (with optional `event_props` as JSON, and `amount`); `POST /api/v3/events` keyed by `click_id`; `p202.js`'s `track(name, props)` on landing and thank-you pages, tied to the click by the first-party ids of §6.2 |

- **Goal definitions attach to campaigns.** An app registration supplies a
  default set that its campaigns inherit. Payable goals, payouts and
  "notify traffic source" are configured per campaign, as in §5.5.
- **Events and progress are keyed by subject**, whichever platform reported
  them:
  - `202_goal_events (subject_type ENUM('click','install'), subject_id, event_id, name, properties JSON, occurred_at, received_at)`,
    `UNIQUE (subject_type, subject_id, event_id)`;
  - `202_goal_progress (subject_type, subject_id, goal_id, goal_version, count, sum, reached_at, times_reached)`.

  The app tables in §5.4 keep only what is app-specific.
- **Old web setups keep working unchanged.** A pixel or postback without
  `event=` is today's plain conversion: one ledger row, `source` =
  pixel/postback, no goal. Goals are opt-in per campaign.
- **Goals replace the Transactions ID workaround.** A funnel becomes one
  campaign with goals (opt-in → sale → upsell), each with its own payout,
  rolled up per click by `accumulate` and broken down by §2.1. Today it means
  copying the campaign once per step. The old recipe still works; the docs
  point to the new one.

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
ownership, app token and SDK contract, events, goals and revenue, public
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
                         │ Goals           events → outcomes → value   │
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
               │                         │   │ 202_app_installs             │
               └─────────────────────────┘   └──────────────────────────────┘
```

A class belongs in the core only if both sources call it.

## 4. App core reshape (PR 3)

PR 3 changes no user-visible iOS behaviour except the renames below. It is
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
| `202_attribution_conversion_values` | `202_goals` (core, §2.2) + `202_app_skan_encodings` | What an outcome is worth is split from how iOS encodes it |
| — | `202_app_installs` | Android (§5). Events are core (`202_goal_events`, §2.2) |

**The legacy guard is deleted, not extended.**
`_upgrade_attribution_legacy_skan_state()` and the "Upgrade paused" halt in
`_upgrade_attribution_tables()` (`functions-upgrade.php:307-350`) exist to
protect postbacks in the pre-release `202_skan_*` tables. Only a branch
deployment above 1.9.55 could hold those, and none exists.

- The 1.9.75 → 1.9.76 rung keeps one job: create the app, goal and ledger
  additions from their installer definitions.
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
- Android only: `attribution_window_days`, `trust_client_revenue`, and the
  Play Integrity mode (§5.6). Whether an install *pays* is not a registration
  setting: it is the campaign's payable-goal list (§5.5), where `install` is a
  built-in goal.
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
- **`GET /apps/schema`** is platform-shaped:
  - iOS gets an **evaluation-only** view of the goals — triggers, predicates,
    thresholds, `after`, windows, repeat rules and the SKAN encodings — with
    every `value` and every campaign payout stripped, and evaluates goals on
    the device (§5.5);
  - Android gets the integrity mode and the SDK settings. Its goals are
    evaluated on the server, so the SDK reports every event.

  Revenue is withheld from both.
- **Both SDKs** expose `configure(endpoint, appToken)`,
  `logEvent(name, properties)` and `setCustomerId(id, signature)`. The
  signature is the operator-server-computed `cust_sig` of §6.2, carried on
  the wire as `customer: {id, signature}` in the install and event bodies;
  the server verifies it before the id becomes an identity signal, and an
  id without a valid signature is stored for LTV only and links nothing.
  There is no one-argument form: the app token is public, so an unsigned id
  from the SDK is exactly the request-controlled `cust` a public pixel
  carries.

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

### 4.5 Goals and iOS encoding

A conversion-value rule currently does two jobs: *what an outcome is worth*,
and *how iOS encodes it in 6 bits*. Android needs only the first, and it needs
it to be far more expressive than "this event name" (§5.5). The split:

- **Goals, `202_goals`** (core, §2.2). Web campaigns and both app platforms use them. A goal is a named
  outcome ("install", "level 3", "first purchase ≥ $10") with its value. It is
  the only place revenue is configured. Goals are defined in §5.5.
- **`202_app_skan_encodings`**
  - Columns: `(user_id, registration_id, fine_value | coarse_value, goal_id, revenue_override NULL)`.
  - Apple only. An encoding says which fine or coarse value means "this goal
    was reached". `revenue_override` keeps tiered decoding.
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
  again. The purge deletes the user's rows in every `202_app_*` table with
  **one named exception**: `202_app_postbacks` rows are *released* — `user_id`
  set to 0 and `registration_id` to NULL — because they are Apple's record
  of a postback, not the user's data. Released rows then fall under the
  30-day unclaimed retention window (§4.6) and are pruned by it.
- **The Analyze page and report filters** move from raw `app_id(s)` to
  `registration_id(s)`. The postback's own `app_id` stays as a forensic column
  and filter.

## 5. Android on the core (PRs 4–7)

### 5.1 The click id in the store link

**The new token.** `[[p202_install_token]]` is added to `replaceTokens()` /
`replaceTrackerPlaceholders()` (`202-config/connect2.php:486-680,
2192-2255`). It expands to `<click_id>.<mac>`:

- `mac` = the first 12 bytes of
  `HMAC-SHA256(K_install, "p202-install-v1|" . click_id)`, in base64url.
- `[[subid]]` is not enough: sequential click ids let anyone credit an
  install, and a payout, to any click (error pattern #16).

**The key.**

- `K_install` is a random 32-byte secret. **It is minted by one idempotent
  function called from both paths that create a 1.9.76 schema**: the fresh
  installer (`INSTALL::install_databases()` / `DataSeeder`) and the
  1.9.75 → 1.9.76 rung. A fresh install never runs a rung, so a key minted
  only there would leave every new deployment with no key and, under the
  fail-closed rule below, no attributed install. The upgrade-equals-install
  test (§7.6) asserts the key exists on both paths.
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
3. **Open one transaction for everything durable that follows.** Steps
   3–6 — the install row, the classification, the conversion, the outbox
   rows — commit together or not at all. The `(registration_id,
   install_uuid)` row is inserted *inside* that transaction, so a failure
   anywhere before the commit leaves nothing behind and the retry does the
   whole job again. Making only the notification atomic with the conversion
   would leave a hole: a durable install row written first, then a failure
   in classification or `ConversionRecorder`, and every retry answered
   `duplicate: true` with the conversion, the MTA outbox row and the
   notification never written (error pattern #13, seen from the retry
   side). The invariant the transaction buys is **an `attributed` install
   row always has its `conversion_id`**; `ConversionRecorder` is the only
   writer of install conversions and it runs inside the same transaction as
   the row that points at it, so the invariant is a fact of the schema's
   write path, not of a retry's good luck. The lock order is install row
   (the `INSERT`), then the click `FOR UPDATE` inside `record()`, everywhere.

   Then **insert the install row**, idempotent on `(registration_id,
   install_uuid)`. A replay of a committed install answers 200 with
   `duplicate: true` and the stored `match`; a replay of one that never
   committed finds nothing and runs the flow. The settle paths — the
   pending-click cron of §5.3 and the Play Integrity decoder — use the same
   transaction shape: `UPDATE` the install row (`match_state`, `settled_at`,
   `conversion_id`) in the transaction that records the conversion and
   queues the notification, locking the install row `FOR UPDATE` first so a
   concurrent replay of the same install waits for the settle rather than
   reading the half-settled row.
4. **Classify** into `MatchState`. Classification is a pure computation over
   the referrer, the token and one click lookup; the one network call
   (decoding a Play Integrity token) is never made inside the transaction —
   `pending_integrity` is a stored state that a worker settles later, as
   above.
5. **Record the conversion** for `attributed` rows, via `ConversionRecorder`
   (§2):
   - **This row *is* the built-in `install` goal's row.** Its key is
     `install`, `transaction_id` is `NULL` because no network sent one, and
     the goal engine never writes a second `goal:` row for the install.
     `UNIQUE (click_id, dedupe_key)` makes one install conversion per click a
     database fact (§2.1, transaction ids).
   - `pixel_type = 4`, which is unused; 0–3 are taken.
   - `conv_time` is Google's server install-begin time.
   - The conversion is skipped when the campaign does not list `install` as
     payable; the install is still stored and reported.
   - The outbox row means MTA picks it up (§6).
6. **Queue the traffic source's notification, durably.** In the same
   transaction as the conversion, a row goes into `202_notification_pending`
   (`conv_id`, pixel id, `kind`, attempt count, next attempt), keyed unique
   on `(conv_id, pixel id, kind)` so a retried install cannot queue it
   twice (`kind` is `reached` here; §5.5 adds `correction` and
   `retraction`). The
   request may attempt the send right after commit, but the outbox row is
   the record: a worker sends whatever is still pending, with backoff, and
   marks the row done. `gpb.php:166-240`'s `202_ppc_account_pixels` logic is
   extracted into the one sender both the request path and the worker call.
   Without the outbox, a process killed between the commit and the send
   would leave nothing for cron to find, and a replay of the install exits
   as a duplicate before reaching the send.
7. **Commit, then answer.** A failure *before* the commit answers 500 with
   nothing stored, and the SDK's retry is safe by construction. A failure
   *after* the commit — the immediate send attempt, the response itself —
   still answers 200 with the stored `match`, and the workers finish the
   work from the outbox rows; that is the boundary `WriteCommittedException`
   marks (error pattern #13). The one thing the request never does is
   answer 200 for an install whose row it did not commit.

   `tests/Api/V3/AppInstallAtomicityTest` plants a throw in each of
   classification, `record()` and the notification enqueue and asserts, for
   every plant, that no install row exists afterwards and that the replay
   records the conversion, the outbox row and the notification exactly
   once; and the structural check
   `AttributedInstallHasConversionTest` scans the schema for any writer of
   `202_app_installs.match_state = 'attributed'` outside the two
   transactional paths above.

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
| `pending_integrity` | null | Registration requires Play Integrity; the verdict is being decoded |

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

Events and goals are core, not app tables (§2.2): **`202_goals`** (versioned
definitions, owned by a campaign or by an app registration as its default
set), **`202_goal_events`**, **`202_goal_progress`** and **`202_goal_subjects`**
(the per-subject lock row, §5.5; all keyed by subject: click or install),
**`202_goal_outcomes`** (§5.5), and **`202_campaign_goals`**
(`campaign_id, goal_id, payout, notify_traffic_source`). An app event is a
`202_goal_events` row with `subject_type = 'install'`.

**`202_aff_campaigns.app_registration_id`** NULL, used by `foreign_click` and
the link builder. It is added in the 1.9.75 → 1.9.76 rung, next to the app tables.

**Why the two signal tables stay separate.** Postback identity is Apple's
(network, transaction, window, signature). Install identity is ours
(`install_uuid`, click). Merging them would make every identity column
nullable and weaken the uniqueness that deduplication rests on.

### 5.5 Events and goals: engagement-based conversions

The requirement: an install can be the conversion, but so can **any
engagement after it**, and it has to be very flexible. For example:

- install;
- reach level 3;
- complete the tutorial *after* registering;
- the 3rd purchase;
- cumulative purchases of $20 or more within 7 days of install;
- every subscription renewal, capped at 12.

The design separates what the app reports (**events**) from what the operator
pays and reports on (**goals**).

**Events: what the app reports.**
`POST /apps/installs/{install_uuid}/events` accepts
`{event_id, name, occurred_at, properties: {…}, revenue?, currency?}`.

- Properties are flat and typed (string, number, bool). At most 32 per event.
- Events are stored in `202_goal_events` with `subject_type = 'install'`,
  idempotent on `(subject, event_id)`.
- An event on its own records nothing: it is evidence, and goals decide what
  it is worth.
- The SDK call is
  `P202Attribution.logEvent("level_reached", mapOf("level" to 3))`, the same
  on iOS.

**Goals: what counts.** `202_goals` (core, §2.2); for apps the registration supplies the default set. `install` is a
built-in goal every registration has, and it is payable by default. A goal is
a validated JSON definition. It is data, never code: there is no expression
evaluation, so there is nothing to inject.

```json
{
  "name": "Reached level 3",
  "trigger": {"event": "level_reached",
              "where": [{"prop": "level", "op": "gte", "value": 3}]},
  "threshold": {"count": 1},
  "after": ["tutorial_complete"],
  "within": {"days": 7, "from": "install"},
  "repeat": {"mode": "once"},
  "value": {"type": "fixed", "amount": 4.00}
}
```

| Field | Options |
|---|---|
| `trigger` | `install`, or an event name, plus `where` predicates on its properties: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`, `exists`. The predicates are ANDed; a second goal expresses OR |
| `threshold` | `count` (the Nth matching event) or `sum` of a numeric property (cumulative spend) |
| `after` | Goals that must already be reached: sequences and funnels |
| `within` | A window from `install` or from `click`; unset means for the life of the install. A subject with no click (an organic install) cannot evaluate a `from: click` window: the goal is **ineligible** for it, recorded as a non-payable outcome with reason `no_click`, never silently re-based on the install time |
| `repeat` | `once`, or `each` with an optional `max`: every renewal, capped |
| `value` | `fixed`, `from_property` (the event's revenue), or `none` (tracked, not paid) |

Complexity is bounded (at most 20 predicates, at most 5 `after` references,
no cycles), so evaluation is O(goals) per event and a definition can never
become a performance or denial-of-service problem.

**Evaluation.**

- Goals are evaluated **server-side** for Android and for web, in the same
  transaction as the event insert, against per-subject state in
  `202_goal_progress` (§2.2).
- It is deterministic and idempotent by `event_id`, so a retried event can
  never reach a goal twice.
- **Events are evaluated in event-time order per subject, not in arrival
  order.** The order key is `(occurred_at, received_at, event_id)`, with
  `occurred_at` clamped to `received_at` so a device clock cannot move an
  event into a window; `within` windows compare the same clamped value.
  Retries, offline queues and separate web callers reorder deliveries, so a
  prerequisite `A` can arrive after the `B` whose `after` names it. An
  engine that evaluated `B` on arrival and never looked at it again would
  miss that funnel for good with both `occurred_at` values sitting in the
  table. Instead:
  - every request that appends events for a subject takes that subject's
    lock first (`SELECT … FOR UPDATE` on a `202_goal_subjects (subject_type,
    subject_id)` row, inserted on first sight), so two requests for one
    subject serialize and the evaluation below never races itself;
  - an event whose order key sorts **after** everything already stored for
    the subject is evaluated incrementally against `202_goal_progress`, the
    common case and O(goals);
  - an event whose order key sorts **before** a stored one is a
    **replay**: progress for the subject is recomputed from its stored
    events in order-key order, under the same lock, in the same
    transaction. Replay is bounded — a subject holds at most 10,000 events
    (the 10,001st is refused with a 422 naming the cap), and the recompute
    is pure PHP over one indexed read — so out-of-order delivery costs one
    bounded recompute and can never fork the result from what in-order
    delivery would have produced.
  - **A replay recomputes every outcome, not only reachability.** The
    recompute yields, for each `(goal, version, n)` the subject has reached,
    the event that reached it, its `reached_at` and its value. Which event
    *is* "the 2nd purchase" changes when an earlier one arrives late, and
    with `from_property` values so does the money: two purchases of $5 and
    $10 give `n = 1` $5 and `n = 2` $10; a late $1 purchase that occurred
    before both makes the correct answer $1, $5, $10. Keeping the stored
    rows would leave revenue and `reached_at` a function of arrival order,
    the thing this rule exists to remove. So the recomputed set is
    reconciled against the stored one:
    - an outcome that is new is written;
    - an outcome whose stored row names the same event is untouched;
    - an outcome whose stored row names a *different* event, `reached_at` or
      value is **superseded**: the stored row gets `superseded_by` the
      recomputed row, `superseded_reason = 'replay'`, and the new row is
      written. Its ledger row is treated the same way, below;
    - no outcome is withdrawn: the number reached for a `(goal, version)`
      never goes down across a replay, because adding an event can only add
      matches.
    The one thing that is never re-decided by arrival is the version:
    events are evaluated under the goal versions current at their own
    `received_at`, not at replay time, so a replay can never apply an edit
    to history — that is what the explicit re-evaluation operation below is
    for.
  - **The ledger follows.** A goal's ledger key is
    `goal:<goal_id>:<goal_version>:<n>:<event_id>` (§2.1): a retry of the
    same event is still a byte-identical duplicate, and a recompute that
    moves `n` to a different event writes a *different* row rather than
    colliding with the old one. The old row is marked `superseded_by` the
    new one, and **a superseded row never counts toward the click total in
    either payout mode** — `accumulate` sums the rows that are payable,
    not deleted and not superseded; `replace` takes the latest such row —
    so the click shows $16 and not $31 after the example above. Both rows
    go to the MTA outbox, as every change of counted state does (§6.3),
    and the notification rule below decides what the traffic source hears.

  `tests/Goals/EventOrderTest` delivers every permutation of a three-step
  funnel's events and asserts the same outcomes for each, plants the gap
  directly (`B` first, then `A`, and the `after: [A]` goal is reached), and
  runs the $5, $10, late-$1 purchase case: after the replay the outcomes
  read $1, $5, $10, the ledger holds two superseded rows and three counted
  ones, and the click total is $16.
- **Goals are versioned.** Editing a goal creates a new version. Conversions
  record the version that produced them, so an edit never rewrites history.
  Re-evaluating past installs under a new version is an explicit operation
  with a dry-run preview. It is stageable like other writes.

**What a reached goal does.** This is decided per **campaign**, not per goal,
because the same app is often sold under different deals.

- A campaign linked to the registration lists its **payable goals** and a
  payout for each. It can override the goal's `value`.
- **Payable** goals record a conversion on the install's click, with
  `dedupe_key = 'goal:' . goal_id . ':' . goal_version . ':' . n . ':' .
  event_id`, where `n` is the repeat index and `event_id` the event that
  reached it. It is deduped by `UNIQUE (click_id, dedupe_key)`, and it
  enters MTA. When the triggering event carried a network transaction id,
  that id is kept in `transaction_id`. The built-in `install` goal is the
  exception: its row is the intake's install row (key `install`, §5.2).
- **Re-evaluation under a new version** replaces the previous version's
  results for each subject it touches, in both tables, in one transaction
  per subject:
  - every `202_goal_outcomes` row of the same `(subject, goal_id)` at an
    older version is retired: `superseded_reason = 'reevaluation'`,
    `superseded_at` set, `superseded_by` the new version's row for the same
    `n` where one exists and NULL where it does not. The new version's
    outcome rows are then written. Because retirement is a state of the old
    row and not a pointer it must be able to follow, a subject the new
    definition no longer qualifies is handled the same way: its old outcomes
    are retired and nothing new is written. Without a marker on the outcome
    table, the funnel and app report — which read this table, not the
    ledger — would count both versions and every re-evaluation would
    inflate them; superseding the ledger rows alone does not touch what
    those reports read;
  - the ledger rows those outcomes had written are marked `superseded_by`
    the new version's row for the same `(subject, goal)` where one exists,
    and **soft-deleted** (`softDelete()`, which recomputes the click total
    under the click lock) where the new version reaches nothing for the
    subject; a row cannot be superseded by a row that does not exist.

  Every read of `202_goal_outcomes` — the funnel, the app report, the
  per-subject breakdown, the CLI — filters `superseded_at IS NULL` through
  one repository method, and `202_goal_progress` needs no marker because it
  is already keyed by `goal_version`. The preview lists exactly the outcome
  rows that would be retired and written, the ledger rows that would be
  superseded or deleted, and the traffic-source notifications already
  delivered for them that cannot be recalled, per subject, before anything
  is applied. `tests/Goals/ReevaluationSupersedesOutcomesTest` re-evaluates a
  subject under a version that reaches the goal, one that reaches it at a
  different `n`, and one that does not reach it, and asserts the funnel
  count is one, one and zero, never two.
- Each payable goal also has a **"notify traffic source"** option (default
  on). It queues the campaign's traffic-source postback through the
  notification outbox of §5.2, with new tokens `[[p202_goal]]` and
  `[[p202_goal_value]]`, so a network can be told "install" and "level 3"
  separately, or only "level 3".
- **A traffic source is told about an outcome once.** A postback that has
  been delivered cannot be recalled, so a replacement row — one written by
  a replay or a re-evaluation that supersedes an earlier row for the same
  `(subject, goal, n)` — never queues a fresh "reached" postback: upstream
  would count, or pay, the same outcome twice while the tables here show
  one. The outbox row carries a `kind`:
  - `reached`: the first row for an outcome. The key becomes
    `(conv_id, pixel id, kind)`;
  - `correction`: a replacement whose predecessor's `reached` was already
    sent, carrying `[[p202_goal_value]]` (new), `[[p202_previous_value]]`
    and `[[p202_original_conv_id]]`; and `retraction`, for an outcome
    retired by re-evaluation with no replacement. Both are sent only to a
    traffic-source pixel that has a **correction URL** configured, which is
    off by default because most networks have no endpoint for one. Without
    it the row is stored as `suppressed` with its reason, shown in the
    click breakdown, and counted in the re-evaluation preview as
    "notifications already delivered that cannot be recalled";
  - a predecessor whose `reached` row is still *pending* is simpler: that
    row is cancelled and the replacement queues its own `reached`, so a
    network that has heard nothing yet hears the corrected value first.
  An outcome the old version never reached is new to the network, and its
  first row is a `reached` like any other.
- **Every reached goal writes an outcome row**, whether or not the subject
  has a click: `202_goal_outcomes (outcome_id, subject_type, subject_id,
  goal_id, goal_version, n, event_id, reached_at, value, payable,
  conversion_id NULL, superseded_by NULL, superseded_reason ENUM('replay',
  'reevaluation') NULL, superseded_at NULL)`, `UNIQUE (subject_type,
  subject_id, goal_id, goal_version, n, event_id)`. That is the
  funnel's and the app report's source, and it is what makes an organic
  install's goals visible at all — `202_conversion_logs` is click-bound, so
  an install with no click can have no ledger row.
- **When the subject has a click**, the outcome also writes a ledger row and
  links it through `conversion_id`: payable outcomes as described above;
  **non-payable** ones with `payable = 0`, so the click breakdown shows them,
  and nothing else follows — no income, no lead, no MTA credit, no
  notification.
- A campaign that has configured no payable goals pays on `install`. That is
  the default the decision in §9 asks for; turning it off is a campaign
  setting, not a global one.

**One click, several payouts: a real constraint in the core.** Classic
campaign reports allow one lead and one payout per click. Income is
`IF(click_lead>0, click_payout, 0)` (`202-config/DataEngine/ClickRollupSql.php:68`).
Every conversion **overwrites** `click_payout` rather than adding to it
(`MysqlConversionRepository::applyStandardClickUpdate()`, `:345-353`;
`p202ApplyConversionUpdate()`, `static-endpoint-helpers.php:57-118`). A
campaign paying $1 for the install and $4 for level 3 would show $4 of
income, not $5.

**The precedent: the revenue CSV upload already consolidates**, within one
file (`tracking202/update/upload.php:128-165`), and today writes no
conversion rows while doing it. That is why the click's value has to be
derived from a ledger every path writes to (§2.1), and why the mode below is
per campaign: networks that send a second postback to *correct* a payout
(pending → approved, a new amount) rely on replacement, and would be
double-counted by an always-add rule.

**Design: a per-campaign payout mode, computed from the ledger (§2.1).**

- **`202_aff_campaigns.payout_mode`:**
  - `replace`: today's behaviour. It is the default for every existing
    campaign and every web campaign.
  - `accumulate`: the click's value is the sum of its payable conversions. It
    is the default for campaigns with goals and for app campaigns, and it is
    selectable on any campaign.
- **Both modes derive the click's cached value from its ledger rows** under
  the click lock `record()` already holds
  (`MysqlConversionRepository.php:136-144`). Neither mode increments a running
  total, so a delete, a supersede or a replayed request cannot leave the
  cache disagreeing with the rows.
- **The CSV upload** keeps "the file replaces the click's uploaded revenue":
  it now writes one row per line, and a new batch supersedes the earlier
  batch's rows for the same click.
- **Nothing changes for any existing campaign's income.** Only campaigns an
  operator switches, or new goal and app campaigns, accumulate.

**Revenue trust.** Anyone holding the app token can post events.

- Goal values come from the goal or campaign definition.
- A `from_property` value is paid only if the registration sets
  `trust_client_revenue`. Otherwise it is stored and reported, but not
  credited.
- Reports say which regime produced each number.

**iOS uses the same goals.** An SKAN encoding maps a fine or coarse value to a
goal, so decoded postbacks report "reached level 3" in the same vocabulary as
Android.

- Apple's postback carries only the value, so the **iOS SDK evaluates goals
  on the device** to decide which value to set. The schema document ships the
  evaluation-only view of the goals (§4.3: no values, no payouts), and the
  helper runs the same evaluator.
- **An in-flight postback carries no version.** SKAN postbacks arrive up to
  35 days after install (the third conversion window), so an encoding edited
  today is still being applied by devices that fetched the old schema. SKAN
  encodings are therefore versioned with an effective time and never
  deleted, and a postback decodes under the encoding version that was active
  at `received_at − 35 days`. If the encoding changed inside that horizon,
  the row is decoded under both versions; where they agree it counts, and
  where they disagree it is reported as `ambiguous_encoding` and credited to
  neither. The UI says so when an encoding is edited: the report is exact
  again 35 days later.
- One evaluator specification, with cross-language vectors in
  `tests/fixtures/app-sdk-contract/goals/`, is run by the PHP and Swift test
  suites, so the two evaluators cannot drift.
- Until the Swift evaluator lands, iOS encodings can name a goal whose trigger
  is a plain event, which is exactly today's behaviour.

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

**Play Integrity, in the first release, opt-in per registration (PR 6).** It is the only
control that tells a real app on a real device from a click-spamming script,
and this plan attaches payouts to installs and goals. It therefore belongs
next to the payouts, not two phases later. It needs the app owner's Google
Cloud project, so it cannot be on by default.

- **Modes:**
  - `off` (the default);
  - `observe`: store the verdict and report it; attribution is unaffected;
  - `require`: attribution and payable goals wait for a `valid` verdict.
- **The SDK** requests a standard token with
  `requestHash = SHA-256(canonical install body)` when the registration's
  schema document says integrity is on. The canonical body is the install
  JSON with keys sorted, no insignificant whitespace, and the
  `integrity_token` field **excluded** (it cannot hash a field derived from
  itself); the SDK and the server compute it with the same rule, and the
  contract vectors (§4.3) pin the bytes.
- **The server** decodes the token through Google's `decodeIntegrityToken`,
  using the owner's service account. The OAuth JWT is signed RS256 with
  `openssl_sign`, so no new dependency is needed. The credential is encrypted
  at rest and redacted like the app token.
- **A cron worker does the decoding, off the request path.** Under `require`
  the install waits as `pending_integrity`, which is typically seconds.
- A verdict is `valid` only with `PLAY_RECOGNIZED` plus
  `MEETS_DEVICE_INTEGRITY`, **and** a `requestHash` that matches the stored
  body.
- Google's default quota is 10,000 decodes per app per day. The page shows
  usage, and a request refused by quota stays pending. Under `require` it is
  never waved through (error pattern #11).

**Advertising ID: not collected.** Nothing in this design needs it:

- the referrer carries the click;
- the goals ride the install id;
- deduplication uses our own ids.

Collecting it would add the `AD_ID` permission, a consent flow and a Play Data
safety declaration to every app that uses the SDK. It is all zeros for users
who deleted it and for apps without the permission. If an ad network later
requires a device id in its postback, it can be added as an opt-in SDK field
without changing anything here.

**After the release, each part independent:**

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
`connect2.php:695-718`).

A single cookie would work, but it fails exactly where browsers are
tightening. So the identity is **a small identity graph fed by several
first-party signals**, each allowed only to *link*, never to *guess*.

| Signal | Where it comes from | What it links | Why it helps |
|---|---|---|---|
| **Tracking-domain cookie** `p202vid` | 128-bit random, set by `dl.php`/`rtr.php` (`Secure`, `HttpOnly`, `SameSite=Lax`, 400-day cap) | Clicks through redirects in one browser | Works everywhere redirects do; the baseline |
| **Landing-page first-party id** `p202lpid` | The LP script (`tracking202/static/landing.php`, which every LP already loads) stores an id in the **landing page's own** `localStorage` and sends it with the pageview beacon (`record_simple.php`/`record_adv.php`) and on every link into the tracker when it is followed | Clicks on the operator's own sites | The LP domain is a site the user actually interacts with, so browsers treat its storage as first-party. It survives where a bounce-only tracking domain is cleared |
| **Customer id, signed** | `cust` plus `cust_sig` on a click or conversion, and `setCustomerId(id, signature)` in both SDKs; stored hashed. `cust_sig = HMAC-SHA256(account linking key, "<type>:<value>")`, computed by the operator's own server, which is the only party that holds the key. The type is the LTV alias vocabulary (`cust_type`, default `custom`; email digests fold to lower case), and it is inside the signed string so a signature for one namespace cannot be replayed in another. A `customer_ref` on an authenticated `POST /api/v3/conversions` is the operator's own statement and links without a signature | A person across browsers, **and web → app** | The only deterministic cross-device link. `cust` is request-controlled on public pixels, so an **unsigned** id keeps its LTV role exactly as today and links no journeys: anyone who learns someone's customer id could otherwise join their clicks. The signature is what makes the id a proof rather than a claim |
| IP address, user agent, fingerprinting | — | **Never used** | Carrier-grade NAT and offices merge strangers, which is today's defect in another form. Fingerprinting is a privacy and platform-policy problem, and it is wrong often enough to corrupt credit silently |

**How the graph works.** Each click records the signals it carried in
`202_identity_observations (click_id, signal_type, signal_hash, observed_at)`,
and each distinct signal maps to a visitor key in
`202_identity_signals (user_id, signal_type, signal_hash, visitor_key)`.
The observations are the evidence: they say which click carried which
signal, which is what the journey view uses to explain a link.
A click carrying two signals that already map to different visitor keys
**merges** them, union-find style:

- a row in `202_identity_merges` records the merge and the click that
  caused it;
- the lower key becomes the alias of the higher;
- journeys resolve to the canonical key.

Merges are append-only and explainable: the journey view shows which signal
linked which clicks.

**A merge re-attributes the conversions it joins.** A signed customer id
arriving in an SDK event *after* the install — the normal order — merges the
install click's visitor key with the person's earlier web clicks after the
install conversion's outbox job has already built its journey. Left there,
that journey stays one-touch and the web-to-app attribution the graph exists
for never appears. So a merge is itself a trigger: the merge row is the
record (`202_identity_merges` gains `requeued_at NULL`), and the attribution
worker, before it claims pending conversions, takes every merge with
`requeued_at IS NULL`, finds the conversions of **either** component's clicks
whose journey window (`conv_time` minus the journey lookback, §6.3) overlaps
a click of the *other* component — one indexed read of `202_clicks_visitor`
per component — and enqueues them with reason `identity_merge`, in batches,
then sets `requeued_at`. The request that caused the merge does only the
merge; the fan-out runs in the worker so a merge that joins two busy keys
cannot slow a click. The re-enqueued conversions rebuild their journeys from
`202_clicks_visitor` under the canonical key, which now spans both sides.
`tests/Attribution/MergeRequeuesConversionsTest` records a web click, an
install click with a different visitor key, the install conversion (journey
built one-touch), then the signed customer id on both, and asserts the
journey is rebuilt with two touches and credits under every active model. The click row itself stores its canonical `visitor_key`
in `202_clicks_visitor (click_id PK, user_id, visitor_key, click_time)`, with
`KEY (user_id, visitor_key, click_time)`. It is not a column on the hot
`202_clicks`. **As built,** it is written in its own transaction immediately
after `recordClick()` commits, not inside it: identity is an enrichment, and a
lock error or a failure in the graph must never cost the click it rides on.
A failed link is retried once on a deadlock, then logged with the click id,
and the click is a one-touch journey. The rotator writes its click rows
inline, so it links after them the same way.

**Guards against over-merging.** A graph that merges too eagerly collapses
strangers, which is the failure being replaced.

- A signal that has linked more than a set number of visitor keys (default 20)
  is quarantined. It stops linking, and it is reported. This catches a
  shared kiosk, a `cust=test` used in QA, or a leaked `p202vid`.
- Customer ids pass through the same cap.

**Journey rule.** A journey is the clicks with the converting click's
canonical `visitor_key`, within the model's lookback (default 30 days),
**across all campaigns**. Crossing campaigns is the point: a journey limited to one
campaign cannot tell models apart at the campaign level. Clicks flagged bot or
filtered are excluded. A click with no `visitor_key` makes a one-touch journey,
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
- **Cross-device only through customer ids.** Without `cust` or
  `setCustomerId()`, a person on two devices is two visitors.
- **Consent is one switch.** `p202_consent=0` on a tracking URL, or
  `p202.consent(false)` on a landing page (remembered in its storage, and
  sent on the beacon and on every link into the tracker), or a campaign's
  `identity_signals = 0`, captures nothing: no cookie is set or read, the LP
  id is deleted, and the click is a one-touch journey. The identity
  parameters (`p202lpid`, `cust_sig`, `p202_consent`) never ride the redirect
  to the offer.
- **A cross-site LP beacon mints nothing.** When the LP and the tracker are
  different sites (`Sec-Fetch-Site: cross-site`) the browser neither sends
  nor keeps the tracker's cookie on the beacon, so the beacon links by
  `p202lpid` alone rather than minting a fresh one-click visitor per
  pageview.
- **Nothing before the upgrade.** Clicks recorded before an install runs the
  release containing visitor capture have no visitor id and cannot be
  backfilled. Every install upgrading from 1.9.55 or older starts with
  one-touch journeys, and they fill in as new clicks arrive. That is why
  visitor capture must be in the first release that goes out (§8, PR 2).

### 6.3 Engine

**Trigger.** The outbox (§2). The worker `202-cronjobs/attribution-worker.php`, run
every minute with overlap protection, claims pending rows in batches. For each
conversion it builds the journey once and computes credits for each active
model. It is idempotent: in one transaction it deletes and rewrites the
conversion's journey rows and credit rows, so a row claimed twice produces
the same result.

**Every change of counted state enqueues, and so does every change of what
a journey could contain.** A conversion's credits must reflect whether its
ledger row still counts. So `record()` enqueues not only the new conversion
but every row it superseded; `softDelete()` and a reversal enqueue the rows
they affect; and the worker, finding a row that no longer counts
(`payable = 0`, superseded, deleted, or netted to zero), deletes its credits
rather than recomputing them. Without this, a `replace` campaign with a $5
then a $10 conversion reports $15 in MTA while the click shows $10. The
other side of the same rule: an identity merge (§6.2) and a model lookback
wider than a journey was built with (Storage, below) each enqueue the
conversions whose journeys they can change, with a `reason` column on
`202_attribution_pending` (`recorded`, `counted_state`, `identity_merge`,
`rebuild_journey`) so the worker's log and the CLI say why a conversion was
recomputed.

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

`revenue` is the conversion row's amount × credit, so the credits of a
conversion sum exactly to its revenue. The rounding remainder is assigned to
the last touch, and a test pins the sum. The journey itself is in
`202_attribution_journeys (conv_id, position, click_id, click_time)` with
`PRIMARY KEY (conv_id, position)`, rebuilt together with the credits in the
same transaction, and it is what reports explain.

**A stored journey is a superset of every model's window, and says so.** The
journey has no `model_id`: it is built once per conversion and every model
reads it. That only works if it was built at least as wide as the widest
model reads, so:

- a journey is built with the **journey lookback**, the maximum lookback of
  every active model at build time (never less than the 30-day default),
  and the fixed 25-touch cap (§7.3), which no model may exceed — the cap is
  validated on the model, not discovered at read time;
- `202_attribution_journey_meta (conv_id PK, built_lookback_days, built_at,
  truncated)` records what each journey was built under. `truncated` says
  the 25-touch cap cut the journey, so a report can label it;
- a model activated or edited to a lookback **wider than a journey's
  `built_lookback_days`** cannot be served from that journey: the clicks it
  wants were never stored. So such a change enqueues, with reason
  `rebuild_journey`, every conversion whose `built_lookback_days` is
  narrower than the new lookback, in batches from the worker, and the
  worker rebuilds those journeys **from `202_clicks_visitor`** — the raw
  click identity data, which is retained as long as the clicks are — and
  then recomputes credits. A narrower change recomputes credits from the
  stored journey, which already holds more than it needs.

`tests/Attribution/JourneyLookbackTest` activates a 60-day model over a
conversion whose journey was built at 30 days with a 45-day-old click, and
asserts that the click appears in the rebuilt journey and its credits, and
that a 7-day model over the same journey reads it without a rebuild.

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
credits from the stored journeys — except a lookback wider than a journey was
built with, which rebuilds that journey from the raw click identity data
first (above).

**Exports.**

- One pipeline, CSV only. The "xls" is tab-separated text today, so the claim
  is dropped.
- A webhook destination must be `https`. At send time the hostname is
  resolved, every resolved address is checked against the private, loopback,
  link-local and metadata ranges, and the connection is then made **to the
  validated address** with the hostname pinned (`CURLOPT_RESOLVE`) and TLS
  verified against the hostname, so a DNS answer cannot change between the
  check and the connect. Redirects are not followed at all. The same check
  runs when the destination is saved, so the error is shown to the person
  saving it. It is signed with HMAC as today.
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
| — | `202_clicks_visitor`, `202_identity_signals`, `202_identity_merges` | The identity graph (§6.2), created by PR 2. `202_clicks_visitor (click_id PK, user_id, visitor_key, click_time)` with `KEY (user_id, visitor_key, click_time)` is a click attribute, so it takes the click tables' prefix |
| `202_attribution_exports` | `202_attribution_exports`, reshaped | One column set. Today there are two conflicting DDLs, in the 1.9.56 and 1.9.58 rungs |
| `202_attribution_audit` | unchanged | |

**Where the definitions live.** The MTA tables move into
`AttributionTables::getDefinitions()`; the identity tables into their own
`IdentityTables`; the goal and ledger additions into `ConversionTables`. `202_conversion_logs`, a core table
whose definition sits in `AttributionTables` today, moves out to its own
`ConversionTables`. Rewriting MTA's definitions then cannot touch the
conversion table.

**Rungs.**

- The 1.9.56 rung creates every MTA table from those definitions: the same
  "installer definitions, advance only on success" shape the 1.9.75 → 1.9.76
  rung uses for app tables.
- The 1.9.57 and 1.9.58 rungs lose their MTA DDL (settings columns, the second
  exports DDL, `202_conversion_touchpoints`) and keep only their version
  advance. Their other work stays exactly as it is.
- The 1.9.56 rung's `202_aff_campaigns.attribution_model_id` column and the
  permission rows 22 and 23 are kept. They are now read.

**One default model, on every path.** Today upgraded installs get a
"last-touch-default" row per user and fresh installs get none (error pattern
#5). Credits need a `model_id`, so an account with no model row would have no
credits and empty MTA and campaign-report attribution until someone created
one. The rewrite therefore seeds a real `last_touch` model, flagged default,
for every account through one idempotent `ensureDefaultModel(user_id)` called
from the fresh installer, the 1.9.56 rung and user creation — the same
one-function-both-paths shape as `K_install` (§5.1) — and the
upgrade-equals-install test asserts every account has exactly one default.
The worker computes credits for every active model, the default included.
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
| Replayed referrer | `UNIQUE (click_id, dedupe_key)` with the `install` key: one install conversion per click |
| **Click spamming** (harvest real tokens from cheap clicks) | The inherent residual risk. Mitigations: (a) Google's server click time must be within minutes of our `click_time`; (b) CTIT distribution with short and long tails flagged; (c) per-registration, per-peer caps; (d) Play Integrity |
| Click injection | Server click time after server install-begin → `implausible` |
| Row minting on public intakes | Body caps; rate limit on `REMOTE_ADDR` (#16) with injective bucket names (#17); a retention class for every untrusted state |
| App token lifted into another app | `app_key` mismatch is a visible 422; the token rotates |
| SSRF through MTA export webhooks | `https` only; private-range refusal of every resolved address; connect pinned to the validated address; no redirects (§6.3) |
| MTA journey poisoning (a crafted `p202vid`, LP id or `cust` joins someone else's journey) | Browser ids are random 128-bit values, so guessing one is infeasible, and a user can only pollute their own journeys. A customer id links a journey only when it carries a valid `cust_sig` from the operator's server (§6.2); an unsigned `cust` on a public pixel never links. Any signal linking more than the cap is quarantined. Credits are bounded by conversions, which MTA never creates |
| **Fabricated in-app events** to reach payable goals (the app token is public) | Goals pay only for installs that are `attributed`, and under `require`, only for integrity-valid ones. Values come from the goal or campaign, never from the client unless `trust_client_revenue` is set. `UNIQUE (subject, event_id)` stops replays inflating counts. Per-install event rate caps apply. Reports flag installs whose goals were reached implausibly fast |
| Goal definitions as an attack surface | Data only (JSON schema validated on write and on load), bounded complexity, no expression evaluation. An invalid stored definition disables that goal with its reason and never throws through other goals (#11) |
| Permission drift between surfaces | One permission check per operation, and a structural test over the routes |
| Malformed values resolving permissively | Missing HMAC key, unparseable referrer, unreadable policy and invalid model config all resolve to the non-trusting or disabled state (#11) |

### 7.2 Privacy

- **New identifiers:** `p202vid` (tracking-domain cookie), the landing-page
  first-party id, and hashed customer ids. The two browser ids are random
  and carry no information. A customer id is canonicalised (trimmed;
  lower-cased where the operator marks the id as an email) and stored as
  `HMAC-SHA256(account hashing key, canonical id)`, where the key is **one
  per Prosper202 account**, held server-side, minted with the schema and
  rotatable. Web pixels, the API and both SDKs all send the raw id over TLS
  and the server hashes it, so the same person hashes the same way on every
  path and the raw value is never stored. None of these is sent to third
  parties.
- **Consent:** operators must cover these in their consent flow where their
  jurisdiction requires it. One switch suppresses every browser signal: a
  `p202_consent=0` parameter, `p202.consent(false)` from the landing-page script (`landing.php`), or a per-campaign
  setting. The journey is then one touch.
- **Browser limits on landing-page storage:** Safari caps storage written by
  scripts (seven days without interaction) and may clear it sooner. The
  landing-page id therefore extends journeys; it does not guarantee them. The
  one-touch share by browser (§6.2) measures what is lost.
- No device identifiers are collected on Android, and no advertising ID.
- **User deletion.** Today `202-account/user-management.php` deletes no
  clicks and no conversion rows at all (a grep finds neither table in its
  purge), so PR 3 first audits the whole cascade and writes it down. The
  target order is: notification and attribution outbox rows for the user's
  conversions, `202_attribution_credits` and `_journeys` for them, the
  user's `202_conversion_logs` rows, goal events and progress, campaign
  goals and goals, identity observations, signals and merges, and
  `202_clicks_visitor` for the user's clicks; then the `202_app_*` rows
  (postbacks released, §4.6) and export files on disk. Where the existing
  cascade keeps the user's clicks, the ledger rows are deleted with the
  same policy the clicks get.

### 7.3 Performance

**Redirect hot path.** It gains one cookie read or write, one HMAC for the
install token, and one row in the existing click transaction. It gains no
query.

**Pixel and postback paths.** They gain one outbox insert and lose MTA's
inline settings lookup, journey queries and 24-hour rebuilds. Conversion
latency goes down.

**MTA worker.** Per conversion it runs one indexed journey query (a journey is
capped at 25 touches, newest kept, a stated design choice rather than an
inherited constant) and writes credits for (models × touches) rows. The target is 1,000
conversions per minute per worker on modest hardware. That target is measured
on a seeded instance before release, not asserted.

**MTA reports.** They are grouped queries over `(model_id, click_id)` joined to
click dimensions. The target is under 2 s for 30 days at 1M conversions. If
measurement misses it, the fix is an hourly rollup keyed on
(model, dimension, hour), recomputed from credits for dirty hours only.

**Android intake and events.** p95 under 100 ms. There are no external calls
on the request path: Integrity decoding and pixel firing are deferred. Goal
evaluation is O(goals) per event, bounded by the complexity limits, and
touches only the install's own progress rows.

**Identity graph.** Each click does one indexed lookup per signal it carries
(at most three) and, rarely, a merge. Journeys resolve a canonical key
through an alias table capped in depth by path compression at merge time.

**App measurement reshape.** It must not slow the iOS receiver: the same
statements under new names.

**Before any performance fix ships,** it must be shown to return the same
answer as the unoptimised path on a sparse and a dense dataset (CLAUDE.md,
"A performance fix must be shown to return the same answer").

### 7.4 Reliability

- **Exactly-once conversions** come from database keys:
  - `(registration_id, install_uuid)`;
  - `(click_id, dedupe_key)` on the ledger;
  - `(subject, event_id)` on events.
- **Exactly-once MTA effect** comes from the in-transaction outbox
  (at-least-once delivery) plus idempotent journey and credit rewrites
  (§6.3).
- **Post-commit failures** complete via cron (#13). No response invites a
  duplicating retry.
- **Isolation.** A broken MTA engine (worker down, credits table missing,
  invalid model config) never affects conversion recording: the outbox
  accumulates. The outbox table itself is conversion schema (§2) and is not
  optional.
- **Every fallible call is checked,** including `get_result`, `store_result`
  and `prepare` (#1).

### 7.5 Compatibility

- The release stays 1.9.76. The ≤ 1.9.55 → 1.9.76 path creates the final
  schema directly. No database holds an intermediate shape, so no legacy
  guard or drop is needed.
- PHP 8.3 (CI) and 8.4; MySQL 8.0+ and MariaDB 10.6+, the floors the
  installer enforces (`202-config/install.php:409-417`).
- The iOS SDK keeps its platform floor (iOS 14+, full support 15.4+) and its
  behaviour, gaining the header rename, `setCustomerId()` and the on-device
  goal evaluator. Android needs API 21+ and Play Store app 8.3.73+.
- CLI and API renames (`p202 app`, `/apps/*`, the `apps` scope area) have no
  compatibility shims, because there are no users.

### 7.6 Verification: what "done" means

**PR 3 (app core reshape):**
- iOS tests are ported and green, and the signature vectors are unchanged.
- The iOS live, browser and SDK suites are green.

**Upgrade (every PR that touches a rung, and the release gate, PR 12):**
- **Upgrade equals install.** The test goes in three steps:
  1. Build a 1.9.55 database by running the installer from the last commit
     whose `202-config/version.php` reads 1.9.55. That needs a full-history
     clone; this sandbox's is shallow.
  2. Upgrade it with the new code.
  3. Compare `SHOW CREATE TABLE` for every table the 1.9.56–1.9.76 rungs touch
     against a fresh 1.9.76 install. They must match, apart from the
     normalisation differences the reconciler docblock lists.

  This one test covers the only real upgrade path, and every schema PR runs
  it: the app tables, the goal and ledger additions, the identity tables and
  MTA's rungs alike.

**PRs 5–7 (Android):**
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
- **Isolation test:** drop `202_attribution_credits` (an engine table, not
  the outbox), fire a conversion, and assert the conversion records with a
  200 and the outbox row is kept; then restore the table, run the worker and
  assert the credits appear.
- **Counted-state test:** on a `replace` campaign record $5 then $10, run the
  worker, and assert MTA reports $10; soft-delete the $10 row and assert it
  reports $5 again.
- **SSRF test:** a webhook to `http://127.0.0.1`, to a private address, and to
  a hostname resolving to one. Each is refused at schedule time and at send
  time.

**Ledger and breakdown (PRs 1, 1b):**
- **Live pass on a click:**
  1. a $2 postback with a transaction id;
  2. a CSV upload row of $1 for the same subid;
  3. an app goal of $5;
  4. an unpaid goal.

  Then assert that `GET /clicks/{id}/conversions` lists four rows with the
  right `source`, `source_ref` names, `payable` flags and counted reasons.
  Under `accumulate`, the click value is $8. Under `replace` it is the latest
  payable row, and the others are marked superseded.
- **Group Overview:** the Transaction ID level's rows sum to the click's
  income. Today it multiplies the click's income by the transaction count; a
  regression test fixes that in place.
- **Legacy paths:** `px.php`, `pb.php`, ClickBank (including a duplicate
  receipt) and the CSV upload (including a re-upload superseding the earlier
  batch) each write the expected rows. Income per click is unchanged in
  `replace` mode.
- **Consistency:** a structural test asserts that nothing **updates**
  `click_payout` or `click_lead` on an existing click in `202_clicks` or
  `202_clicks_spy` except the ledger's recompute. Click creation still seeds
  the campaign default (`connect2.php:3186-3211`). The writers PR 1 moves
  behind the recompute are, by a grep of every `UPDATE` touching those
  columns:
  - `p202ApplyConversionUpdate()` (`static-endpoint-helpers.php:57-118`, used
    by gpx, gpb, upx, px, pb and cb202);
  - `applyStandardClickUpdate()` (`MysqlConversionRepository.php:345-353`);
  - `tracking202/update/upload.php:153-163`;
  - `tracking202/update/subids.php:66`.

  That invariant keeps the cache honest (error pattern #5).

**Goals:**
- **Web:** a postback with `event=optin` and then `event=sale` on one
  campaign's click reaches two goals, with two ledger rows and a rolled-up
  value.
- **Evaluator vectors** (`tests/fixtures/app-sdk-contract/goals/`) run by PHP
  and Swift: predicates, `count`, `sum`, `after` chains, windows, repeat
  caps, clock clamping, and an invalid definition.
- **Live pass:** an install on a campaign paying install $1 and level 3 $4:
  1. post `level_reached` with levels 1, 2 and 3, plus a replay of level 3;
  2. assert exactly two conversions;
  3. with the campaign in `accumulate` mode, assert `click_payout` = 5.00 and
     campaign-report income 5.00. Then repeat in `replace` mode and assert
     4.00;
  4. assert two traffic-source notifications, carrying the goal tokens;
  5. assert the MTA credits for both conversions.
- **Versioning:** edit the goal; the old conversions keep their version;
  a preview of re-evaluation changes nothing until applied; applying it
  leaves the funnel count for the subject at one, with the old outcome
  carrying `superseded_reason = 'reevaluation'`, and queues no second
  "reached" postback for a goal the traffic source was already told about.
- **Order:** deliver `level_reached` 3 before 1 and 2, and a `tutorial_complete`
  after the `register` that its goal's `after` requires but with the earlier
  `occurred_at`; assert the same conversions as the in-order run.

**Play Integrity:** `observe` stores the verdict without changing
attribution. Under `require`:
- a missing or invalid token leaves the install unattributed, with its
  reason;
- a quota refusal stays pending and is never waved through.

This runs against recorded Google responses, because a live Google call is
not possible in CI.

**Identity:**
- A cookie-jar live pass across a tracking-domain click, an LP click and a
  `cust` conversion asserts one visitor key.
- A signal driven past the cap is quarantined and stops merging.

**Cross-feature:** an attributed Android install appears in the MTA report on
the install click's campaign, with that browser's earlier web clicks in its
journey.

**Agent-eval cases:**
- register an Android app from a store link;
- build a campaign link;
- simulate an install;
- read both reports.

## 8. Delivery: many small PRs, one release

**Code quality comes from review size and verification per change, not from
release count.** So the work lands as a sequence of small PRs, each
independently reviewable and verified, and ships as **one 1.9.76 release**.

- **Every release adds an upgrade origin.** Anything released becomes a
  version some database can sit at, and the ladder must upgrade it forever.
  Today the only origin is ≤ 1.9.55. Shipping PR 3 on its own would add a
  second: a database with the reshaped app tables but no Android or goal
  tables. The next release would have to reconcile that, which is exactly the
  machinery this plan deletes.
- **One release means the upgrade-equals-install test (§7.6) has exactly one
  path to prove.**
- **Nothing is half-shipped.** The goal model spans iOS and Android. Shipping
  one platform's half would freeze a schema the other half may need to change.
- **Each PR is still merged green,** with its own live pass where it touches
  a user path. "One release" is not "one PR".

| # | PR | Depends on |
|---|---|---|
| 0 | **Legacy endpoints record conversions** (§2.1): `px.php`, `pb.php` and `cb202.php` through the shared writer; `tests/live/legacy-pixels.sh`. **Merged first, alone.** | — |
| 1 | **Conversion ledger** (§2.1): provenance columns; the CSV upload writes rows (the last path that does not); click value derived from rows under `payout_mode`; outbox row in `record()`; `ConversionTables` split out; `gpb.php` pixel-firing logic extracted to one function. **Built; `tests/live/conversion-ledger.sh`.** | — |
| 1b | **Breakdown reads:** `GET /clicks/{id}/conversions` and `p202 click conversions <id>`, `/conversions` filters, the click-history breakdown view, Group Overview's Goal/source level, and the Transaction ID level fixed to sum rows | 1, 4 (for goal names); U2 |
| 2 | **Identity capture:** `p202vid`, LP first-party id (in `landing.php`, with `p202.consent()`), signed `cust` on clicks and conversions, `202_identity_*`, `202_clicks_visitor`, consent switch and per-campaign `identity_signals`. **Built; `tests/live/identity-graph.sh`, `tests/browser/specs/identity-landing.spec.js`.** | — |
| 3 | **App core reshape** (§4): registry, `AppIdentity`, token rename, verdicts, `PublicIntake`, retention, user-deletion purge, `/apps` and `p202 app` renames, legacy guard deleted | — |
| 4 | **Goals engine** (core): definitions, validation, versioning, server evaluator keyed by subject (click or install), cross-language vectors, campaign goal payouts, SKAN encodings pointing at goals, `/goals` API and `p202 goal …` | 1, 3 |
| 4b | **Web events:** `event=` on pixels and postbacks, `POST /events`, `p202.js` `track()`, the web campaign goal editor, the Transactions ID docs pointing to goals | 2, 4 |
| 5 | **Android intake:** install token, `MatchState`, installs and events endpoints, conversions through goals, traffic-source notify, pending-click cron | 1, 3, 4 |
| 6 | **Play Integrity** (opt-in modes) | 5 |
| 7 | **Android SDK** (installs, events, customer id, integrity) | 5, 6 |
| 8 | **iOS SDK:** header rename, `setCustomerId`, on-device goal evaluator on the shared vectors | 3, 4 |
| 9 | **MTA engine:** schema rewrite and rungs, worker, models, credits, reports API; v2 and dead code deleted | 1, 2 |
| 10 | **MTA UI and exports:** dashboard on the v2 shell, comparison, journey metrics, SSRF-safe webhooks | 9 |
| 11 | **Mobile Apps UI:** Android pages, link builder, goal editor and funnel, cross-platform report | 3–5 |
| 12 | **Release gate:** upgrade-equals-install from a real 1.9.55 database, full live passes, agent-eval cases, docs and OpenAPI, and the whole-app browser pass on v2 | all, including U8 |

- PRs 1, 2 and 3 depend on no other measurement PR and can proceed in
  parallel. PR 1 lands with U5 (§10.4), because it rewrites the revenue
  upload's behaviour and U5 its page.
- The UI migration runs as its own series, **U1–U8** (§10.4), interleaved with these PRs so that each page is moved to v2 before a measurement PR adds to it. U8, which removes the classic shell, comes after both series.
- The later extensions (Meta decryption, other stores, deep links) come after
  the release.

Identity capture (PR 2) gets no special ordering: with a single release, it is
in the first release by construction.

## 9. Decisions

**Settled** (2026-09-25):

| # | Question | Decision | Where |
|---|---|---|---|
| 1 | Install as a conversion by default? | Yes: `install` is a built-in goal, payable unless the campaign says otherwise. Any engagement after it can be a conversion too, through versioned, data-defined goals: event predicates, counts and sums, sequences, windows, repeats, fixed or property values, chosen per campaign | §5.5 |
| 2 | Traffic-source postbacks? | An option per payable goal per campaign, default on, with `[[p202_goal]]` / `[[p202_goal_value]]` tokens | §5.5 |
| 3 | Play Integrity | In the first release (PR 6), opt-in per registration: `off` / `observe` / `require` | §5.6 |
| 4 | Advertising ID | Not collected; nothing in the design needs it | §5.6 |
| 5 | Journey identity | An identity graph: tracking-domain cookie, landing-page first-party id, hashed customer id; merge caps; never IP or fingerprinting | §6.2 |
| 6 | Release shape | Small PRs in dependency order, one 1.9.76 release | §8 |
| 7 | Naming | App measurement: `202_app_*`, `/apps/*`, scope `apps`, `p202 app`, `app_key`, `app_token`, `accept_test_signals`. MTA: `202_attribution_*`, `/attribution/*`, scope `attribution`, `p202 attribution` | §1 |
| 8 | Several payouts on one click | A per-campaign `payout_mode`. `replace` keeps today's behaviour and is the default for every existing and web campaign. `accumulate` consolidates payouts into one value per click, like the revenue CSV upload already does within a file, and is the default for app campaigns | §5.5 |
| 9 | Seeing what a click's value is made of | `202_conversion_logs` becomes a ledger with provenance (`source`, `source_ref`, `event_name`, `payable`, `superseded_by`); every path writes rows; the click value is derived from them; a per-click breakdown in the API and UI; reports group by goal/source | §2.1 |
| 9a | Transaction ids | Kept, with one meaning: the external id, for reconciliation and reversals. Deduplication moves to a namespaced `dedupe_key`. A blank-id payable row counts once per click in `accumulate` mode. The id is passed to traffic sources via `[[transactionid]]` | §2.1 |
| 10 | Goals on web campaigns | Goals are core: the subject is the click (web) or the install (app), and events come from pixel/postback `event=`, `POST /events` and `p202.js` | §2.2 |
| 11 | The app's two looks | Migrate the whole app to the v2 shell in this release (Part E, PRs U1–U8), then delete the classic shell and legacy assets | §10 |

**Confirmed (2026-09-25) and partly done.** Decision 9 has the legacy
pixels (`px.php`, `pb.php`), ClickBank and the revenue CSV upload **write
conversion rows**. The three endpoints do, as PR 0 (see §2.1); the CSV upload
follows in PR 1. From the upgrade on, those conversions appear in conversion
lists, the API, MTA and the breakdown. Income per click in `replace` mode is
unchanged.

---

# Part E: moving the whole app onto the v2 UI

## 10. Whole-app UI migration

### 10.1 Why this is in the plan

`documentation/features/ui-standard.md` moves pages from Bootstrap 3 and Flat
UI Pro to the v2 shell (Bootstrap 5.3 and the Prosper202 theme) one family at a
time. One family has moved: Setup › Mobile Apps and Analyze › Mobile Apps
(#153). A grep for `'ui' => 'v2'` finds those two pages and the admin-only UI
kit, **out of about 60 pages that call `template_top()`**. Every page this plan
adds would be built on v2: Android, goals, the conversion breakdown, the MTA
dashboard. Without a full migration, the release would ship a *wider* mix of
two looks than today.

**Decision (2026-09-25): migrate the whole app in this release,** as its own
PR series running beside the measurement PRs.

### 10.2 Inventory

Counted from the tree. Section counts are pages that call `template_top()`.
Standalone pages render their own `<html>`.

| Family | Pages | Notes |
|---|---|---|
| Analyze | 15 (1 already v2) | keywords, text ads, referers, IPs, countries, regions, cities, ISPs, landing pages, devices, browsers, platforms, variables, LTV. They share a report layout and `202-js/tracking-report.js` (489 lines), so most move together |
| Overview | 6 | Home, group overview, breakdown, day- and week-parting, rotator breakdown. The Highcharts-heavy pages |
| Visitors, Spy | 2 | The click-history views that get the §2.1 breakdown |
| Setup | 13 (1 already v2) | Campaigns, networks, traffic sources, landing pages, text ads, rotators, trackers, postbacks, landing-page code, the smart component, and attribution models. Form-heavy; the campaign form gets payout mode and goals |
| Update | 5 | subids, CPC, revenue upload, clear/delete subids |
| Account | 15 | account, users, API keys and integrations, administration, help, docs, VIP perks, click servers, safe mode, attribution dashboard, and more. `202-js/attribution.js` (1,732 lines) belongs to the MTA dashboard, which §6 rebuilds on v2 rather than migrating |
| Pre-login and standalone | about 10 | `202-login.php`, `202-lost-pass.php`, `202-pass-reset.php`, `202-config/install.php`, `202-config/upgrade.php`, `202-404.php`, `202-access-denied.php`, `202-license.php`, `api-key-required.php`, `index.php` |
| Other sections | 3 + 1 | `202-tv`, `202-resources`, `202-appstore`, plus the separate `202-Mobile/` mini-site (login, mini-stats) |
| AJAX fragments | 18 files | Files under `tracking202/ajax/` and `202-account/ajax/` that return markup with Bootstrap 3 or Flat UI classes. They must move with the page that loads them |
| Shared JavaScript | `202-js/custom.php` (1,804 lines) and the page scripts | Built on the legacy asset set in `202-config/assets.php`: jQuery UI, Bootstrap 3 JS, select2, tablesorter and its widgets, tokenfield, typeahead, jquery-validate, fileinput, radiocheck, Flat UI Pro JS |

### 10.3 How each page moves (the existing recipe, applied everywhere)

- `template_top($title, ['ui' => 'v2'])`, with markup rebuilt from Bootstrap
  components and the component layer. Each component's markup is copied from
  `202-account/ui-kit.php`, parts included (error pattern #19).
- **The chrome does not change.** It is already framework-neutral
  (`202-css/p202-chrome.css`).
- **The page follows the UI standard's principles** (§ "the app decides what
  it can"):
  - common-case forms, with the rest under **Advanced**;
  - defaults explained in one line;
  - one primary action;
  - empty states that do the first step.

  This is a redesign per page, not a class rename. That is why pages move in
  families, with a review of each.
- **Legacy libraries are replaced once, by one choice per job, recorded in
  the UI standard.** Each replacement is a pinned asset in `assets.php`, with
  its SHA-384:

  | Job | Replacement |
  |---|---|
  | Date pickers | Native `<input type="date">` |
  | Tag inputs and typeahead | `<datalist>`, or one small pinned library, chosen in U1 |
  | Sortable tables | `tablesort.js`, already in the v2 manifest |
  | Validation | Native constraint validation plus the server's error sentences |
  | Select boxes | Native `<select>`, or one pinned searchable select where the list is long |

  jQuery stays available on v2 (`jquery.js` is in the manifest), so scripts
  are ported, not rewritten, where porting is enough. **A port is not a
  copy:** the v2 shell emits page scripts in `<head>`
  (`202-config/template.php:211`), before the body exists, so a script that
  touches the DOM as it loads breaks silently there. U1 makes the shell emit
  `js_page` assets with `defer`, and the porting rule is that DOM work runs
  under `DOMContentLoaded`; the browser pass's console-error check is what
  catches the one that was missed.
- **Pre-login pages keep their security invariants.**
  `PreLoginPostRequiresTokenTest` and `tests/live/upgrade-csrf.sh` pin that
  the form that posts carries the session token inside it (error pattern
  #21). They must stay green through the rewrite of login, install and
  upgrade. This is the family where a markup change can silently break a
  security check, so it goes last, alone, with the live CSRF pass run before
  merge.
- **`202-Mobile/`** is retired if the v2 pages pass the phone-width browser
  pass (§10.5): its login and mini-stats become redirects to the responsive
  pages. If any view is missing at phone width, that view is built on v2
  before the redirect.

### 10.4 PR series (U1–U8), interleaved with the measurement PRs

| # | PR | Before / with |
|---|---|---|
| **U1** | **Foundation:** the header and navigation screenshot check on both shells (the comparison started and not finished on 2026-09-25); the replacement-library choices in §10.3 added to the manifest and the UI standard; shared v2 partials for report filters, date ranges and tables | Before everything else in Part E |
| **U2** | **Overview, Visitors, Spy** | Before PR 1b, which adds the per-click breakdown to the click history and a Goal/source level to Group Overview. Those land on v2 pages instead of being built twice |
| **U3** | **Analyze** (the remaining 14 report pages, the shared report layout, `tracking-report.js`, their AJAX fragments) | With or after U2; they share filters |
| **U4** | **Setup** (12 pages) | Before PR 4b and PR 11, which add payout mode, goals and the link builder to the campaign form |
| **U5** | **Update** (5 pages) | With PR 1 (the same PR or the one next to it): PR 1 rewrites the revenue CSV upload's behaviour and U5 its page, and they are reviewed together |
| **U6** | **Account** (14 pages; the attribution dashboard is replaced by PR 10, not migrated) | Any time after U1 |
| **U7** | **Standalone and pre-login** (login, password reset, install, upgrade, error pages, `index.php`, `202-tv`, `202-resources`, `202-appstore`, `202-Mobile` retirement) | Last page family, on its own |
| **U8** | **Removal:** the classic shell branch of `template_top()`, every `legacy.*` asset, Flat UI Pro, the old stylesheets and `202-js/flat-ui-pro.min.js`; `template_top()` stops taking a `ui` option | After U2–U7 and after every measurement PR that touches a page |

### 10.5 What "migrated" means, checked

- **`NoLegacyBootstrapClassesTest` stops being opt-in.** Today it checks the
  pages that pass `'ui' => 'v2'`. U8 points it at **every page, AJAX fragment
  and script in the tree**, so a Bootstrap 3 or Flat UI class anywhere fails
  CI. Until U8, each U-PR's pages are covered from the moment they opt in.
- **A structural test that the legacy assets are unreachable.** No
  `legacy.*` id in `assets.php` is referenced by any page, and U8 deletes
  them. An asset nobody loads is removed, not kept "just in case".
- **`ComponentClassIsConsumedTest`** covers every new class, so no class that
  styles nothing ships (error pattern #19).
- **Browser passes per family** (`tests/browser/`, with a baseline entry per
  page in `lib/checks.js`):
  - light and dark themes;
  - a 1280px desktop and a 390px phone width;
  - no console errors;
  - `flexContainersKeepTheirSpaces` and `currentSubMenuItemIsVisible`;
  - each page's interactive behaviour: forms submit and show server errors,
    filters apply, AJAX panels load, confirm dialogs confirm.
- **Live pass per family:** the Setup and Update pages are driven end to end,
  creating a campaign, uploading a revenue file and generating links, because
  a form that looks right and posts the wrong field is the failure a
  screenshot cannot see.
- **Pre-login:** `PreLoginPostRequiresTokenTest` and
  `tests/live/upgrade-csrf.sh` are green on the migrated pages.

### 10.6 Non-functional notes

- **Performance.** A v2 page loads Bootstrap 5.3 and the theme instead of
  Bootstrap 3, Flat UI Pro, jQuery UI and a stack of plugins. The asset
  weight per page goes down; U8 measures the before and after on three
  representative pages rather than asserting it.
- **Accessibility.** Bootstrap 5 components bring focus handling and ARIA
  that the Bootstrap 3 and Flat UI widgets lack. Each family's browser pass
  also checks keyboard reachability of the primary action and labelled form
  fields.
- **Risk.** The migration is wide but shallow per page: markup and scripts,
  not data. The two places it touches behaviour are covered first by tests:
  the pre-login security checks (U7) and the revenue upload (U5, together
  with the ledger change).
