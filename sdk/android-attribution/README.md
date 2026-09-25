# Prosper202 Android SDK

Install attribution for Android apps: reads Google Play's install referrer
on the first launch, reports the install to your Prosper202 server once,
then the app's events and the signed customer id. The integration guide is
[documentation/api/24-android-sdk.md](../../documentation/api/24-android-sdk.md);
the wire contract is
[documentation/api/21-app-sdk-contract.md](../../documentation/api/21-app-sdk-contract.md).

```kotlin
// Application.onCreate()
P202Attribution.configure(this, "https://track.example.com", "<app token>")

P202Attribution.logEvent("level_reached", mapOf("level" to 3))
P202Attribution.logEvent("purchase", revenue = 4.99, transactionId = order.id)
P202Attribution.setCustomerId(user.id, signatureFromYourServer)
```

## Layout

| Module | What | Needs |
|---|---|---|
| `core/` | Everything that decides anything: the install token, the install and events bodies and the canonical form the server fingerprints, the customer id, the durable queue, the retry rules, the `HttpURLConnection` transport, the file store | a JDK |
| `android/` | `P202Attribution` (the facade), Play's `InstallReferrerClient`, the store in `noBackupFilesDir`, device facts, flushing when the app goes to the background | an Android SDK and AGP |

The core is plain Kotlin that runs on Android from API 21 (no `java.nio.file`,
`java.util.Base64` or `java.time`) and on the JVM, which is where its tests
run. `settings.gradle.kts` includes `android/` only when an Android SDK is
found and `-Pp202.android=false` is not given.

## Tests

```sh
gradle -p sdk/android-attribution -Pp202.android=false :core:test
```

- `ContractVectorsTest` runs every vector in `tests/fixtures/app-sdk-contract/`
  that concerns this SDK — `android/` (the token, every install body's
  field errors, canonical form and fingerprint, every events body, the
  answers and which are retried), `customer-id.json` and the Android keys
  of `app-identity.json` — the same files the PHP suite runs.
- `AttributionEngineTest` drives the engine on virtual time against a
  scripted server: one install, the same bytes on every retry, backoff and
  `Retry-After` across a relaunch, refusals re-armed only by a new token,
  batching under the caps, the queue bound, refuted installs, the customer
  on either route, the integrity seam, the file store.
- `LiveServerTest` runs the real engine against an instance; it skips
  unless `tests/live/android-sdk.sh` sets `P202_LIVE_BASE`.

The goal-evaluator vectors (`goals/`) are not run here: Android goals are
evaluated on the server (plan §4.3), so the SDK reports every event and
evaluates none.

Gradle 8.14 and Kotlin 2.0.21; the Android module uses AGP 8.7.3,
`compileSdk 34`, `minSdk 21`.
