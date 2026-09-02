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

Project any tracked metric forward from historical time-series data. Supports
linear regression, simple and weighted moving averages, damped-trend
Holt-Winters exponential smoothing, and an ensemble — the default (`--method
auto` is an alias for `ensemble`) — that averages the methods weighted by
their recency-discounted rolling-backtest accuracy, drops members that
clearly trail the best, and reports each member's share in the output meta
(`weights`).

```bash
p202 forecast --metric revenue --horizon 7
p202 forecast --metric clicks --history last90 --method linear
p202 forecast --metric profit --horizon 14 --method auto --seasonal
p202 forecast --all-metrics --horizon 7
```

Derived metrics (leads, income, cost, net) requested on their own are
forecast by ratio decomposition: clicks and the linking rates (conversion
rate, average CPC, average payout) are forecast as drivers and the totals
composed from them, so `leads = clicks × conv_rate`, `income = leads ×
avg_payout`, and `net = income − cost` hold exactly across the output. The
meta reports `composition: derived`, or `direct` when a driver is defined on
too few days (under ~70% of buckets) and the metric's own series is forecast
instead. `--all-metrics` forecasts clicks, leads, income, cost, and net
together this way in one table (one row per date with `<metric>`,
`<metric>_lower`, `<metric>_upper` columns); it cannot be combined with
`--metric`, `--seasonal`, `--seasonal-monthly`, `--events`, or
`--event-tag`, and those flags on a single derived metric keep the direct
path since the layers operate on the metric's own series. When composition
is impossible (companion metrics missing from the response, or a driver
too sparse to forecast), the direct path serves the forecast with
`composition: direct` and a `composition_fallback` reason in the meta.
Composed band endpoints combine conservatively (worst-case dependence
between factors), so derived bands can over-cover slightly, and
`bounds_source` reads `mixed` when the composed operands' bounds came from
different methods; derived rows carry no `mae`/`rmse` since rolling errors
are only measured for directly fitted series.

Prediction bounds come from rolling-origin conformal prediction: the model is
re-fit at up to 50 cut points stepping back from the end of the history, and
held-out errors are bucketed by horizon step. `lower_bound`/`upper_bound` are
the empirical quantile pair nearest `--confidence`: P25/P75 for levels under
0.65 (a 50% band), P10/P90 under 0.85 (80%), and P05/P95 otherwise (90% —
the widest band the residual pool supports, so the default 0.95 and 0.99
both get it). The meta's `bounds` names the pair in use, and bounds are
asymmetric when the errors are. Each prediction also carries the remaining
quantile columns (`p05`…`p95`; `p50` is the bias-corrected median path).
Reported `mae`/`rmse` are averages across all rolling cut points. Metrics
that cannot go negative (clicks, leads, cost, income, rates) have bounds
clipped at zero. Conformal bounds need at least 8 held-out residuals, which
takes roughly 12 points at a 4+ step horizon and 16 at `--horizon 1`;
shorter histories fall back to symmetric Gaussian bounds (which do honor the
exact confidence level) without quantile columns — the meta's
`bounds_source` says which applied.

With `--seasonal`, predictions are modulated by day-of-week weights derived
from weekpart report data (requires `--interval day` or `hour`); hourly
forecasts also get an hour-of-day profile learned from the series, and
`--seasonal-monthly` (day interval, non-negative metrics only) adds
day-of-month weights for monthly budget/payout resets. Profile multipliers
are shrunk toward 1.0 when a slot has few samples, and weekday/hourly
profiles only apply when the series actually shows structure at that lag
(detrended autocorrelation ≥ 0.3; a history too short to measure the lag
applies the profile as supplied) — the meta's `seasonal_applied` and
`seasonal_profiles` report the outcome. Count metrics (clicks,
click-throughs, leads) are fitted on log1p scale and inverted on output, so
multiplicative growth extrapolates correctly. When a level shift is detected
(offer paused, traffic source added), the model fits the new regime —
truncating or re-leveling pre-shift history — and reports the boundary in
the meta as `level_shift_at`; `data_points_used` then counts the points
actually fitted. A transient burst (a two-day tracking outage, an untagged
promo spike) is not a shift: short outlier runs that are abnormal *for this
series at that point of its cycle* (compared with the same weekday or hour
in the surrounding weeks, so a business closed on Sundays or a low-volume
tracker is never affected) and at least halved or doubled relative to that
reference are masked from fitting and listed in the meta
as `anomalies_masked`, keeping the bands at the healthy level so the
alerting layer still flags the outage. Runs of five or more points are a
regime change and go to the shift detector instead. `--no-anomaly-mask`
fits every point as data, `--anomaly-sigma` (default 5) and
`--anomaly-cycles` (default 4) tune how far a point must deviate and how
many surrounding weeks supply its reference, and `--no-level-shift` fits
the full history as-is if the detector misreads a known transient; tagging known outages and
promos as events remains the explicit override for both. Event-aware forecasting (`--events`,
`--event-tag`) folds in stored [forecast
events](../api/18-forecast-events.md) and requires `--interval day`:

```bash
p202 forecast --metric revenue --events --horizon 14
p202 forecast --metric clicks --events --event-tag us-holidays
```

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
