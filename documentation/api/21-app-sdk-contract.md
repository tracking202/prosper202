# App SDK wire contract

What an app build and the Prosper202 server say to each other. The iOS
helper (`sdk/ios-attribution/`) and the Android SDK
(`sdk/android-attribution/`, [24-android-sdk.md](24-android-sdk.md)) speak
it, and the server is the one implementation either side is tested
against.

Everything below lives under `/api/v3` on the install's own origin, except
Apple's postbacks, which devices send to the `/.well-known/` receivers
without any SDK involvement (see [App measurement](19-app-measurement.md)).

## The app token

Every request an app build makes carries the registration's **app token**
in one header:

```
X-P202-App-Token: <64 hexadecimal characters>
```

- It is minted when the app is registered (`POST /apps`, `p202 app create`)
  and shown to the owner by `GET /apps/{id}` and `p202 app get <id>`.
- **It is an identifier, not a secret.** It ships inside every copy of the
  app, so anyone can extract it. What it does is select a registration
  without an API key. A token lifted into another app gets that other
  app's document, never access to anything the owner has.
- It travels **only** in the header. A token in a query string would be
  captured by ordinary request logging, so the server never reads one there.
- It rotates: `POST /apps/{id}/app-token/rotate` (`p202 app rotate-token
  <id>`) replaces it, and the old one stops working at once. Builds carrying
  the old token keep their last cached document until they ship the new one.

A value that is not 64 hexadecimal characters is a `400` naming the header
(a pasted API key or a truncated copy); a well-formed token nobody holds is
a `404`.

## `GET /apps/schema`

The document a build configures itself with, selected by the token. It is
platform-shaped:

```json
{"data": {"platform": "ios", "app_key": "525463029", "app_id": 525463029,
  "schema_version": "d6eefc…",
  "events": {"purchase": {"fine_value": 63, "coarse_value": "high"}},
  "generated_at": 1725690000}}
```

```json
{"data": {"platform": "android", "app_key": "com.example.app",
  "integrity_mode": "require",
  "integrity": {"request_token": true, "token_type": "standard",
                "cloud_project_number": "123456789012",
                "request_hash": "sha256_hex_of_canonical_install_body"},
  "sdk": {"installs_path": "/api/v3/apps/installs",
          "events_path": "/api/v3/apps/installs/{install_uuid}/events",
          "max_events_per_request": 100},
  "schema_version": "5b1a…", "generated_at": 1725690000}}
```

- **iOS** gets the SKAN encode map: event name → the fine and coarse values
  to set when the app reports that event (`events` is always a JSON object,
  even when empty). With the goals engine (plan §4.5, §5.5) it becomes an
  evaluation-only view of the goals.
- **Android** gets its identity, `integrity_mode` (`off`, `observe` or
  `require`), `integrity` — whether to request a Play Integrity token
  (`request_token`, true under `observe` and `require`), for which Google
  Cloud project number, and what to bind it to (below) — and `sdk`: where
  installs and events go and how many events one request may carry. Its
  goals are evaluated on the server, so the SDK reports every event.
- **Revenue is withheld from both.** The document says what a device should
  do, never what the operator is paid.
- `ETag` is the quoted `schema_version` and changes exactly when the
  document's behaviour changes; send it back as `If-None-Match` and an
  unchanged document answers `304` (weak comparison: `W/"…"`, a list and `*`
  all work). Responses are `Cache-Control: private, max-age=300` and
  `Vary: X-P202-App-Token`.

## The Android intake

Two routes, both selected by the app token (the operator's guide is
[23-android-installs.md](23-android-installs.md)).

### `POST /apps/installs`

Sent once, on first launch, with what the Play Install Referrer API returned:

```json
{
  "install_uuid": "8d7c4a52-9f0e-4b1d-a7c3-2e5f60718293",
  "app_key": "com.example.app",
  "store": "google_play",
  "referrer": {
    "status": "ok",
    "install_referrer": "p202=1042.3MVffxqa2WX3CV49&utm_source=news",
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

- `install_uuid` is a random UUID in lower case, minted once. **The SDK
  builds the body once, persists it, and resends it unchanged** until it is
  answered: a replay of the same body is `200` with `duplicate: true`, and
  the same `install_uuid` with other content is `409`. The comparison is
  over the canonical body — keys sorted at every level, no insignificant
  whitespace, `integrity_token` left out — so key order and spacing do not
  matter.
- Every field is typed as JSON types it: a timestamp is an integer (Play's
  `0` for "none" is accepted), `test` a boolean. A string where a number
  belongs, an unknown field or an upper-case UUID is `400` naming the field.
- `referrer.status` is Play's response code in lower case (`ok`,
  `feature_not_supported`, `service_unavailable`, `developer_error`,
  `service_disconnected`, `permission_error`); with anything but `ok` the
  other referrer fields are absent.
- `app_key` must be the token's app (`422` naming both).
- `integrity_token` is a Play Integrity token or `null` (below). Under
  `off` it is kept and never decoded; under `observe` and `require` it is
  decoded by the server's worker after the answer, never on the request
  path.
- `customer` is optional: the signed customer id (below), when the app
  knew it before the body was built. It is part of the body, so it is part
  of the fingerprint.
- The body is capped at 16 KB (`413`).

The answer never carries the click, a conversion or money:

```json
{"data": {"install_uuid": "8d7c4a52-…", "match": "attributed",
  "reason": "Attributed to click 1042.", "trusted": 1, "test": false,
  "integrity": "not_requested", "duplicate": false}}
```

With a `customer` in the body, the answer also carries `"customer"` — see
[the signed customer id](#the-signed-customer-id).

`integrity` is where the install's Play Integrity verdict stands:
`not_requested` or `received` under `off`, `missing` (no token) or
`pending` under `observe` and `require`; the worker later makes it `valid`,
`invalid`, `error` or `skipped`. Under `require` an install that would be
attributed is answered `match: pending_integrity` and settled by the worker
into `attributed`, `integrity_failed` or `integrity_unverified`; its events
are `503` with `Retry-After` until then, like a pending click's.

### Play Integrity: binding the token to the install

When the schema document says `integrity.request_token: true`, the SDK
requests a **standard** integrity token
(`StandardIntegrityManager`, prepared once for `cloud_project_number`) with

```
requestHash = lower-case hex SHA-256 of the install body's canonical form
```

— exactly the bytes the server's replay check fingerprints: the body
without `integrity_token`, object keys sorted (byte order) at every level,
no whitespace, `/` and non-ASCII unescaped, UTF-8. The SDK builds the body,
computes the hash, requests the token, puts it in `integrity_token` and
sends that body. Because the hash covers `install_uuid` and the referrer,
a token cannot be moved onto another install; the server compares the
hash Google signed into the verdict with the hash of the body it stored.

- Request a **fresh token for each send attempt** that is not a replay of a
  committed install: the server refuses a token issued more than 10 minutes
  before the install arrived (or 2 minutes after). Resending the same body
  with a new token is fine — the token is not part of the fingerprint, and
  a replay of a committed install is its duplicate whatever token it carries.
- A device that cannot get a token (no Play services, a Play error) sends
  `integrity_token: null`. Under `require` that install is recorded
  `integrity_unverified` and never paid; under `observe` it is `missing`.
- Classic (nonce) requests are not accepted: a verdict without
  `requestHash` fails.

`android/integrity.json` pins the hash for a set of bodies, and the
server's verdict policy over Google's decoded payload.

### `POST /apps/installs/{install_uuid}/events`

```json
{"events": [
  {"event_id": "e-1", "name": "level_reached", "occurred_at": 1727200500,
   "properties": {"level": 3}},
  {"event_id": "p-1", "name": "purchase", "occurred_at": 1727200600,
   "revenue": 4.99, "transaction_id": "GPA.1234-5678"}
]}
```

- 1–100 events, 64 KB. `event_id` is unique within the install (1–128
  printable characters, not starting with `@`); a retried id is answered as
  a duplicate, the same id with other content is `409`.
- `properties` is flat — string (≤ 255 bytes), number or boolean — and at
  most 32 entries. `revenue` is a number in the account's currency.
- The server stamps `received_at` and decides whether `revenue` may be paid
  (the registration's `trust_client_revenue`); sending either is `400`.
- `occurred_at` is the device's clock. An event is ordered by
  `min(occurred_at, received_at)`, so a clock can move an event earlier but
  never past its arrival.
- `customer` may sit beside `events`, or alone (`{"customer": {…}}`, no
  `events`), so an app that reports no events can still send it. With it
  the answer carries `"customer"`.

### The signed customer id

Both bodies may carry `customer: {"id", "type", "signature"}` — the SDKs'
`setCustomerId(id, signature, type)`:

- `type` is one of `custom` (also what an absent or `null` type means),
  `email_md5`, `email_sha256`, `esp_id`, `merchant_id`, `subid`; `id` is
  up to 255 bytes, trimmed, an email digest hex of its length (folded to
  lower case); `signature` is `cust_sig`, 64 hexadecimal characters of
  `HMAC-SHA256(linking key, "<type>:<id>")`, computed by the operator's
  server ([visitor identity](../features/visitor-identity.md)). A claim that
  breaks a rule is `400` naming `customer.<field>`.
- After the request commits, and only for an **attributed, trusted**
  install, the server verifies the signature under the account's linking
  key and links the install's click to the customer
  (`ClickIdentity`, the same join a signed `cust` makes on a tracking
  link). It answers one word in `data.customer`: `linked`, `unverified`
  (the signature is not the linking key's), `no_click` (the install proved
  no click — organic, pending, refuted or untrusted), or `not_linked` (the
  campaign's identity capture is off, or linking failed and was logged).
  A pending install whose body carried the claim links when it settles.
- An id that does not verify links nothing; it stays only in the install's
  stored body. A replay of an install links again, harmlessly.

## Retry semantics

The public app routes share one set of answers (`Api\V3\Apps\PublicIntake`),
and an SDK treats them the same way everywhere:

| Status | Meaning | The SDK |
| ------ | ------- | ------- |
| `200`, `304` | Served (for an install or events, also a replay of what was already stored) | Uses the answer |
| `400` | The request is not one the server will ever accept (a malformed token, a malformed body) | Does not retry the same request |
| `404` | Unknown token; for events, an install this app never reported | Does not retry; for events, reports the install first |
| `405` | Method not served | Does not retry |
| `409` | The install_uuid or event_id was sent before with other content; events for a refuted install | Does not retry |
| `413` | Body over the route's cap | Does not retry the same body |
| `422` | The body's app_key is not the token's app; the token is an iOS app's; an install holding 10,000 events | Does not retry |
| `429` | Rate limited per peer address; `Retry-After` says when | Retries after that many seconds |
| `5xx` | The server or its database is unavailable, or (`503`, events) the install is still pending; `Retry-After` when sent | Retries with backoff; nothing was stored |

## The test flag

A registration's `accept_test_signals` decides whether test signals count:
AdAttributionKit postbacks signed with Apple's development keys, and
installs the Android SDK sends with `test: true` (classified as usual, but
trusted — and paid — only under the flag). Off by default, and off whenever
the policy cannot be read.

## Vectors

`tests/fixtures/app-sdk-contract/` holds the cross-language vectors every
implementation runs. `customer-id.json` is the customer id's canonical form
and signatures, run by `tests/Identity/CustomerIdVectorsTest.php` and both
SDKs. Then: `app-identity.json`, what each store link, App
Store id and package name names (and which are refused), which the server's
`AppIdentity` runs in `tests/Apps/AppIdentityTest.php`. `goals/` is the goal
evaluator's specification as data — `definitions.json` (what a valid goal
is) and `evaluator.json` (what a goal set makes of a subject's events), with
the format and every rule in `goals/README.md` — run by
`tests/Goals/GoalVectorsTest.php` and the Swift evaluator (Android goals are
evaluated on the server, so the Kotlin SDK has no evaluator to run them
against). `android/` holds the intake's: `install-token.json` (the
token format under a test key), `install-requests.json` (install bodies, the
field errors of each invalid one, and each valid one's canonical form and
fingerprint), `events-requests.json`, `responses.json` (every answer and
whether the SDK retries it) and `integrity.json` (the Play Integrity
request hash and the server's verdict policy), specified in
`android/README.md` and run by
`tests/Apps/Android/AndroidContractVectorsTest.php` and the Android SDK's
`ContractVectorsTest`; the vectors were written by an implementation
independent of both.
