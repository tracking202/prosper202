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
  the old token keep their last cached document until they ship the new one
  — and the iOS SDK encodes with a document for at most 7 days after its
  last successful fetch (`P202Attribution.maxSchemaAge`, the third term of
  the report's 48-day decode horizon), so an old build's events wait, and
  set no conversion value, from then on.

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
- **Android** gets its identity; the Android intake adds the SDK settings
  (the integrity mode among them). Its goals are evaluated on the server,
  so the SDK reports every event.
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
body route when one exists; the Android intake carries it from PR 5.

## Retry semantics

The public app routes share one set of answers (`Api\V3\Apps\PublicIntake`),
and an SDK treats them the same way everywhere:

| Status | Meaning | The SDK |
| ------ | ------- | ------- |
| `200`, `304` | Served | Uses the answer |
| `400` | The request is not one the server will ever accept (a malformed token, a malformed body) | Does not retry the same request |
| `404` | Unknown token | Does not retry; keeps its cached document (the iOS SDK encodes with it for 7 days from its last successful fetch) |
| `405` | Method not served | Does not retry |
| `413` | Body over the route's cap | Does not retry the same body |
| `429` | Rate limited per peer address; `Retry-After` says when | Retries after that many seconds |
| `5xx` | The server or its database is unavailable | Retries with backoff; nothing was stored |

## The test flag

A registration's `accept_test_signals` decides whether test signals count:
AdAttributionKit postbacks signed with Apple's development keys today, and
installs the Android SDK sends with `test: true` once the intake exists.
Off by default, and off whenever the policy cannot be read.

## Vectors

`tests/fixtures/app-sdk-contract/` holds the cross-language vectors every
implementation runs:

| File | What | PHP | Swift (`sdk/ios-attribution`) |
|---|---|---|---|
| `app-identity.json` | What each store link, App Store id and package name names, and which are refused | `tests/Apps/AppIdentityTest.php` | `ContractVectorsTests` (the `keys`: checking the document's identity) |
| `goals/definitions.json`, `goals/evaluator.json` | The goal evaluator's specification as data; format and rules in `goals/README.md` | `tests/Goals/GoalVectorsTest.php` | `GoalVectorsTests`, whole and one event at a time from stored state |
| `customer-id.json` | A customer id's canonical form, and `cust_sig` values computed independently of either implementation | `tests/Identity/CustomerIdVectorsTest.php` | `ContractVectorsTests` |

The Kotlin SDK (PR 7) runs the same files, and the Android intake adds its
vectors beside them.
