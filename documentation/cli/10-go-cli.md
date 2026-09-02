# Go CLI (p202)

Cross-platform CLI distributed as a single static binary with zero dependencies.

## Installation

Pre-built binaries are available for Linux, macOS, and Windows.

```bash
cd go-cli
make build        # Build for current platform
make all          # Cross-compile for all platforms
```

The binary is output as `p202` (or `p202.exe` on Windows).

Note that this Go binary is distinct from the PHP/Symfony Console CLI entrypoint at `bin/p202`. Because both share the name `p202`, they can collide on your `PATH`. To avoid ambiguity, invoke each by its full path (e.g. `./go-cli/p202 ...` for the Go CLI vs `./bin/p202 ...` for the PHP CLI), or rename the Go binary.

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

## Commands

| Command | Description |
| ------- | ----------- |
| `p202 campaign list` | List campaigns |
| `p202 campaign get <id>` | Get a single campaign |
| `p202 campaign create` | Create a campaign |
| `p202 campaign update <id>` | Update a campaign |
| `p202 campaign delete <id>` | Delete a campaign |
| `p202 aff-network list` | List affiliate networks (alias: `category`) |
| `p202 ppc-network list` | List PPC/traffic networks (alias: `traffic-network`) |
| `p202 tracker list` | List trackers |
| `p202 click list` | List clicks |
| `p202 conversion list` | List conversions |
| `p202 rotator list` | List rotators |
| `p202 report summary` | Performance summary |
| `p202 report breakdown` | Performance by dimension |
| `p202 attribution model list` | List attribution models |
| `p202 forecast` | Forecast future metrics from historical data |
| `p202 dashboard` | Overview of clicks, conversions, revenue, cost, profit, ROI |
| `p202 analytics` | Grouped performance analytics shorthand |
| `p202 user list` | List users |
| `p202 system health` | Health check |

All entities support standard CRUD operations (`list`, `get`, `create`, `update`, `delete`) where applicable.

## Multi-Profile Management

Manage connections to multiple Prosper202 instances.

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

## Multi-Profile Report Aggregation

```bash
# Dashboard across all profiles
p202 dashboard --all-profiles --period today

# Summary for specific profiles
p202 report summary --profiles prod,staging --period last7

# Aggregate by tag group
p202 report summary --group env:production --period today
```

## Parallel Command Execution

Run any command across multiple profiles simultaneously.

```bash
p202 exec --all-profiles -- campaign list --limit 5
p202 exec --profiles prod,staging --concurrency 2 -- report summary --period today
```

## Diff Between Instances

Compare entities between two instances.

```bash
p202 diff campaigns --from prod --to staging --json
p202 diff all --from prod --to staging
```

Reports `only_in_source`, `only_in_target`, `changed`, and `identical_count` using natural key matching.

## Sync Orchestration

One-way replication with dependency ordering and foreign key remapping.

```bash
# Preview changes
p202 sync all --from prod --to staging --dry-run

# Execute sync
p202 sync campaigns --from prod --to staging --force-update

# Incremental re-sync
p202 re-sync --from prod --to staging
```

Sync respects entity dependencies: aff-network/ppc-network -> ppc-account -> campaign -> landing-page -> text-ad -> rotator -> tracker.

## Data Export/Import

```bash
# Export to JSON
p202 export campaigns --output /tmp/campaigns.json
p202 export all --output /tmp/full-export.json

# Import from JSON
p202 import campaigns /tmp/campaigns.json --dry-run
p202 import campaigns /tmp/campaigns.json --skip-errors
```

## Analytics Shorthand

```bash
p202 analytics --group-by country --period last30 --sort conversions --limit 10
```

Aliases: `--group-by lp` -> `landing_page`, `--sort conversions` -> `total_leads`, `--sort revenue` -> `total_income`.

## Forecasting

Project any tracked metric forward from historical time-series data, with
calibrated prediction bands, an accuracy-weighted ensemble of methods,
coherent multi-metric output, seasonal profiles, level-shift handling, and
transient (outage/spike) masking. The full guide with worked examples is
[Forecasting Guide](11-forecasting.md).

```bash
p202 forecast --metric revenue --horizon 7
p202 forecast --metric clicks --history last90 --method linear
p202 forecast --metric profit --horizon 14 --seasonal --events
p202 forecast --all-metrics --horizon 7 --json
```

Key behaviors, each detailed in the guide:

- **Bands** (`lower_bound`/`upper_bound`, plus `p05`…`p95` columns) come from
  rolling-origin conformal prediction; `--confidence` snaps to a 50%, 80%,
  or 90% band and the meta's `bounds` names the one in use. Histories under
  ~12 points fall back to Gaussian bands (`bounds_source`).
- **`--method auto`** is an ensemble weighted by recent rolling accuracy;
  the meta's `weights` shows each member's share.
- **Derived metrics** (leads, income, cost, net, or `--all-metrics`) are
  composed from driver forecasts so `net = income − cost` and
  `leads = clicks × conv_rate` hold exactly (`composition` in meta).
- **`--seasonal`** applies shrunk weekday (and hourly) profiles only when
  the series repeats at that lag (`seasonal_applied`); `--seasonal-monthly`
  adds a day-of-month profile for non-negative metrics.
- **Level shifts** are detected and the new regime fitted
  (`level_shift_at`; `--no-level-shift` to disable). **Transients** such as a
  two-day tracking outage are masked from fitting and listed in
  `anomalies_masked` (`--no-anomaly-mask`, `--anomaly-sigma`,
  `--anomaly-cycles`).
- **`--events` / `--event-tag`** fold in stored
  [forecast events](../api/18-forecast-events.md) (day interval only).

## Bulk Operations

```bash
p202 campaign delete --ids 1,2,3 --force
p202 conversion delete --ids 789,790,791 --force
```

## Config Defaults

Set per-profile defaults for frequently used flags.

```bash
p202 config set-default report.period last30
p202 config set-default report.campaign_id 5
p202 config get-default report.period
p202 config unset-default report.period
```

## Errors

Every failure is reported on **stderr** with a category, the message, and,
whenever there is a concrete next step, a recovery hint. Stdout stays empty
on failure, so a script never confuses an error for data. Exit codes follow
the category (table below) and survive wrapping: "fetching historical data:
API error (401)" still exits 2.

Human mode:

```text
Error [auth]: fetching historical data: API error (401): invalid api key
Hint: Verify your API key: run `p202 config get`, then `p202 config set-key <key>` if it's wrong.
```

With `--json` or `--ndjson` the same failure is a single JSON envelope, so
an agent reads structured fields instead of parsing prose:

```json
{"error":{"category":"auth","message":"fetching historical data: API error (401): invalid api key","hint":"Verify your API key: run `p202 config get`, then `p202 config set-key <key>` if it's wrong.","exit_code":2,"command":"p202 forecast","http_status":401}}
```

| Field | Always | Meaning |
| --- | --- | --- |
| `category` | yes | `validation`, `auth`, `network`, `server`, or `partial_failure` |
| `message` | yes | What failed |
| `exit_code` | yes | The process exit code (table below) |
| `command` | yes | The command that ran, e.g. `p202 rotator create` |
| `hint` | when known | The recovery step to take next |
| `http_status` | API errors | The server's status code |
| `field_errors` | 422 responses | Per-field messages from the server |

Lists of valid values (supported entities, the metrics a response did
contain) sit in the message itself, so they are visible even when the hint
is ignored; the hint carries the next action. Hints come from three sources,
in order of precedence: a hint attached by the command itself (for example,
which flag to change when the requested metric is missing, or the dependency
order to sync first when a foreign key cannot be resolved); a generic hint
for the failure class (401/403 key check, 404 use `list` for ids, 409 update
instead of create, 429 back off, 5xx retry then `p202 system health`,
network check the URL and `p202 config test`); and for any remaining
validation error, a pointer to `<command> --help`.

### Exit Codes

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
