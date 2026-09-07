# P202SKAN — remote-configured SKAdNetwork conversion values

A small, dependency-free Swift helper that lets an iOS app take its
SKAdNetwork conversion-value mapping from your Prosper202 server at runtime.
Change what the values mean in Prosper202 (`p202 skan cv …` or
`/skan/conversion-values`) and shipped builds pick it up — **no App Store
resubmission**.

The server builds the document this helper fetches (`GET /api/v3/skan/schema`)
and the decode step in `/skan/report` from the same rules, so what the app
encodes and what your reports decode can never drift.

## What still ships with the app (once)

Two things cannot be remote-configured, by iOS design:

1. The postback endpoint in `Info.plist`:

   ```xml
   <key>NSAdvertisingAttributionReportEndpoint</key>
   <string>https://your-domain.com</string>
   ```

2. This helper plus the app's **schema token** — minted when you register
   the app (`p202 skan app create …`; shown by `p202 skan app get <id>`).
   The token grants read access to the conversion-value mapping only.
   If a shipped token leaks, `p202 skan app rotate-token <id>` invalidates
   it; builds carrying the old token stop fetching until updated.

## Install

The package lives in this repository at `sdk/ios-skan`. Either:

- **Copy the four files** in `Sources/P202SKAN/` into your app target
  (they are self-contained — Foundation and StoreKit only), or
- add the `sdk/ios-skan` directory to your project as a **local Swift
  package** (File → Add Package Dependencies → Add Local…).

## Use

```swift
import P202SKAN

// At launch:
P202SKAN.shared.configure(
    endpoint: URL(string: "https://your-domain.com")!,
    schemaToken: "<schema_token from p202 skan app get>"
)

// Wherever conversions happen — names must match your rules' event_name:
P202SKAN.shared.logEvent("purchase")
P202SKAN.shared.logEvent("signup")

// Optionally, before any event has fired (modern equivalent of
// registerAppForAdNetworkAttribution; iOS 15.4+):
P202SKAN.shared.registerAttribution()
```

`logEvent` resolves the name through the fetched schema and calls
`SKAdNetwork.updatePostbackConversionValue` with the mapped fine value
(0–63) and, on iOS 16.1+, the coarse value. Verify what devices will
receive with `p202 skan schema <app-registration-id>` — it performs the
same request this helper makes.

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
  supported; 16.1+ adds coarse values and window locking.

## Testing

The decode/resolution/cache logic is pure and runs anywhere Swift does
(`swift test` — StoreKit calls are compiled out off-iOS). An integration
suite exercises the real fetch contract against a live Prosper202 instance:

```bash
P202SKAN_LIVE_ENDPOINT=http://127.0.0.1:8000 \
P202SKAN_LIVE_TOKEN=<schema token> \
swift test --filter LiveServerIntegrationTests
```
