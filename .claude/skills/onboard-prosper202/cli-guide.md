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

- `--user_tracking_domain` — tracking domain
- `--user_account_currency` — 3-letter currency code
- `--user_daily_email` — `on`/`off`
- `--user_slack_incoming_webhook` — Slack webhook URL
- `--ipqs_api_key` — IPQS fraud key

`p202 user prefs get <user_id>` reads them back.

## Entities (create)
Each supports `create` and `--json`. Required flags in **bold**.

| Resource | Key flags |
|----------|-----------|
| `ppc-network` | **`--ppc_network_name`** |
| `ppc-account` | **`--ppc_account_name`**, `--ppc_network_id`, `--ppc_account_default` |
| `aff-network` | **`--aff_network_name`**, `--aff_network_postback_url`, `--aff_network_postback_append`, `--dni_network_id` |
| `campaign` | **`--aff_campaign_name`**, **`--aff_campaign_url`**, `--aff_network_id`, `--aff_campaign_payout`, `--aff_campaign_cpc`, `--aff_campaign_currency`, `--aff_campaign_url_2..5`, `--aff_campaign_postback_url` |
| `landing-page` | **`--landing_page_url`**, `--aff_campaign_id`, `--landing_page_nickname`, `--landing_page_type`, `--leave_behind_page_url` |
| `tracker` | **`--aff_campaign_id`**, `--ppc_account_id`, `--landing_page_id`, `--text_ad_id`, `--rotator_id`, `--click_cpc`, `--click_cpa` |

## Tracker URL
- `p202 tracker get-url <id>` — tracking URL for an existing tracker.
- `p202 tracker create-with-url [tracker flags]` — create and return the URL in one call.

## Reporting
- `p202 dashboard [--period today|yesterday|last7|last30|last90] [--json]`
- `p202 report breakdown` / `p202 analytics` — grouped stats.

## Authentication model
The CLI authenticates with `Authorization: Bearer <key>` where `<key>` is a row in
the local `202_api_keys` table — generated on the install success screen or under
**Account → REST API Keys**. It is distinct from `p202_customer_api_key` (the paid
my.tracking202 install key).
