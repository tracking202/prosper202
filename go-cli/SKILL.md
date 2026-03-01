# Prosper202 CLI (p202)

Command-line tool for managing Prosper202 affiliate tracking instances. Works with any coding agent (Claude Code, Cursor, Codex, Gemini CLI, etc.).

## Setup

```bash
p202 config set-url https://your-prosper202.com
p202 config set-key YOUR_API_KEY
p202 config test  # verify connectivity
```

## Output Modes

All commands support these global flags:

- `--json` — machine-readable JSON on stdout; errors as JSON on stderr
- `--csv` — CSV output (mutually exclusive with `--json`)
- `--profile <name>` — target a specific named profile

Always use `--json` when parsing output programmatically. Errors with `--json` are structured:
```json
{"error": true, "category": "validation", "message": "...", "exit_code": 1, "field_errors": {}}
```

Success messages for void operations (delete, etc.) are also structured:
```json
{"success": true, "message": "Campaign 42 deleted."}
```

User-cancelled operations (when `--force` is not used and the user declines) are distinct from success:
```json
{"cancelled": true, "message": "Campaign 42 not deleted (cancelled by user)."}
```

## Non-Interactive Mode

For automation, avoid interactive prompts:

- `--force` / `-f` — skip all confirmation prompts (delete, remove, etc.)
- `--user_pass <password>` — supply password directly (user create/update)
- `--no-interaction` — Cobra built-in, suppresses all prompts

## CRUD Commands

Seven entities support full CRUD (list, get, create, update, delete):

| Entity | Command | Aliases |
|--------|---------|---------|
| Campaigns | `campaign` | — |
| Affiliate Networks | `aff-network` | `category` |
| Traffic Source Networks | `ppc-network` | `traffic-network` |
| Traffic Sources | `ppc-account` | `traffic-source` |
| Trackers | `tracker` | — |
| Landing Pages | `landing-page` | — |
| Ad Creatives | `text-ad` | — |

### Common patterns

```bash
# List with pagination
p202 campaign list --json
p202 campaign list --limit 50 --offset 100 --json
p202 campaign list --all --json  # fetch all pages

# Get by ID
p202 campaign get 42 --json

# Create (pass required fields as flags)
p202 campaign create --aff_campaign_name "My Campaign" --aff_network_id 1 --json

# Update (only changed fields)
p202 campaign update 42 --aff_campaign_name "New Name" --json

# Delete (single or bulk)
p202 campaign delete 42 --force --json
p202 campaign delete --ids 42,43,44 --force --json
```

### Tracker-specific commands

```bash
p202 tracker get-url 42 --json         # get tracking URL
p202 tracker create-with-url ... --json # create and return URL
p202 tracker bulk-urls --json           # concurrent URL fetch for all trackers
```

### Campaign cloning

```bash
p202 campaign clone 42 --name "Clone of Campaign" --json
```

## Reports

```bash
p202 report summary --period today --json
p202 report breakdown --breakdown campaign --period last7 --json
p202 report timeseries --period last30 --interval daily --json
p202 report weekpart --period last30 --json
p202 report daypart --period last30 --json
```

**Period values:** `today`, `yesterday`, `last7`, `last30`, `last90`
**Breakdown dimensions:** `campaign`, `aff_network`, `ppc_account`, `ppc_network`, `landing_page`, `keyword`, `country`, `city`, `browser`, `platform`, `device`, `isp`, `text_ad`
**Sort fields:** `total_clicks`, `total_leads`, `total_income`, `total_cost`, `total_net`, `roi`, `epc`, `conv_rate`

### Analytics shorthand

```bash
p202 analytics --group-by campaign --period today --json
p202 analytics --group-by country --sort revenue --sort-dir DESC --limit 10 --json
```

Sort aliases: `clicks`, `conversions`, `revenue`, `profit`, `cost`, `roi`, `epc`, `conv_rate`
Group-by aliases: `lp` = `landing_page`

## Clicks & Conversions

```bash
p202 click list --campaign_id 42 --time_from 1700000000 --json
p202 click get 123 --json

p202 conversion list --campaign_id 42 --json
p202 conversion get 456 --json
p202 conversion create --click_id 123 --payout 5.50 --json
p202 conversion delete 456 --force --json
```

## Rotators (Redirectors)

```bash
p202 rotator list --json
p202 rotator create --name "My Rotator" --json
p202 rotator rule-create 1 --rule_name "US Traffic" \
  --criteria_json '[{"type":"country","statement":"is","value":"US"}]' \
  --redirects_json '[{"redirect_url":"https://...","weight":"50","name":"A"}]' --json
p202 rotator rule-delete 1 2 --force --json
```

## Attribution

```bash
p202 attribution model list --json
p202 attribution model create --model_name "My Model" --model_type linear --json
p202 attribution model delete 1 --force --json
p202 attribution snapshot list 1 --json
p202 attribution export schedule 1 --format json --json
```

## User Management

```bash
p202 user list --json
p202 user create --user_name admin --user_email admin@example.com --user_pass secret --json
p202 user update 1 --user_pass newpassword --json
p202 user delete 1 --force --json

p202 user role list --json
p202 user role assign 1 --role_id 2 --json
p202 user role remove 1 2 --force --json

p202 user apikey list 1 --json
p202 user apikey create 1 --json
p202 user apikey delete 1 KEY --force --json
p202 user apikey rotate 1 OLDKEY --force --update-config --json

p202 user prefs get 1 --json
p202 user prefs update 1 --user_account_currency USD --json
```

## System

```bash
p202 system health --json   # no auth required
p202 system version --json
p202 system db-stats --json
p202 system cron --json
p202 system errors --limit 50 --json
p202 system dataengine --json
```

## Multi-Profile Management

```bash
# Add and switch profiles
p202 config add-profile staging --url https://staging.example.com --key KEY
p202 config use staging
p202 config list-profiles --json

# Tag profiles for group operations
p202 config tag-profile staging env:staging
p202 config tag-profile prod env:prod

# Run commands across profiles
p202 exec --all-profiles -- campaign list
p202 exec --group env:prod -- report summary --period today --json
p202 exec --profiles prod,staging -- system health --json

# Multi-profile reports (aggregated)
p202 report summary --all-profiles --period today --json
p202 dashboard --all-profiles --json
```

## Data Sync & Diff

```bash
# Compare entities between profiles
p202 diff all --from prod --to staging --json
p202 diff campaigns --from prod --to staging --json

# Sync entities
p202 sync all --from prod --to staging --dry-run --json
p202 sync campaigns --from prod --to staging --force-update --json

# Re-sync (incremental)
p202 re-sync --from prod --to staging --json
```

## Import / Export

```bash
# Export
p202 export all --output backup.json
p202 export campaigns --json

# Import
p202 import campaigns backup.json --dry-run --json
p202 import campaigns backup.json --skip-errors --json
```

Supported entities: `campaigns`, `aff-networks`, `ppc-networks`, `ppc-accounts`, `trackers`, `landing-pages`, `text-ads`, `rotators`

## Configurable Defaults

Save default flag values per profile to avoid repeating them:

```bash
p202 config set-default report.period last7
p202 config set-default report.breakdown campaign
p202 config get-default            # list all defaults
p202 config unset-default report.period
```

Supported keys: `report.period`, `report.time_from`, `report.time_to`, `report.aff_campaign_id`, `report.ppc_account_id`, `report.aff_network_id`, `report.ppc_network_id`, `report.landing_page_id`, `report.country_id`, `report.breakdown`, `report.sort`, `report.sort_dir`, `report.limit`, `report.offset`, `report.interval`, `crud.aff_campaign_id`, `crud.ppc_account_id`, `crud.aff_network_id`, `crud.ppc_network_id`, `crud.landing_page_id`, `crud.text_ad_id`, `crud.rotator_id`, `crud.country_id`

## Exit Codes

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | Validation error (bad input, missing flags) |
| 2 | Authentication/authorization failure |
| 3 | Network error (connection timeout, DNS failure) |
| 4 | Server error (API returned 5xx) |
| 5 | Partial failure (bulk operation with some failures) |

## Metrics / Telemetry

Set `P202_METRICS=1` to emit structured JSON events to stderr for CI/CD and log aggregation:
```json
{"op":"delete","entity":"campaigns","action":"delete","duration_ms":123.4,"count":1,"success":true}
```
