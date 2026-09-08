# P202Attribution — remote-configured conversion values for SKAdNetwork and AdAttributionKit

A small, dependency-free Swift helper that lets an iOS app take its
conversion-value mapping from your Prosper202 server at runtime and report
it to both of Apple's attribution frameworks. Change what the values mean
in Prosper202 (`p202 attribution cv …` or `/attribution/conversion-values`)
and shipped builds pick it up — **no App Store resubmission**.

The server builds the document this helper fetches (`GET /api/v3/attribution/schema`)
and the decode step in `/attribution/report` from the same rules, so what the app
encodes and what your reports decode can never drift.

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

2. This helper plus the app's **schema token** — minted when you register
   the app (`p202 attribution app create …`; shown by `p202 attribution app get <id>`).
   The token grants read access to the conversion-value mapping only.
   If a shipped token leaks, `p202 attribution app rotate-token <id>` invalidates
   it; builds carrying the old token stop fetching until updated.

## Install

The package lives in this repository at `sdk/ios-attribution`. Either:

- **Copy the four files** in `Sources/P202Attribution/` into your app target
  (they are self-contained — Foundation and StoreKit only), or
- add the `sdk/ios-attribution` directory to your project as a **local Swift
  package** (File → Add Package Dependencies → Add Local…).

## Use

```swift
import P202Attribution

// At launch:
P202Attribution.shared.configure(
    endpoint: URL(string: "https://your-domain.com")!,
    schemaToken: "<schema_token from p202 attribution app get>"
)

// Wherever conversions happen — names must match your rules' event_name:
P202Attribution.shared.logEvent("purchase")
P202Attribution.shared.logEvent("signup")

// A conversion that belongs to a re-engagement (AdAttributionKit, iOS 18+)
// rather than the install:
P202Attribution.shared.logEvent("purchase", conversionTypes: [.reengagement])

// Optionally, before any event has fired (modern equivalent of
// registerAppForAdNetworkAttribution; iOS 15.4+):
P202Attribution.shared.registerAttribution()
```

`logEvent` resolves the name through the fetched schema and hands the
mapped fine value (0–63) and coarse value to both frameworks:
`SKAdNetwork.updatePostbackConversionValue` (iOS 15.4+; coarse values on
16.1+) and AdAttributionKit's `Postback.updateConversionValue` (iOS 17.4+).
Apple's guidance for an app that cannot know which framework its ad
networks integrate is to call both; the system ignores whichever has no
pending postback. Verify what devices will receive with
`p202 attribution schema <app-registration-id>` — it performs the same
request this helper makes.

`conversionTypes` scopes an update to AdAttributionKit's install and/or
re-engagement postback (iOS 18+; earlier systems apply the unscoped
update). An update that leaves out `.install` is not sent to SKAdNetwork at
all — its only postback is the install one — and each postback keeps its
own last fine value for coarse-only mappings, so a re-engagement update
never falls back to, or overwrites, the install postback's value.

## Behaviour you should know about

- **Unmapped events are no-ops.** Sending a guess would overwrite a
  meaningful conversion value; `logEvent` returns the `ConversionUpdate` it
  submitted (or `nil`) so you can log the decision.
- **Offline-safe.** The schema is cached across launches (keyed by token,
  so rotation never reuses a stale cache) and refreshed with `If-None-Match`
  — an unchanged schema costs a 304. The default refresh interval is 6
  hours; `refreshSchema()` forces one (call it on foregrounding if you want
  faster pickup).
- **Coarse-only mappings keep the last fine value.** SKAdNetwork's
  coarse-bearing call always takes a fine value; the helper re-sends the
  last fine value it reported (or 0) rather than downgrading it.
- **`lockWindow` applies only when a coarse value is present** — that is
  the SKAdNetwork API shape, not a helper limitation.
- **iOS 14.0–15.3** offers only deprecated SKAN calls; the helper is silent
  there rather than shipping deprecated API usage. iOS 15.4+ is fully
  supported; 16.1+ adds coarse values and window locking; 17.4+ adds the
  AdAttributionKit call; 18+ adds re-engagement scoping.
- **Development-signed AdAttributionKit postbacks** (the ones a phone in
  Developer Mode generates) are stored by Prosper202 flagged `development`
  and count nowhere until you turn on `accept_development_postbacks` for
  the app registration (`p202 attribution app update <id>
  --accept-development-postbacks 1`) — do that while integration-testing,
  and turn it off again before trusting the numbers.

## Testing

The decode/resolution/cache logic is pure and runs anywhere Swift does
(`swift test` — StoreKit calls are compiled out off-iOS). An integration
suite exercises the real fetch contract against a live Prosper202 instance:

```bash
P202ATTRIBUTION_LIVE_ENDPOINT=http://127.0.0.1:8000 \
P202ATTRIBUTION_LIVE_TOKEN=<schema token> \
swift test --filter LiveServerIntegrationTests
```
