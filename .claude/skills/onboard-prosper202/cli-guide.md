# p202 CLI reference (onboarding subset)

Verified against the Go CLI in `go-cli/`. Run any command with `--help` to see the
full flag list; add `--json` for machine-parseable output.

## Config
| Command | Purpose |
|---------|---------|
| `p202 config set-url <url>` | Set the Prosper202 instance URL |
| `p202 config set-key <api-key>` | Set the REST API (Bearer) key |
| `p202 config test` | Verify connectivity (`/api/v3/system/health`) |
| `p202 config show` | Show current config |

## System
| Command | Purpose |
|---------|---------|
| `p202 system health` | Health check (no auth) |
| `p202 system version` | API version |
| `p202 system cron` | Cron status |

## Preferences
`p202 user prefs update <user_id> [flags]`

- `--user-tracking-domain` — tracking domain
- `--user-account-currency` — 3-letter currency code
- `--user-daily-email` — the hour to send it, `00`-`23` in the user's time zone, or `never`
- `--user-slack-incoming-webhook` — Slack webhook URL
- `--ipqs-api-key` — IPQS fraud key

`p202 user prefs get <user_id>` reads them back.

## Entities (create)
Each supports `create` and `--json`. Required flags in **bold**.

| Resource | Key flags |
|----------|-----------|
| `ppc-network` | **`--ppc-network-name`** |
| `ppc-account` | **`--ppc-account-name`**, `--ppc-network-id`, `--ppc-account-default` |
| `aff-network` | **`--aff-network-name`**, `--aff-network-postback-url`, `--aff-network-postback-append`, `--dni-network-id` |
| `campaign` | **`--aff-campaign-name`**, **`--aff-campaign-url`**, `--aff-network-id`, `--aff-campaign-payout`, `--aff-campaign-cpc`, `--aff-campaign-currency`, `--aff-campaign-url-2..5`, `--aff-campaign-postback-url` |
| `landing-page` | **`--landing-page-url`**, `--aff-campaign-id`, `--landing-page-nickname`, `--landing-page-type`, `--leave-behind-page-url` |
| `tracker` | **`--aff-campaign-id`**, `--ppc-account-id`, `--landing-page-id`, `--text-ad-id`, `--rotator-id`, `--click-cpc`, `--click-cpa` |

## Tracker URL
- `p202 tracker get-url <id>` — tracking URL for an existing tracker. Add
  `--t202kw '{keyword}'` (and `--c1`..`--c4`, `--utm_*`, `--t202ref`, `--t202b`)
  with the traffic source's macros, as the Get Links boxes take them.
- `p202 tracker create-with-url [tracker flags]` — create and return the URL in one call.

## Reporting
- `p202 dashboard [--period today|yesterday|last7|last30|last90] [--json]`
- `p202 report breakdown` / `p202 analytics` — grouped stats.

## Authentication model
The CLI authenticates with `Authorization: Bearer <key>` where `<key>` is a row in
the local `202_api_keys` table — generated on the install success screen or under
**Account → REST API Keys**. It is distinct from `p202_customer_api_key` (the paid
my.tracking202 install key).
