# Android SDK

The Prosper202 Android SDK (`sdk/android-attribution/`) reports an app's
install, the events after it, and the signed customer id to your
Prosper202 server. It is the device half of
[23-android-installs.md](23-android-installs.md): the store link puts a
signed click token into Google Play's install referrer, the SDK reads that
referrer on the first launch and sends it, and the server turns it into a
conversion on the click — in every campaign report and in multi-touch
attribution.

It is Kotlin, `minSdk 21`, and its one third-party dependency is Google's
`com.android.installreferrer:installreferrer:2.2`. It reads no advertising
ID and adds no permission beyond `INTERNET`.

## 1. Before the app: the server side

1. Register the app with its Play link: `p202 app create --store-link
   https://play.google.com/store/apps/details?id=com.example.app` (or
   `POST /apps`). Note its **app token**: `p202 app get <id>` shows it.
2. Make the campaign's offer URL the store link with the install token in
   its referrer, and link the campaign to the app
   ([23-android-installs.md §1](23-android-installs.md#1-the-store-link)):

   ```
   https://play.google.com/store/apps/details?id=com.example.app&referrer=p202%3D[[p202_install_token]]
   ```

3. Decide what pays: by default the campaign pays its payout for the
   install; list goals for events after it ([22-goals.md](22-goals.md)).

## 2. Add the SDK to the app

The SDK is a Gradle build in this repository. Include it in the app's
build and depend on its Android module:

```kotlin
// settings.gradle.kts of the app
includeBuild("../prosper202/sdk/android-attribution")

// app/build.gradle.kts
dependencies {
    implementation("com.prosper202:android:1.0.0")
}
```

The included build adds its Android module only when it can find an
Android SDK: set `ANDROID_HOME`, or put `sdk.dir=…` in
`sdk/android-attribution/local.properties`. (Copying `core/` and
`android/` into the app as two modules works as well.)

## 3. Configure, once per launch

```kotlin
class App : Application() {
    override fun onCreate() {
        super.onCreate()
        P202Attribution.configure(this, "https://track.example.com", "<app token>")
    }
}
```

- `endpoint` is your Prosper202 install's address; the SDK appends
  `/api/v3/apps/…`. `https://` in production (Android refuses cleartext by
  default); `http://` is accepted for a development server whose network
  security config allows it.
- The **app token** is an identifier, not a secret: it ships in every copy
  of the app. It selects the registration; `422` is the answer if it is
  another app's.
- On the first launch the SDK reads the install referrer, builds the
  install body **once**, stores it, reads the registration's schema
  document (for Play Integrity, §8), and sends it. Later launches send
  nothing unless the first send has not been answered yet.
- Debuggable builds send `test: true`; those installs count only while the
  registration's `accept_test_signals` is on. Override with
  `P202Attribution.Options(test = false)`.

`configure` throws `IllegalArgumentException` for an endpoint that is not an
`http(s)` URL or a token that is not the 64-character app token (a pasted
API key, say).

## 4. Events

```kotlin
P202Attribution.logEvent("tutorial_complete")
P202Attribution.logEvent("level_reached", mapOf("level" to 3))
P202Attribution.logEvent("purchase", mapOf("sku" to "gems_100"), revenue = 4.99, transactionId = order.id)
```

Events are evidence; the server's goals decide what they are worth. The SDK
checks each one against the server's own rules before it is queued and
throws `InvalidEventException` naming every bad field, so a mistake shows up
in development rather than as a refused request days later:

| | Rule |
|---|---|
| name | 1–64 of letters, digits, `_ . : -`, starting with a letter, digit or `_` |
| properties | at most 32; names a letter or `_` then letters, digits, `_` (≤ 64); values a string of at most 255 bytes, a finite number or a boolean |
| revenue | a finite number, in the account's currency (no currency field) |
| transactionId | 1–255 bytes, not blank |

A string value, a `transactionId` and a customer id must also be valid
Unicode text: a Kotlin/Java `String` can hold an unpaired UTF-16 surrogate
(a string cut in the middle of an emoji, an encoding bug upstream), which
no server can store, and the SDK refuses it by name where you pass it
rather than accept something it could never send.

The SDK gives each event a random `event_id` (the server's idempotency key),
stamps `occurred_at` from the device clock but never earlier than the event
before it, and queues it durably. Events are sent once the install has been
recorded, in batches of up to 100 and under 64 KB, five seconds after the
first unsent one, at once when a batch fills, and when the app goes to the
background. At most 500 wait on the device; beyond that the newest are
dropped (the listener hears which), so the history the goals read is never
missing its beginning.

Revenue an app reports is paid only when the registration sets
`trust_client_revenue`: anyone holding the app token can send events.

## 5. The customer id

```kotlin
P202Attribution.setCustomerId(user.id, signatureFromYourServer)
P202Attribution.setCustomerId(emailSha256, sig, CustomerId.Type.EMAIL_SHA256)
P202Attribution.clearCustomerId()   // on sign-out
```

A customer id links this install's click to the same person's other clicks
— on the web, on another device ([visitor identity](../features/visitor-identity.md)).
Because the app token is public, an id the app merely *says* links nothing:
your **server** signs it with the account's linking key and hands the app
the signature.

```
cust_sig = hex(HMAC-SHA256(linking_key, "<type>:<id>"))
```

Get the key with `p202 user identity-key get <user_id>`; keep it on your
server. The SDK trims the id and lower-cases an email digest exactly as the
server does (the shared vectors `tests/fixtures/app-sdk-contract/customer-id.json`
pin it), checks the signature's shape and that the id is valid Unicode
text (no unpaired UTF-16 surrogate), and throws
`InvalidCustomerIdException` otherwise. The id is kept across launches and
sent once — with the install when it was set before the first launch's
install was built, otherwise on the next events request (on its own if
there are no events) — and again whenever it changes.

Clearing it (`clearCustomerId()`, on sign-out) or setting a different one
before the install has been answered takes the earlier id's signed claim
out of the waiting install, so a retry can never link the install to a
customer who has signed out; a new id then goes on the events route once
the install is recorded. If the install had already reached the server
before the change, the server answers the changed body `409` (a reused
install id with other content): the SDK takes that as "recorded" — the
listener hears `onInstallRecorded` with an empty `match` — and never sends
the first version, with its withdrawn claim, again.

What the server does with it: once the install is **attributed and
trusted**, it verifies the signature and links the click. The answer says
which happened (`AttributionListener.onCustomerLinked`): `linked`;
`unverified` (the signature is not the linking key's — check what your
server signs); `no_click` (an organic, pending, refuted or untrusted
install proved no click); `not_linked` (the campaign's identity capture is
off). An install that settles later (`pending_click`) links when it
settles.

## 6. Retries, storage and backup

- A request answered `429` or `5xx`, or lost to the network, is retried
  with backoff — 30 seconds doubling to 6 hours, jittered, `Retry-After`
  honoured — and the next attempt's time is stored, so a relaunch waits
  too. Every other answer is final for that request
  ([contract](21-app-sdk-contract.md#retry-semantics)).
- A `2xx` counts only when it is the route's receipt: for the install, its
  own `install_uuid` echoed with a `match`; for events, the `install_uuid`
  echoed with `accepted` and `duplicates` that together name every event
  sent; for the schema document, the document. Anything else — a captive
  portal's page, a proxy's JSON, an answer about another install or that
  leaves an event out — is retried like a `5xx`, so nothing is marked sent
  on the strength of a `200` alone.
- An install the server refuses (`400`, `404`, `409`, `422`) is not sent
  again under the same endpoint and token. A build that ships a corrected
  token re-arms it; the stored body, and so the install, is unchanged.
- A refuted install (`bad_token`, `foreign_click`, `implausible`) refuses
  its events (`409`): the SDK drops them and stops queueing.
- State lives in one file in `Context.getNoBackupFilesDir()`, so Auto
  Backup and device transfer never copy the install's id to another phone.
  A file that cannot be read is moved aside, not deleted, and the SDK
  starts fresh.

## 7. What the SDK sends

The install body is the contract's
([21-app-sdk-contract.md](21-app-sdk-contract.md#post-appsinstalls)): the
install's random `install_uuid`, the package name as `app_key`, Play's
referrer response field for field (a busy Play service is read up to three
times before its status is reported as it is), `first_open_at`, the app's
`versionName`, the SDK's version and Android's release, `test`, and
`customer` when there is one. The device's version facts that the server
would refuse (a control character, a string longer than its column) are
sent as `null` rather than costing the install.

## 8. Play Integrity

If the registration uses Play Integrity (`integrity_mode` `observe` or
`require`, [23-android-installs.md §9](23-android-installs.md#9-play-integrity)),
add the optional integrity module and hand its provider to `configure`:

```kotlin
// app/build.gradle.kts
implementation("com.prosper202:integrity:1.0.0")

// Application.onCreate()
P202Attribution.configure(this, "https://track.example.com", "<app token>",
    P202Attribution.Options(integrity = PlayIntegrityProvider(this)))
```

- Before it first sends the install, the SDK reads the registration's
  schema document. When it asks for a token, every attempt requests a
  fresh **standard** token for the Cloud project it names, with
  `requestHash` = the SHA-256 of the install body's canonical form — the
  bytes the server fingerprints, so the token cannot be moved onto another
  install. Under `off` the provider is never called.
- Play's network, server, quota and transient errors hold the install back
  and ask again (at most three times, on the install's backoff): under
  `require` an install without a token is recorded `integrity_unverified`
  and never paid. Other errors (no Play Store or services, an outdated
  one) send it without a token at once.
- Under `require` an attributable install is answered `pending_integrity`
  until the server's worker has Google's verdict; its events wait on the
  device (`503`) and go once it settles. `integrity_failed` refutes it, and
  its events are dropped.
- A registration that asks for tokens from a build without the provider
  gets installs without one, and a Logcat warning.
- The module depends on `com.google.android.play:integrity:1.6.0`; apps
  that do not use Play Integrity leave it out and keep the base SDK's
  single dependency. For a custom source of tokens, implement
  `IntegrityProvider` yourself: `tokenFor(install)` receives the install's
  `requestHash` and `cloudProjectNumber`.

## 9. Diagnostics

`Options(listener = …)` receives `onInstallRecorded(match, reason,
duplicate)`, `onInstallRefused`, `onEventsDelivered`, `onEventsDropped` and
`onCustomerLinked`. The SDK logs to Logcat under `P202Attribution`
(`Options(logging = false)` silences it). `P202Attribution.installMatch` is
the server's classification once it has answered. The operator sees the
same install with `p202 app install list <id>`.

## 10. Testing it

- `p202 app install simulate <id> --click <click id>` posts the install the
  SDK would send for a real click.
- The SDK's JVM suite: `gradle -p sdk/android-attribution :core:test` — the
  core, and every vector in `tests/fixtures/app-sdk-contract/` the server
  also runs.
- Against a running instance: `tests/live/android-sdk.sh` drives the SDK's
  engine (its store, transport, worker and retry rules) through a real
  click, install, events and customer id, and reads the database back.
