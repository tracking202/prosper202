# Destinations: conversions to your ad platforms, from your server

A destination is an ad platform Prosper202 sends each new conversion to,
from your own server, as the conversion is recorded. It is a conversions API
gateway that keeps the raw data where it is: the platform receives the
conversion, its value and its ids, and at most a SHA-256 hash of the
customer's email or phone, never the address or number itself.

The first destination is **Google Analytics 4**, through its Measurement
Protocol. Google Ads (API upload with gclid, gbraid and wbraid, and enhanced
conversions) is next.

Set them up on **Setup › Destinations**, with `p202 destination`, or through
`/api/v3/destinations`.

## How a conversion travels

1. A conversion is recorded (pixel, postback, API, upload or a goal). In the
   same database transaction, it is queued once for every enabled
   destination of the account that takes its campaign. Only a paid sale is
   queued: a replayed postback is a duplicate, and a reversal, a negative
   amount or a goal outcome tracked but not paid is not sent. A conversion
   deleted or replaced before it goes out is cancelled.
2. The minutely cron job (`202-cronjobs/index.php`, which every default
   deployment runs) sends what is due. A send that fails is tried again 1
   minute later, then 2, 4, … up to 6 hours apart, 8 tries in all. A
   platform that refuses the conversion outright, or an address the server
   never calls (private, loopback, metadata), fails it at once. A host name
   that does not resolve, or a `P202_WEBHOOK_ALLOW_NETWORKS` that does not
   parse, is tried again like a timeout.
3. Every row is in the destination's delivery log with its status, the
   number of tries, the platform's last answer and a summary of what was
   sent: the event, its value, currency and transaction id, the names of
   the mapped parameters, consent, and which identifiers went (client id,
   user id, hashed email or phone), never their values.

| Status | Meaning |
| --- | --- |
| `pending` | Waiting, or backing off after a failed try. |
| `sent` | The platform accepted it. |
| `failed` | Tries ran out, or the platform refused it, or it was not sent (a GA4 event more than 72 hours old, a private address). `last_error` says which. |
| `withheld` | Nothing was sent: the click was guessed, or the visitor is held back (below). `last_error` says which. Never retried. |
| `cancelled` | The conversion or the destination was deleted before it went out. |

A destination you turn off queues nothing; what it had queued waits and goes
out when you turn it on again (a GA4 event by then more than 72 hours old
fails instead). A deleted destination's unsent rows are
cancelled, and its credentials erased.

## Privacy and personal data

- **Only clicks the conversion named are sent.** A conversion whose click
  was found by its postback's or pixel's subid, an API call, an upload or a
  tracking cookie is sent; one whose click was guessed (the visitor id, or the
  latest click from the same IP address) is withheld, as the offline
  conversion files leave it out: a guess would teach the platform's bidding
  the wrong clicks.
- **A visitor who may be in the EEA or UK is withheld unless consent is
  granted**, whatever your privacy setting. GeoIP decides from the click's
  address; an address it cannot place counts as possibly European. Such a
  visitor is sent only when your privacy setting is `disabled` and the
  destination states both consent signals (`ad_user_data` and
  `ad_personalization`) as `granted`.
- **Your privacy setting** (Personal settings, the same setting that masks
  addresses and drops cookies) applies on top: `all` sends no visitor; `eu`
  (the default for new accounts) sends only visitors placed outside Europe
  and the EU. The strictest of the install's and the account's setting
  applies.
- **Hashed email and phone are off by default.** With *send hashed email
  and phone* on, the conversion's LTV customer email is trimmed and
  lower-cased (for gmail.com, dots and a `+tag` are dropped, as Google
  does) and hashed with SHA-256 on your server; a phone number is hashed
  only in E.164 form (`+` and the country code). The raw values are never
  written to the queue, the log or an error.
- **Consent signals** (`ad_user_data`, `ad_personalization`) are what you
  state, per destination, from your consent banner: Prosper202 does not
  record a visitor's consent. Hashed data (and `user_id`) go only when
  `ad_user_data` is unstated or `granted`.
- Credentials (the GA4 API secret) are stored encrypted (AES-256-GCM,
  bound to the destination) and are never shown again.

## Google Analytics 4

You need the web stream's **Measurement ID** (`G-…`) and a **Measurement
Protocol API secret**: GA4 Admin › Data streams › your stream ›
Measurement Protocol API secrets › Create.

Each conversion is sent as one event, `purchase` unless you name another,
with `value`, `currency` (the account's) and `transaction_id` (the network's
transaction id, else `P202-<conversion id>`, so GA4 counts a purchase it
receives twice once), at the conversion's own time. A conversion that
replaces an earlier one on the same click (a campaign that pays the latest
conversion, or a newer revenue upload) is sent with the transaction id of
the first one it replaced, so GA4 counts the sale once and keeps the value
it received first. Delivery is at least once: a send whose result could not be
recorded (a crash mid-run) is sent again, and only `purchase` events are
de-duplicated by GA4 on `transaction_id`, so a custom event name can count
such a conversion twice.

| Setting | |
| --- | --- |
| `client_id_source` | GA4 joins an event to a browser session only through the `_ga` client id. Prosper202 does not see that cookie, so either your landing page passes the id on a click variable (`c1`–`c4`) or Prosper202 sends `p202.<click id>` (`click`, the default), which records the conversion and its value but not the session's source. |
| `user_id_source` | `customer` sends `p202c.<customer id>`, the LTV customer's Prosper202 id, as `user_id`, only with hashed user data on and `ad_user_data` not denied. Never the customer's own reference, which can be an email address. |
| `params` | Extra event parameters, each from a click field: `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `keyword`, `c1`–`c4`, `campaign_id`, `click_id`. They carry whatever your links put in those fields, as is, whatever the hashed-data and consent settings: do not map one that holds an email address or other personal data. |
| `campaign_ids` | Only these campaigns' conversions; empty for every campaign. |

GA4 takes events up to 72 hours old, so a conversion still unsent after that
fails with that reason.

**Test it**: *send test* (or `p202 destination test <id>`) posts a made-up
purchase to GA4's validation endpoint (`/debug/mp/collect`), which checks it
and records nothing in your property, and shows what GA4 found wrong. It checks
the event, not the API secret, and the regular endpoint answers "accepted"
even for an event it drops: after a test, confirm the first real conversions
in GA4's Realtime report.

## CLI

```bash
printf %s "$GA4_API_SECRET" | p202 destination create --name "Online store GA4" \
    --measurement-id G-ABC123 --api-secret-file - --param campaign=utm_campaign
p202 destination test 1
p202 destination update 1 --campaign-id 12 --send-user-data
p202 destination deliveries 1 --status failed
p202 destination update 1 --disable
```

## API

| Method | Path | |
| --- | --- | --- |
| `GET` | `/destinations` | List (credentials never served; `credentials_set`) |
| `POST` | `/destinations` | Add: `type`, `name`, `settings`, `credentials` |
| `GET` | `/destinations/{id}` | One |
| `PATCH` | `/destinations/{id}` | `name`, `enabled`, `settings` (replaced whole), `credentials` (replaced whole) |
| `DELETE` | `/destinations/{id}` | Delete (`?dry_run=1` previews) |
| `POST` | `/destinations/{id}/test` | Test fire |
| `GET` | `/destinations/{id}/deliveries` | The delivery log (`status`, `kind`, `conv_id`, `time_from`, `time_to`, `limit`, `offset`) |

Scopes `destinations:read` and `destinations:write`. Every route needs the
Setup permission (`access_to_setup_section`); the delivery log, which
carries conversion values, needs campaign data (`access_to_campaign_data`)
too. Unknown keys in `settings` and `credentials` are refused.

```json
POST /api/v3/destinations
{
  "type": "ga4",
  "name": "Online store GA4",
  "settings": {"measurement_id": "G-ABC123", "event_name": "purchase", "params": {"campaign": "utm_campaign"}},
  "credentials": {"api_secret": "…"}
}
```
