# Prosper202 CLI (`p202`)

A command-line tool for managing a Prosper202 tracking instance. Distributed as a single static binary with zero dependencies.

This is the Go CLI (`go-cli/`). The repository also ships a legacy PHP CLI at `bin/p202` that answers to the same name with a subset of these commands and `noun:verb` names; if `p202 --version` prints `p202 legacy PHP CLI (bin/p202)`, the `p202` on your `PATH` is that one.

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
p202 commands --brief             # every command on one line, with the UI page it does
p202 search breakdown by browser   # rank commands for a task (offline)
p202 commands                      # the whole command tree, indented
p202 commands --json               # every command and flag, with allowed values
```

`p202 commands --brief` is the catalog to read when choosing a command: each command
on one line with its summary and the web UI pages it does (with each page's command
line), then the pages no command does and where they are done instead. It is about
6,000 tokens, prints as text even for an agent (`--json` gives it as JSON), and takes
a subtree like `p202 commands` does.

`p202 search <words...>` matches your words against every command's name, aliases,
description, examples, flags, the values its flags accept, and the tasks it runs: the
web UI page that does the same ("Spy") and the words people use for it ("realtime
traffic"). Plural forms, inflected forms (imported, charged), split words (real time,
real-time) and common synonyms match (referrer/referer, offer/campaign, dead/broken,
undo/revert, link/url), and "per"/"by" ask for a breakdown. Misspellings are not
corrected: a word no command mentions is listed first ("No command mentions:
language."; `unknown_terms` in JSON) -- the commands lack it or call it something
else. Each result says why it matched and, when a flag value or a task
matched, prints the command line to try. It lists candidates, best first (`--limit N`,
default 5), and says when the first is not a confident match (`good_match: false` in
JSON: it matched under three quarters of your words); `--quiet` prints the first path
alone, and only for a confident match. It fails (exit 1, nothing on stdout) only when nothing matches, or
when the words name a UI page no command does, which it answers with where that page's
work is done instead.

`p202 commands [command...]` lists the tree, or one subtree (`p202 commands report`).
With `--json` each command carries its flags' name, shorthand, type, default, usage,
`required`, and for fixed-set flags `allowed_values` and `value_aliases`. Global flags
appear once. `--ndjson` prints one command per line and `--quiet` prints paths only;
`--brief` is the one-line-per-command catalog above.

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

Checks that the instance answers (the health endpoint, which needs no key) and
then that it accepts the configured key, printing the user the key acts as. A
refused key exits 2 (`auth`). The health check alone used to be the whole test,
so any key passed.

### `p202 whoami`

The user, roles and scopes the configured key acts as, with the username and
email, the profile and the URL:

```bash
p202 whoami --json
p202 --profile staging whoami
```

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
| `--aff_campaign_url_2` | No | Offer URL 2 (on update, `""` clears it) |
| `--aff_campaign_url_3` | No | Offer URL 3 (on update, `""` clears it) |
| `--aff_campaign_url_4` | No | Offer URL 4 (on update, `""` clears it) |
| `--aff_campaign_url_5` | No | Offer URL 5 (on update, `""` clears it) |
| `--aff_campaign_payout` | Yes | Default payout |
| `--aff_campaign_currency` | No | Currency code |
| `--aff_campaign_foreign_payout` | No | Foreign currency payout |
| `--aff_network_id` | Yes | Category (affiliate network) id, from `p202 aff-network list` |
| `--aff_campaign_cloaking` | No | Enable cloaking (0/1) |
| `--aff_campaign_rotate` | No | Enable rotation (0/1) |
| `--payout_mode` | No | How conversions set a click's value: `replace` (default) or `accumulate` |
| `--identity_signals` | No | Link this campaign's clicks into multi-touch journeys (1, default) or not (0) |
| `--app_registration_id` | No | The Android app registration the campaign's store links install (0 unlinks) |
| `--attribution_model_id` | No | The attribution model its conversions are credited with, overriding the account default (`p202 attribution model list`; 0 returns it to the default) |

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
| `--period <p>` | Window: `today`, `yesterday`, `last7`, `last14`, `last30`, `last90`, `thismonth`, `lastmonth`, `thisyear`, `lastyear`, `alltime` (default `last30`) |
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

### PPC network (`p202 ppc-network`)

| Flag                 | Required | Description  |
|----------------------|----------|--------------|
| `--ppc_network_name` | Yes      | Network name |

A traffic source's custom variables — the extra `parameter=placeholder` pairs
its tracking links carry, edited in Setup › Traffic Sources › variables:

```bash
p202 ppc-network variable list 3
p202 ppc-network variable create 3 --name 'Ad id' --parameter adid --placeholder '{ad_id}'
p202 ppc-network variable update 3 7 --placeholder '{{ad.id}}'
p202 ppc-network variable delete 3 7 --dry-run      # then --force
```

`tracker get-url` writes the live ones into every link of the source's
accounts; a parameter that is a built-in token (c1-c4, utm_*, t202kw, t202ref,
t202b) sets that token's default instead. Every field is required and not
blank; spaces, `&`, `#`, `?`, an `=` in the parameter, and the parameter
`t202id` are refused (they would break every link). A removed variable is
retired: clicks already recorded keep its values. Needs a role with
`remove_traffic_source` and `access_to_setup_section` (Super user or Admin):
the page shows its variables dialog only to such a role.

### PPC account (`p202 ppc-account`)

| Flag | Required | Description |
|------|----------|-------------|
| `--ppc_account_name` | Yes | Account name |
| `--ppc_network_id` | Yes | PPC network ID |
| `--ppc_account_default` | No | Set as default account (0/1) |

The pixels an account fires when one of its clicks converts (Setup › Traffic
Sources › the account's Advanced):

```bash
p202 ppc-account pixel list 4
p202 ppc-account pixel create 4 --type-id 4 --code 'https://network.example/pb?click=[[subid]]&payout=[[payout]]'
p202 ppc-account pixel create 4 --type-id 1 --code 'https://ads.example/px.gif?c=[[subid]]'
p202 ppc-account pixel update 4 9 --correction-url 'https://network.example/fix?tx=[[transactionid]]'
p202 ppc-account pixel update 4 9 --correction-url ''    # removes it
p202 ppc-account pixel delete 4 9 --force
```

`--type-id`: 1 Image, 2 Iframe, 3 Javascript, 4 Postback (server to server),
5 Raw (markup as given), 6 Bot202 Facebook Pixel Assistant. For every type but
Raw, `--code` is the pixel's URL (several separated by spaces); a Postback's
must be http(s). `--correction-url` goes on a Postback only, one URL per code
URL in the same order. A removed pixel goes with its correction URL.

### Tracker (`p202 tracker`)

| Flag | Required | Description |
|------|----------|-------------|
| `--aff_campaign_id` | Yes | Campaign ID |
| `--ppc_account_id` | No | PPC account ID |
| `--text_ad_id` | No | Text ad ID |
| `--landing_page_id` | No | Landing page ID |
| `--rotator_id` | No | Rotator ID |
| `--click_cpc` | No | Cost per click (0 to 99.99999). On `update`, switches a CPA tracker to CPC |
| `--click_cpa` | No | Cost per action (0 to 99.99999). On `update`, switches a CPC tracker to CPA. Not with `--click_cpc` |
| `--click_cloaking` | No | `-1` the campaign's setting (the default), `0` off, `1` on |

Tracker utility subcommands:

```bash
p202 tracker get-url 56
p202 tracker get-url 56 --t202kw '{keyword}' --c1 '{placement}'
p202 tracker create-with-url --aff_campaign_id 42
p202 tracker bulk-urls --aff_campaign_id 42 --concurrency 5
p202 tracker list --all --resolve-names
```

The link is the one **Get Links** builds: the tracking domain from Settings
(or this server's own address when none is set) and the install directory,
then the traffic source's custom variables, then the built-in tokens.
`get-url`, `create-with-url` and `bulk-urls` take a flag per built-in token —
`--c1` … `--c4`, `--utm_source`, `--utm_medium`, `--utm_campaign`,
`--utm_term`, `--utm_content`, `--t202ref`, `--t202b`, `--t202kw` — whose value
is written into the link as given, so pass the traffic source's macro. A value
containing `&`, `#`, `?`, whitespace or control characters is refused before
anything is sent; with `create-with-url` that means no tracker is created.

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
| `--landing_page_nickname` | Yes | Landing page nickname |
| `--leave_behind_page_url` | No | Leave-behind page URL (on update, `""` clears it) |
| `--landing_page_type` | No | Landing page type: 0 simple (one campaign), 1 advanced (several offers) |

The page's tracking code, as Setup › Get LP Code hands it out:

```bash
p202 landing-page code 12                                      # a simple page
p202 landing-page code 14 --offer campaign:3 --offer rotator:2  # an advanced page's offers, in order
p202 landing-page code 12 --json                               # every snippet as data
```

It prints the loader script for the page (above `</body>` of only the page
visitors arrive on), then the way out: for a simple page the outbound link
(`go.php?lpip=`), a PHP redirect page and a JavaScript redirect page; for an
advanced page each offer's outbound link (`go.php?acip=` / `go.php?rpi=`) and
PHP redirect. The links are scheme-relative (`//host/...`), as the page writes
them. An advanced page needs at least one `--offer`; the public id the code
carries (`lpip=`) is accepted too.

### Text ad (`p202 text-ad`)

| Flag | Required | Description |
|------|----------|-------------|
| `--text_ad_name` | Yes | Text ad name |
| `--text_ad_headline` | Yes | Headline |
| `--text_ad_description` | Yes | Description text |
| `--text_ad_display_url` | Yes | Display URL |
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
p202 click list --follow                         # the Spy page: newest 10, then each new click
p202 click list --follow --ndjson --stop-after 10m
```

| Flag                | Default | Description                          |
|---------------------|---------|--------------------------------------|
| `-l, --limit`       | 50      | Maximum results                      |
| `-o, --offset`      | 0       | Pagination offset                    |
| `--page`            |         | Page number (maps to offset)         |
| `--time_from`       |         | Start: unix seconds, a date (`2026-10-01`, account timezone) or a time with offset |
| `--time_to`         |         | End, inclusive: the same forms (a date runs through its last second) |
| `--aff_campaign_id` |         | Filter by campaign                   |
| `--ppc_account_id`  |         | Filter by PPC account                |
| `--landing_page_id` |         | Filter by landing page               |
| `--click_lead`      |         | 0 = clicks only, 1 = conversions only |
| `--click_bot`       |         | 0 = human, 1 = bot                   |
| `-p, --period`      |         | A named window in the account's timezone: `today`, `yesterday`, `last7`, `last14`, `last30`, `last90`, `thismonth`, `lastmonth`, `thisyear`, `lastyear`, `alltime` |
| `--keyword`, `--referer` | | The keyword, or the referring URL, contains this text |
| `--ip`              |         | One address, IPv4 or IPv6            |
| `--show`            | all     | `all`, `real`, `filtered`, `filtered_bot`, `leads` |
| `--device_type`, `--method_of_promotion`, `--aff_network_id`, `--ppc_network_id`, `--text_ad_id`, `--country_id`, `--region_id`, `--isp_id`, `--browser_id`, `--platform_id` | | The rest of the Visitors page's filters, as `p202 report` takes them |
| `--all`             | false   | Fetch all rows across pages          |
| `--follow`          |         | Print the newest `--limit` clicks (default 10), then each new click as it arrives |
| `--interval`        | 5s      | With `--follow`: how often to poll (at least 1s) |
| `--stop-after`      | 0       | With `--follow`: stop after this long (`0` follows until interrupted); the last read is at the deadline, so a click from the final interval is shown |

`--follow` is the Spy page. It prints each click once, in time order: JSON
output (`--json`, `--ndjson`, or chosen for an agent) is one object per line.
Each poll re-reads the 15 seconds behind the newest click it has seen, so a
click whose row is written a moment after a later click's still appears. A
failed poll ends it with that error's exit code (2–4); `--all`, `--offset`,
`--page`, `--time_from`, `--time_to` and `--csv` are refused with it.

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

### Set what past clicks cost

```bash
p202 click update-cpc --from 2026-10-01 --to 2026-10-07 --cpc 0.25 --aff-campaign-id 12 --dry-run
p202 click update-cpc --from 2026-10-01 --to 2026-10-07 --cpc 0.25 --aff-campaign-id 12
p202 click update-cpc --from 2026-10-07 --to 2026-10-07 --cpc 0.00125 --ppc-account-id 3 --method-of-promotion directlink --force
```

The UI's Update CPC. The clicks are the account's between `--from` 00:00:00
and `--to` 23:59:59 in the account's time zone, narrowed by `--aff-network-id`,
`--aff-campaign-id`, `--ppc-network-id`, `--ppc-account-id`,
`--landing-page-id`, `--text-ad-id` (0 = every one) and
`--method-of-promotion`. It counts them first and asks (`--force` skips the
question, `--dry-run` stops after the count); the update changes only the
clicks it counted, and writes nothing if they changed in between (run it
again). Needs the `access_to_update_section` role permission; cannot be
staged. Reports show the new cost once the data engine rebuilds the hours.

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
| `--time_from`   |         | Start: unix seconds, a date (`2026-10-01`, account timezone) or a time with offset |
| `--time_to`     |         | End, inclusive: the same forms |
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
| `--conv-time`      | No       | When it converted, unix seconds (default now) |
| `--status reversed`| No       | Record a reversal of the click's conversion with this `--transaction_id` instead |
| `--reversal-id`    | No       | The network's id for the reversal (with `--status reversed`) |
| `--customer-id` / `--customer-ref` | No | The LTV customer (one of them); `--customer-ref-type` says what the ref is, `--customer-crm '{…}'` seeds a customer the ref creates |
| `--item` / `--items-file` | No | Product line items (JSON objects) on the customer's revenue event; they need a customer — named here, already linked to the click, or the account's customer c-param — or the server refuses them (`422` naming `items`) and records nothing |

```bash
p202 conversion create --click_id 12345 --payout 49 --transaction_id ORD-1 \
    --customer-ref CUST-77 --item '{"sku":"PRO-1","quantity":1,"unit_price":49}'
p202 conversion create --click_id 12345 --status reversed --transaction_id ORD-1
```

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
| `conflict` | the click already has the row's transaction id with another payout or time; `reason` names the conversion and what differs. Not sent (or refused by the server), nothing recorded, exit 5: correct the row, or reverse the recorded sale and give the row its own transaction id. |
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

### Get the postback URL and the conversion pixel

```bash
p202 conversion postback-url --subid '{aff_sub}' --amount '{payout}'   # stdout: the URL alone
p202 conversion postback-url --campaign 12 --subid '#s2#'              # the advanced postback (cid)
p202 conversion pixel                                                 # the image pixel for the thank-you page
p202 conversion pixel --type universal [--iframe]                     # the smart pixel
p202 conversion pixel --json                                          # every pixel and postback the page shows
```

What Setup › Postback / Pixel shows, on this install's tracking domain.
`--subid` is your network's sub id macro and `--amount` a number or its
payout macro (empty pays the campaign's payout), written in as given;
`--campaign` fills the advanced pixel's `cid`; `--scheme http|https` overrides
the protocol. Stdout is the URL or pixel alone, the guidance on stderr, and
`-q` prints the same where JSON would otherwise be chosen (for an agent): the
image or iframe pixel is one line, the universal JavaScript pixel the page's
several-line `<script>` block. `landing-page code -q` prints the page's id.

### Update subids and upload revenue reports

```bash
p202 conversion mark-subids subids.txt --dry-run      # Update Subids: one subid per line (- reads stdin)
p202 conversion mark-subids subids.txt
p202 conversion delete-subids wrong.txt               # Delete Subids: previews, then asks
p202 conversion reset-subids --aff-network-id 3 --aff-campaign-id 12   # Reset Campaign Subids
p202 conversion upload-revenue march.csv --dry-run    # Upload Revenue Reports
p202 conversion upload-revenue march.csv --subid-column 'Sub ID 2' --amount-column Commission --force
```

The rest of the UI's Update section, over the same code the pages run.
`mark-subids` records each subid's click as converted at its campaign's payout,
once per click; `delete-subids` clears each subid's conversions (the clicks
stay); `reset-subids` clears every converted click of a category or one of
its campaigns. Each answers every line of the list with its line number and a
status (`marked`/`cleared`, `already_converted`, `not_found`, `not_a_subid`,
`duplicate_in_list`). `upload-revenue` records a commission report as one
batch: a click's lines are summed and the newest report replaces earlier
uploads' values (unlike `conversion import`, which records each sale with its
transaction id); its table lists the lines not recorded, with the reason.
`delete-subids`, `reset-subids` and `upload-revenue` ask before writing
(`--force` skips). All need the `access_to_update_section` role permission
(`delete-subids` also `delete_individual_subids`), refuse `--staged`, and are
safe to run again.

## Reports

All report commands (`report summary|breakdown|timeseries|daypart|weekpart|crosstab|breakeven|losers|winners`,
`analytics`, `dashboard`, `rotator stats`, `campaign optimize`) share the window and the Analyze pages' filters.

### Common report flags

| Flag                | Description              |
|---------------------|--------------------------|
| `-p, --period`      | Preset: today, yesterday, last7, last14, last30, last90, thismonth, lastmonth, thisyear, lastyear, alltime. Calendar presets start at the account's midnight (its timezone); lastN is today and the N whole days before it, from that day's midnight |
| `--time_from`       | Start: unix seconds, a date (`2026-10-01`, account timezone) or a time with offset (`2026-10-01T09:30:00Z`) |
| `--time_to`         | End, inclusive: the same forms (a date runs through its last second) |
| `--aff_campaign_id` | Filter by campaign       |
| `--ppc_account_id`  | Filter by PPC account    |
| `--aff_network_id`  | Filter by aff network    |
| `--ppc_network_id`  | Filter by PPC network    |
| `--landing_page_id` | Filter by landing page   |
| `--country_id`      | Filter by country        |
| `--text_ad_id`      | Filter by text ad        |
| `--region_id`       | Filter by region (the id of a `--breakdown region` row) |
| `--isp_id`          | Filter by ISP/carrier (the id of a `--breakdown isp` row) |
| `--browser_id`      | Filter by browser (the id of a `--breakdown browser` row) |
| `--platform_id`     | Filter by platform/OS (the id of a `--breakdown platform` row) |
| `--device_type`     | Filter by device type: 1 Desktop, 2 Mobile, 3 Tablet, 4 Bot |
| `--method_of_promotion` | `directlink` or `landingpage` |
| `--show`            | Which clicks count: `all` (default), `real` (not filtered), `filtered`, `filtered_bot`, `leads` (converted) |
| `--keyword`         | Keyword contains this text (case-insensitive; `%` and `_` are literal) |
| `--ip`              | One IPv4 or IPv6 address, exactly |
| `--referer`         | Referring URL contains this text (case-insensitive) |

A filter the server does not know, or a malformed value (`--ip 999.1.1.1`), is a
validation error naming it; `--show`, `--method_of_promotion` and `--period` are
checked before anything is sent. An id of `0` is no filter.

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
p202 report summary --time_from 2026-09-01 --time_to 2026-09-30
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
p202 report breakdown --breakdown referer --period lastmonth --show real --device_type 2
p202 report breakdown --breakdown ip --keyword "running shoes" --period thismonth
p202 report groups --by ppc_network,campaign,keyword --period last7   # the Group Overview: nested, each group with its totals
```

| Flag               | Default       | Description                |
|--------------------|---------------|----------------------------|
| `-b, --breakdown`  | campaign      | Dimension (see below)      |
| `-s, --sort`       | total_clicks  | Sort column                |
| `--sort_dir`       | DESC          | Sort direction: ASC or DESC |
| `-l, --limit`      | 50            | Maximum results (1–500)    |
| `-o, --offset`     | 0             | Pagination offset          |

**Breakdown dimensions:** campaign, aff_network, ppc_account, ppc_network, landing_page, keyword, country, city, region, browser, platform, device, isp, text_ad, ip, referer (the referring domain), referer_url (the whole URL), device_type (Desktop/Mobile/Tablet/Bot), c1, c2, c3, c4, utm_source, utm_medium, utm_campaign, utm_term, utm_content, rotator, rotator_rule (aliases: lp, source, network, offer, geo, referrer, referrer_url, rule). The server advertises its own list as `features.report_breakdowns` in `/capabilities`; a value missing from the CLI's list is sent when the server lists it.

A row is one stored value; clicks with no value for the dimension (no referer, no c1, no rotator rule) are in `report summary` only. A rotator's default is not a rule: `p202 rotator stats <id>` shows it.

**Sort columns:** total_clicks, total_click_throughs, total_leads, total_income, total_cost, total_net, epc, avg_cpc, conv_rate, roi, cpa (aliases: clicks, conversions, revenue, profit, cost, …). An unknown sort is refused by name; older servers ranked it by clicks.

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
# Which countries moved after 2026-09-04? (default window: --days 90)
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

- **Window**: `--days N` (the last N×24 hours, ending now), or `--time_from <unix>` with an optional
  `--time_to <unix>` (default now); with none of them, `--days 90`. Every `--period` is refused: each
  starts at a midnight in the account's timezone (`last7` is today and the 7 whole days before it, as
  the report pages count Last 7 Days), which the CLI cannot see, and `alltime` has no start. For the
  window `--period last7` reads, pass that midnight as `--time_from <unix>`. `--time_to` alone is
  refused too.
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
p202 rotator stats 5 --period last30          # totals, each rule, and the default
p202 rotator create --name "Geo Split"
p202 rotator update 5 --name "Geo Split v2" --default_url "https://fallback.example.com"
p202 rotator delete 5
p202 rotator delete --ids 5,6 --force
```

| Flag                 | Required (create) | Description             |
|----------------------|-------------------|-------------------------|
| `--name`             | Yes               | Rotator name            |
| `--default_url`      | No                | Default destination: an http(s) URL |
| `--default_campaign` | No                | Default destination: one of your campaign ids |
| `--default_lp`       | No                | Default destination: one of your landing page ids |

The default is one destination: give at most one of the three. On `update`,
giving one replaces the default whatever its kind (a campaign default becomes a
URL default), as the Redirectors page does.

### Rotator stats

`p202 rotator stats <id>` is the Overview's Rotator Breakdown for one rotator
(`GET /rotators/{id}/stats`): `totals`, one row per rule (`rule_id`,
`rule_name`, `status`, `deleted`) and `default` (the clicks no rule matched),
each with every report metric. It takes the report window and filters
(`--period`, `--show real`, `--keyword`, …). The rules and the default add up
to the totals; a rule deleted since, with clicks in the window, is listed with
`deleted: true`. A click counts for the rule that matched it — the Overview
page counted by the chosen redirect's id, so its per-rule rows can differ.
Needs read scope on rotators and reports. `--breakdown rotator_rule` gives
every rule of every rotator in one breakdown, without the defaults.

### Create a rule

```bash
p202 rotator rule-create 5 \
  --rule_name "US Traffic" \
  --criteria_json '[{"type":"country","statement":"is","value":"United States(US)"}]' \
  --redirects_json '[{"redirect_url":"https://us.example.com","weight":"100","name":"US Offer"}]'
```

| Flag               | Required | Description                |
|--------------------|----------|----------------------------|
| `--rule_name`      | Yes      | Rule name                  |
| `--splittest`      | No       | Enable split test (0 or 1) |
| `--status`         | No       | 1 active (default), 0 created paused |
| `--criteria_json`  | No       | Criteria as JSON array: `type` country, region, city, isp, ip, platform, device or browser; `statement` is or is_not; `value` comma-separated, countries as `United States(US)`, `ip` as single IPv4/IPv6 addresses (no ranges) |
| `--redirects_json` | No       | Redirects as JSON array: each with exactly one of `redirect_url`, `redirect_campaign`, `redirect_lp`, plus `weight` (0-100) and `name` |

Both JSON fields are checked for syntax before sending, and the server checks
the rest before writing anything: an unknown criterion type, a country written
as a bare code (`US` never matches), a redirect with no destination or two, or
a campaign or landing page that is not yours is refused naming the entry.

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
  --criteria_json '[{"type":"country","statement":"is","value":"United States(US)"}]' \
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
| `--period`        | last 30 days | today, yesterday, last7, last14, last30, last90, thismonth, lastmonth, thisyear, lastyear, alltime |
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

## Customer lifetime value (LTV)

Reads:

```bash
p202 ltv summary --period last30
p202 ltv customers --search acme --segment repeat          # repeat | subscribers | at_risk
p202 ltv customers --cf plan=pro --cf score.min=50 --all   # custom-field filters; every page
p202 ltv customers 42                                      # one customer in full
p202 ltv breakdown --by product --cf plan=pro
p202 ltv predict --by campaign
p202 ltv cohorts --months 12
p202 ltv products --limit 100
```

`--cf key=value` matches a custom field; `--cf key.min=N` and `--cf
key.max=N` bound a number or date field (at most 3 filters, on summary,
customers, breakdown and predict). `p202 ltv fields list` shows the keys.

Customer records. A record flag (`--first-name`, `--last-name`, `--email`,
`--phone`, `--company`, `--address-line1`, `--address-line2`, `--city`,
`--region`, `--postal-code`, `--country`) given `""` clears that field; one
not given is left as it is. `--field key=value` sets a custom field (`key=`
clears it), `--alias type=value` adds an identifier.

```bash
p202 ltv customer upsert --customer-ref CUST-77 --email ada@example.com --field plan=pro
p202 ltv customer update 42 --phone "" --city London
p202 ltv customer merge 42 --from 57 --force          # 57's history moves to 42; not reversible
p202 ltv customer erase 42 --dry-run                  # what goes, what stays
p202 ltv customer erase 42 --force
p202 ltv customer alias add 42 --type esp_id --value 48213
p202 ltv customer alias remove 42 7 --force
```

`erase` is a GDPR-style erasure, not a delete: name, email, phone, company,
address, aliases, custom-field values and personalization tokens are removed
and the record is anonymized, while its revenue events and subscriptions stay
(LTV totals do not change).

Companies:

```bash
p202 ltv company create --name "Acme Corp" --domain acme.com   # 409 when the name or domain is taken
p202 ltv company update 3 --domain ""                          # clear the auto-attach domain
p202 ltv company merge 3 --from 9 --force
p202 ltv company delete 9 --dry-run                            # `refused` says why it would not delete
```

Data from other systems (each names its customer with `--customer-id` or
`--customer-ref`):

```bash
p202 ltv revenue record --customer-ref CUST-77 --amount 49.00 --idempotency-key ORD-1001 \
    --item '{"sku":"PRO-1","quantity":1,"unit_price":49}'
p202 ltv revenue record --customer-id 42 --amount 10 --event-type refund
p202 ltv engagement-event record --customer-ref CUST-77 --event demo_requested
p202 ltv subscription upsert --external-sub-id sub_123 --customer-ref CUST-77 --amount 29 --interval month
p202 ltv subscription event sub_123 --type renewal --transaction-id ch_889
p202 ltv product upsert --sku PRO-1 --name "Pro plan" --price 49
p202 ltv next-offer impression 42 --campaign-id 7
```

Catalog products by id (`p202 ltv products` lists the ids):

```bash
p202 ltv product update 12 --name "Pro plan (annual)" --price 490
p202 ltv product update 12 --sku ""          # clear the sku ("" clears --price too)
p202 ltv product delete 12 --dry-run          # `refused` says why while order line items name it
p202 ltv product delete 12 --force
```

Settings:

```bash
p202 ltv fields create --key plan --type select --option free --option pro
p202 ltv fields update 3 --label "Plan tier" --required
p202 ltv fields delete 3 --dry-run                     # and how many customers' values go with it
p202 ltv webhooks create --url https://hooks.example.com/p202 --events revenue.recorded,subscription.changed
p202 ltv webhooks deliveries 4 --status failed   # status, attempts, next retry, last response or error
p202 ltv webhooks delete 4 --force
p202 ltv integrations create --provider klaviyo --config '{"list_id":"XyZ"}'
p202 ltv integrations delete 2 --force
```

`webhooks create` prints the signing secret once; it cannot be read back.
Every LTV write refuses the global `--staged` flag before sending anything
(the server stages none of them); every LTV delete takes `--dry-run`,
`--force` and `--ids`.

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

Every preference the settings pages set has a flag; `p202 user prefs update --help`
lists each with the values it takes, and a value outside them is refused before any
request. A free-text flag given `""` clears it.

| Flag | Values |
|------|--------|
| `--user_tracking_domain` | host, e.g. `trk.example.com`; `""` uses the install's own domain |
| `--user_daily_email` | `never`, `00`-`23` (hour, your time zone) |
| `--user_account_currency` | 3-letter code; re-prices every campaign's payout into it |
| `--user_keyword_searched_or_bidded`, `--user_pref_referer_data`, `--user_pref_dynamic_bid`, `--user_pref_privacy`, `--user_pref_cloak_referer`, `--user_pref_ad_settings` | Personal settings' choices |
| `--user_pref_time_predefined`, `--user_pref_limit`, `--user_cpc_or_cpv`, `--chart_time_range` | report and chart defaults |
| `--user_slack_incoming_webhook`, `--ipqs_api_key`, `--cb_key`, `--zaxaa_api_signature`, `--jvzoo_ipn_secret_key` | Integrations (a value here stays in shell history) |
| `--user_ltv_customer_cparam`, `--user_ltv_personalization_fields`, `--user_ltv_score_weights`, `--user_ltv_rec_fatigue` | LTV › Settings |

## Export and import

```bash
p202 export campaigns --output /tmp/campaigns.json
p202 export all --output /tmp/full-export.json

p202 import campaigns /tmp/campaigns.json --dry-run
p202 import campaigns /tmp/campaigns.json --skip-errors
```

`export` supports: `campaigns`, `aff-networks`, `ppc-networks`, `ppc-accounts`, `rotators`, `trackers`, `landing-pages`, `text-ads`, `all`.

`import` currently supports one entity at a time and strips immutable fields before create requests.
It sends linked ids (`aff_network_id`, `aff_campaign_id`, …) as they are in the file, and the server
takes only ids of the importing account's own live records, so a file exported from another
account or server is refused row by row with the field named. To copy between servers use
`p202 sync`, which maps each linked id to the target's. Without `--skip-errors` the first
refused record stops the import with that record's number, how many were imported before it,
and the API error's category, exit code and hint (a bad key exits 2; a 5xx, 4). With
`--skip-errors` the rest are sent, the summary lists each refusal under `errors`, and the command
exits 5 when any record failed; a 401 or 403 still stops it, since every later record would fail
the same way.

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
| `--skip-errors` | Continue on record-level errors; exits 5 when any record failed (a 401 or 403 still stops the sync) |
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
p202 system metrics      # Sync counters, job queue and active alerts
```

| Command              | Auth required |
|----------------------|---------------|
| `p202 system health` | No            |
| `p202 system info`, `login-log`, `retention …`, `isp-lookup …` | Admin, and a role with `access_to_settings` |
| `p202 system integrations` | Admin, and a role with `access_to_api_integrations` |
| All others           | Admin         |

### Account › Settings

What the Administration page shows and changes for this install. A key whose
user is not an Admin or the Super user is refused with `Admin access
required.` (exit 2; the hint names the role and `p202 whoami`); an Admin whose
role lacks the page's permission is refused naming it.

```bash
p202 system info                    # versions (and database_upgrade_needed), PHP limits, memcache,
                                    # clicks recorded, database size, cron last ran, DataEngine
p202 system login-log --limit 100   # sign-in attempts: user name, time, IP, passed/failed (default 50)
p202 system integrations --wide     # the INS/IPN/ZPN/webhook URLs to paste into ClickBank, JVZoo,
                                    # Zaxaa, Slack, PayKickstart; secret_stored, never the secret

p202 system retention show          # auto_delete_days, and any scheduled one-off deletion
p202 system retention set --days 180          # asks when it keeps less than now; --force skips
p202 system retention delete-before --date 2026-01-01 --dry-run   # what would go, per table
p202 system retention delete-before --date 2026-01-01             # previews, then asks

p202 system isp-lookup show         # on/off, and whether the MaxMind ISP database is in place
p202 system isp-lookup enable       # refused while GeoIP2-ISP.mmdb / GeoIPISP.dat is missing
p202 system isp-lookup disable
```

Retention applies to every account's clicks on the install (the cron job
reads it from user 1's preferences). `delete-before` deletes nothing itself:
it schedules the cron job to delete, in batches, every click all of whose
visits were recorded before midnight that begins `--date` (the account's time
zone), from every click table; a click visited again on or after the day is
kept whole, conversions and setup data are kept, and it cannot be undone. It
always previews first (counts per table) and asks before scheduling;
`--force` skips the question, never the preview, and the write carries the
cutoff time the preview named, so it never schedules a different cutoff than
was shown. None of these writes can be staged: `--staged` is refused before any
request. AutoCron and "update available" are not here: the page reaches a
remote Prosper202 service for both.

`retention set --days N` makes the cron job delete every click recorded
before midnight that began the day N days ago (server time), from every
click table, in batches over its next runs; N whole days and today are kept.

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
