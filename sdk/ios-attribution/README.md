# P202Attribution — remote-configured goals and conversion values for SKAdNetwork and AdAttributionKit

A small, dependency-free Swift helper that lets an iOS app take its goals
and its conversion-value mapping from your Prosper202 server at runtime,
evaluate the goals on the device, and report the right value to both of
Apple's attribution frameworks. Change what counts as an outcome
(`p202 goal …` or `/goals`) or which value means it (`p202 app encoding …`
or `/apps/skan-encodings`) in Prosper202 and shipped builds pick it up —
**no App Store resubmission**.

The server builds the document this helper fetches (`GET /api/v3/apps/schema`)
and the decode step in `/apps/report` from the same encodings, and the goal
evaluator here is held to the same vectors as the server's
(`tests/fixtures/app-sdk-contract/goals/`), so what the app encodes and what
your reports decode can never drift.

## What still ships with the app (once)

Two things cannot be remote-configured, by iOS design:

1. The postback endpoints in `Info.plist` — SKAdNetwork's, and
   AdAttributionKit's (iOS 17.4+; add the Boolean key too if you want copies
   of re-engagement postbacks, iOS 18+):

   ```xml
   <key>NSAdvertisingAttributionReportEndpoint</key>
   <string>https://your-domain.com</string>
   <key>AttributionCopyEndpoint</key>
   <string>https://your-domain.com</string>
   <key>EligibleForAdAttributionKitReengagementPostbackCopies</key>
   <true/>
   ```

   Apple appends the well-known paths itself
   (`/.well-known/skadnetwork/report-attribution/` and
   `/.well-known/appattribution/report-attribution/`); Prosper202 serves both.

2. This helper plus the app's **app token** — minted when you register
   the app (`p202 app create --store-link …`; shown by `p202 app get <id>`).
   The helper sends it in the `X-P202-App-Token` header. It identifies the
   app rather than protecting anything (it ships in every copy of the app),
   and reads the goals and the conversion-value mapping only — never a goal's
   value or a payout. `p202 app rotate-token <id>` replaces it; builds
   carrying the old token stop fetching until updated.

## Install

The package lives in this repository at `sdk/ios-attribution`. Either:

- **Copy the files** in `Sources/P202Attribution/` into your app target
  (they are self-contained — Foundation and StoreKit only), or
- add the `sdk/ios-attribution` directory to your project as a **local Swift
  package** (File → Add Package Dependencies → Add Local…).

## Use

```swift
import P202Attribution

// At launch (the first launch is the install the goals count from):
P202Attribution.shared.configure(
    endpoint: URL(string: "https://your-domain.com")!,
    appToken: "<app_token from p202 app get>"
)

// Report what happens; the goals decide what it is worth:
try P202Attribution.shared.logEvent("tutorial_complete")
try P202Attribution.shared.logEvent("level_reached", properties: ["level": .int(3)])
try P202Attribution.shared.logEvent("purchase", revenue: 4.99)

// A conversion that belongs to a re-engagement (AdAttributionKit, iOS 18+)
// rather than the install:
try P202Attribution.shared.logEvent("purchase", conversionTypes: [.reengagement])

// Who the user is, signed by YOUR server (see "Customer id" below):
try P202Attribution.shared.setCustomerId(userId, signature: signatureFromYourServer)

// Optionally, before any event has fired (modern equivalent of
// registerAppForAdNetworkAttribution; iOS 15.4+):
P202Attribution.shared.registerAttribution()
```

Each event runs through the goals the schema document carries — the goals
your SKAN encodings name, with every goal they wait for — on the device:
predicates on properties (`eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`,
`exists`), counts, cumulative sums (`$revenue` or any numeric property),
`after` sequences, windows from the install, and repeats. When an event
reaches goals that an encoding maps, the helper hands the highest mapped
fine value (0–63) and coarse value to both frameworks:
`SKAdNetwork.updatePostbackConversionValue` (iOS 15.4+; coarse values on
16.1+) and AdAttributionKit's `Postback.updateConversionValue` (iOS 17.4+).
Apple's guidance for an app that cannot know which framework its ad
networks integrate is to call both; the system ignores whichever has no
pending postback. Verify what devices will receive with
`p202 app schema <registration-id>` — it performs the same request this
helper makes.

`conversionTypes` scopes an update to AdAttributionKit's install and/or
re-engagement postback (iOS 18+). Leaving it nil means the install
postback, and on iOS 18+ the helper says so explicitly instead of using
AdAttributionKit's unscoped call, which Apple documents as updating *every*
postback type. An update that leaves out `.install` is not sent to
SKAdNetwork at all — its only postback is the install one — and on iOS
17.4–17.x, which has no re-engagement postback and no way to scope an
update, it is dropped rather than applied to the install postback. Each
postback keeps its own last fine value for coarse-only mappings, so a
re-engagement update never falls back to, or overwrites, the install
postback's value.

## Customer id

`setCustomerId(_:signature:type:)` records who the app's user is, as a
customer id your own server has signed with your account's linking key —
the same `cust_sig` a tracking link carries
(`documentation/features/visitor-identity.md`):

```
signature = hex(HMAC-SHA256(linking_key, "<type>:<id>"))
```

Compute it on your server and hand it to the app; **never ship the linking
key in the app**. There is no unsigned form: the app token is public, so an
id the app merely says is something anyone could send, and links nothing.
`type` is `.custom` (the default), `.emailMD5`, `.emailSHA256`, `.espId`,
`.merchantId` or `.subid`; send an email as its digest. The helper refuses
an id or signature the server would refuse (it throws
`P202CustomerId.Invalid`), canonicalises the id exactly as the server does
(the shared vectors in `tests/fixtures/app-sdk-contract/customer-id.json`),
keeps it across launches until `clearCustomerId()`, and exposes it as the
wire contract's `customer` object (`customerId?.wireObject`). No request
this helper makes carries a body today — SKAN is the iOS signal — so the
customer rides the first iOS body route when one exists.

## Behaviour you should know about

- **An event that reaches no encoded goal is a no-op.** Sending a guess
  would overwrite a meaningful conversion value; `logEvent` returns the
  `ConversionUpdate` it submitted (or `nil`) so you can log the decision.
- **Bad events throw.** An event name, property or revenue the server's
  rules refuse throws `P202Attribution.EventError` and records nothing
  (names: 1–64 of letters, digits, `_ . : -`; at most 32 properties; string
  values up to 255 bytes; finite numbers).
- **Goals count from the first launch, never from a click.** SKAdNetwork
  does not tell an app which ad it came from, so the device is an install
  with no click; a goal windowed `from: click` is never reached here, and
  the server refuses to encode one.
- **Early events wait for the first schema.** Events logged before any
  schema has arrived (first launch, offline) are kept — up to 100 — and
  evaluated with their own times once it does, along with the install
  itself; the 101st throws `SDKError.pendingEventsFull`.
- **A clock set back cannot reorder events.** Event times never go below
  the last one evaluated, so the incremental evaluation stays equal to
  evaluating everything again.
- **Offline-safe.** The schema is cached across launches (keyed by token,
  so rotation never reuses a stale cache) and refreshed with `If-None-Match`
  — an unchanged schema costs a 304. The default refresh interval is 6
  hours; `refreshSchema()` forces one (call it on foregrounding if you want
  faster pickup). A document that is not an iOS app's (a token lifted into
  the wrong build) is refused whole.
- **Edits reach devices gradually, and reports know it.** A device applies
  the schema it last fetched, and a postback can arrive up to 35 days after
  the value was set, so after you change an encoding the report counts a
  value whose old and new meanings disagree as `ambiguous_encoding` for 35
  days.
- **Coarse-only mappings keep the last fine value.** SKAdNetwork's
  coarse-bearing call always takes a fine value; the helper re-sends the
  last fine value it reported (or 0) rather than downgrading it.
- **`lockWindow` applies only when a coarse value is present** — that is
  the SKAdNetwork API shape, not a helper limitation.
- **iOS 14.0–15.3** offers only deprecated SKAN calls; the helper is silent
  there rather than shipping deprecated API usage. iOS 15.4+ is fully
  supported; 16.1+ adds coarse values and window locking; 17.4+ adds the
  AdAttributionKit call; 18+ adds re-engagement scoping — below 18 a
  `.reengagement`-scoped event reports nothing at all, because the postback
  it names does not exist there.
- **Development-signed AdAttributionKit postbacks** (the ones a phone in
  Developer Mode generates) are stored by Prosper202 flagged `development`
  and count nowhere until you turn on `accept_test_signals` for
  the app registration (`p202 app update <id>
  --accept-test-signals 1`) — do that while integration-testing,
  and turn it off again before trusting the numbers.

## Testing

The evaluator, the decode/resolution logic and the cache are pure and run
anywhere Swift does (`swift test` — StoreKit calls are compiled out
off-iOS, which is how this repository runs them on Linux). The suite reads
the cross-language vectors from `tests/fixtures/app-sdk-contract/` in this
repository (or from `P202_CONTRACT_VECTORS`), so the device and the server
answer every goal case the same way. An integration suite exercises the real
fetch contract against a live Prosper202 instance:

```bash
P202ATTRIBUTION_LIVE_ENDPOINT=http://127.0.0.1:8000 \
P202ATTRIBUTION_LIVE_TOKEN=<app token> \
swift test --filter LiveServerIntegrationTests
```

`P202ATTRIBUTION_LIVE_EXPECT` adds steps to it: a JSON list of
`[event name, properties, expected fine value or null]`, logged in order
against the live document (`tests/live/ios-sdk.sh` uses it).
