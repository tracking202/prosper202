# Android installs

Android has no platform postback. Instead, the Prosper202 Android SDK reads
the **Google Play Install Referrer** on the app's first launch and reports
it to your server. The referrer carries what your store link put in it, so
an install can be tied to the **click** that sent the user to the store —
and becomes a real conversion on that click, visible in every campaign
report and in multi-touch attribution.

This guide covers the server side: the store link, the intake the SDK
calls, how an install is classified, what it pays, events after the
install, the signed customer id, traffic-source postbacks, the operator's
reads, and Play Integrity (§9). The SDK itself is
[24-android-sdk.md](24-android-sdk.md); the wire contract it is built
against is
[21-app-sdk-contract.md](21-app-sdk-contract.md); goals are
[22-goals.md](22-goals.md); the app registry is
[19-app-measurement.md](19-app-measurement.md#apps).

## 1. The store link

Register the app (`POST /apps` with its Play link, or `p202 app create
--store-link https://play.google.com/store/apps/details?id=com.example.app`),
then make the campaign's offer URL the store link with the install token in
its `referrer`:

```
https://play.google.com/store/apps/details?id=com.example.app&referrer=p202%3D[[p202_install_token]]
```

- **`[[p202_install_token]]`** expands, on every redirect, to
  `<click_id>.<signature>`: the click id and the first 12 bytes of an
  HMAC-SHA256 over it, keyed with a secret only your server holds. A bare
  `[[subid]]` is not enough — click ids are sequential, so anyone could
  credit an install (and its payout) to any click. The token's characters
  survive URL encoding unchanged.
- The key is minted when the database is installed or upgraded to 1.9.76
  (`202_deployment_secrets`). If it is missing the token expands **empty**,
  and installs through the link are recorded unattributed rather than
  attributable to a guessable click. While MySQL is down the redirect's
  cached fallback also expands it empty.
- Link the campaign to the app: `app_registration_id` on the campaign
  (`PUT /campaigns/{id}` or `p202 campaign update <id>
  --app_registration_id <registration>`). An install of another app on one
  of its clicks is then `foreign_click`. An unlinked campaign accepts any
  of your apps.
- Passthrough parameters are appended at the top level of the Play URL,
  where Play ignores them: they never reach the app.
- `GET /apps/{id}/install-token?click_id=N` (`p202 app install token <id>
  --click N`) shows the token and the full store link for one of your
  clicks, without writing anything.

## 2. The intake

The SDK calls two public routes, selected by the app's token in the
`X-P202-App-Token` header (no API key: a binary cannot hold one):

| Method | Path | What |
| ------ | ---- | ---- |
| `GET` | `/apps/installs` | Reachability probe: `{"data": {"status": "ready"}}` |
| `POST` | `/apps/installs` | The install, once, on first launch |
| `POST` | `/apps/installs/{install_uuid}/events` | Events after it, in batches of up to 100 |

Both are rate-limited per peer address (120 installs and 600 event requests
a minute), never per a header the sender chooses. The body shapes and
every answer are in the [contract](21-app-sdk-contract.md#the-android-intake).

What happens to an install, in **one transaction** (nothing is stored when
anything fails, so the SDK's retry is safe):

1. The body is validated strictly — a number sent as a string is refused,
   not cast — and its `app_key` must be the token's app (`422` naming both:
   a token lifted into another app is visible).
2. The install row is written, keyed on `(registration, install_uuid)`. A
   replay of the same body is answered `duplicate: true` with the stored
   state; the same `install_uuid` with other content is `409`.
3. It is **classified** (below).
4. The install is evaluated against its goals. The built-in **install**
   goal is reached by every install that is not refuted; when the install
   is attributed and trusted, its outcome is the **install conversion** on
   the click: ledger key `install` (so one per click), `pixel_type` 4, at
   Google's install time, queued for multi-touch attribution.
5. The traffic source's postback is **queued** (not sent) beside it.

## 3. Match states

| State | Trust | Meaning |
| ----- | ----- | ------- |
| `attributed` | trusted | The token verifies, the click is yours, its campaign is not linked to another app, Google's timestamps place the install after the click and within `attribution_window_days` |
| `organic` | unvouched | Play's organic referrer, or none |
| `third_party` | unvouched | Another source's referrer (a `gclid`, Meta's envelope, another tracker's `utm_*`); the fields are kept |
| `unavailable` | unvouched | The referrer API was unavailable on the device |
| `pending_click` | unvouched | The token verifies but its click row is not written yet — the redirect writes it after sending the user on. `202-cronjobs/app-installs.php` settles it; a click that never appears within 24 hours makes it `bad_token` |
| `bad_token` | refuted | The signature does not verify, the token is malformed, or the click was never recorded |
| `foreign_click` | refuted | The click is another account's, or its campaign is linked to another app |
| `implausible` | refuted | Google's timestamps contradict the click: the install began before the store click (click injection); Google saw the store click more than 2 minutes before your click or more than 30 minutes after it (click spamming); or Play gave no server timestamps |
| `outside_window` | unvouched | Later than the registration's `attribution_window_days` (default 7) |
| `duplicate_click` | unvouched | The click already has an attributed install |
| `pending_integrity` | unvouched | The install would be attributed, and the app requires Play Integrity: it waits for its verdict (§9) |
| `integrity_failed` | refuted | It would be attributed, but its Play Integrity verdict failed the policy, Google could not decode its token, or another install already verified that token |
| `integrity_unverified` | unvouched | It would be attributed, but the app requires Play Integrity and there is no verdict: no token was sent, or none could be decoded within 24 hours. Recorded, never paid |

Every install carries a `match_reason` sentence. A **test** install (a debug
build's `test: true`) is classified the same way and counts — is trusted,
and pays — only when the registration's `accept_test_signals` is `1`.

## 4. What an install pays

What an install is worth is the **campaign's** decision, through the
built-in install goal (`GET /goals?scope=registration&scope_id=<id>`, the
goal with `builtin: install`):

- A campaign that lists **no** goals pays for installs at its default payout
  (`aff_campaign_payout`), and notifies its traffic source.
- A campaign that lists goals pays for installs only if it lists the install
  goal too (`PUT /goals/{install goal}/campaigns/{campaign}`, optionally
  with a `payout` of its own and `notify_traffic_source`). Otherwise the
  install is recorded on the click as a tracked, unpaid row.

App campaigns usually want `payout_mode: accumulate`, so the click's value
is the sum of its install and goal conversions.

## 5. Events

Events (`logEvent("level_reached", {"level": 3})` in the SDK) are evidence;
the goal engine decides what they are worth, per
[22-goals.md](22-goals.md). An install evaluates its registration's goals,
the account's goals and — when it is attributed — its click's campaign's.
Events are idempotent on `event_id`, ordered by the time they happened
(never later than they arrived), and an event that arrives late replays the
install's history so the result is the one in-order delivery would have
given.

- A revenue the app reports is paid by a goal valued `from_property` only
  when the registration sets `trust_client_revenue: 1`; otherwise it is
  stored and reported, never credited. The app token is public.
- Events for a **pending** install are answered `503` with `Retry-After`
  and evaluated once it settles; for a **refuted** one they are refused
  (`409`); for an install the app never reported, `404`.

### The signed customer id

The SDK's `setCustomerId(id, signature)` sends a customer id your server
signed with the account's linking key
([visitor identity](../features/visitor-identity.md)) on the install body or
an events request. Once the install is attributed and trusted, the server
verifies the signature and links the install's click to that customer, so
a user who clicked on the desktop and installed on the phone is one
journey. The answer says `linked`, `unverified`, `no_click` or `not_linked`
([contract](21-app-sdk-contract.md#the-signed-customer-id)). An id that does
not verify links nothing and stays only in the install's stored body.

## 6. Traffic-source postbacks

Each paid install or goal conversion with "notify traffic source" on queues
one row per URL of each **server postback pixel** (type 4) of the click's
traffic-source account in `202_notification_pending`, in the same
transaction as the conversion. A pixel whose code holds several
space-separated URLs gets a row for each (`destination` is the URL's
position), so each endpoint is retried on its own and one that accepted is
never sent the conversion again because another failed. Browser pixels (image, iframe, script, raw) have no page to
render on and are not queued. The URL is resolved when queued, with the
click's tokens plus `[[payout]]` (this conversion's amount),
`[[transactionid]]` (the network's id, else the ledger key),
`[[p202_goal]]` (`install`, or the goal's name) and `[[p202_goal_value]]`.

`202-cronjobs/app-installs.php` sends what is due (the request path makes no
external calls), retrying with backoff from a minute to six hours and
marking a row `failed` after 8 attempts. A traffic source hears about an
outcome **once**, decided per URL: if a late event moves an outcome to
another event and its postback to a URL has not been attempted, it is
cancelled and the replacement's goes to that URL instead; if it has been
attempted (sent, retrying or failed), nothing more is sent there and a
`correction` is recorded as `suppressed` (no pixel has a correction URL
yet). Other URLs are unaffected by that decision.

Schedule the job every minute:

```
* * * * * /usr/bin/php /path/to/prosper202/202-cronjobs/app-installs.php >> /var/log/prosper202/app-installs.log 2>&1
```

## 7. Reading installs

| Method | Path | CLI |
| ------ | ---- | --- |
| `GET` | `/apps/{id}/installs` (`match_state`, `trusted`, `test`, `click_id`, `time_from`, `time_to`, paging) | `p202 app install list <id>` |
| `GET` | `/apps/{id}/installs/{install_uuid}` | `p202 app install get <id> <uuid>` |
| `GET` | `/apps/{id}/install-token?click_id=N` | `p202 app install token <id> --click N` |

`p202 app install simulate <id> --click N` posts the install the SDK would
send for a real click — Google's timestamps seconds after it — and prints
the intake's answer; the install is real. It refuses `--staged`: the public
intake records at once and cannot hold a proposal.

These routes need the `apps` scope area (`apps:read`). The PHP CLI has
`app:install:list`, `app:install:get` and `app:install:token`.

## 8. Retention and deletion

Refuted installs are pruned after 90 days and unvouched, settled installs
without events after 180 (`P202_APP_RETENTION_DAYS_INSTALLS_REFUTED`,
`…_INSTALLS_UNVOUCHED`; `202-cronjobs/app-retention.php`). Trusted installs,
pending ones and installs with events are kept. Deleting a user deletes
their installs, their queued postbacks and their Play Integrity
credentials; deleting a registration keeps its installs (their conversions
stay on the ledger) but no token reaches them any more, and deletes its
credential. Installs of that registration still waiting for a Play
Integrity verdict can then never get one, so the delete settles them in the
same transaction: `integrity_state` becomes `error` and a held
`pending_integrity` install `integrity_unverified` — recorded, never paid,
with a reason naming the deletion. (A `pending_click` install of a deleted
registration stays `pending_click`: there is no policy left to settle it
under.) Both unlink the campaigns linked to the deleted registration
(`app_registration_id` back to `null`), so registering the app again and
linking the campaign to the new registration is all it takes to attribute
its clicks again.

## 9. Play Integrity

Play Integrity is the one control that tells a real copy of your app on a
real device from a script replaying harvested referrers. It needs your own
Google Cloud project, so it is **opt-in per registration**, with three
modes (`integrity_mode`):

| Mode | The SDK | The server |
| ---- | ------- | ---------- |
| `off` (default) | requests no token | keeps a token it is sent (`integrity: received`), decodes nothing |
| `observe` | requests a token for every install | decodes it and records the verdict; attribution, payouts and postbacks are unaffected |
| `require` | the same | an install that would be attributed waits as `pending_integrity`, and is attributed — paid, sent to MTA, its postback queued — only once its verdict passes |

### Setting it up

1. In Play Console, link the app to a Google Cloud project and enable the
   Play Integrity API for it. Note the project **number**.
2. In that project, create a service account and a JSON key for it.
3. Give Prosper202 the key and the project number, then pick the mode:

```
p202 app integrity credential set 3 --file service-account.json
p202 app update 3 --integrity-mode observe --integrity-cloud-project-number 123456789012
p202 app integrity status 3
```

(`PUT /apps/{id}/integrity-credential` with `{"credential": <the key
file>}`, then `PUT /apps/{id}` with `integrity_mode`; `bin/p202` has
`app:integrity:credential:set`, `app:integrity:mode`,
`app:integrity:status` and `app:integrity:credential:clear`.)

- The credential must come first: `observe` and `require` are refused
  without one, so an app is created `off`.
- `observe` and `require` also need the Cloud project **number** — the SDK
  requests its tokens for that project, and without it no install could
  carry one. Send it with the mode, as above, or set it earlier; a mode is
  refused (`422` on `integrity_cloud_project_number`) while none is stored.
  The number can be replaced but not cleared: `null` is refused, in every
  mode. To stop Play Integrity, set the mode to `off`.
- The credential cannot be cleared (`409`) while the mode is `observe` or
  `require`, **nor while any install is still waiting for a verdict**:
  each install keeps the mode it arrived under, so switching `require` off
  does not release the ones already waiting, and without the credential
  they would end `integrity_unverified` — valid installs never paid. The
  refusal says how many are waiting; each is settled within 24 hours of
  arriving (`p202 app integrity status 3` shows
  `installs.by_integrity_state.pending`). Rotating it (setting it again) is
  always allowed.
- The key is stored **encrypted** (AES-256-GCM under an installation key in
  `202_deployment_secrets`, bound to the registration), and no response,
  CLI output or error message ever contains it — the status shows the
  account's email and key id. Setting it again rotates it. The credential
  routes cannot be staged: a staged change would store the key and show it
  to reviewers. The encryption key lives in the same database, so this
  protects the key from anything that reads the credential table alone (an
  export, a query, an API row), not from someone holding a full dump.
- A `token_uri` other than `https://oauth2.googleapis.com/token` is refused:
  the server sends its signed assertion only to Google's pinned endpoints.
- Start with `observe`: `p202 app install list 3 --integrity-state invalid`
  shows what `require` would have refused, and why, before any money
  depends on it.

### What the SDK sends

The schema document tells the SDK to request a **standard** token with
`requestHash` = the SHA-256 of the install body's canonical form (the same
bytes the server fingerprints for replays), so the token is bound to that
install and cannot be moved onto another. The contract is in
[21-app-sdk-contract.md](21-app-sdk-contract.md#play-integrity-binding-the-token-to-the-install).

### How a verdict is judged

`202-cronjobs/app-installs.php` decodes tokens through Google's
`decodeIntegrityToken`, off the request path. A verdict is **valid** only
when every check passes; the first that fails is recorded as the reason:

| Check | Fails as |
| ----- | -------- |
| The token was requested by this app's package (and Google recognised that package) | `wrong_package` |
| Its `requestHash` is this install's | `request_hash` |
| It was issued at most 10 minutes before the install arrived, and not more than 2 minutes after | `stale`, `future` |
| `appRecognitionVerdict` is `PLAY_RECOGNIZED` | `app_not_recognized` |
| The device meets device integrity (`MEETS_DEVICE_INTEGRITY` or `MEETS_STRONG_INTEGRITY`; basic or virtual alone does not) | `device_integrity` |
| The licensing verdict is not `UNLICENSED` (`UNEVALUATED` or none passes) | `unlicensed` |

A token Google refuses to decode is `invalid`, and so is a token another
install of the app already verified (a replay: refused without spending a
decode). An install refuted on its referrer alone (`bad_token`, …) is not
decoded (`skipped`), so forged installs cannot spend your quota.

### Retries, quota and outages

Google being unreachable, slow (10-second timeout), out of quota (`429`),
refusing the service account, or not yet linked to the app is **retried**:
after 1 minute, doubling to at most an hour, for 24 hours. Then the verdict
is `error`, and under `require` the install is `integrity_unverified` —
recorded, unvouched, never paid. Nothing is ever waved through. The
install's `integrity_reason` says what the last attempt met.

Google's default quota is 10,000 decodes per app per day;
`GET /apps/{id}/integrity` shows how many tokens Google decoded since UTC
midnight beside it.

### What `require` does to money

- Nothing is paid, sent to MTA or announced to the traffic source while an
  install waits: its conversion and postback are written in the same
  transaction as the passing verdict, so a traffic source never hears about
  an install that is then refused, and nothing is ever un-paid.
- Its events wait (`503`, the SDK retries) and are evaluated once it
  settles; a refuted one's events are refused (`409`).
- Two installs waiting on one click: the first whose verdict passes gets
  the click; the other becomes `duplicate_click`.
- Each install keeps the mode it arrived under. Switching `require` off does
  not release installs already waiting (they still settle on their verdict),
  and switching it on does not re-judge installs already attributed.
- `observe` never changes attribution or money, whatever the verdicts say.

### Reading verdicts

| Method | Path | CLI |
| ------ | ---- | --- |
| `GET` | `/apps/{id}/integrity` | `p202 app integrity status <id>` |
| `GET` | `/apps/{id}/installs?integrity_state=invalid` | `p202 app install list <id> --integrity-state invalid` |
| `GET` | `/apps/{id}/installs/{install_uuid}` | `p202 app install get <id> <uuid>` |

Each install carries `integrity_mode` (as it arrived), `integrity_state`,
`integrity_reason`, `integrity_attempts`, `integrity_next_at`,
`integrity_checked_at` and `integrity_verdict` — what the policy read from
Google's verdict (never the token).
