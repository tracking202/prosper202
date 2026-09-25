# Android installs

Android has no platform postback. Instead, the Prosper202 Android SDK reads
the **Google Play Install Referrer** on the app's first launch and reports
it to your server. The referrer carries what your store link put in it, so
an install can be tied to the **click** that sent the user to the store —
and becomes a real conversion on that click, visible in every campaign
report and in multi-touch attribution.

This guide covers the server side: the store link, the intake the SDK
calls, how an install is classified, what it pays, events after the
install, traffic-source postbacks, and the operator's reads. The wire
contract the SDK is built against is
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
| `pending_integrity` | unvouched | Reserved for Play Integrity (a later release); nothing produces it yet |

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

## 6. Traffic-source postbacks

Each paid install or goal conversion with "notify traffic source" on queues
one row per **server postback pixel** (type 4) of the click's traffic-source
account in `202_notification_pending`, in the same transaction as the
conversion. Browser pixels (image, iframe, script, raw) have no page to
render on and are not queued. The URL is resolved when queued, with the
click's tokens plus `[[payout]]` (this conversion's amount),
`[[transactionid]]` (the network's id, else the ledger key),
`[[p202_goal]]` (`install`, or the goal's name) and `[[p202_goal_value]]`.

`202-cronjobs/app-installs.php` sends what is due (the request path makes no
external calls), retrying with backoff from a minute to six hours and
marking a row `failed` after 8 attempts. A traffic source hears about an
outcome **once**: if a late event moves an outcome to another event and its
postback has not gone out, it is cancelled and the replacement's goes out
instead; if it has gone out, nothing more is sent and a `correction` is
recorded as `suppressed` (no pixel has a correction URL yet).

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
their installs and their queued postbacks; deleting a registration keeps
its installs (their conversions stay on the ledger) but no token reaches
them any more.
