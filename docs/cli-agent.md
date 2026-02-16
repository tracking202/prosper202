# Prosper202 CLI -- Agent & LLM Integration Guide

This document describes how to use the `p202` CLI from an AI agent, automation script, or LLM tool-use context. The CLI was explicitly designed for both human operators and programmatic consumers.

## Key principles

1. **Always use `--json`** -- Table output is for humans. JSON output gives you the exact API response with stable, parseable structure.
2. **Always use `--force` on deletes** -- Interactive confirmation prompts will hang a non-interactive process.
3. **Never rely on table column order** -- Use `--json` and parse the response fields by name.
4. **Do not rely on interactive password prompts** -- In non-interactive runs, pass the password explicitly: `--user_pass "thepassword"`.

## Setup

Configure the CLI before use. These commands are idempotent.

```bash
p202 config set-url https://your-prosper202-instance.example.com
p202 config set-key YOUR_API_KEY

# Optional: create explicit named profiles
p202 config add-profile prod --url https://prod.example.com --key PROD_KEY
p202 config add-profile staging --url https://staging.example.com --key STAGING_KEY
p202 config use prod
```

Verify with:

```bash
p202 config test --json
```

Expected response on success:

```json
{
  "data": {
    "status": "healthy",
    "timestamp": 1700000000,
    "api_version": "v3"
  }
}
```

If this fails, the URL or API key is wrong. Check `p202 config show --json` to inspect the stored values.

## JSON output structure

Every response follows one of these shapes:

### Single object

```json
{
  "data": {
    "field": "value"
  }
}
```

### Paginated list

```json
{
  "data": [
    {"field": "value"},
    {"field": "value"}
  ],
  "pagination": {
    "total": 42,
    "limit": 50,
    "offset": 0
  }
}
```

### Success message (void operations like delete)

```
Deleted campaign 42.
```

Void operations print a plain-text success message to stdout. There is no JSON body -- the API returns 204 No Content.

### Error

Errors are printed to stderr and the process exits with code 1. The error message contains the API's error text or the HTTP status code.

## Error handling

| Exit code | Meaning |
|-----------|---------|
| 0 | Success |
| 1 | Any error (network, auth, validation, not found) |

When parsing errors programmatically, check the exit code first. If non-zero, the stderr output contains the error description.

The API may return structured errors with field-level detail:

```
Error: Validation failed
  aff_campaign_name: required
  aff_campaign_url: required
```

## Common workflows

### List all campaigns with full data

```bash
p202 campaign list --limit 500 --json
```

To paginate through all results:

```bash
# Page 1
p202 campaign list --limit 100 --offset 0 --json
# Page 2
p202 campaign list --limit 100 --offset 100 --json
# Continue until data array is empty or offset >= pagination.total
```

### Create a resource and capture its ID

```bash
RESULT=$(p202 campaign create \
  --aff_campaign_name "New Campaign" \
  --aff_campaign_url "https://example.com/offer" \
  --json)

CAMPAIGN_ID=$(echo "$RESULT" | jq -r '.data.aff_campaign_id')
```

### Get a performance summary

```bash
p202 report summary --period today --json
```

Response fields: `total_clicks`, `total_leads`, `total_income`, `total_cost`, `total_net`, `epc`, `avg_cpc`, `conv_rate`, `roi`, `cpa`.

### Break down performance by dimension

```bash
p202 report breakdown --breakdown country --period last7 --sort total_net --sort_dir DESC --limit 10 --json
```

Available breakdowns: `campaign`, `aff_network`, `ppc_account`, `ppc_network`, `landing_page`, `keyword`, `country`, `city`, `browser`, `platform`, `device`, `isp`, `text_ad`.

### Create a user with a known password

```bash
p202 user create \
  --user_name "agent_user" \
  --user_email "agent@example.com" \
  --user_pass "securepassword123" \
  --json
```

Always provide `--user_pass` explicitly. Without it, the CLI reads from stdin interactively.

### Generate an API key

```bash
RESULT=$(p202 user apikey create 1 --json)
API_KEY=$(echo "$RESULT" | jq -r '.data.api_key')
```

The full key is returned only at creation time. Store it.

### Delete with no prompt

```bash
p202 campaign delete 42 --force --json
```

Without `--force`, the CLI prompts for `[y/N]` confirmation which will hang a non-interactive process.

### Create a rotator with rules

```bash
# Create the rotator
ROTATOR=$(p202 rotator create --name "Geo Split" --json)
ROTATOR_ID=$(echo "$ROTATOR" | jq -r '.data.id')

# Add a rule with criteria and redirects
p202 rotator rule-create "$ROTATOR_ID" \
  --rule_name "US Traffic" \
  --criteria_json '[{"type":"country","statement":"is","value":"US"}]' \
  --redirects_json '[{"redirect_url":"https://us.example.com","weight":"100","name":"US Offer"}]' \
  --json
```

JSON fields (`--criteria_json`, `--redirects_json`, `--weighting_config`) are validated locally before the API call. Malformed JSON produces an immediate error.

### Check system health

```bash
p202 system health --json
```

This endpoint does not require authentication. Use it as a liveness probe.

## Complete command reference

Below is every command with its required and optional flags. Flags marked `(R)` are required for create operations.

### Config

```
p202 config set-url <url>
p202 config set-key <api-key>
p202 config show [--json]
p202 config test [--json]
p202 config set-default <key> <value>
p202 config get-default [key]
p202 config unset-default <key>
p202 config add-profile <name> --url <url> --key <key>
p202 config remove-profile <name> [--force]
p202 config use <name>
p202 config list-profiles [--tag <tag>]
p202 config rename-profile <old> <new>
p202 config tag-profile <name> <tag> [<tag>...]
p202 config untag-profile <name> <tag> [<tag>...]
```

Global profile selectors:
- `--profile <name>`: one-command profile override
- `--group <tag>`: profile-tag group selector for multi-profile commands

### CRUD resources

All seven resources (`campaign`, `aff-network`, `ppc-network`, `ppc-account`, `tracker`, `landing-page`, `text-ad`) support:

```
p202 <resource> list    [--limit N] [--offset N] [--page N] [--json]
p202 <resource> get     <id> [--json]
p202 <resource> create  --field value ... [--json]
p202 <resource> update  <id> --field value ... [--json]
p202 <resource> delete  <id> [--force] [--json]
```

#### Campaign fields

| Flag | Create | Type |
|------|--------|------|
| `--aff_campaign_name` | (R) | string |
| `--aff_campaign_url` | (R) | string |
| `--aff_campaign_url_2..5` | optional | string |
| `--aff_campaign_cpc` | optional | string |
| `--aff_campaign_payout` | optional | string |
| `--aff_campaign_currency` | optional | string |
| `--aff_campaign_foreign_payout` | optional | string |
| `--aff_network_id` | optional | string |
| `--aff_campaign_cloaking` | optional | 0/1 |
| `--aff_campaign_rotate` | optional | 0/1 |
| `--aff_campaign_postback_url` | optional | string |
| `--aff_campaign_postback_append` | optional | string |

#### Aff-network fields

| Flag | Create | Type |
|------|--------|------|
| `--aff_network_name` | (R) | string |
| `--dni_network_id` | optional | integer |
| `--aff_network_postback_url` | optional | string |
| `--aff_network_postback_append` | optional | string |

#### PPC network fields

| Flag | Create | Type |
|------|--------|------|
| `--ppc_network_name` | (R) | string |

#### PPC account fields

| Flag | Create | Type |
|------|--------|------|
| `--ppc_account_name` | (R) | string |
| `--ppc_network_id` | (R) | string |
| `--ppc_account_default` | optional | 0/1 |

#### Tracker fields

| Flag | Create | Type |
|------|--------|------|
| `--aff_campaign_id` | (R) | string |
| `--ppc_account_id` | optional | string |
| `--text_ad_id` | optional | string |
| `--landing_page_id` | optional | string |
| `--rotator_id` | optional | string |
| `--click_cpc` | optional | string |
| `--click_cpa` | optional | string |
| `--click_cloaking` | optional | 0/1 |

Tracker utility commands:

```
p202 tracker get-url <id> [--json]
p202 tracker create-with-url --aff_campaign_id N [tracker flags...] [--json]
p202 tracker bulk-urls [--aff_campaign_id N] [--ppc_account_id N]
                       [--landing_page_id N] [--concurrency N] [--json]
```

#### Landing page fields

| Flag | Create | Type |
|------|--------|------|
| `--landing_page_url` | (R) | string |
| `--aff_campaign_id` | (R) | string |
| `--landing_page_nickname` | optional | string |
| `--leave_behind_page_url` | optional | string |
| `--landing_page_type` | optional | integer |

#### Text ad fields

| Flag | Create | Type |
|------|--------|------|
| `--text_ad_name` | (R) | string |
| `--text_ad_headline` | optional | string |
| `--text_ad_description` | optional | string |
| `--text_ad_display_url` | optional | string |
| `--aff_campaign_id` | optional | string |
| `--landing_page_id` | optional | string |
| `--text_ad_type` | optional | integer |

### Clicks (read-only)

```
p202 click list [--limit 50] [--offset 0] [--time_from T] [--time_to T]
                [--aff_campaign_id N] [--ppc_account_id N] [--landing_page_id N]
                [--click_lead 0|1] [--click_bot 0|1] [--json]
p202 click get <id> [--json]
```

### Conversions

```
p202 conversion list   [--limit 50] [--offset 0] [--campaign_id N]
                       [--time_from T] [--time_to T] [--json]
p202 conversion get    <id> [--json]
p202 conversion create --click_id N [--payout F] [--transaction_id S] [--json]
p202 conversion delete <id> [--force] [--json]
```

### Reports

```
p202 dashboard         [-p period] [filters...] [--all-profiles | --profiles P1,P2 | --group TAG] [--json]
p202 report summary    [-p period] [--time_from T] [--time_to T] [filters...]
                       [--all-profiles | --profiles P1,P2 | --group TAG] [--json]
p202 report breakdown  [-b dimension] [-s sort_col] [--sort_dir ASC|DESC]
                       [-l limit] [-o offset] [-p period] [filters...] [--json]
p202 report timeseries [-i interval] [-p period] [filters...] [--json]
p202 report daypart    [-s sort_col] [--sort_dir ASC|DESC] [-p period] [filters...] [--json]
p202 report weekpart   [-s sort_col] [--sort_dir ASC|DESC] [-p period] [filters...] [--json]
```

Report filter flags (all optional): `--aff_campaign_id`, `--ppc_account_id`, `--aff_network_id`, `--ppc_network_id`, `--landing_page_id`, `--country_id`.

`dashboard` defaults `period=today` if omitted.

Multi-profile report output includes:
- per-profile result objects
- aggregated numeric totals
- partial error list (`errors`) if one or more profiles fail

### Rotators

```
p202 rotator list   [--limit N] [--offset N] [--json]
p202 rotator get    <id> [--json]
p202 rotator create --name S [--default_url S] [--default_campaign N] [--default_lp N] [--json]
p202 rotator update <id> [--name S] [--default_url S] [--default_campaign N] [--default_lp N] [--json]
p202 rotator delete <id> [--force] [--json]

p202 rotator rule-create <rotator_id> --rule_name S [--splittest 0|1]
                         [--criteria_json JSON] [--redirects_json JSON] [--json]
p202 rotator rule-delete <rotator_id> <rule_id> [--force] [--json]
```

### Attribution

```
p202 attribution model list      [--type T] [--json]
p202 attribution model get       <id> [--json]
p202 attribution model create    --model_name S --model_type T
                                 [--weighting_config JSON] [--is_active 0|1]
                                 [--is_default 0|1] [--json]
p202 attribution model update    <id> [flags...] [--json]
p202 attribution model delete    <id> [--force] [--json]

p202 attribution snapshot list   <model_id> [--scope_type S] [--limit 100] [--offset 0] [--json]

p202 attribution export list     <model_id> [--json]
p202 attribution export schedule <model_id> [--scope_type S] [--scope_id N]
                                 [--start_hour T] [--end_hour T]
                                 [--format csv|json] [--webhook_url URL] [--json]
```

### Users

```
p202 user list   [--json]
p202 user get    <id> [--json]
p202 user create --user_name S --user_email S --user_pass S
                 [--user_fname S] [--user_lname S] [--user_timezone S] [--json]
p202 user update <id> [--user_fname S] [--user_lname S] [--user_email S]
                 [--user_pass S] [--user_timezone S] [--user_active 0|1] [--json]
p202 user delete <id> [--force] [--json]

p202 user role list [--json]
p202 user role assign <user_id> --role_id N [--json]
p202 user role remove <user_id> <role_id> [--force] [--json]

p202 user apikey list   <user_id> [--json]
p202 user apikey create <user_id> [--json]
p202 user apikey delete <user_id> <api_key> [--force] [--json]
p202 user apikey rotate <user_id> <old_api_key> [--keep-old] [--force]
                       [--update-config] [--force-config-update] [--json]

p202 user prefs get    <user_id> [--json]
p202 user prefs update <user_id> [--user_tracking_domain S]
                       [--user_account_currency S] [--user_slack_incoming_webhook S]
                       [--user_daily_email S] [--ipqs_api_key S] [--json]
```

### Data portability

```
p202 export <entity|all> [--output PATH] [--json]
p202 import <entity> <file> [--dry-run] [--skip-errors] [--json]
```

Supported export entities: `campaigns`, `aff-networks`, `ppc-networks`, `ppc-accounts`, `rotators`, `trackers`, `landing-pages`, `text-ads`, `all`.

### Multi-server workflows

```
p202 diff <entity|all> --from <profile> --to <profile> [--json]
p202 sync <entity|all> --from <profile> --to <profile> [--dry-run] [--skip-errors] [--force-update] [--json]
p202 sync status --from <profile> --to <profile> [--json]
p202 sync history --from <profile> --to <profile> [--json]
p202 re-sync --from <profile> --to <profile> [--dry-run] [--skip-errors] [--force-update] [--json]
p202 exec --all-profiles [--concurrency N] -- <subcommand...>
p202 exec --profiles <p1,p2,...> [--concurrency N] -- <subcommand...>
p202 exec --group <tag> [--concurrency N] -- <subcommand...>
```

Notes for agents:
- Use `sync --dry-run` before real sync.
- `sync` and `re-sync` store state in `~/.p202/sync/<source>-<target>.json`.
- `exec` returns non-zero if any profile execution fails.
- `exec --concurrency` controls fan-out parallelism (default `5`, minimum `1`).
- If the API reports `sync_plan` and `async_jobs` capabilities, CLI auto-routes to server-side `/sync/*` endpoints.
- If server capabilities are unavailable, CLI falls back to local diff/sync behavior.
- When async jobs are enabled server-side, ensure the sync worker is running (`POST /sync/worker/run` or cron script `202-cronjobs/sync-worker.php`).

### System

```
p202 system health     [--json]     # No auth required
p202 system version    [--json]     # Admin only
p202 system db-stats   [--json]     # Admin only
p202 system cron       [--json]     # Admin only
p202 system errors     [--limit N] [--json]  # Admin only
p202 system dataengine [--json]     # Admin only
```

## Tool-use schema hints

If you are defining this CLI as a tool for an LLM, here are recommendations:

### Minimum tool definition

A single "execute p202 command" tool is sufficient. The LLM constructs the full command string.

```json
{
  "name": "p202",
  "description": "Execute a Prosper202 CLI command. Always include --json for parseable output and --force for delete operations.",
  "input_schema": {
    "type": "object",
    "properties": {
      "command": {
        "type": "string",
        "description": "The full p202 command to execute, e.g. 'p202 campaign list --limit 10 --json'"
      }
    },
    "required": ["command"]
  }
}
```

### Granular tool definitions

For tighter control, define separate tools per operation category:

- `p202_list` -- List any resource type
- `p202_get` -- Get a single resource by ID
- `p202_create` -- Create a resource with fields
- `p202_update` -- Update a resource
- `p202_delete` -- Delete a resource (always include --force)
- `p202_report` -- Generate reports
- `p202_system` -- System diagnostics

### System prompt snippet

If embedding this CLI as a tool for an LLM agent, include this in the system prompt:

```
You have access to the Prosper202 CLI (p202) for managing an affiliate tracking platform.

Rules:
- Always append --json to get structured output
- Always append --force to delete commands
- Provide --user_pass explicitly for user create/update (do not rely on interactive prompt)
- Use unix timestamps for time_from/time_to parameters
- Pagination: check pagination.total vs offset+limit to determine if more pages exist
- The health endpoint (p202 system health) does not require authentication
- All other endpoints require a valid API key configured via p202 config set-key
```

## Idempotency and safety

| Operation | Idempotent | Side effects |
|-----------|------------|--------------|
| list / get | Yes | None (read-only) |
| create | No | Creates a new resource each call |
| update | Yes | Same input produces same state |
| delete | Yes | First call deletes, subsequent calls return 404 |
| config set-url/set-key | Yes | Overwrites stored value |
| report | Yes | None (read-only) |

For agents that may retry on failure: list, get, update, delete, and report commands are safe to retry. Create commands are not -- a retry may produce duplicates.
