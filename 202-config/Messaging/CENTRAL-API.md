# Prosper202 product analytics — portal API contract

This document defines the HTTP endpoint the **portal** (`my.tracking202.com`)
implements so that self-hosted Prosper202 installs can deliver product
analytics: custom attributes and behavioural events, used to segment
announcements and onboarding.

Chat is not part of this contract. Installs from 1.9.77 on do not store or
sync chat messages: `202-account/ajax/chat-pass.php` asks the portal for a
chat pass (`POST /api/v3/chat/pass`, `CHAT_PASS_URL` in `connect.php`) and
the chat box (`202-js/p202-chat.js`) talks to the chat service with it.
Installs still on 1.9.76 keep calling `/pull`, `/send` and `/read`: pull
answers with announcements only, and send answers 410 "Chat has moved.
Update Prosper202 to chat with support."

The client side (this repository) is implemented in:

- `202-config/Messaging/MessagingClient.class.php` — HTTP transport
- `202-config/Messaging/MessagingService.class.php` — the local queue and delivery
- `202-account/ajax/messaging/track.php` — the browser endpoint
- `202-js/p202-chat.js` — the `Prosper202Messenger` command queue
- `MessagingService::deliverPending()` — background delivery, run hourly by
  `202-cronjobs/index.php` on every default deployment and by
  `202-cronjobs/sync-messaging.php` on its own

Each install is self-hosted and can only make **outbound** HTTPS requests, so
all communication is client-initiated. The portal never connects to an
install.

---

## Base URL

```
https://my.tracking202.com/api/v3/messaging
```

Configured client-side as `MESSAGING_API_URL` in `202-config/connect.php`
(environment override; `http://` only on loopback).

## Transport

- `POST` with a JSON body and `Content-Type: application/json`.
- Responses are JSON with HTTP `200` on success.
- Non-`200` responses (and `{"ok": false}`) are failures; the client keeps the
  rows queued and retries on a later delivery.
- TLS certificate verification is enforced. Use a valid certificate.

## Authentication & identity

Every request body includes an `identity` object. The portal authenticates the
install with `api_key` + `install_hash` and uses the remaining fields for
targeting.

```json
{
  "identity": {
    "install_hash":  "install identifier",
    "api_key":       "p202_customer_api_key for this install",
    "user_id":       123,
    "user_email":    "user@example.com",
    "registered_at": 1700000000,
    "attributes": {
      "plan": "pro",
      "monthly_clicks": 18204
    }
  }
}
```

| Field          | Source (client)                          | Purpose                              |
|----------------|------------------------------------------|--------------------------------------|
| `install_hash` | `202_users.install_hash`                 | Identifies the installation          |
| `api_key`      | `202_users.p202_customer_api_key`, or the install owner's for a sub-user without one | Authenticates the customer account |
| `user_id`      | local `202_users.user_id`                | Identifies the user within the install |
| `user_email`   | `202_users.user_email`                   | Targeting                            |
| `registered_at`| `202_users.user_time_register` (unix seconds) | Cohort targeting (e.g. "new users") |
| `attributes`   | `202_messaging_attributes` snapshot      | **Custom attributes for segmentation** |

Only an active, undeleted user has an identity
(`MessagingService::identityFor()`, which the chat pass request is built from
too). If `api_key`/`install_hash` do not validate, respond `401`.

---

## `POST /track`

Delivers custom attributes and behavioural events. The client queues them
locally and delivers in batches, so one call may carry the latest attribute
snapshot plus several events.

**Request**

```json
{
  "identity": { ... },
  "attributes": {
    "plan": "pro",
    "monthly_clicks": 18204
  },
  "events": [
    {
      "name": "created_tracker",
      "metadata": { "tracker_id": 42, "source": "google" },
      "occurred_at": "2026-06-16 07:05:00",
      "client_token": "uuid for idempotency"
    }
  ]
}
```

**Response**

```json
{ "ok": true }
```

- `attributes` mirrors `identity.attributes`.
- Each event has a `client_token`; treat a repeated token as idempotent so a
  retry does not double-count.
- The client calls `/track` only when an event is pending or the attribute
  snapshot changed since the last delivery, at most once per
  `MESSAGING_SYNC_THROTTLE` seconds (default 20) per user from a page, and
  once per run from the cron job.

---

## Client JavaScript API

Page code on a Prosper202 install feeds this through a command queue on a
single global function, which `template_bottom()` defines on every signed-in
page and `202-js/p202-chat.js` replaces when it loads, replaying anything
queued.

```js
// Set/merge custom attributes on the current user (for segmentation):
Prosper202Messenger('update', { plan: 'pro', monthly_clicks: 18204 });

// Record a behavioural event (optionally with metadata):
Prosper202Messenger('trackEvent', 'created_tracker', { tracker_id: 42 });

// The chat box:
Prosper202Messenger('show');     // open it
Prosper202Messenger('hide');     // close it
Prosper202Messenger('toggle');   // toggle it
```

`update` and `trackEvent` POST to `202-account/ajax/messaging/track.php`, which
records the data (`202_messaging_attributes`, `202_messaging_events`) and then
delivers whatever is pending to `POST /track`, throttled per user. Its answer
says what happened (`delivery`: `sent`, `idle`, `throttled` or `failed`); when
the delivery was held back it carries `retry_after_s`, and the page posts
`flush=1` after that long so a held-back event does not wait for the next one.
Attribute values should be scalars.

---

## Error handling

| Situation                         | Portal response  | Client behaviour                        |
|-----------------------------------|------------------|-----------------------------------------|
| Bad/missing auth                  | `401`            | Logged; rows stay queued                |
| Malformed request                 | `400`            | Logged; an event is retried up to five times, then marked failed |
| Transient server error            | `5xx`            | Retried once at once, then on the next delivery |
| Success                           | `200` + `ok:true`| Rows marked sent, snapshot marked clean |

The client never shows transport errors to users.

---

## Operational note: the cron delivers users one by one

`MessagingService::deliverPending()` lists the active users with an event
still pending or a changed attribute snapshot, and delivers each one's rows in
batches of 100 events, one `/track` call per batch, up to 10 batches a user a
run. Users with nothing pending are not visited. After three failed
deliveries in a row the run stops and the rest wait for the next run: the
portal is most likely down, and each failed delivery spends one of an event's
five attempts.

`202-cronjobs/index.php` runs it hourly, so a portal outage of a few hours
does not use up those attempts. `202-cronjobs/sync-messaging.php` is the same
run on its own, for an operator who wants a tighter cadence; not more often
than every 5 minutes.

The page's own delivery (`track.php`) sends one batch a request. It answers
`delivery: "more"` with `retry_after_s` when rows are left, as it does for
`"throttled"`, and the page asks again then.
