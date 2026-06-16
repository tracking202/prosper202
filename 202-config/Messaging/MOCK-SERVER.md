# Local mock messaging server

`mock-server.php` is a dependency-free stand-in for the central
`my.tracking202.com` messaging API, so you can click through the messenger
widget locally. It implements the contract in `CENTRAL-API.md`.

## Run it

1. Start the mock (it acts as its own router):

   ```sh
   php -S 127.0.0.1:8787 202-config/Messaging/mock-server.php
   ```

2. Start Prosper202 pointed at the mock. The messaging URL and sync throttle are
   read from environment variables (see `202-config/connect.php`):

   ```sh
   MESSAGING_API_URL=http://127.0.0.1:8787/messaging \
   MESSAGING_SYNC_THROTTLE=0 \
   php -S 127.0.0.1:8080 -t .
   ```

   `MESSAGING_SYNC_THROTTLE=0` removes the 20s throttle so syncs happen on every
   widget poll — handy for a snappy demo.

3. Log in and look for the chat bubble at the bottom-right of any account page.

## What the mock demonstrates

- **First pull** seeds a "Welcome to Prosper202" broadcast.
- **Sending a message** creates a conversation and posts a canned team
  auto-reply, which appears on the next poll (≈25s, or sooner if you reopen the
  panel) — i.e. a two-way thread.
- **Segmentation**: set a custom attribute from the browser console and the mock
  delivers a targeted broadcast on the next pull:

  ```js
  Prosper202Messenger('update', { plan: 'pro' });   // -> "A Pro tip, just for you"
  Prosper202Messenger('trackEvent', 'created_tracker', { tracker_id: 42 });
  ```

## Dev conveniences

- `GET http://127.0.0.1:8787/` — status page dumping current mock state.
- `GET http://127.0.0.1:8787/reset` — wipe all mock state.

State persists to `<system temp dir>/p202_mock_messaging.json`. This is a dev
tool only: no authentication, no hardening.

## Offline behaviour

To confirm the app stays error-free when the central server is down, just don't
start the mock (or stop it). The widget will show cached/empty state, sends queue
locally as "Sending…", and nothing throws — the client treats every transport
failure as "keep the local cache and retry later".
