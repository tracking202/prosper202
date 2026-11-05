# Destinations: conversions to your ad platforms, from your server

A destination is an ad platform Prosper202 sends each new conversion to,
from your own server, as the conversion is recorded. It is a conversions API
gateway that keeps the raw data where it is: the platform receives the
conversion, its value and its ids, and at most a SHA-256 hash of the
customer's email or phone, never the address or number itself.

Two destinations exist: **Google Analytics 4**, through its Measurement
Protocol, and **Google Ads**, as click conversions uploaded through the
Google Ads API with your own developer token and OAuth client (gclid, wbraid
or gbraid, and optionally enhanced conversions for leads).

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
| `pending` | Waiting, or backing off after a failed try. A Google Ads row also waits, without using a try, for its click to be 6 hours old, for the destination to be connected to Google, and for you to fix what Google refused (the developer token, access, the customer IDs or the conversion action). |
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
- Credentials (the GA4 API secret; the Google Ads developer token, OAuth
  client secret and refresh token) are stored encrypted (AES-256-GCM, bound
  to the destination) and are never shown again. Every error stored or shown
  has them replaced by `…`.

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

## Google Ads

Each conversion is uploaded to one Google Ads account as a click conversion
(`customers/{id}:uploadClickConversions`, Google Ads API v26), straight from
your server to Google with your own developer token and your own OAuth
client: nothing passes through Tracking202.

### What you need

1. **A developer token.** It belongs to a Google Ads *manager* account (MCC):
   in the manager account, **Admin › API Center** shows it and its access
   level. A new token starts at *Test* access, which reaches test accounts
   only; apply there for *Basic* access, which is enough for a single
   business's conversions. Google's policy is at
   https://developers.google.com/google-ads/api/docs/api-policy/developer-token.

   **Google's changes in 2026, as its documentation reads on 2026-11-04:**
   developer tokens were sunset on 9 September 2026 in favour of access
   granted to the Google Cloud project that owns your OAuth client (applied
   for on that project's *Google Ads API* page in Cloud Console, not the API
   Center; Test access there still reaches test accounts only), and since
   15 June 2026 `UploadClickConversions` answers
   `CUSTOMER_NOT_ALLOWLISTED_FOR_THIS_FEATURE` to developer tokens that made
   no offline conversion upload between 17 December 2025 and 15 June 2026.
   Google recommends its Data Manager API for new integrations. A token that
   was uploading conversions in that window keeps working; a delivery that
   gets that answer fails and its log says so. See
   https://developers.google.com/google-ads/api/docs/conversions/upload-clicks.
2. **An OAuth client** in Google Cloud Console, in the same project as the
   API access:
   - *APIs & Services › Library*: enable the **Google Ads API**.
   - *OAuth consent screen*: set it up (Internal for a Workspace
     organisation, otherwise External) and **publish it to production**: a
     client left in *Testing* gets refresh tokens that Google expires after
     7 days, and the destination then waits for you to connect it again.
   - *Credentials › Create credentials › OAuth client ID*, type **Web
     application**. Under *Authorised redirect URIs* add this install's
     callback exactly as Setup › Destinations shows it, for example
     `https://track.example.com/tracking202/setup/destination-oauth.php`
     (it is also in the answer of `p202 destination connect`). Copy the
     client ID and secret.
3. **A conversion action** for uploads: *Goals › Conversions › New
   conversion action › Import › other data sources or CRMs › Track
   conversions from clicks*. Its ID is the number after `ctId=` in its
   address. For hashed email and phone, turn on *enhanced conversions for
   leads* and accept the customer data terms in *Goals › Settings*.
4. **The customer ID** of the account the conversions go to (`123-456-7890`,
   top of Google Ads) and, when you reach it through a manager account, that
   manager's ID as the login customer ID.

### Connect it

Add the destination on Setup › Destinations (*Add Google Ads*) or with
`p202 destination create --type google_ads`, then press **Connect Google
Ads** (or run `p202 destination connect <id>` and open the address it
prints, in a browser signed in to Prosper202 as the same user, within 10
minutes). Google asks you to allow access to your Google Ads account and
sends you back to the callback, which stores the refresh token. Until then
the destination's status is `needs_authorisation` and its conversions wait
as `pending`, using none of their tries; connecting makes them due at once.
Access tokens are minted from the refresh token for each run and never
stored. If Google later refuses the refresh token (revoked, expired, or the
client changed), the destination's conversions wait again and the log says
to connect it again. New credentials with the same OAuth client ID keep the
connection; another client ID needs connecting again.

The callback checks a signed state that names you and the destination and
expires after 10 minutes, so a Google answer meant for another user, another
install or an old attempt is refused.

### What is sent

| Field | |
| --- | --- |
| `gclid`, `wbraid` or `gbraid` | One of them, from the click, in that order (gbraid and wbraid together are refused by Google). |
| `userIdentifiers` | With *send hashed email and phone* on and `ad_user_data` not denied: the SHA-256 hashes of the customer's email and E.164 phone (enhanced conversions for leads). Not sent beside a wbraid or gbraid alone, which Google ignores them with. |
| `conversionAction` | The destination's `conversion_action`, or the one `campaign_conversion_actions` names for the conversion's campaign. An ID, or `customers/<id>/conversionActions/<id>` for an action a manager account owns. |
| `conversionDateTime` | The conversion's time in your account's time zone, with that day's offset (`2026-06-01 14:30:00-04:00`). |
| `conversionValue`, `currencyCode` | The payout and the account's currency. |
| `orderId` | The network's transaction id, else `P202-<conversion id>`: Google counts one conversion per order id, so a retry or an overlap with another uploader is not counted twice. |
| `consent` | `adUserData` and `adPersonalization` as the destination states them. |

A conversion whose click has no gclid, wbraid or gbraid and that carries no
hashed email or phone is `withheld`: there is nothing Google can match.
Google takes a click's conversion only 6 hours after the click, so a newer
one waits until then, using none of its tries. A click more than 90 days old
fails: no conversion window is longer. When the click's time is not on record,
the conversion's time stands in for it (a click is never later than its
conversion), so every wait ends by 90 days at the latest.

Google's answers: a conversion Google already holds (`ORDER_ID_ALREADY_IN_USE`,
`CLICK_CONVERSION_ALREADY_EXISTS`) counts as sent. Google keeps the one it
holds, and its API reads no click conversion back, so the value and time of
this one are not compared with it: the row says so in `last_error`, and its
summary carries `already_in_google_ads: true` (a corrected value sent under the
same order id does not change Google's; adjusting it is a later feature). `TOO_RECENT_EVENT` and
`TOO_RECENT_CONVERSION_ACTION` wait 6 hours, uncounted (`TOO_RECENT_EVENT`
more than a day after the click is an ordinary, counted retry); a quota error, a timeout and a
5xx are retried with backoff. What only you can mend waits instead of
failing, using none of its tries, with Google's error code in `last_error`:
an authentication or authorization error (a developer token Google has not
approved yet, the connected Google user losing access, a customer that is not
enabled), any 403, and a wrong customer or login customer ID hold every
conversion of the destination for an hour at a time; a missing or wrong
conversion action holds only that conversion (it may be one campaign's
action). Setup › Destinations and the destination's `waiting` (API, `p202
destination get`) show the latest such wait and Google's error. Saving the
destination's settings or credentials, or connecting it again, makes them
due at once. Anything else Google refuses (an expired click, a malformed field)
fails at once, with Google's error code and message in `last_error`.

**Test it**: *send test* uploads a made-up conversion with `validateOnly`,
which records nothing in Google Ads. It mints an access token and has Google
check the developer token, the account, the conversion action and the
consent; its made-up gclid is expected to be refused, so the test passes
when nothing else is. It cannot prove a real click will match: check the
action's *Uploads* in Google Ads after the first real conversions (a
conversion appears there a few hours after its upload).

Not yet: conversion adjustments and retractions (a refund does not reach
Google Ads), batching, and backfilling conversions recorded before the
destination existed.

## CLI

```bash
printf %s "$GA4_API_SECRET" | p202 destination create --name "Online store GA4" \
    --measurement-id G-ABC123 --api-secret-file - --param campaign=utm_campaign
p202 destination test 1
p202 destination update 1 --campaign-id 12 --send-user-data
p202 destination deliveries 1 --status failed
p202 destination update 1 --disable

# Google Ads: google-ads.json holds developer_token, oauth_client_id, oauth_client_secret
p202 destination create --type google_ads --name "Search campaigns" --customer-id 123-456-7890 \
    --conversion-action 987654 --credentials-file google-ads.json
p202 destination connect 2          # open the authorisation_url it prints
p202 destination update 2 --campaign-conversion-action 12=987655   # sets campaign 12's, keeps the others; 12= removes it
p202 destination test 2
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
| `POST` | `/destinations/{id}/oauth/start` | Google Ads: the consent screen's `authorisation_url` and the `redirect_uri` to register |
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

```json
POST /api/v3/destinations
{
  "type": "google_ads",
  "name": "Search campaigns",
  "settings": {"customer_id": "123-456-7890", "conversion_action": "987654", "campaign_conversion_actions": {"12": "987655"}},
  "credentials": {"developer_token": "…", "oauth_client_id": "….apps.googleusercontent.com", "oauth_client_secret": "…"}
}
```

A read shows `status`: `ready`, `needs_authorisation` (a Google Ads
destination not connected yet) or `credentials_unreadable`, and `waiting`:
null, or `{"reason", "status_code", "until"}` while conversions wait for you
(not connected, a refused refresh token, a developer token Google has not
approved, lost access, a wrong customer ID or conversion action). `until` is
when the next one is tried; saving or connecting the destination makes them
due at once.
