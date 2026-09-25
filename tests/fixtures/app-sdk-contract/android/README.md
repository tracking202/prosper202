# Android intake vectors

What the Android SDK and the server say to each other, as data (plan §4.3,
§5.1, §5.2, §5.5). The server runs every file in
`tests/Apps/Android/AndroidContractVectorsTest.php`; the Kotlin SDK (PR 7)
runs the same files. The vectors were produced by an implementation written
separately from the server's (Python's `hmac`, `hashlib` and `json`), so the
PHP suite passing is two implementations agreeing.

The prose contract is `documentation/api/21-app-sdk-contract.md`; this file
specifies the formats.

## `install-token.json`

The value a store link carries in `referrer=p202%3D<token>`.

- `key_hex` — a **test** key, 32 bytes as hex. A real installation mints its
  own and never shares it; the SDK never needs it.
- `domain` — the HMAC input prefix, `p202-install-v1|`.
- `tokens[]` — `{click_id, token}`: `token` is
  `<click_id>.<mac>`, where `mac` is the first 12 bytes of
  `HMAC-SHA256(key, domain + decimal(click_id))`, base64url without padding
  (always 16 characters). The click id is canonical decimal: positive, no
  sign, no leading zero, at most 2^63 − 1.
- `malformed[]` — strings that are not shaped like a token at all: any
  implementation must refuse them before computing a MAC.
- `wrong_mac[]` — `{name, token}`: shaped like a token, but the MAC does not
  verify under the key.

The SDK passes the referrer through untouched; what it may do with these
vectors is recognise a Prosper202 referrer (`p202=` plus a well-formed token)
for its own diagnostics.

## `install-requests.json`

Bodies of `POST /api/v3/apps/installs`.

- `cases[]` — `{name, body, expect}`.
- `expect.valid: true` — the server accepts the body. `canonical` is its
  canonical form and `fingerprint` the lower-case hex SHA-256 of that form's
  UTF-8 bytes.
- `expect.valid: false` — the server answers `status` (400) with
  `field_errors` naming exactly the listed fields (sorted). Paths inside the
  referrer are `referrer.<field>`.

**Canonical form.** The body as parsed JSON with `integrity_token` removed,
serialised with object keys sorted (byte order) at every level, lists kept in
order, no whitespace, `/` and non-ASCII characters unescaped, integers as
integers. It is what the server compares to tell a retry from a reused
`install_uuid`, and what Play Integrity's `requestHash` is computed over from
PR 6 on. An SDK must therefore build the body once, persist it, and resend it
unchanged; key order and whitespace do not matter, content does.

Every JSON type is exact: a timestamp is an integer (Play's `0` meaning
"none" is accepted), `test` and `google_play_instant` are booleans, the
optional strings are strings or `null`.

## `events-requests.json`

Bodies of `POST /api/v3/apps/installs/{install_uuid}/events`: `{"events":
[…]}` with 1 to `max_events` events. Each `case` is `{name, body, expect}`
as above; paths are `events[<index>].<field>`. `received_at` and
`revenue_trusted` belong to the server and are refused by name.

## `integrity.json`

Play Integrity (PR 6). The SDK requests a **standard** token with
`requestHash` = the lower-case hex SHA-256 of the install body's canonical
form — the same form and the same hash as `install-requests.json`'s
`fingerprint` — and sends the token as `integrity_token` in that body.

- `cases[]` — `{name, body, canonical, request_hash}`: the hash for bodies
  the SDK builds. The token is not hashed (it is derived from the hash), so
  the reference body with and without a token share one; another
  `install_uuid` or another click's referrer gives another hash, which is
  what stops a token being moved onto another install.
- `verdicts[]` — `{name, payload, expect: {valid, code}}`: the server's
  policy over Google's decoded `tokenPayloadExternal`, for an install of
  `package` received at `received_at` whose request hash is `cases[0]`'s.
  `code` is `valid` or the first check that failed: `wrong_package`,
  `request_hash`, `stale` (issued more than 600 s before arrival), `future`
  (more than 120 s after), `app_not_recognized`, `device_integrity`,
  `unlicensed`. The SDK does not judge verdicts; these pin the server for
  anyone reimplementing it.

## `responses.json`

Every answer the two routes give, the situation that produces it, and
whether the SDK retries it (`retry`). Only `429` and `5xx` are retried; a
`Retry-After` header, when present, is honoured. `match_states` is the list
an install can be classified into, in the server's order, and
`integrity_states` the values of an answer's `integrity`.
