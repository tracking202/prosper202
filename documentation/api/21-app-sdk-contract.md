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
  "events": {"purchase": {"fine_value": 63, "coarse_value": "high"}},
  "generated_at": 1725690000}}
```

```json
{"data": {"platform": "android", "app_key": "com.example.app",
  "schema_version": "5b1a…", "generated_at": 1725690000}}
```

- **iOS** gets the SKAN encode map: event name → the fine and coarse values
  to set when the app reports that event (`events` is always a JSON object,
  even when empty). With the goals engine (plan §4.5, §5.5) it becomes an
  evaluation-only view of the goals.
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

## Retry semantics

The public app routes share one set of answers (`Api\V3\Apps\PublicIntake`),
and an SDK treats them the same way everywhere:

| Status | Meaning | The SDK |
| ------ | ------- | ------- |
| `200`, `304` | Served | Uses the answer |
| `400` | The request is not one the server will ever accept (a malformed token, a malformed body) | Does not retry the same request |
| `404` | Unknown token | Does not retry; keeps its cached document |
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
implementation runs. Today: `app-identity.json`, what each store link, App
Store id and package name names (and which are refused), which the server's
`AppIdentity` runs in `tests/Apps/AppIdentityTest.php`. `goals/` is the goal
evaluator's specification as data — `definitions.json` (what a valid goal
is) and `evaluator.json` (what a goal set makes of a subject's events), with
the format and every rule in `goals/README.md` — run by
`tests/Goals/GoalVectorsTest.php` and, from PRs 7 and 8, by the Kotlin and
Swift evaluators. The Android intake adds its vectors beside them.
