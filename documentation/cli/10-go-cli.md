# Go CLI (p202)

Cross-platform CLI distributed as a single static binary with zero dependencies.

## Installation

**From a release zip** the binaries are already built, under
`go-cli/dist/<os>-<arch>/p202` for `linux-amd64`, `linux-arm64`, `darwin-amd64`
and `darwin-arm64`, and `go-cli/dist/windows-<arch>/p202.exe`.
`.claude/skills/onboard-prosper202/scripts/find-cli.sh` prints the path of the
one for the machine it runs on. The zip carries no Go source, so the build
below is for a checkout of the repository.

**From a checkout**, build for the current platform (requires Go):

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
p202 config test        # the instance answers AND accepts the key
p202 whoami             # which user the key acts as, its roles and scopes
p202 config show
```

## Output Modes

| Flag | Format | Use Case |
| ---- | ------ | -------- |
| (default) | Table, or compact JSON for an AI agent | Human-readable output; see below |
| `--json` | JSON (pretty-printed) | Structured output for automation |
| `--ndjson` | One compact JSON object per row | Streaming rows into other tools |
| `--csv` | CSV | Spreadsheet-compatible output |
| `-q`, `--quiet` | Ids, one per line | Scripting pipelines |
| `--table` | Table | Force tables when an agent would get JSON |

**AI agents get JSON by default.** When `AI_AGENT`, `CLAUDECODE`, `GEMINI_CLI`, `CODEX_SANDBOX`,
`CODEX_SANDBOX_NETWORK_DISABLED`, `CODEX_THREAD_ID` or `CURSOR_AGENT` is set (to anything but
empty, `0`, `false`, `no` or `off`), commands print compact single-line JSON and errors print as
the JSON envelope. Precedence, first match wins: a format flag; `P202_OUTPUT=json|table|ndjson|csv`;
the profile default `p202 config set-default output.format <format>`; an agent marker (unless
`--wide`, `--raw-headers` or `--fields` asks for a table shape); a table. Explicit `--json` keeps
its pretty-printed form, and `p202 config show` reports the format in use and why. The evidence for
each marker is in `docs/cli-agent.md`.

## Finding a Command

`p202 search <what you want to do>` ranks commands offline by your words, matched
against each command's name, aliases, description, examples, flags and the values
its flags accept (plurals fold; synonyms such as referrer/referer, offer/campaign,
dead/broken, undo/revert and link/url match; "per"/"by" ask for a breakdown). Each
result says why it matched and, when a flag value matched, gives a command line to
try; `--json` returns `{query, terms, good_match, note, results[]}`. When nothing
matches well, `good_match` is false, the note says so, and only the closest three
are shown.

`p202 commands [command...]` lists the command tree, or one subtree. `--json`
returns `{schema, cli_version, global_flags, commands[]}`: per command its path,
use, aliases, short and long description, examples, whether it runs, and its flags
(name, shorthand, type, default, usage, `required`, and for fixed-set flags
`allowed_values`, `value_aliases`, `value_list`). Global flags are listed once and
hidden flags are left out. `p202 --help` points at both commands.

## Commands

| Command | Description |
| ------- | ----------- |
| `p202 search <words...>` | Find the command for a task (offline; see [Finding a Command](#finding-a-command)) |
| `p202 commands [command...]` | Every command and flag, with allowed values, in one call (`--json`, `--ndjson`, `--quiet`) |
| `p202 campaign list` | List campaigns; `--url-contains <text>` returns every campaign with an offer URL (any of the five slots) containing the text; `--with-stats` adds each campaign's `total_clicks`, `total_leads`, `total_income`, `total_cost`, `total_net` for `--period` (default `last30`) or `--days N`, `0` when it had no traffic (needs `reports:read`); `--min-clicks N` keeps campaigns with at least N clicks |
| `p202 campaign get <id>` | Get a single campaign |
| `p202 campaign create` | Create a campaign |
| `p202 campaign update <id>` | Update a campaign |
| `p202 campaign delete <id>` | Delete a campaign |
| `p202 campaign replace-url` | Rewrite offer URLs in bulk: `--match <text>` plus `--with <text>` (replace the matched part) or `--set <url>` (whole URL, `{id}`/`{slug}` filled per campaign); `--slot`, `--ids`, `--aff-network-id` narrow it; `--dry-run` lists campaign, slot, old and new URL and writes nothing; otherwise confirms first (`--force` skips), one `PUT` per campaign, exit 5 on partial failure. Each applying run saves an undo manifest of its applied slots to `~/.p202/undo/` and prints `Undo with: … --undo <file>` (`meta.undo_manifest` under `--json`); `--undo <file>` restores the old URLs only where the slot still holds that run's new URL (others `skipped`), refuses a manifest from another base URL, and exits 5 if a manifest cannot be saved after the writes (it is then printed on stderr) |
| `p202 campaign check-urls` | Find dead offer URLs without sending clicks: once per unique host, a DNS lookup, a TCP connect and, for https, a verified TLS handshake, with no HTTP bytes sent. One row per URL slot with `status` (`ok`, `invalid_url`, `dns_failed`, `connect_failed`, `timeout`, `tls_failed`) and `detail`. `--url-contains`, `--slot`, `--ids` and `--aff-network-id` narrow it; `--timeout` is per host (default 5s). `--http` also sends a HEAD (a GET on 405) and reports `http_status`/`location`, after a click warning and a confirmation (`--force` skips it). Exits 5 when any status is not `ok` |
| `p202 aff-network list` | List affiliate networks (alias: `category`) |
| `p202 ppc-network list` | List PPC/traffic networks (alias: `traffic-network`) |
| `p202 tracker list` | List trackers |
| `p202 tracker get-url <id>` | The tracker's link as Get Links builds it: the tracking domain (or this server's address when none is set) and install directory, the traffic source's custom variables, then the built-in tokens. `--c1`..`--c4`, `--utm_source`, `--utm_medium`, `--utm_campaign`, `--utm_term`, `--utm_content`, `--t202ref`, `--t202b` and `--t202kw` fill a token with the value as given (the traffic source's macro, e.g. `--t202kw '{keyword}'`); `&`, `#`, `?`, whitespace and control characters are refused before any request. `create-with-url` and `bulk-urls` take the same flags; if `create-with-url` creates the tracker and then cannot fetch its link, the hint names the new `tracker_id` so the retry is `get-url`, not a second create |
| `p202 landing-page list` | List landing pages; `--url-contains <text>` returns every landing page whose `landing_page_url` or `leave_behind_page_url` contains the text |
| `p202 click list` | List clicks |
| `p202 click conversions <id>` | Explain a click's value: every conversion on it, whether it counts and why not, what produced it (goal and version, upload, reversal, API key), ending with the click's value; `--json` is `GET /clicks/{id}/conversions` unchanged |
| `p202 click update-cpc` | Set what past clicks cost (the UI's Update CPC): `--from`/`--to` days in the account's time zone, `--cpc`, optional id filters and `--method-of-promotion`; counts first, asks, then writes only the clicks it counted. See [Update CPC, subids and revenue reports](#update-cpc-subids-and-revenue-reports) |
| `p202 conversion mark-subids <file\|->` | Mark clicks converted from a subid list, one per line (the UI's Update Subids); once per click, safe to repeat |
| `p202 conversion delete-subids <file\|->` | Clear the conversions of a subid list (the UI's Delete Subids); previews, asks (`--force`), `--dry-run` |
| `p202 conversion reset-subids` | Clear every conversion of a category (`--aff-network-id`) or one of its campaigns (`--aff-campaign-id`) (the UI's Reset Campaign Subids) |
| `p202 conversion upload-revenue <file.csv>` | Record a network's revenue report as a new upload batch (the UI's Upload Revenue Reports): columns read from the header or named with `--subid-column`/`--amount-column`; the newest report replaces earlier uploads' values |
| `p202 conversion list` | List conversions, with their provenance (`--click_id`, `--source`, `--goal` filter by click, by what produced them and by goal) |
| `p202 conversion create` | Record a conversion on `--click-id` (`--payout`, `--transaction-id`, `--conv-time`); `--status reversed` (with the sale's `--transaction-id`, optionally `--reversal-id`) records a reversal instead. `--customer-id` or `--customer-ref` (+ `--customer-ref-type`, `--customer-crm '{…}'`) links it to an LTV customer, and `--item '{…}'`/`--items-file` add product line items, which need a customer named |
| `p202 conversion import <file>` | Record a network's conversion export (CSV with a header row, or a JSON array of objects) against the clicks its subids name, for installs whose postbacks were never wired. Columns are auto-detected from common headers (subid: `subid`, `sub_id`, `aff_sub`, `sub1`, `click_id`, `clickid`, `s2`; payout: `payout`, `commission`, `amount`, `revenue`; transaction id: `transaction_id`, `order_id`, `txid`; time: `date`, `time`, `conversion_date`, `created_at`) and reported on stderr and in `meta.columns`; two candidate headers for one column are refused, and `--subid-column`/`--payout-column`/`--txid-column`/`--time-column` choose. The subid is read as the postback reads it (the click id: digits, no leading zero); rows are `invalid` (with the reason), `duplicate_in_file`, or ready. `--dry-run` sends nothing (`--check-clicks` adds one read-only `GET /clicks/{id}/conversions` per click). Otherwise the clicks are read first, you confirm (`--force` skips; `--staged` records proposals), and each ready row is `POST /conversions` with an `Idempotency-Key` derived from its click, transaction id, payout and time. Rows end `created`, `duplicate` (already on the click), `click_not_found`, `failed` or `staged`; re-running the same file sends only what is not recorded yet; exit 5 if any row failed |
| `p202 rotator list` | List rotators |
| `p202 rotator stats <id>` | A rotator's totals, each rule (the rule a click matched) and its default (clicks no rule matched) over the report window and filters (`GET /rotators/{id}/stats`); rules plus default add up to the totals, and a rule deleted since is listed with `deleted: true` |
| `p202 report summary` | Performance summary |
| `p202 report breakdown` | Performance by dimension, including the Analyze pages' `ip`, `referer` (domain), `referer_url`, `device_type`, `c1`-`c4`, `utm_*`, `rotator` and `rotator_rule`; rows tied on `--sort` come in id order, so `--offset` paging neither skips nor repeats a row. Every report command takes the Analyze pages' filters: `--text_ad_id`, `--region_id`, `--isp_id`, `--browser_id`, `--platform_id`, `--device_type`, `--method_of_promotion`, `--show all\|real\|filtered\|filtered_bot\|leads`, `--keyword` (contains), `--ip` (exact), `--referer` (contains), and the periods `last14`, `thismonth`, `lastmonth`, `thisyear`, `lastyear`, `alltime` beside `today`, `yesterday`, `last7`, `last30`, `last90` (calendar periods start at the account's midnight). An unknown filter, value or `--sort` is a validation error naming it |
| `p202 report timeseries` | Performance over time (`--interval hour\|day\|week\|month`), oldest bucket first, at most 2000 buckets. A series the server cut has `truncated: true` and `limit` in `--json`, and stderr warns with the last bucket returned and a hint to narrow `--time_from`/`--time_to` or use a coarser `--interval`; 2000 buckets from a server that predates the flag get the same warning as "may be missing" |
| `p202 attribution model list` | List attribution models |
| `p202 attribution export create` | Queue a multi-touch export: every group of a breakdown as CSV, now or at `--run-at`, optionally POSTed to an https `--webhook-url` (signed; public addresses only, pinned, no redirects). `export list`, `get`, `download <id> --output file`, `retry`, `delete` |
| `p202 app postbacks list` | List received SKAdNetwork and AdAttributionKit postbacks (`--registration-id`, `--protocol skan\|aak`, `--conversion-type`, `--ad-interaction-type`, `--signature valid\|invalid\|unverifiable\|development`; `app postbacks get <id>` for one). `--redownload` and `--fidelity-type` are SKAdNetwork's spellings of the same two filters and match both protocols — `--redownload 1\|0` selects `conversion_type` `redownload`\|`download`, `--fidelity-type 1\|0` selects `ad_interaction_type` `click`\|`view` — but only `--conversion-type` can name `re-engagement`, so prefer the neutral pair |
| `p202 app report` | The app report: Apple's postbacks with SKAN decoding by default (as before Android), Android installs with `--platform android`, both with `--platform all` (totals in `meta.totals`: `{ios, android, combined}`). Any platform groups by `day\|registration\|platform`; iOS also by `ad-network\|source\|country\|version\|protocol\|conversion-type`, counting unique trusted postbacks and every trust class beside them; Android also by `campaign\|match-state\|integrity-state\|ctit-flag\|goal` (the funnel), with `--match-state`, `--integrity-state`, `--trusted`, `--test`, `--aff-campaign-id`, `--ctit-flag short\|ok\|long\|unmeasured`, `--fast-goals 0\|1` (rows carry `ctit_measured`, `ctit_short`, `ctit_long`, `fast_goals`). A grouping or filter one platform lacks is refused before any request, naming the `--platform` to use. A day report with no `--time-from` covers the whole retained history: it returns the newest `--limit` populated days however far back they sit, and sets `meta.groups_truncated` when older ones were cut |
| `p202 app link <registration-id>` | The store link a campaign advertising the app should send its clicks to (Android: with `[[p202_install_token]]`), and with `--campaign-id N` whether that campaign is ready and what is missing; `--apply` sets the campaign's offer URL and, for Android, links it to the app |
| `p202 app notifications` | The traffic-source postbacks app installs' goals queued, one row per pixel URL, with `meta.summary` counting each status (`--registration-id`, `--status pending\|sent\|failed\|cancelled\|suppressed`, `--kind reached\|correction\|retraction`, `--time-from/--time-to`) |
| `p202 app list` | Registered apps, iOS and Android (CRUD; `app create --store-link <App Store or Google Play link>` registers from a link the server reads, or `--app-key`; registering claims the app's postbacks; `--accept-test-signals 1` trusts AdAttributionKit development-signed postbacks while integration-testing; `--platform ios\|android` narrows the list; Android fraud limits on create and update: `--ctit-min-seconds`, `--ctit-max-seconds`, `--install-cap-per-minute`, `--event-cap-per-minute`, `--fast-goal-seconds`, `--fast-goal-policy count\|hold`, see [Android installs §10](../api/24-android-installs.md#10-fraud-limits)) |
| `p202 app encoding list` | SKAN encodings (CRUD; `--registration-id` names an iOS registration, `0` the account-wide set; `--goal-id` the goal the value means — any goal a device can reach, since the iOS SDK evaluates it on the device, so none counting from the click — `--revenue-override` its tiered revenue; drives both reports and the runtime schema. Every edit keeps the old meaning: for 48 days the report counts a value whose meanings disagree as `ambiguous_encoding`) |
| `p202 goal list` | Goals, versioned (`goal create --campaign-id\|--registration-id\|--account` from quick flags — `--name --event --where "level gte 3; country in US,CA" --count --sum-prop --sum-gte --after --within-days --within-from --repeat --repeat-max --value --value-from-property --no-value` — or `--definition`/`--file`; `goal update <id>` changes only the parts its flags name, as a new version; `goal versions`, `goal outcomes`, `goal validate`, `goal evaluate --file`, `goal reevaluate <id> [--apply]`, `goal campaign set\|list\|remove` for payouts; `goal delete` archives) |
| `p202 event send` | Report a click's web events, evaluated by its campaign's goals: `--click-id --name --id` (your id: resending it is recorded once) with `--revenue --transaction-id --occurred-at --props '{…}'`, or `--file` of events; `--idempotency-key`, `--staged` (see [web events](../api/23-events.md)) |
| `p202 app install list <registration-id>` | An Android registration's installs with their match state and reason (`--match-state`, `--trusted trusted\|refuted\|unvouched`, `--test 0\|1`, `--click-id`, `--time-from/--time-to`; `app install get <id> <install-uuid>` for one). `app install token <id> --click N` shows a click's install token and store link; `app install simulate <id> --click N` posts the install the SDK would send for that click and prints the answer (`--install-uuid` to replay one, `--test`; refused under `--staged`, since the public intake records at once). `--integrity-state` filters by Play Integrity verdict, `--ctit-flag short\|ok\|long\|unmeasured` by click-to-install time |
| `p202 app integrity status <registration-id>` | Play Integrity for an Android app: its mode, its service account (never the key), verdict counts and today's decodes. `app integrity credential set <id> --file key.json` sets or rotates the service account (read from a file or stdin, never a flag value; refused under `--staged`); `app integrity credential clear <id>` deletes it (confirms unless `--force`; refused while the mode is observe or require, or while installs still wait for a verdict). The mode is `app update <id> --integrity-mode off\|observe\|require --integrity-cloud-project-number N`; observe and require need both the credential and the project number, which can be replaced but not cleared |
| `p202 app schema <registration-id>` | Show the app's schema document exactly as devices fetch it (`app rotate-token <id>` replaces the app token) |
| `p202 app verify` | Verify a postback's Apple signature — SKAdNetwork, or AdAttributionKit when the body carries a `jws-string` (stores nothing). Reads the postback from `--file <path>`, or from piped stdin when `--file` is omitted or given as `-`; with neither (stdin still a terminal) it fails naming both forms instead of blocking on a read that never returns |
| `p202 ltv summary` | Customer lifetime value reads: `summary`, `customers [id]` (`--search`, `--segment repeat\|subscribers\|at_risk`, `--all` pages at 500), `breakdown`, `predict`, `cohorts --months 1-24`, `products`, `mrr`, `subscriptions`, `companies`, `abm`, `engagement <customer-id>`. `--cf key=value`, `--cf key.min=N`, `--cf key.max=N` (at most 3) filter summary, customers, breakdown and predict by custom field |
| `p202 ltv customer upsert` | Customer records: `upsert` (`--customer-ref` creates or finds, `--customer-id` updates), `update <id>`, `merge <target> --from <source>` (asks; `--force`), `erase <id>` (GDPR erasure: personal data, aliases and field values go, revenue stays; `--dry-run` lists both, asks otherwise), `alias add <id> --type --value`, `alias remove <id> <alias-id>`. Record flags `--first-name … --country`, `--field key=value`, `--alias type=value`; a flag given `""` clears that field, one not given is left alone |
| `p202 ltv company create` | Company (ABM account) writes: `create --name [--domain]` (409 names the one that exists), `update <id> [--name] [--domain ""]`, `merge <target> --from <source>`, `delete <id>` (refused while customers are attached; `--dry-run` says why under `refused`) |
| `p202 ltv revenue record` | Ingest from other systems: `revenue record` (`--amount`, `--event-type purchase\|one_time\|refund\|chargeback\|adjustment`, `--item`/`--items-file`, `--idempotency-key`), `engagement-event record --event` (ABM events, not `p202 event send`), `subscription upsert --external-sub-id --amount` and `subscription event <external-sub-id> --type renewal\|cancel\|refund`, `product upsert`, `next-offer impression <customer-id> [--campaign-id]`. Each names its customer with `--customer-id` or `--customer-ref` |
| `p202 ltv fields list` | Account settings: `fields list\|create\|update\|delete` (custom fields: `--key`, `--type`, `--option` for select), `webhooks list\|create\|delete` (`--url https://…`, `--events` from the known list or `*`; the secret is printed once), `integrations list\|create\|delete` (`--provider`, `--config '{…}'` or `--config-file`). Every LTV write refuses `--staged` (the server stages none); every delete takes `--dry-run`, `--force` and `--ids` |
| `p202 forecast` | Forecast future metrics from historical data |
| `p202 dashboard` | Overview of clicks, conversions, revenue, cost, profit, ROI |
| `p202 analytics` | Grouped performance analytics shorthand |
| `p202 user list` | List users |
| `p202 change list` | Review staged writes awaiting approval |
| `p202 eval run` | Run behavioral evals against an agent driving this instance |
| `p202 system health` | Health check, plus a TLS certificate check of an https base URL made first, on its own connection (verified handshake, no HTTP request). Adds `tls_status` (`ok`, `expiring` within `--cert-warn-days` (default 21), `expired`, `hostname_mismatch`, `unknown_authority`, `invalid`, `unreachable`; `not_used` for http), `tls_not_after`, `tls_days_left`, `tls_issuer` and `tls_detail`. Exits 5 with the health object on stdout when `tls_status` is not `ok`/`not_used`; an expired certificate is reported as expired with a `certbot renew` hint, not as the network error the API call then hits |
| `p202 system cron` | Whether cron is ticking: per `cronjob_type` its row count and last run with age, and the last execution from `202_cronjob_logs` with its age. `hour` is labelled hourly and `secon` every minute; `hourl`, the `hourly` that development builds between 1.9.55 and 1.9.76 wrote, truncated by the `char(5)` column, is labelled so, with a note when it piles up. Exits 5 with the summary on stdout when the last execution is older than 5 minutes (`stale`) or missing (`never_ran`). `--raw` prints every row as the server returns it (under `--json`, added to the summary as `jobs`/`recent_logs`) |

All entities support standard CRUD operations (`list`, `get`, `create`, `update`, `delete`) where applicable. Five behaviors apply across the board on servers that advertise them in `/capabilities`:

- every `create` takes `--idempotency-key <key>` — a retry with the same key and payload replays the recorded response instead of creating a duplicate (`features.create_idempotency`);
- every `delete` (including `--ids` bulk and `rotator rule-delete` / `user role remove` / `user apikey delete`) takes `--dry-run` — a read-only preview of the record and cascade counts the delete would remove (`features.delete_dry_run`);
- `p202 user apikey create|rotate` refuse an *explicitly empty* `--scope` (`--scope "$SCOPE"` with the variable unset): omitting the flag means full access by design, but a blank value is malformed input and must not silently mint a full-access credential;
- the global `--staged` flag turns any write into a recorded proposal with a server-issued change id instead of executing it; `p202 change list|show|apply|discard` reviews and resolves the queue, with the write re-validated at apply time (`features.staged_writes`);
- `user apikey create` takes `--scope` to mint least-privilege keys (`*`, `read`, `write`, `stage`, or `<area>:read`/`<area>:write`/`<area>:stage` tokens — `read,stage` is the propose-only agent shape), and `user apikey rotate` carries the old key's scope onto the replacement (`features.api_key_scopes`).
- `user identity-key get` shows the key your own server signs customer ids with (`cust_sig = hex(HMAC-SHA256(key, "<cust_type>:<cust>"))`); `user identity-key rotate` replaces it after a confirmation (`--force` skips it, `--staged` proposes it instead).

For teams shipping an AI agent on top of the CLI, `p202 eval run` executes behavioral snapshot evals: it hands each case's ask to a pluggable agent command, captures every `p202` invocation the agent makes via a PATH shim, re-reads instance state, and grades expectations (commands run, state unchanged/changed, reply contents, optional judged rubric). Cases follow the shape in `.claude/skills/p202-agent-evals/SKILL.md`; a starter suite ships in `tests/fixtures/agent-eval/cases/`. Exit code 0 when clean, 5 (`partial_failure`) when cases fail — results stay on stdout.

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

`--group-by` takes the report breakdown dimensions: campaign, aff_network, ppc_account, ppc_network, landing_page, keyword, country, city, region, browser, platform, device, isp, text_ad, ip, referer, referer_url, device_type, c1, c2, c3, c4, utm_source, utm_medium, utm_campaign, utm_term, utm_content, rotator, rotator_rule. Aliases: `--group-by lp` -> `landing_page` (also `source`, `network`, `offer`, `geo`, `referrer`, `referrer_url`, `rule`), `--sort conversions` -> `total_leads`, `--sort revenue` -> `total_income`. A dimension missing from this list is sent when the server advertises it in `/capabilities` (`features.report_breakdowns`); otherwise it is refused with the list.

`--split-at YYYY-MM-DD|unix` compares the two sides of a date (00:00 UTC) inside the window
(`--period last7|last14|last30|last90`, `--days N`, `--time_from`/`--time_to`; default `last90`; the calendar periods and `alltime` are refused, their bounds being the account's midnight or none): one row per
value with clicks, conversions and revenue before, after, the change and percent change, and each
side's per-day rate, since the sides are rarely the same length. Values on one side only get zeros on
the other; rows rank by the absolute change in clicks (`--sort clicks_per_day` for the per-day rate).
Both windows' bounds and lengths go to stderr and to `meta` under `--json`.

```bash
p202 analytics --group-by country --split-at 2026-09-04 --sort clicks_per_day --limit 10
```

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
p202 conversion import network-feb.csv --dry-run --check-clicks
p202 conversion import network-feb.csv --force
```

`conversion import` is safe to re-run. Before writing, it reads each click's
conversions: a row whose transaction id is already on the click, or an
id-less row whose click already converted (the postback converts a click once
without an id), is `duplicate` and is not sent. The rest carry a fixed
`Idempotency-Key` each, so a retry within the server's 24-hour idempotency
window replays instead of recording again. Negative rows (reversals) are sent
after the sales, so a newest-first report still reverses the right sale. A
refused key or a lost connection while reading stops the command with its own
exit code (2 or 3) before anything is written; during the writes it stops
sending, marks the unsent rows `failed`, and exits 5 with a hint to re-run.

## Update CPC, Subids and Revenue Reports

The UI's **Update** section, over the [Update API](../api/26-update.md). Each
command checks first with the endpoint's own `?dry_run=1` and writes only
after that; the ones that change or remove what is recorded ask before
writing (`--force` skips the question; with no terminal to answer it, the
command fails rather than reading "no"). `--dry-run` stops after the check.
None can be staged: `--staged` is refused before any request. They need a
role with the `access_to_update_section` permission (and
`delete_individual_subids` for `delete-subids`), as the pages do; a role
without it is exit 2 with a hint naming `p202 user role assign`.

```bash
# What did those clicks really cost? Count, confirm, write.
p202 click update-cpc --from 2026-10-01 --to 2026-10-07 --cpc 0.25 --aff-campaign-id 12
p202 click update-cpc --from 2026-10-07 --to 2026-10-07 --cpc 0.00125 --ppc-account-id 3 --dry-run

# A network's list of converted subids, one per line (- reads stdin).
p202 conversion mark-subids subids.txt --dry-run
p202 conversion mark-subids subids.txt
p202 conversion delete-subids wrong.txt --force

# Undo a whole campaign's subids, to upload the right ones again.
p202 conversion reset-subids --aff-network-id 3 --aff-campaign-id 12

# A commission report: one batch, a click's lines summed, the newest report replacing the last.
p202 conversion upload-revenue march.csv --dry-run
p202 conversion upload-revenue march.csv --subid-column 'Sub ID 2' --amount-column Commission
```

- **`click update-cpc`** sets the cost of the account's clicks between
  `--from` 00:00:00 and `--to` 23:59:59 in the account's time zone, narrowed by
  `--aff-network-id`, `--aff-campaign-id`, `--ppc-network-id`,
  `--ppc-account-id`, `--landing-page-id`, `--text-ad-id` (0 = every one) and
  `--method-of-promotion directlink|landingpage`. The check answers how many
  clicks match and the highest click id; the write carries both and the
  server changes the clicks only if they still number the same — so a click
  recorded after the check is never changed, and when the selection moved
  nothing is written (exit 1, "run the same command again"). `--force` skips
  the question, never the check. Reports show the new cost once the data
  engine rebuilds those hours (its cron job).
- **`conversion mark-subids`** records each subid's click as converted at its
  campaign's payout (source `subid_upload`), once per click.
  **`conversion delete-subids`** clears each subid's conversions (the clicks
  stay). Both read one subid per line and answer every line with the file's
  line number and a status: `marked`/`cleared` (`would_mark`/`would_clear`),
  `already_converted`, `not_found` (not a click of this account),
  `not_a_subid`, `duplicate_in_list`. A list is sent 1,000 lines a request;
  when one fails, the lines before it stand, the error says which, and
  running the same command again is safe.
- **`conversion reset-subids`** clears every converted click of the category,
  or of one campaign in it; it counts them first.
- **`conversion upload-revenue`** is the UI's upload, not `conversion
  import`: the whole report is one batch (at most 8 MB), each click's lines
  are summed, and the newest report replaces what earlier uploads and
  conversions set for the click. Columns come from the header when it names
  them plainly; `--subid-column`/`--amount-column` take a header or a 0-based
  column number. The table lists the lines not recorded, with the reason; a
  report that would record nothing is refused (exit 1) with the subid column
  it read.

Under `--json` each prints the API's answer (for a list in parts, the parts
put together); otherwise a table of the lines, and a summary on stderr.

## Config Defaults

Set per-profile defaults for frequently used flags.

```bash
p202 config set-default report.period last30
p202 config set-default report.campaign_id 5
p202 config get-default report.period
p202 config unset-default report.period
p202 config set-default output.format table   # json, table, ndjson or csv
```

## Errors

Every failure is reported on **stderr** with a category, the message, and,
whenever there is a concrete next step, a recovery hint, including failures
the CLI raises before running the command (a wrong argument count, an
unknown subcommand). Stdout stays empty on failure, so a script never
confuses an error for data. Exit codes follow
the category (table below) and survive wrapping: "fetching historical data:
API error (401)" still exits 2.

Human mode:

```text
Error [auth]: fetching historical data: API error (401): invalid api key
Hint: Verify your API key: run `p202 config get`, then `p202 config set-key <key>` if it's wrong.
```

With `--json` or `--ndjson`, and whenever JSON was chosen automatically
for an AI agent (see Output Modes), the same failure is a single JSON
envelope, so an agent reads structured fields instead of parsing prose:

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
is ignored; the hint carries the next action. Every flag that takes a fixed
set of values is declared with that set: its `--help` lists it, a value
outside it is refused before the command runs (so before any request, and
with no configuration needed) as `--<flag> must be one of: <values>; got
"<value>"`, exit 1, and `p202 commands --json` reports it as
`allowed_values`. Tests walk the command tree to keep every such flag this
way and to refuse help text that trails off (`etc.`, `...`) instead of
listing values.

An unknown command or flag exits 1 with a hint naming `<command> --help` and
`p202 search <what you want to do>`. A mistyped subcommand under a group
(`p202 campaign lsit`) is refused the same way, with Cobra's suggestion (`did
you mean list?`), instead of printing the group's help and exiting 0. Hints come from three sources,
in order of precedence: a hint attached by the command itself (for example,
which flag to change when the requested metric is missing, or the dependency
order to sync first when a foreign key cannot be resolved); a generic hint
for the failure class (401/403 key check — or, when the 403 names a required
scope, minting a key with `--scope`, and when it names a role permission
such as `access_to_update_section`, granting a role with `p202 user role
assign`; 404 use `list` for ids; 429 back off;
5xx retry then `p202 system health`; network check the URL and `p202 config
test`); and for any remaining validation error, a pointer to `<command>
--help`.

A flag given an **empty value** (`--click_id ""`, or `--source "$SOURCE"`
with the variable unset) is refused before the command runs:
`Error [validation]: --click_id was given an empty value`, exit 1, with a
hint to omit the flag or give it a value. Read as "not given", an empty
filter would list everything as though filtered, and an empty update field
would silently leave the field as it was. The few flags whose empty value
is a deliberate write — clearing an app's `--notes` on `p202 app update`,
and the fields of `p202 app encoding update` — pass it through; the root
flags `--fields`, `--profile` and `--group` keep their "empty is the
default" meaning. Every other string flag of every command follows the
rule, and a test walks the whole command tree to keep it so.

`--staged` holds across the nested runners too: the interactive shell resets
the whole flag tree between commands, so it saves and restores the session's
staged mode (otherwise `p202 shell --staged` would execute the writes it
promised to propose), and `p202 exec` passes the flag to each profile's child
process, which inherits nothing from its parent.

`p202 sync` and `p202 re-sync` refuse `--staged` outright: a sync resolves
each entity's foreign keys from ids the preceding creates returned, and a
staged create returns a proposal rather than a record, so the run would count
proposals as synced and then fail to resolve their dependents. Use `--dry-run`
to see what a sync would change, or stage individual writes.

Commands that create something and then use it — `tracker create-with-url`,
`import` — recognise the staged-change envelope a write returns under
`--staged` and stop there rather than running their follow-up step against a
record that is still a proposal: `create-with-url` reports the change id and
where to get the URL after `p202 change apply`, and `import` counts the
records as `staged` with their `change_ids`, never as `imported`.

A 409 is resolved by cause rather than by status alone, because its causes
need opposite responses: a still-running idempotent retry says to wait and
resend the same command; a spent `Idempotency-Key` (its request died without
recording a response) says to check whether the record exists and use a new
key only if it does not; an `apply_interrupted` staged change says to check
whether the write landed and stage it again if not; a staged change in the
wrong state points at `p202 change show`; and only an actual duplicate gets
"update it instead of creating".

### Exit Codes

| Code | Meaning |
| ---- | ------- |
| 0 | Success |
| 1 | Validation error (bad input, missing flags) |
| 2 | Authentication/authorization failure |
| 3 | Network error (connection timeout, DNS failure) |
| 4 | Server error (5xx response) |
| 5 | Partial failure (some items in bulk operation failed, or a check such as `system health` or `rotator check` found a problem; its rows stay on stdout) |

## Telemetry

Enable structured JSON telemetry on stderr:

```bash
P202_METRICS=1 p202 campaign list
```

Emits timing, success/failure, and operation metadata for monitoring.
