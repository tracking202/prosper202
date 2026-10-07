# Prosper202 CLI (`p202`)

A command-line tool for managing a Prosper202 tracking instance. Distributed as a single static binary with zero dependencies.

## Installation

### Download a prebuilt binary

Download the appropriate archive for your platform from the releases page. Each archive contains a single `p202` binary (or `p202.exe` on Windows). Extract it and place it in your `PATH`.

| Platform         | Archive directory  | Binary      |
|------------------|--------------------|-------------|
| Linux (x86_64)   | `linux-amd64/`     | `p202`      |
| Linux (ARM64)    | `linux-arm64/`     | `p202`      |
| macOS (Intel)    | `darwin-amd64/`    | `p202`      |
| macOS (Apple Si) | `darwin-arm64/`    | `p202`      |
| Windows (x86_64) | `windows-amd64/`   | `p202.exe`  |
| Windows (ARM64)  | `windows-arm64/`   | `p202.exe`  |

The binary is always named `p202` on every platform.

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

## Finding a command

```bash
p202 search breakdown by browser   # rank commands for a task (offline)
p202 commands                      # the whole command tree, indented
p202 commands --json               # every command and flag, with allowed values
```

`p202 search <words...>` matches your words against every command's name, aliases,
description, examples, flags and the values its flags accept. Plural forms and common
synonyms match (referrer/referer, offer/campaign, dead/broken, undo/revert, link/url),
and "per"/"by" ask for a breakdown. Each result says why it matched and, when a flag
value matched, prints a command line to try. When nothing matches well it says so and
shows only the closest three. `--limit N` sets the number of results (default 10).

`p202 commands [command...]` lists the tree, or one subtree (`p202 commands report`).
With `--json` each command carries its flags' name, shorthand, type, default, usage,
`required`, and for fixed-set flags `allowed_values` and `value_aliases`. Global flags
appear once. `--ndjson` prints one command per line and `--quiet` prints paths only.

Every flag that takes a fixed set of values lists the set in `--help`. A value outside
the set is refused before any request, with every accepted value in the message.

## Global flags

| Flag       | Description                              |
|------------|------------------------------------------|
| `--json`   | Output pretty-printed JSON instead of formatted tables |
| `--ndjson` | Output one compact JSON object per row   |
| `--csv`    | Output CSV instead of formatted tables   |
| `-q`, `--quiet` | Print only ids, one per line        |
| `--table`  | Output tables even when an AI agent would get JSON |
| `--profile` | Override active profile for this command |
| `--group`  | Select a profile tag group for multi-profile commands (`report summary`, `dashboard`, `exec`) |

`--json` and `--csv` are mutually exclusive, and so is `--table` with any other format flag.

### Output format when no flag is given

People get tables. When an AI agent runs `p202` (one of `AI_AGENT`, `CLAUDECODE`, `GEMINI_CLI`,
`CODEX_SANDBOX`, `CODEX_SANDBOX_NETWORK_DISABLED`, `CODEX_THREAD_ID` or `CURSOR_AGENT` is set to a
value other than empty, `0`, `false`, `no` or `off`), every command prints compact single-line JSON
and errors print as the JSON envelope on stderr. The first of these that applies decides:

1. A format flag (`--json`, `--ndjson`, `--csv`, `-q`, `--table`).
2. `P202_OUTPUT=json|table|ndjson|csv` in the environment (JSON from here is pretty-printed).
3. The profile default: `p202 config set-default output.format table`.
4. An agent marker: compact JSON, unless `--wide`, `--raw-headers` or `--fields` asks for a table shape.
5. A table.

`p202 config show` prints the format in use and the reason (`Output: table (default)`,
`output_source: agent:CLAUDECODE`). `--json` output is unchanged by any of this.
[docs/cli-agent.md](cli-agent.md#output-agents-get-json-without-asking) has the evidence behind
each marker and the ones deliberately left out.

## Configuration

The CLI stores its configuration in `~/.p202/config.json` with `0600` permissions.

```json
{
  "active_profile": "default",
  "profiles": {
    "default": {
      "url": "https://your-prosper202.example.com",
      "api_key": "your_api_key_here",
      "defaults": {
        "report.period": "last30"
      },
      "tags": ["env:prod"]
    }
  }
}
```

On Windows, this path is typically `%USERPROFILE%\\.p202\\config.json` (that is, the current user's home directory under `.p202`).

Legacy single-profile files (`url`, `api_key`) are auto-migrated in memory to `profiles.default` and written back in profile format on the next config write.

### `p202 config set-url <url>`

Set the Prosper202 instance URL. Trailing slashes are stripped automatically.

### `p202 config set-key <api-key>`

Set the API key used for authentication.

### `p202 config show`

Display the current configuration. The API key is masked in output (first 4 + last 4 characters shown).

```
$ p202 config show
Config file: ~/.p202/config.json
Active:      default
Profile:     default
URL:         https://prosper.example.com
API key:     abc1...xyz9
Profiles:    default
Output:      table (default)
```

The last line (`output_format` and `output_source` under JSON) says which output format commands
use and why: `flag`, `P202_OUTPUT`, `config`, `agent:<VARIABLE>` or `default`.

### `p202 config test`

Test the connection by calling the system health endpoint.

### Multi-profile config commands

```bash
p202 config add-profile prod --url https://prod.example.com --key PROD_KEY
p202 config add-profile staging --url https://staging.example.com --key STAGING_KEY
p202 config list-profiles
p202 config use prod
p202 --profile staging config show
p202 config rename-profile staging stage
p202 config remove-profile stage --force
```

| Command | Description |
|---------|-------------|
| `config add-profile <name> --url <url> --key <key>` | Create a named profile |
| `config remove-profile <name> [--force]` | Remove a profile (active profile removal is blocked) |
| `config use <name>` | Set active profile |
| `config list-profiles [--tag <tag>]` | List profiles and active marker |
| `config rename-profile <old> <new>` | Rename a profile |
| `config tag-profile <name> <tag> [<tag>...]` | Add profile tags (stored lowercase) |
| `config untag-profile <name> <tag> [<tag>...]` | Remove profile tags |

`config set-url`, `config set-key`, `config show`, and all `config *-default` commands operate on the resolved profile (`--profile` override or active profile).

### Config defaults

Set reusable defaults for high-frequency flags:

```bash
p202 config set-default report.period last30
p202 config get-default report.period
p202 config get-default
p202 config unset-default report.period
```

Supported default keys include:
- `report.period`, `report.time_from`, `report.time_to`
- `report.aff_campaign_id`, `report.ppc_account_id`, `report.aff_network_id`, `report.ppc_network_id`, `report.landing_page_id`, `report.country_id`
- `report.breakdown`, `report.sort`, `report.sort_dir`, `report.limit`, `report.offset`, `report.interval`
- `crud.aff_campaign_id`, `crud.ppc_account_id`, `crud.aff_network_id`, `crud.ppc_network_id`, `crud.landing_page_id`, `crud.text_ad_id`, `crud.rotator_id`, `crud.country_id`
- `output.format`: `json`, `table`, `ndjson` or `csv`; the output format when no format flag or `P202_OUTPUT` is given (see [Global flags](#output-format-when-no-flag-is-given))

### Feature flags

Optional environment flags for staged rollout:
- `CLI_ENABLE_RESOLVE_NAMES=0|1` controls `--resolve-names` list behavior.
- `CLI_ENABLE_ANALYTICS_SHORTHAND=0|1` controls `p202 analytics`.

If unset, both features are enabled by default.

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
p202 campaign list --aff_network_id 5
```

| Flag | Description |
|------|-------------|
| `-l, --limit <n>` | Maximum results |
| `-o, --offset <n>` | Pagination offset |
| `--page <n>` | Page number |
| `--all` | Fetch all rows across pages (overrides `--limit`) |
| `--resolve-names` | Resolve foreign key IDs to human-readable names |
| Entity-specific filter flags (for example `--aff_network_id`) | Filter by related entity |

When the response contains more rows than shown, a truncation warning appears on stderr: `Warning: Showing N of M results. Use --all to fetch all.` This warning is suppressed in `--json` mode.

`--resolve-names` adds companion fields (for example `campaign_name`) without removing original ID fields. If a lookup fails, the fallback value is `id:<n>`.

Legacy raw filter syntax (`--filter[aff_network_id]`) is still accepted where previously supported.

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
p202 campaign delete 42                    # Prompts for confirmation
p202 campaign delete 42 --force            # Skips confirmation
p202 campaign delete --ids 42,43,44 --force # Bulk delete
```

| Flag          | Description              |
|---------------|--------------------------|
| `-f, --force` | Skip confirmation prompt |
| `--ids <list>` | Comma-separated IDs for bulk delete |

Bulk delete (`--ids`) processes each ID individually and reports a summary. If any ID fails, the command exits with code 5 (partial failure) and the summary shows the count of succeeded/failed operations.

## Resource field reference

### Campaign (`p202 campaign`)

| Flag | Required | Description |
|------|----------|-------------|
| `--aff_campaign_name` | Yes | Campaign name |
| `--aff_campaign_url` | Yes | Primary offer URL |
| `--aff_campaign_url_2` | No | Offer URL 2 |
| `--aff_campaign_url_3` | No | Offer URL 3 |
| `--aff_campaign_url_4` | No | Offer URL 4 |
| `--aff_campaign_url_5` | No | Offer URL 5 |
| `--aff_campaign_cpc` | No | Cost per click |
| `--aff_campaign_payout` | No | Default payout |
| `--aff_campaign_currency` | No | Currency code |
| `--aff_campaign_foreign_payout` | No | Foreign currency payout |
| `--aff_network_id` | No | Affiliate network ID |
| `--aff_campaign_cloaking` | No | Enable cloaking (0/1) |
| `--aff_campaign_rotate` | No | Enable rotation (0/1) |
| `--aff_campaign_postback_url` | No | Postback URL |
| `--aff_campaign_postback_append` | No | Postback append string |

Campaign utility subcommand:

```bash
p202 campaign clone 42
p202 campaign clone 42 --name "Q1 Offer (Copy)"
```

Find campaigns by offer URL, and rewrite offer URLs in bulk:

```bash
# Every campaign with a URL (any of the five slots) containing the text; case-insensitive, all pages
p202 campaign list --url-contains old-network.com

# Preview: which campaign, slot, old URL and new URL. Writes nothing.
p202 campaign replace-url --match old-network.com --set 'https://example.com/?utm_source={slug}' --dry-run

# Swap just the matched text, e.g. http -> https for one host
p202 campaign replace-url --match http://promo.example.com --with https://promo.example.com

# Revert that run: the path is printed after "Undo with:" (preview with --dry-run)
p202 campaign replace-url --undo ~/.p202/undo/replace-url-20260930T101500Z.json
```

| Flag | Description |
|------|-------------|
| `--match <text>` | Required unless `--undo`. Text the URLs to change contain (case-insensitive) |
| `--with <text>` | Replace the matched text inside the URL |
| `--set <url>` | Replace the whole URL; `{id}` and `{slug}` (campaign name, lowercased and hyphenated) are filled per campaign. Must be absolute `http(s)://` |
| `--slot <1-5\|all>` | URL slots to consider, comma-separated (default `all`; 1 is `aff_campaign_url`) |
| `--ids <list>` | Only these campaign IDs |
| `--aff-network-id <id>` | Only campaigns in this affiliate network |
| `--dry-run` | List the changes without writing |
| `-f, --force` | Skip the confirmation prompt |
| `--undo <file>` | Revert a run from its undo manifest (see below); not combinable with `--match`, `--with`, `--set`, `--slot`, `--ids`, `--aff-network-id` |

Pass exactly one of `--with` or `--set`. Without `--dry-run` the change list is printed and
confirmed first; `--staged` records one proposal per campaign instead of writing. Each campaign
gets a single `PUT /campaigns/{id}` carrying only its changed slots. The output lists every slot
with `status` `applied`, `staged` (with `change_id`) or `failed`; any failure exits 5 (partial
failure). Matching runs in the CLI over every page, so it works with any server version.

**Undo.** Every run that applies at least one change saves an undo manifest to
`~/.p202/undo/replace-url-<UTC time>.json` (directory `0700`, file `0600`) and prints
`Undo with: p202 campaign replace-url --undo <file> --profile <name>`; under `--json` the path is
also `meta.undo_manifest`. The manifest holds only the `applied` slots (staged and failed ones
changed nothing), with the profile and base URL the writes went to and the original flags:

```json
{
  "format": "p202.campaign.replace-url.undo",
  "version": 1,
  "created_at": "2026-09-30T10:15:00Z",
  "profile": "default",
  "base_url": "https://tracker.example.com",
  "match": "g2afse.com",
  "set": "https://example.com/?utm_source={slug}",
  "changes": [
    {"aff_campaign_id": "279", "aff_campaign_name": "Darkmoon Realm", "field": "aff_campaign_url",
     "old_url": "https://aanicca.g2afse.com/click?pid=2753", "new_url": "https://example.com/?utm_source=darkmoon-realm"}
  ]
}
```

`--undo <file>` refuses (exit 1, before any request) a manifest written against a different base
URL than the active profile's; the hint names the `--profile` to use, or how to add one. It re-reads every campaign
and restores `old_url` only where the slot still holds the manifest's `new_url`. Slots changed
since, slots already restored, and campaigns deleted since are left alone and listed with
`status` `skipped` and the reason in `error`; skipped slots alone do not make the run fail.
Rows show `old_url` as the current value and `new_url` as the restored one, and `--dry-run`, the
prompt, `--force`, `--staged` and exit 5 on a failed `PUT` work as in a normal run. An undo that
applies changes saves its own manifest (with `undo_of`), so it can be undone too.

If the manifest cannot be saved after the writes (for example `~/.p202` is not writable), the
rows are still printed, the manifest is printed on stderr after `Undo manifest` (save it to a
file to use with `--undo`), and the command exits 5 (`partial_failure`): the writes happened,
but their undo record did not.

Find dead offer URLs **without sending a click**. Opening an affiliate link registers one, so by
default no HTTP request is made:

```bash
# Every offer URL slot of every campaign
p202 campaign check-urls

# One network's primary URLs, with more time per host
p202 campaign check-urls --aff-network-id 32 --slot 1 --timeout 10s --json
```

| Flag | Description |
|------|-------------|
| `--url-contains <text>` | Only URLs containing the text (case-insensitive, per slot) |
| `--slot <1-5\|all>` | URL slots to check, comma-separated (default `all`) |
| `--ids <list>` | Only these campaign IDs |
| `--aff-network-id <id>` | Only campaigns in this affiliate network |
| `--timeout <duration>` | Time per host for DNS, connect and TLS together, and per `--http` request (default `5s`) |
| `--http` | Also send one request per URL (see below); asks first |
| `-f, --force` | Skip the `--http` confirmation |

Each unique host (scheme, host and port) is checked once: a DNS lookup, a TCP connect and, for
`https`, a TLS handshake with normal certificate verification, after which the connection is
closed. There is one row per campaign URL slot: `aff_campaign_id`, `aff_campaign_name`, `field`,
`url`, `host` (`host:port`), `status` and `detail`. `status` is one of:

- `ok`: the detail gives the address reached and, for https, the certificate's expiry date
- `invalid_url`: does not parse, has no host, is not http/https, or has a click token in the host
- `dns_failed`: the detail says `no such host` when the domain does not resolve
- `connect_failed`: e.g. connection refused
- `timeout`: no answer to the connect or TLS handshake within `--timeout`
- `tls_failed`: the detail starts with `expired`, `hostname mismatch`, `unknown authority` or
  `invalid certificate`

Tokens such as `[[subid]]` are fine anywhere except the host. A summary with the count per status
goes to stderr.

`--http` also sends one `HEAD` per unique URL on a host that passed (a `GET` only if `HEAD` gets
405), follows no redirects, and adds `http_status` and `location`. A request that fails is
reported as `http_failed`. Affiliate networks may record each request as a click, so the command
warns and asks first (`--force` skips the question). Tokens are sent as `p202check`.
`http_status` is reported but not judged: a 4xx leaves `status` as `ok`.

Like `p202 rotator check`, it exits 5 (`partial_failure`) when any row's `status` is not `ok`, with
every row still on stdout. Exit 0 means every URL in scope passed.

Put each campaign's recent traffic next to its URLs:

```bash
# Every campaign on an old network's links, with its last-30-day clicks, conversions and revenue
p202 campaign list --url-contains old-network.com --with-stats

# Only campaigns that still get traffic in the last 90 days
p202 campaign list --with-stats --period last90 --min-clicks 1
```

| Flag | Description |
|------|-------------|
| `--with-stats` | Add `total_clicks`, `total_leads`, `total_income`, `total_cost` and `total_net` (Clicks, Conversions, Revenue, Cost, Profit in tables) to every row; a campaign with no traffic in the window gets `0`s |
| `--period <p>` | Window: `today`, `yesterday`, `last7`, `last30`, `last90` (default `last30`) |
| `--days <n>` | Window of the last N days instead; `--period` wins when both are given (as in `analytics`) |
| `--min-clicks <n>` | Only campaigns with at least N clicks in the window; searches every page, so no `--page/--limit/--offset` |

`--with-stats` works with `--url-contains`, `--all`, `--aff_network_id` and plain paging. It reads the
campaign breakdown once (`GET /reports/breakdown?breakdown=campaign`, paged 500 rows at a time),
so the API key needs `reports:read` (or `read`) as well as `campaigns:read`; a 403 there says so. The
stats are not narrowed by `--aff_network_id`: a campaign's clicks count wherever they were recorded.
The window flags are refused without `--with-stats`. There is no last-click date: no API endpoint
returns one per campaign without a request per campaign.

### Affiliate network (`p202 aff-network`)

| Flag | Required | Description |
|------|----------|-------------|
| `--aff_network_name` | Yes | Network name |
| `--dni_network_id` | No | DNI network ID |
| `--aff_network_postback_url` | No | Postback URL |
| `--aff_network_postback_append` | No | Postback append string |

### PPC network (`p202 ppc-network`)

| Flag                 | Required | Description  |
|----------------------|----------|--------------|
| `--ppc_network_name` | Yes      | Network name |

### PPC account (`p202 ppc-account`)

| Flag | Required | Description |
|------|----------|-------------|
| `--ppc_account_name` | Yes | Account name |
| `--ppc_network_id` | Yes | PPC network ID |
| `--ppc_account_default` | No | Set as default account (0/1) |

### Tracker (`p202 tracker`)

| Flag | Required | Description |
|------|----------|-------------|
| `--aff_campaign_id` | Yes | Campaign ID |
| `--ppc_account_id` | No | PPC account ID |
| `--text_ad_id` | No | Text ad ID |
| `--landing_page_id` | No | Landing page ID |
| `--rotator_id` | No | Rotator ID |
| `--click_cpc` | No | Cost per click |
| `--click_cpa` | No | Cost per action |
| `--click_cloaking` | No | Enable cloaking (0/1) |

Tracker utility subcommands:

```bash
p202 tracker get-url 56
p202 tracker create-with-url --aff_campaign_id 42
p202 tracker bulk-urls --aff_campaign_id 42 --concurrency 5
p202 tracker list --all --resolve-names
```

`tracker list` supports:
- `--all` fetches all pages.
- `--resolve-names` adds resolved FK labels (for example `campaign_name`) while preserving original ID fields.

### Landing page (`p202 landing-page`)

`p202 landing-page list --url-contains <text>` returns every landing page whose
`landing_page_url` or `leave_behind_page_url` contains the text (case-insensitive, all pages;
not combinable with `--page`/`--limit`/`--offset`).

| Flag | Required | Description |
|------|----------|-------------|
| `--landing_page_url` | Yes | Landing page URL |
| `--aff_campaign_id` | Yes | Campaign ID |
| `--landing_page_nickname` | No | Landing page nickname |
| `--leave_behind_page_url` | No | Leave-behind page URL |
| `--landing_page_type` | No | Landing page type |

### Text ad (`p202 text-ad`)

| Flag | Required | Description |
|------|----------|-------------|
| `--text_ad_name` | Yes | Text ad name |
| `--text_ad_headline` | No | Headline |
| `--text_ad_description` | No | Description text |
| `--text_ad_display_url` | No | Display URL |
| `--aff_campaign_id` | No | Campaign ID |
| `--landing_page_id` | No | Landing page ID |
| `--text_ad_type` | No | Text ad type |

## Clicks

Clicks are read-only.

### List clicks

```bash
p202 click list
p202 click list --limit 100 --time_from 1700000000 --time_to 1700100000
p202 click list --aff_campaign_id 5 --click_lead 1
p202 click list --all
```

| Flag                | Default | Description                          |
|---------------------|---------|--------------------------------------|
| `-l, --limit`       | 50      | Maximum results                      |
| `-o, --offset`      | 0       | Pagination offset                    |
| `--page`            |         | Page number (maps to offset)         |
| `--time_from`       |         | Start timestamp (unix)               |
| `--time_to`         |         | End timestamp (unix)                 |
| `--aff_campaign_id` |         | Filter by campaign                   |
| `--ppc_account_id`  |         | Filter by PPC account                |
| `--landing_page_id` |         | Filter by landing page               |
| `--click_lead`      |         | 0 = clicks only, 1 = conversions only |
| `--click_bot`       |         | 0 = human, 1 = bot                   |
| `--all`             | false   | Fetch all rows across pages          |

### Get a click

```bash
p202 click get 12345
```

### Explain a click's value

```bash
p202 click conversions 12345
p202 --json click conversions 12345
```

Every conversion recorded on the click — counted, unpaid, superseded, deleted
and reversals alike — with its amount, whether it counts toward the click's
value (and the reason when it does not: `deleted`, `unpaid`, `superseded
(replace|batch|pre_ledger|replay|reevaluation)`, `not_netted`), its source,
what that is (a goal and version, an upload, the sale a reversal nets, the API
key that wrote it), its transaction id and event. The table ends with the
click's value and says so if the counted rows do not add up to it. `--json`
prints the API's answer unchanged (`GET /clicks/{id}/conversions`). The PHP
CLI's `click:conversions <id>` prints the same.

## Conversions

### List conversions

```bash
p202 conversion list
p202 conversion list --campaign_id 3 --time_from 1700000000
p202 conversion list --click_id 12345
p202 conversion list --source goal --goal 7
p202 conversion list --all
```

| Flag            | Default | Description            |
|-----------------|---------|------------------------|
| `-l, --limit`   | 50      | Maximum results        |
| `-o, --offset`  | 0       | Pagination offset      |
| `--campaign_id` |         | Filter by campaign     |
| `--time_from`   |         | Start timestamp (unix) |
| `--time_to`     |         | End timestamp (unix)   |
| `--click_id`    |         | Only this click's conversions |
| `--source`      |         | Only this source's: `pixel`, `postback`, `universal_pixel`, `api`, `subid_upload`, `revenue_upload`, `legacy_pixel`, `clickbank`, `app_install`, `goal`, `legacy_baseline` |
| `--goal`        |         | Only this goal's outcomes (every version) |
| `--all`         | false   | Fetch all rows across pages |

Each row carries its provenance: `source`, `source_ref`, `event_name`,
`payable`, `reverses_conv_id`, `superseded_by`, `superseded_reason`, and a
goal row's `goal_id` and `goal_version`. A filter value that is not one of
these is refused before any request, with the command that finds a right one.

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

A transaction id the click already has records nothing: the answer is that
conversion, with `"duplicate": true` beside `data` under `--json`. One whose
conversion was deleted is refused (exit 1) and never recorded again; a
different sale needs its own transaction id.

### Import a network's conversions

When a network's postback was never wired up, its conversion report still
carries the subid your offer URL sent it (`...&subid=[[subid]]`). Export that
report and import it:

```bash
p202 conversion import network-feb.csv --dry-run                  # the plan; no request at all
p202 conversion import network-feb.csv --dry-run --check-clicks   # + which clicks exist / already have it
p202 conversion import network-feb.csv                            # asks before writing
p202 conversion import network-feb.csv --force --json             # agents
p202 conversion import export.csv --subid-column 'Sub ID 2' --time-column 'Sale Date' --timezone America/New_York
```

The file is a CSV with a header row (comma, semicolon or tab, detected from
the header line) or a JSON array of objects. The columns are found by their
header; case, spaces and punctuation are ignored, so `Sub ID` is `sub_id`:

| Column | Headers recognised | Override | If absent |
|--------|--------------------|----------|-----------|
| subid (required) | `subid`, `sub_id`, `aff_sub`, `sub1`, `click_id`, `clickid`, `s2` | `--subid-column` | refused |
| payout | `payout`, `commission`, `amount`, `revenue` | `--payout-column` | the campaign's default payout |
| transaction id | `transaction_id`, `order_id`, `txid` | `--txid-column` | none |
| time | `date`, `time`, `conversion_date`, `created_at` | `--time-column` | the server's now |

The chosen columns are printed on stderr (`Columns: subid="Sub ID" (auto), ...`)
and returned in `meta.columns`. If two headers could be the same column (a
network's own `click_id` beside `aff_sub`), the command refuses and asks for
the flag rather than guessing.

How each cell is read:

- **subid**: exactly as the postback (`tracking202/static/gpb.php`) and the
  subid uploads read it: the click id, digits only, no sign, no leading zero,
  within bigint (`Prosper202\Click\ClickId::parse`). Surrounding spaces are
  trimmed, as the uploads do.
- **payout**: `$`, thousands separators and spaces are dropped, as the revenue
  upload does; more than five decimals are rounded the way the ledger stores
  them. A negative payout needs a transaction id: it reverses the sale with that
  id, as a postback would.
- **transaction id**: up to 255 bytes.
- **time**: unix seconds (10 digits) or milliseconds (13), `YYYYMMDD`,
  `2026-02-03`, `2026-02-03 14:05[:00]` (with or without `T`), or RFC 3339.
  Times without a zone are read in `--timezone` (default `UTC`; an IANA name
  or an offset such as `-05:00`). `--time-format` takes any Go layout, e.g.
  `'01/02/2006 3:04 PM'`. Slash dates are refused without it, because
  `02/03/2026` could be either month first or day first.

Each row gets a status and, where it is not imported, a `reason`:

| Status | Meaning |
|--------|---------|
| `ready` | (dry run) would be sent |
| `invalid` | a cell could not be read; `reason` names each one |
| `duplicate_in_file` | an earlier row has the same click and transaction id, or, without a transaction id, the same click. Like the postback, an id-less conversion converts its click once. |
| `created` | recorded; `conv_id` and `recorded_payout` are the server's |
| `duplicate` | already on the click (same transaction id, or an id-less row on a click that already converted), or the server matched or replayed an earlier one. Nothing new was recorded. |
| `click_not_found` | no click with that id in this account |
| `failed` | `reason` is the server's error, or `not sent: ...` after a lost connection |
| `staged` | under `--staged`: a proposal; `change_id` is what `p202 change apply` takes |

```text
$ p202 conversion import network-feb.csv --dry-run
Columns: subid="Sub ID" (auto), payout="Commission" (auto), transaction id="Order ID" (auto), conversion time="Date" (auto).
row  subid  click_id  transaction_id  payout   conv_time   status             reason
---  -----  --------  --------------  -------  ----------  -----------------  ------------------------------------------
2    12345  12345     A-1001          45.00    1770127500  ready
3    12346  12346     A-1002          1200.50  1770196350  ready
4    abc              A-1003          10.00    1770199200  invalid            subid "abc" is not a Prosper202 click id …
5    12345  12345     A-1001          45.00    1770285600  duplicate_in_file  same subid and transaction id as row 2
6    12347  12347                              1770375600  ready
Dry run: 5 row(s): 3 ready, 1 duplicate_in_file, 1 invalid; the ready rows carry 1245.50 in payouts (1 at the campaign's default payout). Clicks were not checked (add --check-clicks) and nothing was written; drop --dry-run to import.
```

Under `--json` the rows also carry `idempotency_key`, and `meta.summary`
counts every status and totals `payout_imported` (`ready_payout` in a dry run).

**Re-running is safe.** Without `--dry-run` the command first reads each
click's conversions (`GET /clicks/{id}/conversions`, once per click). Rows the
click already has are `duplicate` and are not sent. Every other row is sent as
`POST /conversions` with an `Idempotency-Key` computed from its click,
transaction id, payout and time, so the same row always has the same key and
a retry replays instead of recording again. A row the server answers as a
duplicate (`duplicate: true`, or a 409 for a deleted conversion's transaction
id) is `duplicate` too, even when the read did not show it. Rows with a negative payout go
after the sales, so a newest-first report reverses the right sale. After a
partial failure (exit 5), fix the cause and run the same command again: only
the rows not yet recorded are sent. The read needs a server with that endpoint
(1.9.76 or later); against an older one the command refuses before writing.

| Flag | Description |
|------|-------------|
| `--dry-run` | Show the plan; no request unless `--check-clicks` |
| `--check-clicks` | With `--dry-run`: also read each click's conversions |
| `--force`, `-f` | Skip the confirmation prompt |
| `--subid-column`, `--payout-column`, `--txid-column`, `--time-column` | Choose a column by its header |
| `--time-format` | Go layout of the time column |
| `--timezone` | Zone of times written without one (default `UTC`) |

### Compatibility aliases

The CLI accepts the following legacy flags for backward compatibility:

| Legacy flag            | Preferred flag |
|------------------------|----------------|
| `--click_id_public`    | `--click_id`   |
| `--conversion_payout`  | `--payout`     |

### Delete a conversion

```bash
p202 conversion delete 789
p202 conversion delete 789 --force
p202 conversion delete --ids 789,790,791 --force
```

`--ids` performs bulk delete in one CLI command and returns non-zero when any ID fails.

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

### Dashboard

Dashboard summary is a shortcut to `reports/summary`.

```bash
p202 dashboard
p202 dashboard --period last7 --aff_campaign_id 42
p202 dashboard --all-profiles
p202 dashboard --profiles prod,staging
p202 dashboard --group env:prod
```

If `--period` is omitted, dashboard defaults to `today`.

### Summary

Aggregate totals for the selected time period and filters.

```bash
p202 report summary --period today
p202 report summary --time_from 1700000000 --time_to 1700100000
p202 report summary --all-profiles --period today
p202 report summary --profiles prod,staging --period today
p202 report summary --group env:prod --period today
```

Summary/dashboard multi-profile selectors:

| Flag | Description |
|------|-------------|
| `--all-profiles` | Query all configured profiles |
| `--profiles <p1,p2,...>` | Query explicit profile list |
| `--group <tag>` | Query all profiles tagged with `tag` |

Multi-profile output includes per-profile rows, aggregated totals, and an `errors` list for partial failures.

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

**Breakdown dimensions:** campaign, aff_network, ppc_account, ppc_network, landing_page, keyword, country, city, region, browser, platform, device, isp, text_ad (aliases: lp, source, network, offer, geo). The server advertises its own list as `features.report_breakdowns` in `/capabilities`; a value missing from the CLI's list is sent when the server lists it.

**Sort columns:** total_clicks, total_leads, total_income, total_cost, total_net, roi, epc, conv_rate

Rows tied on the sort column come back in id order, so paging with `--offset` neither skips nor repeats a row.

### Analytics shorthand

```bash
p202 analytics --group-by country --period last30 --sort conversions
p202 analytics --group-by campaign --days 14 --sort roi --limit 10
```

`analytics` wraps `report breakdown` with friendly aliases:
- `--group-by` takes the breakdown dimensions above; `lp`, `source`, `network`, `offer` and `geo` map to `landing_page`, `ppc_account`, `aff_network`, `campaign` and `country`
- `--sort conversions` maps to `total_leads`
- `--period` takes precedence over `--days`

#### Before/after a date (`--split-at`)

```bash
# Which countries moved after 2026-09-04? (default window: last90)
p202 analytics --group-by country --split-at 2026-09-04

# Rank by the change in clicks per day, since the two sides differ in length
p202 analytics --group-by campaign --split-at 2026-09-04 --days 120 --sort clicks_per_day --limit 20 --json
```

`--split-at` takes a date `YYYY-MM-DD` (read as 00:00 UTC) or unix seconds. It splits the window into
`[start, split)` and `[split, end]`, reads the breakdown for each side (every page, 500 rows a request,
so the server's row cap drops nothing) and returns one row per value:

| Columns (for `clicks`, `conversions`, `revenue`) | Meaning |
|------|-------------|
| `<m>_before`, `<m>_after` | Totals on each side; a value seen on one side only gets `0` on the other |
| `<m>_change`, `<m>_change_pct` | `after − before`, and that as a percent of `before` (`null` when `before` is 0) |
| `<m>_per_day_before`, `<m>_per_day_after` | Each total divided by its side's length in days |
| `<m>_per_day_change`, `<m>_per_day_change_pct` | The same comparison on the per-day rates |

The sides are rarely the same length, so compare the `_per_day` columns: 6,500 clicks over 63.5 days
against 2,080 over 26.5 days is −68% in total but −23% per day. The table shows the clicks columns and
the percent changes; `--json` and `--csv` carry every column, and `--fields` picks any of them.

- **Window**: `--period last7|last30|last90`, `--days N`, or `--time_from <unix>` with an optional
  `--time_to <unix>` (default now); with none of them, `last90`. `today` and `yesterday` are refused
  (their bounds follow the server's midnight), and so is `--time_to` alone.
- **Order**: by the absolute change in clicks, largest first. `--sort clicks|conversions|revenue` ranks
  by another metric's change, `--sort clicks_per_day` (or `conversions_per_day`, `revenue_per_day`) by
  the change in its per-day rate; `--sort-dir ASC` reverses; `--limit`/`--offset` apply to the ranked rows.
- **Summary**: both sides' exact bounds, lengths and totals go to stderr, and under `--json` to `meta`
  (`split_at`, `window.{source,time_from,time_to}`, `before`/`after` with `time_from`, `time_to`,
  `seconds`, `days`, `rows` and the totals and per-day rates, `sort`, `rows`, `returned`).
- The split must fall inside the window with both sides non-empty; that, the window and the sort flags
  are checked before any request.

### Timeseries

Performance data over time intervals.

```bash
p202 report timeseries --period last30 --interval day
p202 report timeseries --interval hour --time_from 1700000000
```

| Flag            | Default | Description                   |
|-----------------|---------|-------------------------------|
| `-i, --interval` | day    | Interval: hour, day, week, month |

Invalid `--interval` values now return a validation error from the API (`422`) instead of silently defaulting.

Buckets come oldest first, at most 2000 per response. When the window holds more, the server cuts the newest ones and says so (`"truncated": true`, `"limit": 2000` in `--json` output), and the CLI prints a warning to stderr naming the last bucket returned, with a hint: narrow `--time_from`/`--time_to` (or use a shorter `--period`), or use a coarser `--interval` (`week` or `month`). A server from before the flag cuts at 2000 without saying so; 2000 buckets from one of those get the same warning, as "may be missing".

### Daypart

Performance aggregated by hour-of-day (`0`-`23`) across the selected date range.

```bash
p202 report daypart --period last30
p202 report daypart --sort roi --sort_dir DESC --country_id 223
```

| Flag            | Default      | Description |
|-----------------|--------------|-------------|
| `-s, --sort`    | hour_of_day  | Sort by: hour_of_day, total_clicks, total_click_throughs, total_leads, total_income, total_cost, total_net, epc, avg_cpc, conv_rate, roi, cpa |
| `--sort_dir`    | ASC          | Sort direction: ASC or DESC |

### Weekpart

Performance aggregated by day-of-week (`0` = Monday ... `6` = Sunday) across the selected date range.

```bash
p202 report weekpart --period last30
p202 report weekpart --sort roi --sort_dir DESC --country_id 223
```

| Flag            | Default      | Description |
|-----------------|--------------|-------------|
| `-s, --sort`    | day_of_week  | Sort by: day_of_week, total_clicks, total_click_throughs, total_leads, total_income, total_cost, total_net, epc, avg_cpc, conv_rate, roi, cpa |
| `--sort_dir`    | ASC          | Sort direction: ASC or DESC |

## Forecasting

`p202 forecast` projects any tracked metric forward from its history, entirely on the client, with calibrated prediction bands. Full guide with worked examples: [documentation/cli/11-forecasting.md](../documentation/cli/11-forecasting.md).

```bash
p202 forecast --metric revenue --horizon 7
p202 forecast --metric clicks --interval hour --horizon 24
p202 forecast --all-metrics --horizon 14 --json
p202 forecast --metric profit --seasonal --events
```

| Flag | Default | Meaning |
|------|---------|---------|
| `--metric`, `-m` | required unless `--all-metrics` | clicks, leads/conversions, revenue/income, cost, profit/net, epc, avg_cpc, conv_rate, roi, cpa |
| `--all-metrics` | off | Clicks, leads, income, cost, net together, kept consistent (`net = income − cost`, `leads = clicks × conv_rate`) |
| `--horizon`, `-n` | 7 | Periods to forecast (max 365) |
| `--interval`, `-i` | day | hour, day, week, month |
| `--history` | last90 (last30 hourly) | Training window |
| `--method` | auto | auto/ensemble, linear, sma, wma, holtwinters |
| `--confidence` | 0.95 | Band selection: <0.65 → p25–p75 (50%), <0.85 → p10–p90 (80%), else p05–p95 (90%) |
| `--seasonal` | off | Weekday profile learned from the fetched history (plus hour-of-day for hourly), applied only when the data repeats at that lag |
| `--seasonal-monthly` | off | Day-of-month profile (day interval, non-negative metrics) |
| `--events`, `--event-tag` | off | Fold in stored forecast events (day interval) |
| `--no-level-shift` | off | Do not fit only the newest regime after a detected level shift |
| `--no-anomaly-mask`, `--anomaly-sigma`, `--anomaly-cycles` | off / 5 / 4 | Transient (outage/spike) masking controls |

Each row carries the point forecast, `lower_bound`/`upper_bound`, and quantile columns; the meta reports the method and ensemble `weights`, `bounds`/`bounds_source`, rolling `mae`/`rmse`, `data_points_used`, `anomalies_masked`, `level_shift_at`, `composition`, and `seasonal_applied`. Forecasts are read-only and deterministic for the same history.

## Rotators

### List/get/create/update/delete rotators

```bash
p202 rotator list
p202 rotator list --all
p202 rotator get 5
p202 rotator create --name "Geo Split"
p202 rotator update 5 --name "Geo Split v2" --default_url "https://fallback.example.com"
p202 rotator delete 5
p202 rotator delete --ids 5,6 --force
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
p202 rotator rule-delete 5 --ids 12,13 --force
```

### Update a rule

```bash
p202 rotator rule-update 5 12 --rule_name "US Traffic v2"
p202 rotator rule-update 5 12 --status 0
p202 rotator rule-update 5 12 \
  --criteria_json '[{"type":"country","statement":"is","value":"US"}]' \
  --redirects_json '[{"redirect_campaign":"4","weight":"100","name":"US Offer"}]'
```

| Flag               | Description                |
|--------------------|----------------------------|
| `--rule_name`      | Rule name                  |
| `--splittest`      | Enable split test (0 or 1) |
| `--status`         | Rule status (0 or 1)       |
| `--criteria_json`  | Criteria as JSON array     |
| `--redirects_json` | Redirects as JSON array    |

## Attribution

Multi-touch attribution spreads each conversion's value over the visitor's
journey — their clicks, linked by first-party identity signals (the
tracking-domain cookie, the landing-page id, a signed customer id), across
campaigns — under every active model. A worker (`202-cronjobs/attribution-worker.php`,
also run by the minutely cron) computes credits from the conversion outbox.

### Models

```bash
p202 attribution model list
p202 attribution model create --model-name "Decay 24h" --model-type time_decay \
  --weighting-config '{"half_life_hours": 24}' --lookback-days 60
p202 attribution model update 3 --default
p202 attribution model update 3 --status inactive
p202 attribution model delete 3
```

| Flag                 | Required (create) | Description |
|----------------------|-------------------|-------------|
| `--model-name`       | Yes | Model name (unique per account) |
| `--model-type`       | Yes | last_touch, first_touch, linear, time_decay, position_based |
| `--weighting-config` | No  | JSON object: time_decay `{"half_life_hours":48}`; position_based `{"first_weight":0.4,"last_weight":0.4}`; the others take none |
| `--lookback-days`    | No  | 1–365, default 30: how far before a conversion a click can earn credit |
| `--status`           | No  | active or inactive |
| `--default`          | No  | Make it the account default (every account has exactly one; the default cannot be deleted or deactivated) |

### Reports

```bash
p202 attribution breakdown --group-by campaign
p202 attribution breakdown --group-by traffic_source --model 3 --compare-model 4 --period last7
p202 attribution journeys --period last30
p202 attribution journey 1234
p202 attribution queue
```

| Flag              | Default  | Description |
|-------------------|----------|-------------|
| `--group-by`      | campaign | campaign, traffic_source, landing_page, keyword, c1–c4, country, device, day |
| `--model`         | effective | A model id; without it each conversion uses its campaign's override, else the account default |
| `--compare-model` |          | A second model, side by side |
| `--period`        | last 30 days | today, yesterday, last7, last30, last90 |
| `--time-from/--time-to` |    | Unix seconds (exclusive with `--period`) |
| `--limit`         | 100      | Rows, 1–1000 |

Each row has attributed conversions (Σ credit), attributed revenue, the
dimension's own clicks and cost, ROI, and assisted conversions.

### Exports

```bash
p202 attribution export create --group-by campaign --model 3 --period last30
p202 attribution export create --group-by day --run-at 1790000000 \
  --webhook-url https://hooks.example.com/p202
p202 attribution export list --status failed
p202 attribution export download 7 --output export.csv
p202 attribution export retry 7
p202 attribution export delete 7
```

An export writes every group of a breakdown to a CSV (the minutely cron runs
it), downloadable, and optionally POSTs it to an https webhook signed with
HMAC-SHA256 (`X-P202-Signature: sha256=<hex>` over `<X-P202-Timestamp>.<body>`).
The create response carries `webhook_secret` once. Webhooks go only to public
addresses (every resolved address is checked, the connection is pinned to it,
redirects are not followed); a refused URL is a validation error naming the
address.

| Flag | Default | Description |
|------|---------|-------------|
| `--group-by` | campaign | A breakdown dimension |
| `--model`, `--compare-model` | the default model | Model ids |
| `--period`, `--time-from/--time-to` | last 30 days | As for `breakdown` |
| `--run-at` | now | Unix seconds |
| `--webhook-url`, `--webhook-secret` | none, generated | https only |
| `--output` / `-O` (download) | stdout | Write the CSV to a file |

## Users

### List and manage users

```bash
p202 user list
p202 user get 1
p202 user create --user_name admin2 --user_email admin2@example.com
p202 user update 1 --user_fname "Jane" --user_lname "Doe"
p202 user delete 2
```

When creating a user without `--user_pass`, the CLI asks for the password without echo (twice, so a typo is caught), or reads it as one line of stdin when piped. To change a password on update, use `--set-password` (the same prompt or pipe); changing your **own** password also needs `--current-password`, read first. A `--user_pass` value works on both but stays in shell history.

| Flag              | Required (create) | Description         |
|-------------------|-------------------|---------------------|
| `--user_name`     | Yes               | Username            |
| `--user_email`    | Yes               | Email address       |
| `--user_pass`     | Yes (prompted or piped) | Password, 8-72 characters |
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
p202 user apikey rotate 1 <old-key> --force
p202 user apikey rotate 1 <old-key> --keep-old --update-config
```

The full API key is displayed only once at creation time. Store it securely.

`rotate` creates a replacement key and can optionally delete the old key and update local CLI config.

### Identity linking key

```bash
p202 user identity-key get 1          # the key your server signs customer ids with
p202 user identity-key rotate 1       # asks first; --force skips the prompt
```

A `cust` on a tracking link, pixel or postback joins a person's journeys across
browsers and devices only when it carries `cust_sig`, computed on your own server:

```
cust_sig = hex(HMAC-SHA256(linking_key, "<cust_type>:<cust>"))
```

`cust_type` defaults to `custom`; `email_md5` and `email_sha256` digests are signed
in lower case. Rotating the key stops every signature made with the old one from
linking; clicks already linked stay linked.

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

## Export and import

```bash
p202 export campaigns --output /tmp/campaigns.json
p202 export all --output /tmp/full-export.json

p202 import campaigns /tmp/campaigns.json --dry-run
p202 import campaigns /tmp/campaigns.json --skip-errors
```

`export` supports: `campaigns`, `aff-networks`, `ppc-networks`, `ppc-accounts`, `rotators`, `trackers`, `landing-pages`, `text-ads`, `all`.

`import` currently supports one entity at a time and strips immutable fields before create requests.

## Multi-server workflows

### Diff

```bash
p202 diff campaigns --from prod --to staging --json
p202 diff all --from prod --to staging --json
```

`diff` reports per-entity `only_in_source`, `only_in_target`, `changed`, and `identical_count`.
When server capabilities expose `sync_plan`, the CLI uses `POST /api/v3/sync/plan` automatically and falls back to client-side diff when unavailable.

### Sync and re-sync

```bash
p202 sync all --from prod --to staging --dry-run --json
p202 sync campaigns --from prod --to staging --json
p202 sync campaigns --from prod --to staging --force-update --json

p202 sync status --from prod --to staging --json
p202 sync history --from prod --to staging --json
p202 re-sync --from prod --to staging --json
p202 re-sync --from prod --to staging --force-update --json
```

| Flag | Description |
|------|-------------|
| `--dry-run` | Compute actions without writes |
| `--skip-errors` | Continue on record-level errors |
| `--force-update` | Update mismatched target records instead of skipping |

Sync state is stored in `~/.p202/sync/<source>-<target>.json`.
When server capabilities expose `async_jobs`, the CLI routes sync execution through server endpoints (`/sync/jobs`, `/sync/re-sync`, `/sync/status`, `/sync/history`) and falls back to local client-side sync when unavailable.
Server-side jobs are queue-driven; run the worker endpoint or cron worker (`202-cronjobs/sync-worker.php`) in environments where asynchronous processing is enabled.
Server-side rotator rule re-sync after updates can be controlled with the server environment variable `SYNC_ROTATOR_RULE_RESYNC_ENABLED=0|1` (enabled by default).

### Server-side sync capabilities not yet exposed in CLI

The backend API supports additional sync features that are not yet available as CLI flags:

| Feature | API parameter | Description |
|---------|---------------|-------------|
| Prune | `prune=true` | Delete records in target that don't exist in source |
| Prune preview | `prune_preview=true` | Preview what prune would delete without acting |
| Collision mode | `collision_mode=warn\|manual` | Control behavior on natural-key collisions |
| Max attempts | `max_attempts=1-10` | Max retry attempts per job (default: 3) |
| Idempotency | `Idempotency-Key` header | Prevent duplicate job creation on retries |

The backend also provides endpoints for direct job management (`/sync/jobs/{id}`, `/sync/jobs/{id}/cancel`, `/sync/jobs/{id}/events`) and audit logging (`/audit/sync-jobs`). These are accessible via direct API calls but have no CLI commands yet.

### Exec across profiles

```bash
p202 exec --all-profiles -- campaign list --limit 5
p202 exec --profiles prod,staging -- report summary --period today
p202 exec --group env:prod -- dashboard --period last7
p202 exec --profiles prod,staging --concurrency 2 -- campaign list --limit 5
p202 --json exec --profiles prod,staging -- campaign list
```

`exec` table mode prints `=== profile ===` sections. JSON mode returns per-profile exit codes/output. The command exits non-zero if any profile run fails.
Use `--concurrency <n>` to limit parallel profile executions (default: `5`, minimum: `1`).

## System

```bash
p202 system health       # Health check (unauthenticated) and TLS certificate check
p202 system health --cert-warn-days 30
p202 system version      # Prosper202 + PHP + MySQL versions
p202 system db-stats     # Database table sizes
p202 system cron         # Is cron ticking? Rows and last run per job type
p202 system cron --raw   # Every 202_cronjobs row, as the server returns them
p202 system errors       # Recent system errors
p202 system errors --limit 5
p202 system dataengine   # Data engine job status
```

| Command              | Auth required |
|----------------------|---------------|
| `p202 system health` | No            |
| All others           | Admin         |

For an https base URL, `system health` checks the host's TLS certificate before it calls the API:
a verified handshake on its own connection, with no HTTP request. It adds `tls_status`,
`tls_detail`, `tls_not_after`, `tls_days_left` and `tls_issuer` to the health output.
`tls_status` is `ok`, `expiring` (expires within `--cert-warn-days`, default 21; 0 turns it off),
`expired`, `hostname_mismatch`, `unknown_authority`, `invalid` (any other certificate or handshake
failure) or `unreachable`; an `http://` base URL reports `not_used`.

Like `p202 rotator check`, it exits 5 (`partial_failure`) when `tls_status` is anything but `ok` or
`not_used`, with the health output still on stdout. An expired certificate is reported as one, with
the fix, even though the API call behind it then fails on the same certificate:

```
$ p202 system health
api_error:      network error (send_request): Get "https://tracker.example.com/api/v3/system/health": tls: failed to verify certificate: x509: certificate has expired or is not yet valid: …
status:         unknown
tls_days_left:  -3
tls_detail:     expired: tls: failed to verify certificate: x509: certificate has expired or is not yet valid: …
tls_issuer:     CN=R11,O=Let's Encrypt,C=US
tls_not_after:  2026-09-02T23:59:59Z
tls_status:     expired
Error [partial_failure]: the TLS certificate for tracker.example.com:443 expired on 2026-09-02 (3 day(s) ago): browsers refuse every https tracking link on this host
Hint: Renew the certificate on the server that answers for tracker.example.com (Let's Encrypt: `sudo certbot renew`, then reload nginx or Apache), and find out why auto-renewal stopped with `sudo certbot renew --dry-run`. Re-run `p202 system health` to confirm.
```

Run it from cron or an uptime monitor to hear about a certificate before it lapses. When only the
API call fails, the exit code is that error's (3 network, 4 server).

`system cron` summarizes `202_cronjobs` instead of printing every row (an install can hold
thousands): per job type, its row count and last run with its age, then the last execution
recorded in `202_cronjob_logs`. The column is `char(5)`: `hour` is the hourly tier and `secon` the
per-minute one, which records a row every minute by design (the daily prune keeps one day).

```
$ p202 system cron
type                  rows  last_run                 age
--------------------  ----  -----------------------  ---------
daily                 1     2026-10-03 12:00:00 UTC  in 11h42m
hour (hourly)         1     2026-10-03 00:00:00 UTC  17m49s
secon (every minute)  1     2026-10-03 00:17:01 UTC  48s
Last cron execution: 2026-10-03 00:17:49 UTC (0s ago); 3 row(s) in 202_cronjobs.
```

Development builds between 1.9.55 and 1.9.76 wrote the hourly type as `hourly` (1.9.55 wrote
`hour`), which the column stores as `hourl`: their already-ran check never found that row, so the
hourly tier ran, and added a row, every minute. Those rows are labelled `hourl (hourly, truncated)`,
and when they number more than 100 a note says so. Upgrading the server fixes it; the leftover rows
go with the next daily prune.

Rows record the slot a job ran for: the daily row is noon of its day, the hourly row the start of
its hour. When the last execution is older than 5 minutes (cron is not ticking) or none is
recorded, it exits 5 (`partial_failure`) with the summary still on stdout and a hint to check the
crontab line for `202-cronjobs/index.php`. `--json` returns the summary as one object (`status`,
`last_execution`, `types`, `warnings`, `notes`, …); `--raw` prints the server's rows as before, and
with `--json` adds them to that object as `jobs` and `recent_logs`.

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

### CSV mode (`--csv`)

CSV output is available for list/object responses:

```bash
p202 campaign list --csv
p202 report daypart --period last30 --csv
```

## Exit codes

| Code | Category | Meaning |
|------|----------|---------|
| 0    |          | Success |
| 1    | validation | Bad input, missing flags, invalid arguments |
| 2    | auth     | Authentication or authorization failure |
| 3    | network  | Connection timeout, DNS failure, unreachable server |
| 4    | server   | API returned a 5xx error |
| 5    | partial_failure | Bulk operation completed with some failures, or a check found a problem (its rows stay on stdout) |

Errors go to stderr; stdout stays empty on failure. In human mode:

```
Error [auth]: fetching historical data: API error (401): invalid api key
Hint: Verify your API key: run `p202 config get`, then `p202 config set-key <key>` if it's wrong.
```

With `--json` or `--ndjson` the same failure is one JSON envelope:

```json
{"error":{"category":"auth","message":"...","hint":"...","exit_code":2,"command":"p202 forecast","http_status":401}}
```

`field_errors` is added for 422 responses. Exit codes hold through error wrapping, and nearly every error carries a `hint` naming the next step (the command that yields the right value, the flag to change, or the order to follow); validation errors without a specific hint point at `<command> --help`. See the agent guide's [Error handling](cli-agent.md#error-handling) for the decision procedure.

## Telemetry

Set `P202_METRICS=1` to enable structured JSON telemetry on stderr. Each operation emits a single-line JSON event:

```
[metrics] {"op":"diff","entity":"rotators","duration_ms":1234,"success":true,"fields":{"ts":"2026-02-16T12:00:00Z"}}
```

Telemetry is off by default and adds no overhead when disabled.
