# Prosper202 Messaging — Central Server API Contract

This document defines the HTTP API that the **central server** (`my.tracking202.com`)
must implement so that self-hosted Prosper202 installs (the *client*) can deliver an
Intercom-style messenger to their users.

The client side (this repository) is implemented in:

- `202-config/Messaging/MessagingClient.class.php` — HTTP transport
- `202-config/Messaging/MessagingService.class.php` — local cache + sync orchestration
- `202-account/ajax/messaging/*.php` — browser endpoints
- `202-js/messenger.php`, `202-css/messenger.css` — the floating widget
- `202-cronjobs/sync-messaging.php` — proactive background delivery

Because each Prosper202 install is self-hosted and can only make **outbound** HTTPS
requests, all communication is **client-initiated (pull)**. The central server never
connects to an install. The client polls; the central server queues.

---

## Base URL

```
https://my.tracking202.com/api/v3/messaging
```

Configured client-side as `MESSAGING_API_URL` in `202-config/connect.php`.

## Transport

- All requests are `POST` with a JSON body and `Content-Type: application/json`.
- All responses are JSON with `Content-Type: application/json` and HTTP `200` on success.
- Non-`200` responses are treated as failures; the client keeps its local cache and retries later.
- TLS certificate verification is enforced (`CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST`). Use a valid certificate.

## Authentication & identity

Every request body includes an `identity` object. The central server authenticates the
install with `api_key` + `install_hash` and uses the remaining fields for **targeting**
(broadcasts to a customer/cohort) and **routing** (which user a conversation belongs to).

```json
{
  "identity": {
    "install_hash":  "32-char install identifier",
    "api_key":       "p202_customer_api_key for this install",
    "user_id":       123,
    "user_email":    "user@example.com",
    "registered_at": "2024-01-02 03:04:05",
    "attributes": {
      "plan": "pro",
      "monthly_clicks": 18204,
      "trackers_created": 12
    }
  }
}
```

| Field          | Source (client)                          | Purpose                              |
|----------------|------------------------------------------|--------------------------------------|
| `install_hash` | `202_users.install_hash`                 | Identifies the installation          |
| `api_key`      | `202_users.p202_customer_api_key`        | Authenticates the customer account   |
| `user_id`      | local `202_users.user_id`                | Routes conversations to a user       |
| `user_email`   | `202_users.user_email`                   | Targeting / display on central side  |
| `registered_at`| `202_users.user_time_register`           | Cohort targeting (e.g. "new users")  |
| `attributes`   | `202_messaging_attributes` snapshot      | **Custom attributes for segmentation** |

`attributes` is the latest snapshot of custom attributes set by page JavaScript via
`Prosper202Messenger('update', {...})` (see *Client JavaScript API* below). It is sent
on **every** request so the central server always has fresh data to segment audiences on.
Values are scalars (string/number/bool); nested objects are not guaranteed.

If `api_key`/`install_hash` do not validate, respond `401`. The client degrades
gracefully (shows cached data, no error to the user).

---

## Targeting model (server-side)

Targeting is entirely the central server's responsibility. Both modes Intercom offers
are supported by the same `pull` response:

- **Broadcast / one-way** — the server creates a `type: "broadcast"` conversation for
  every user matching an audience (all users, a plan, a signup cohort, a specific
  `user_id`, etc.). The user may reply, which upgrades it into a two-way thread.
- **Two-way conversation** — the server creates/continues a `type: "conversation"`
  thread for a specific user, and support agents reply into it.

The client does not filter or target; it simply renders whatever `pull` returns for the
identified user.

---

## Endpoints

### `POST /pull`

Returns all conversations and messages visible to the identified user. The client
upserts the result into its local cache keyed by `external_id`, so the endpoint may
return either full state or only changes since `cursor`.

**Request**

```json
{
  "identity": { ... },
  "cursor": "opaque string from previous pull, or null on first sync"
}
```

**Response**

```json
{
  "ok": true,
  "server_time": "2026-06-16 07:00:00",
  "cursor": "opaque-cursor-to-send-next-time",
  "conversations": [
    {
      "external_id": "conv_abc123",
      "type": "conversation",          // "conversation" | "broadcast"
      "subject": "Welcome to Prosper202",
      "status": "open",                 // "open" | "closed"
      "last_message_at": "2026-06-16 06:59:00",
      "messages": [
        {
          "external_id": "msg_001",
          "direction": "inbound",       // "inbound" (team→user) | "outbound" (user→team)
          "author": "team",             // "team" | "system" | "user"
          "body": "Hi! Need a hand getting set up?",
          "created_at": "2026-06-16 06:59:00"
        }
      ]
    }
  ]
}
```

Notes:
- `direction` is from the **user's** perspective: `inbound` = received, `outbound` = sent.
- `body` is plain text. The client HTML-escapes it before rendering. Do not send HTML.
- `cursor` is opaque to the client and echoed back on the next `pull`. Use it to return
  only deltas; returning full state every time is also valid (the client upserts).
- Messages the user already sent (echoed back with a real `external_id`) let the client
  reconcile its locally-queued copies via `client_token` (see `send`).

### `POST /send`

Delivers a message the user composed in the widget.

**Request**

```json
{
  "identity": { ... },
  "conversation_external_id": "conv_abc123 or null to start a new thread",
  "body": "plain text the user typed",
  "client_token": "uuid generated by the client for idempotency"
}
```

**Response**

```json
{
  "ok": true,
  "conversation": {
    "external_id": "conv_abc123",
    "type": "conversation",
    "subject": "Welcome to Prosper202",
    "status": "open"
  },
  "message": {
    "external_id": "msg_042",
    "client_token": "the uuid from the request",
    "direction": "outbound",
    "author": "user",
    "body": "plain text the user typed",
    "created_at": "2026-06-16 07:01:00"
  }
}
```

- `client_token` **must** be echoed back so the client can match the server's canonical
  message to the optimistic local copy and avoid duplicates. Treat repeated
  `client_token`s as idempotent (return the same message).
- If `conversation_external_id` is null, create a new `conversation` thread and return it.

### `POST /read`

Reports which inbound messages the user has read (for agent-side read receipts).

**Request**

```json
{
  "identity": { ... },
  "message_external_ids": ["msg_001", "msg_002"]
}
```

**Response**

```json
{ "ok": true }
```

This is advisory; the client also tracks read state locally for its unread badge.

### `POST /track`

Delivers custom attributes and behavioural events for **segmentation**. The client
batches these (queued locally, flushed on sync) so a flush may carry the latest attribute
snapshot plus several events at once.

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

- `attributes` mirrors what is sent inside `identity.attributes`; it is included here too
  so a dedicated flush can update the central profile even when no message is pulled.
- Each event has a `client_token`; treat repeated tokens as idempotent so retries do not
  double-count.
- The central server stores attributes on the user profile and records events on a
  timeline, both usable to define audiences for broadcasts.

---

## Client JavaScript API

Page code on a Prosper202 install can feed the messenger exactly the way Intercom's
JavaScript API works — a command queue on a single global function. The widget
(`202-js/messenger.php`) installs this before it finishes loading, so calls made early
are buffered and replayed.

```js
// Set/merge custom attributes on the current user (for segmentation):
Prosper202Messenger('update', { plan: 'pro', monthly_clicks: 18204 });

// Record a behavioural event (optionally with metadata):
Prosper202Messenger('trackEvent', 'created_tracker', { tracker_id: 42 });

// Control the widget:
Prosper202Messenger('show');     // open the panel
Prosper202Messenger('hide');     // close the panel
Prosper202Messenger('toggle');   // toggle the panel
```

`update` and `trackEvent` POST to `202-account/ajax/messaging/track.php`, which persists
the data locally (`202_messaging_attributes`, `202_messaging_events`) and forwards it to
`POST /track` on the next sync. Attribute values should be scalars.

---

## Error handling

| Situation                         | Server response | Client behavior                         |
|-----------------------------------|-----------------|-----------------------------------------|
| Bad/missing auth                  | `401`           | Silent; keep cache; retry next cycle    |
| Malformed request                 | `400`           | Logged; retried with backoff            |
| Transient server error            | `5xx`           | Retried with exponential backoff        |
| Success                           | `200` + `ok:true`| Cache updated                           |

The client never surfaces raw transport errors to end users; the widget simply shows the
last successfully cached state.
