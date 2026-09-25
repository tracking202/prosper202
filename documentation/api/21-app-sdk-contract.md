# App SDK wire contract

What an app build and the Prosper202 server say to each other. The iOS
helper (`sdk/ios-attribution/`) speaks it today; the Android SDK and the
Android intake join it (plan §5), and the server is the one implementation
either side is tested against.

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
  "goals": [{"goal_id": 7, "starts_at": 0, "ends_at": null,
             "versions": [{"version": 1, "effective_at": 1725600000,
                           "definition": {"name": "Purchase", "trigger": {"event": "purchase", "where": []},
                                          "threshold": {"count": 1}, "after": [], "within": null,
                                          "repeat": {"mode": "each"}}}]}],
  "encodings": [{"goal_id": 7, "fine_value": 63, "coarse_value": "high"}],
  "generated_at": 1725690000}}
```

```json
{"data": {"platform": "android", "app_key": "com.example.app",
  "integrity_mode": "off",
  "sdk": {"installs_path": "/api/v3/apps/installs",
          "events_path": "/api/v3/apps/installs/{install_uuid}/events",
          "max_events_per_request": 100},
  "schema_version": "5b1a…", "generated_at": 1725690000}}
```

- **iOS** gets an **evaluation-only view of the goals** its SKAN encodings
  name — each with every prerequisite it waits for, every version with the
  unix time it took effect, its span (`starts_at`, `ends_at`), and its
  definition without `value` — and `encodings`: per goal, the fine and/or
  coarse value to set when the device reaches it. Both are always JSON
  lists, even when empty. The SDK evaluates the goals **on the device**
  (plan §5.5): Apple's postback carries only the value, so the device is
  the only place that can decide which one to set. The device is an
  `install` subject whose install time is the first launch the SDK saw and
  which has no click (SKAdNetwork never says which ad it came from), which
  is why the server refuses to encode a goal that counts from the click.
  An SDK refuses a document whose `platform` is not `ios`, whose `app_key`
  is not a canonical App Store id, or whose `app_id` disagrees with it (a
  token lifted into the wrong build), and one whose encodings or goal ids
  it cannot read; a definition it cannot parse disables that goal version
  (`invalid_definition`), as the server's evaluator does.
- **Android** gets its identity, `integrity_mode` (`off` until Play
  Integrity ships; the SDK then requests no token) and `sdk`: where installs
  and events go and how many events one request may carry. Its goals are
  evaluated on the server, so the SDK reports every event.
- **Revenue is withheld from both.** The document says what a device should
  do, never what the operator is paid.
- `ETag` is the quoted `schema_version` and changes exactly when the
  document's behaviour changes; send it back as `If-None-Match` and an
  unchanged document answers `304` (weak comparison: `W/"…"`, a list and `*`
  all work). Responses are `Cache-Control: private, max-age=300` and
  `Vary: X-P202-App-Token`.

## The customer

Both SDKs expose `setCustomerId(id, signature)` (iOS:
`setCustomerId(_:signature:type:)`). The signature is the operator's own
server's `cust_sig` for the id —
`hex(HMAC-SHA256(linking_key, "<type>:<id>"))`, exactly as a tracking link's
(see [Visitor identity](../features/visitor-identity.md)) — and the app
fetches it from that server; the linking key never ships in a binary.
There is no unsigned form: the app token is public, so an id an SDK merely
says is exactly the request-controlled `cust` a public pixel carries.

On the wire the customer is one object in the install and event bodies:

```json
"customer": {"id": "u-829", "type": "custom", "signature": "9f86d0…"}
```

- `type` is one of `custom` (the default), `email_md5`, `email_sha256`,
  `esp_id`, `merchant_id`, `subid`; an email is sent as its digest.
- `id` is sent as the server canonicalises it (trimmed of ASCII space, tab,
  newline, return, NUL and vertical tab; email digests in lower case), and
  `signature` in lower-case hex. An SDK refuses an id the server would
  refuse (empty, an unknown type, a digest that is not one) and a signature
  that is not 64 hexadecimal characters, at the call, and stores nothing.
- The server verifies the signature before the id becomes an identity
  signal; an id without a valid one is stored for lifetime value only and
  links nothing.

The iOS SDK keeps the customer across launches (until `clearCustomerId()`)
and exposes the object as `customerId?.wireObject`. No route an iOS build
calls carries a body today — SKAN is the iOS signal, and the schema fetch
is a `GET` that must not carry a person's id — so it rides the first iOS
body route when one exists. The Android intake (PR 5) does not accept a
`customer` object yet: its bodies refuse unknown fields, so the Android SDK
(PR 7) and the intake add it together.

## The Android intake

Two routes, both selected by the app token (the operator's guide is
[24-android-installs.md](24-android-installs.md)).

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
- `integrity_token` is reserved for Play Integrity (a later release): sent
  or not, it is not judged yet.
- The body is capped at 16 KB (`413`).

The answer never carries the click, a conversion or money:

```json
{"data": {"install_uuid": "8d7c4a52-…", "match": "attributed",
  "reason": "Attributed to click 1042.", "trusted": 1, "test": false,
  "duplicate": false}}
```

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
implementation runs:

| File | What | PHP | Swift (`sdk/ios-attribution`) |
|---|---|---|---|
| `app-identity.json` | What each store link, App Store id and package name names, and which are refused | `tests/Apps/AppIdentityTest.php` | `ContractVectorsTests` (the `keys`: checking the document's identity) |
| `goals/definitions.json`, `goals/evaluator.json` | The goal evaluator's specification as data; format and rules in `goals/README.md` | `tests/Goals/GoalVectorsTest.php` | `GoalVectorsTests`, whole and one event at a time from stored state |
| `customer-id.json` | A customer id's canonical form, and `cust_sig` values computed independently of either implementation | `tests/Identity/CustomerIdVectorsTest.php` | `ContractVectorsTests` |
| `android/install-token.json`, `android/install-requests.json`, `android/events-requests.json`, `android/responses.json` | The Android intake's: the token format under a test key, install bodies with each invalid one's field errors and each valid one's canonical form and fingerprint, event bodies, and every answer with whether the SDK retries it; specified in `android/README.md`, written by an implementation independent of the server's | `tests/Apps/Android/AndroidContractVectorsTest.php` | — (the Kotlin SDK, PR 7) |

The Kotlin SDK (PR 7) runs the same files.
