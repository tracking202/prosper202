# Prosper202 CLI (`p202`)

A command-line tool for managing a Prosper202 tracking instance. Distributed as a single static binary with zero dependencies.

## Installation

### Download a prebuilt binary

Download the appropriate binary for your platform from the releases page and place it in your `PATH`.

| Platform        | Binary                   |
|-----------------|--------------------------|
| Linux (x86_64)  | `p202-linux-amd64`       |
| Linux (ARM64)   | `p202-linux-arm64`       |
| macOS (Intel)   | `p202-darwin-amd64`      |
| macOS (Apple Si) | `p202-darwin-arm64`     |
| Windows (x86_64) | `p202-windows-amd64.exe` |
| Windows (ARM64) | `p202-windows-arm64.exe` |

### Build from source

Requires Go 1.22+.

```bash
cd go-cli
make build          # Build for current platform
make all            # Cross-compile for all platforms
make install        # Install to $GOPATH/bin
```

## Quick start

```bash
# 1. Point the CLI at your Prosper202 instance
p202 config set-url https://your-prosper202.example.com

# 2. Set your API key
p202 config set-key YOUR_API_KEY

# 3. Verify the connection
p202 config test

# 4. Start using it
p202 campaign list
p202 report summary --period today
```

## Global flags

| Flag     | Description                              |
|----------|------------------------------------------|
| `--json` | Output raw JSON instead of formatted tables |

The `--json` flag works with every command and outputs the exact JSON response from the API.

## Configuration

The CLI stores its configuration in `~/.p202/config.json` with `0600` permissions.

```json
{
  "url": "https://your-prosper202.example.com",
  "api_key": "your_api_key_here"
}
```

On Windows, the config directory is `%TEMP%/.p202/`.

### `p202 config set-url <url>`

Set the Prosper202 instance URL. Trailing slashes are stripped automatically.

### `p202 config set-key <api-key>`

Set the API key used for authentication.

### `p202 config show`

Display the current configuration. The API key is masked in output (first 4 + last 4 characters shown).

```
$ p202 config show
Config file  ~/.p202/config.json
URL          https://prosper.example.com
API Key      abc1...xyz9
```

### `p202 config test`

Test the connection by calling the system health endpoint.

## Resource management (CRUD)

Seven resource types share identical CRUD commands:

| Resource       | Command          |
|----------------|------------------|
| Campaigns      | `p202 campaign`  |
| Affiliate networks | `p202 aff-network` |
| PPC networks   | `p202 ppc-network` |
| PPC accounts   | `p202 ppc-account` |
| Trackers       | `p202 tracker`   |
| Landing pages  | `p202 landing-page` |
| Text ads       | `p202 text-ad`   |

### List resources

```bash
p202 campaign list
p202 campaign list --limit 10 --offset 20
p202 campaign list --page 3
p202 campaign list --filter[aff_network_id] 5
```

| Flag                        | Description           |
|-----------------------------|-----------------------|
| `-l, --limit <n>`          | Maximum results       |
| `-o, --offset <n>`         | Pagination offset     |
| `--page <n>`               | Page number           |
| `--filter[<field>] <value>` | Filter by field value |

### Get a resource

```bash
p202 campaign get 42
```

### Create a resource

```bash
p202 campaign create \
  --aff_campaign_name "Q1 Offer" \
  --aff_campaign_url "https://example.com/offer"
```

Required and optional fields vary by resource type. The CLI validates required fields before making the API call.

### Update a resource

```bash
p202 campaign update 42 --aff_campaign_name "Q1 Offer (Updated)"
```

At least one field flag must be provided.

### Delete a resource

```bash
p202 campaign delete 42         # Prompts for confirmation
p202 campaign delete 42 --force # Skips confirmation
```

| Flag          | Description              |
|---------------|--------------------------|
| `-f, --force` | Skip confirmation prompt |

## Resource field reference

### Campaign (`p202 campaign`)

| Flag                             | Required | Description               |
|----------------------------------|----------|---------------------------|
| `--aff_campaign_name`            | Yes      | Campaign name             |
| `--aff_campaign_url`             | Yes      | Primary offer URL         |
| `--aff_campaign_cpc`             | No       | Cost per click            |
| `--aff_campaign_payout`          | No       | Default payout            |
| `--aff_network_id`               | No       | Affiliate network ID      |
| `--aff_campaign_postback_url`    | No       | Postback URL              |
| `--aff_campaign_postback_append` | No       | Postback append string    |

### Affiliate network (`p202 aff-network`)

| Flag                             | Required | Description            |
|----------------------------------|----------|------------------------|
| `--aff_network_name`             | Yes      | Network name           |
| `--aff_network_postback_url`     | No       | Postback URL           |
| `--aff_network_postback_append`  | No       | Postback append string |

### PPC network (`p202 ppc-network`)

| Flag                 | Required | Description  |
|----------------------|----------|--------------|
| `--ppc_network_name` | Yes      | Network name |

### PPC account (`p202 ppc-account`)

| Flag                 | Required | Description    |
|----------------------|----------|----------------|
| `--ppc_account_name` | Yes      | Account name   |
| `--ppc_network_id`   | Yes      | PPC network ID |

### Tracker (`p202 tracker`)

| Flag                | Required | Description            |
|---------------------|----------|------------------------|
| `--tracker_name`    | Yes      | Tracker name           |
| `--aff_campaign_id` | Yes      | Campaign ID            |
| `--ppc_account_id`  | No       | PPC account ID         |
| `--landing_page_id` | No       | Landing page ID        |
| `--tracker_cpc`     | No       | Cost per click override |

### Landing page (`p202 landing-page`)

| Flag                  | Required | Description      |
|-----------------------|----------|------------------|
| `--landing_page_name` | Yes      | Landing page name |
| `--landing_page_url`  | Yes      | Landing page URL |

### Text ad (`p202 text-ad`)

| Flag                    | Required | Description  |
|-------------------------|----------|--------------|
| `--text_ad_name`        | Yes      | Text ad name |
| `--text_ad_headline`    | No       | Headline     |
| `--text_ad_body`        | No       | Body text    |
| `--text_ad_display_url` | No       | Display URL  |

## Clicks

Clicks are read-only.

### List clicks

```bash
p202 click list
p202 click list --limit 100 --time_from 1700000000 --time_to 1700100000
p202 click list --aff_campaign_id 5 --click_lead 1
```

| Flag                | Default | Description                          |
|---------------------|---------|--------------------------------------|
| `-l, --limit`       | 50      | Maximum results                      |
| `-o, --offset`      | 0       | Pagination offset                    |
| `--time_from`       |         | Start timestamp (unix)               |
| `--time_to`         |         | End timestamp (unix)                 |
| `--aff_campaign_id` |         | Filter by campaign                   |
| `--ppc_account_id`  |         | Filter by PPC account                |
| `--landing_page_id` |         | Filter by landing page               |
| `--click_lead`      |         | 0 = clicks only, 1 = conversions only |
| `--click_bot`       |         | 0 = human, 1 = bot                   |

### Get a click

```bash
p202 click get 12345
```

## Conversions

### List conversions

```bash
p202 conversion list
p202 conversion list --campaign_id 3 --time_from 1700000000
```

| Flag            | Default | Description            |
|-----------------|---------|------------------------|
| `-l, --limit`   | 50      | Maximum results        |
| `-o, --offset`  | 0       | Pagination offset      |
| `--campaign_id` |         | Filter by campaign     |
| `--time_from`   |         | Start timestamp (unix) |
| `--time_to`     |         | End timestamp (unix)   |

### Get a conversion

```bash
p202 conversion get 789
```

### Create a conversion

```bash
p202 conversion create --click_id 12345
p202 conversion create --click_id 12345 --payout 4.50 --transaction_id "TXN-001"
```

| Flag               | Required | Description              |
|--------------------|----------|--------------------------|
| `--click_id`       | Yes      | Click ID to attribute    |
| `--payout`         | No       | Payout amount            |
| `--transaction_id` | No       | Transaction ID (dedup)   |

### Delete a conversion

```bash
p202 conversion delete 789
p202 conversion delete 789 --force
```

## Reports

All report commands share common time and entity filters.

### Common report flags

| Flag                | Description              |
|---------------------|--------------------------|
| `-p, --period`      | Preset: today, yesterday, last7, last30, last90 |
| `--time_from`       | Start timestamp (unix)   |
| `--time_to`         | End timestamp (unix)     |
| `--aff_campaign_id` | Filter by campaign       |
| `--ppc_account_id`  | Filter by PPC account    |
| `--aff_network_id`  | Filter by aff network    |
| `--ppc_network_id`  | Filter by PPC network    |
| `--landing_page_id` | Filter by landing page   |
| `--country_id`      | Filter by country        |

### Summary

Aggregate totals for the selected time period and filters.

```bash
p202 report summary --period today
p202 report summary --time_from 1700000000 --time_to 1700100000
```

### Breakdown

Performance broken down by a dimension.

```bash
p202 report breakdown --breakdown campaign --period last7
p202 report breakdown --breakdown country --sort total_net --sort_dir ASC --limit 10
```

| Flag               | Default       | Description                |
|--------------------|---------------|----------------------------|
| `-b, --breakdown`  | campaign      | Dimension (see below)      |
| `-s, --sort`       | total_clicks  | Sort column                |
| `--sort_dir`       | DESC          | Sort direction: ASC or DESC |
| `-l, --limit`      | 50            | Maximum results            |
| `-o, --offset`     | 0             | Pagination offset          |

**Breakdown dimensions:** campaign, aff_network, ppc_account, ppc_network, landing_page, keyword, country, city, browser, platform, device, isp, text_ad

**Sort columns:** total_clicks, total_leads, total_income, total_cost, total_net, roi, epc, conv_rate

### Timeseries

Performance data over time intervals.

```bash
p202 report timeseries --period last30 --interval day
p202 report timeseries --interval hour --time_from 1700000000
```

| Flag            | Default | Description                   |
|-----------------|---------|-------------------------------|
| `-i, --interval` | day    | Interval: hour, day, week, month |

## Rotators

### List/get/create/update/delete rotators

```bash
p202 rotator list
p202 rotator get 5
p202 rotator create --name "Geo Split"
p202 rotator update 5 --name "Geo Split v2" --default_url "https://fallback.example.com"
p202 rotator delete 5
```

| Flag                 | Required (create) | Description             |
|----------------------|-------------------|-------------------------|
| `--name`             | Yes               | Rotator name            |
| `--default_url`      | No                | Default redirect URL    |
| `--default_campaign` | No                | Default campaign ID     |
| `--default_lp`       | No                | Default landing page ID |

### Create a rule

```bash
p202 rotator rule-create 5 \
  --rule_name "US Traffic" \
  --criteria_json '[{"type":"country","statement":"is","value":"US"}]' \
  --redirects_json '[{"redirect_url":"https://us.example.com","weight":"100","name":"US Offer"}]'
```

| Flag               | Required | Description                |
|--------------------|----------|----------------------------|
| `--rule_name`      | Yes      | Rule name                  |
| `--splittest`      | No       | Enable split test (0 or 1) |
| `--criteria_json`  | No       | Criteria as JSON array     |
| `--redirects_json` | No       | Redirects as JSON array    |

Both JSON fields are validated before sending.

### Delete a rule

```bash
p202 rotator rule-delete 5 12        # rotator_id rule_id
p202 rotator rule-delete 5 12 --force
```

## Attribution

### Models

```bash
p202 attribution model list
p202 attribution model list --type time_decay
p202 attribution model get 3
p202 attribution model create \
  --model_name "30-Day Decay" \
  --model_type time_decay \
  --weighting_config '{"half_life_days": 7}'
p202 attribution model update 3 --is_default 1
p202 attribution model delete 3
```

| Flag                | Required (create) | Description                     |
|---------------------|-------------------|---------------------------------|
| `--model_name`      | Yes               | Model name                      |
| `--model_type`      | Yes               | first_touch, last_touch, linear, time_decay, position_based, algorithmic |
| `--weighting_config` | No               | Weighting config as JSON string |
| `--is_active`       | No                | 1 = active, 0 = inactive        |
| `--is_default`      | No                | 1 = default model                |

### Snapshots

```bash
p202 attribution snapshot list 3
p202 attribution snapshot list 3 --scope_type campaign --limit 500
```

| Flag            | Default | Description                       |
|-----------------|---------|-----------------------------------|
| `--scope_type`  |         | Filter: global, campaign, landing_page |
| `-l, --limit`   | 100     | Maximum results                   |
| `-o, --offset`  | 0       | Pagination offset                 |

### Exports

```bash
p202 attribution export list 3
p202 attribution export schedule 3 \
  --scope_type campaign \
  --format json \
  --webhook_url "https://hooks.example.com/receive"
```

| Flag            | Default | Description                   |
|-----------------|---------|-------------------------------|
| `--scope_type`  | global  | Scope: global, campaign, landing_page |
| `--scope_id`    | 0       | Scope entity ID               |
| `--start_hour`  |         | Start timestamp               |
| `--end_hour`    |         | End timestamp                 |
| `--format`      | csv     | Export format: csv or json    |
| `--webhook_url` |         | Webhook URL for delivery      |

## Users

### List and manage users

```bash
p202 user list
p202 user get 1
p202 user create --user_name admin2 --user_email admin2@example.com
p202 user update 1 --user_fname "Jane" --user_lname "Doe"
p202 user delete 2
```

When creating or updating a user, if `--user_pass` is omitted, the CLI prompts for the password securely (input is hidden).

| Flag              | Required (create) | Description         |
|-------------------|-------------------|---------------------|
| `--user_name`     | Yes               | Username            |
| `--user_email`    | Yes               | Email address       |
| `--user_pass`     | Yes (prompted)    | Password            |
| `--user_fname`    | No                | First name          |
| `--user_lname`    | No                | Last name           |
| `--user_timezone` | No                | Timezone (default: UTC) |
| `--user_active`   | No                | 1 = active, 0 = inactive |

### Roles

```bash
p202 user role list                    # List all available roles
p202 user role assign 2 --role_id 1   # Assign role to user
p202 user role remove 2 3             # Remove role 3 from user 2
```

### API keys

```bash
p202 user apikey list 1               # List keys for user 1
p202 user apikey create 1             # Generate new key (shown once)
p202 user apikey delete 1 <key>       # Delete a specific key
```

The full API key is displayed only once at creation time. Store it securely.

### Preferences

```bash
p202 user prefs get 1
p202 user prefs update 1 \
  --user_tracking_domain "trk.example.com" \
  --user_account_currency "USD"
```

| Flag                             | Description                 |
|----------------------------------|-----------------------------|
| `--user_tracking_domain`         | Tracking domain             |
| `--user_account_currency`        | Currency (3-letter code)    |
| `--user_slack_incoming_webhook`  | Slack webhook URL           |
| `--user_daily_email`             | Daily email: on or off      |
| `--ipqs_api_key`                 | IPQS fraud detection key    |

## System

```bash
p202 system health       # Health check (unauthenticated)
p202 system version      # Prosper202 + PHP + MySQL versions
p202 system db-stats     # Database table sizes
p202 system cron         # Cron job status
p202 system errors       # Recent system errors
p202 system errors --limit 5
p202 system dataengine   # Data engine job status
```

| Command              | Auth required |
|----------------------|---------------|
| `p202 system health` | No            |
| All others           | Admin         |

## Output modes

### Table mode (default)

Human-readable tables. Lists show column headers with rows; single objects show key-value pairs.

```
$ p202 campaign list --limit 3
aff_campaign_id  aff_campaign_name  aff_campaign_url
1                Q1 Offer           https://example.com/q1
2                Summer Sale        https://example.com/summer
3                Retarget US        https://example.com/retarget
```

Pagination metadata is displayed below the table when present.

### JSON mode (`--json`)

Raw API response, pretty-printed with 2-space indentation. Suitable for piping into `jq` or other tools.

```bash
p202 campaign list --json | jq '.data[].aff_campaign_name'
p202 report summary --period today --json > report.json
```

## Exit codes

| Code | Meaning               |
|------|-----------------------|
| 0    | Success               |
| 1    | Error (printed to stderr) |
