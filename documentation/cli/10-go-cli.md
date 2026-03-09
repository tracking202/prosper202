# Go CLI (p202)

Prosper202 also ships a Go-based CLI (`p202`) distributed as a single static binary with zero dependencies. It provides all the functionality of the PHP CLI plus advanced multi-instance orchestration features.

## Installation

Pre-built binaries are available for Linux, macOS, and Windows. You can also build from source:

```bash
cd go-cli
make build        # Build for current platform
make all          # Cross-compile for all platforms
```

The binary is output as `p202` (or `p202.exe` on Windows).

## Configuration

```bash
p202 config set-url https://your-domain.com
p202 config set-key YOUR_API_KEY
p202 config test
p202 config show
```

## Output Modes

| Flag | Format | Use Case |
| ---- | ------ | -------- |
| (default) | Table | Human-readable output |
| `--json` | JSON | Structured output for automation |
| `--csv` | CSV | Spreadsheet-compatible output |

## Features Unique to the Go CLI

### Multi-Profile Management

Manage connections to multiple Prosper202 instances:

```bash
# Add named profiles
p202 config add-profile prod --url https://prod.example.com --key PROD_KEY
p202 config add-profile staging --url https://staging.example.com --key STAGING_KEY

# Tag profiles for grouping
p202 config tag-profile prod env:production
p202 config tag-profile staging env:staging

# Switch active profile
p202 config use prod

# List all profiles
p202 config list-profiles

# One-off profile override
p202 --profile staging campaign list
```

### Multi-Profile Report Aggregation

Aggregate reports across multiple instances:

```bash
# Dashboard across all profiles
p202 dashboard --all-profiles --period today

# Summary for specific profiles
p202 report summary --profiles prod,staging --period last7

# Aggregate by tag group
p202 report summary --group env:production --period today
```

### Parallel Command Execution

Run any command across multiple profiles simultaneously:

```bash
p202 exec --all-profiles -- campaign list --limit 5
p202 exec --profiles prod,staging --concurrency 2 -- report summary --period today
```

### Diff Between Instances

Compare entities between two instances:

```bash
p202 diff campaigns --from prod --to staging --json
p202 diff all --from prod --to staging
```

Reports `only_in_source`, `only_in_target`, `changed`, and `identical_count` using natural key matching.

### Sync Orchestration

One-way replication with dependency ordering and foreign key remapping:

```bash
# Preview changes
p202 sync all --from prod --to staging --dry-run

# Execute sync
p202 sync campaigns --from prod --to staging --force-update

# Incremental re-sync
p202 re-sync --from prod --to staging
```

Sync respects entity dependencies: aff-networks -> ppc-networks -> ppc-accounts -> campaigns -> landing-pages -> text-ads -> rotators -> trackers.

### Data Export/Import

```bash
# Export to JSON
p202 export campaigns --output /tmp/campaigns.json
p202 export all --output /tmp/full-export.json

# Import from JSON
p202 import campaigns /tmp/campaigns.json --dry-run
p202 import campaigns /tmp/campaigns.json --skip-errors
```

### Analytics Shorthand

Simplified reporting with human-friendly aliases:

```bash
p202 analytics --group-by country --period last30 --sort conversions --limit 10
```

Aliases: `--group-by lp` -> `landing_page`, `--sort conversions` -> `total_leads`, `--sort revenue` -> `total_income`.

### Bulk Operations

Delete multiple records at once:

```bash
p202 campaign delete --ids 1,2,3 --force
p202 conversion delete --ids 789,790,791 --force
```

### Config Defaults

Set per-profile defaults for frequently used flags:

```bash
p202 config set-default report.period last30
p202 config set-default report.aff_campaign_id 5
p202 config get-default report.period
p202 config unset-default report.period
```

## Exit Codes

| Code | Meaning |
| ---- | ------- |
| 0 | Success |
| 1 | Validation error (bad input, missing flags) |
| 2 | Authentication/authorization failure |
| 3 | Network error (connection timeout, DNS failure) |
| 4 | Server error (5xx response) |
| 5 | Partial failure (some items in bulk operation failed) |

## Telemetry

Enable structured JSON telemetry on stderr:

```bash
P202_METRICS=1 p202 campaign list
```

Emits timing, success/failure, and operation metadata for monitoring.

## Command Parity with PHP CLI

The Go CLI supports all commands available in the PHP CLI (campaigns, networks, trackers, clicks, conversions, reports, rotators, attribution, users, system). See the individual command docs in this CLI reference — the syntax is nearly identical, with the main difference being `p202 entity action` (space-separated) vs `./cli/prosper202 entity:action` (colon-separated).
