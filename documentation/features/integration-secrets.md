# Integration secrets

From 1.9.81 the secrets Prosper202 verifies incoming integration requests
with are kept in one sealed store, `202_integration_secrets`, instead of in
plaintext columns. The Shopify webhook secret is the first moved there; the
upgrade to 1.9.81 moves every account's secret out of `202_users_pref` and
drops that column.

## What the store does

- **Sealed at rest.** Each secret is encrypted with AES-256-GCM under a data
  key of its own, and that data key is encrypted under the install's
  secrets key (envelope encryption). The account, the secret's name and
  the slot (current or previous) are bound into the encryption, so a value
  copied onto another account or secret does not open.
- **Never served back.** The API answers a stored secret as
  `{"stored": true}` (`GET /users/{id}/preferences`), and
  `GET /system/integrations` says when it was set (`secret_set_at`) and,
  after a rotation, until when the old one is still accepted
  (`previous_secret_expires_at`). Account › API integrations shows the last
  four characters of a long secret, as before.
- **Rotation with an overlap.** Replacing the Shopify secret on Account ›
  API integrations can keep the old one verifying webhooks for 7 days
  ("Keep the old secret verifying"), so webhooks signed before your store
  switched still land. Without it, the old secret stops at once, which is
  what you want for a secret that leaked. `PUT /users/{id}/preferences`
  replaces it with no overlap.
- **Every change recorded.** `202_integration_secret_events` keeps each set,
  replacement, rotation, removal and upgrade move: the account, who asked
  and how (page, API, upgrade), when, and the id of the key it was sealed
  with. Never the value.

## The secrets key

The key that seals the data keys is read, in this order, from:

1. the environment variable `P202_SECRETS_KEY`: 64 hexadecimal characters,
   for example the output of `openssl rand -hex 32`;
2. the environment variable `P202_SECRETS_KEY_FILE`, or
   `define('P202_SECRETS_KEY_FILE', '/path/to/key');` in `202-config.php`:
   the absolute path of a file holding such a key. The constant is the
   simplest way to give the web server and the cron jobs the same key.

Set exactly one; more than one is refused. With none set, Prosper202 uses a
key it mints once in `202_deployment_secrets` (`integration_secrets`). That
is the documented fallback: it keeps the secrets out of anything that reads
the store's table alone (an export, a debugging query, a support bundle),
but someone holding a full database dump holds the key too. A key kept
outside the database is what keeps a dump from opening them.

What happens when the key is wrong:

- A key that is set but cannot be read (not 64 hex characters, a file that
  is missing or unreadable, a relative path) is an error: nothing is sealed
  or opened, and the server log names the variable or file. Prosper202 never
  falls back to the database's key because the configured one failed.
- A secret sealed with a key this process does not have is an error naming
  the secret, the account and the key's id, never an empty secret. Shopify
  webhooks are then answered with a failure Shopify retries, not as
  unverified.
- Once any secret is sealed with an external key, a process without that key
  refuses to save secrets with the database's key.

Secrets sealed with the database's key keep opening after you configure an
external key; each is sealed with the external key the next time it is
saved.

## What is not in the store yet

The Shopify webhook secret is the first. These are still plaintext and move
in later releases: the ClickBank, JVZoo, Zaxaa and PayKickstart keys, the
IPQualityScore and RevContent credentials and the Slack webhook URL in
`202_users_pref`; the DNI network API keys (`202_dni_networks.apiKey`); the
LTV webhook and attribution export signing secrets. The Play Integrity
credentials, destination and ad platform credentials, postback credentials
and sync job API keys are already sealed, under keys in
`202_deployment_secrets`.
