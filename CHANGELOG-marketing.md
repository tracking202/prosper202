# What's New in Prosper202

Everything that shipped after v1.9.56 — the biggest update in Prosper202 history.

---

## Multi-Touch Attribution

Stop guessing which campaigns actually drive revenue. Prosper202 now tracks every touchpoint in the customer journey and lets you see exactly how your traffic sources work together to produce conversions.

- **Four attribution models** — Last Touch, Time Decay, Position-Based, and Assisted — so you can choose the lens that matches your business.
- **Model sandbox** — Compare models side-by-side before committing. See how revenue credit shifts when you switch from last-touch to time-decay, without changing your live data.
- **Attribution dashboard** — A dedicated view with KPI cards for Revenue, Conversions, Clicks, and ROI, filterable by campaign or landing page.
- **Snapshot exports** — Export attribution data as CSV or Excel on demand, with optional webhook callbacks so your downstream systems stay in sync.
- **Anomaly detection** — Automatically flags unusual attribution patterns so you catch issues before they cost you money.
- **Background rebuilds and backfills** — Regenerate conversion journeys and attribution snapshots over any historical range without blocking your tracking.
- **Automatic journey purging** — A cron job cleans up disabled attribution journeys so your database stays lean.

---

## Full Remote Management: REST API v3 & CLI

Manage your entire Prosper202 installation from the command line or any HTTP client — no browser required.

### REST API v3
A modern, versioned API with 16+ endpoints covering campaigns, clicks, conversions, reports, attribution, users, and system health. Includes CORS support, security headers, machine-readable error codes, and explicit version negotiation via `X-P202-API-Version`.

### `p202` CLI
A purpose-built command-line tool with 20+ commands:

- **`p202 report:summary`** — Aggregate performance totals at a glance.
- **`p202 report:breakdown`** — Slice stats by campaign, country, landing page, browser, device, ISP, and 14+ other dimensions.
- **`p202 report:timeseries`** — Hourly or daily performance trends.
- **`p202 report:daypart` / `report:weekpart`** — Find your highest-converting hours and days.
- **`p202 attribution:model`** — Full CRUD for attribution models.
- **`p202 analytics`** — Friendly shorthand for breakdowns with `--group-by` dimensions and `--sort` metrics.
- **`p202 export` / `p202 import`** — Full JSON backup and restore of campaigns, rules, and settings across installations, with dry-run mode and error recovery.
- **`p202 sync:*`** — Multi-server synchronization with diff previews, selective syncs, re-sync of failed items, and full audit history.

### CLI power features

- **Named profiles with tags** — Manage multiple installations (e.g., `env:prod`, `env:staging`) from one machine, with automatic migration from legacy configs.
- **Reusable defaults** — Save frequently-used flags (date ranges, filters, entity IDs) per profile to eliminate repetitive typing.
- **Structured telemetry** — Set `P202_METRICS=1` to emit JSON performance metrics per operation for observability pipelines.
- **Flexible output** — JSON, CSV, or human-readable tables on any command.

---

## Real-Time Spy Mode, Reimagined

The click spy view is now incremental. Instead of reloading your entire click history every few seconds, Prosper202 streams only new clicks to the top of the table.

- **Instant updates** — New clicks appear at the top without disrupting your view.
- **Read-only replica support** — Spy queries run against a read-only database connection, keeping your primary DB free for tracking.
- **Smart client-side management** — Caps the display at 200 rows and cancels in-flight requests to prevent pile-up during high-traffic bursts.

The result: monitor your campaigns in real time without taxing your server or your browser.

---

## Automation & Background Jobs

A new generation of cron jobs keeps Prosper202 running smoothly without manual intervention.

- **Attribution export worker** — Pending export jobs process automatically in the background, with webhook delivery and retry logic.
- **Data engine job processor** — Long-running analytics and reporting computations run as tracked background jobs.
- **Daily summary email** — Opt-in performance digest delivered to your inbox every morning.
- **Affiliate network sync (DNI)** — Scheduled background sync with third-party affiliate networks.
- **Cron health endpoint** — An authenticated `/202-cronjobs/health.php` endpoint lets you monitor job execution from your own uptime tools.

---

## Centralized Dashboard

Your Prosper202 homepage now pulls in curated content — alerts, community posts, upcoming meetups, and sponsor highlights — from the Tracking202 network.

- **Local caching** — Content syncs in the background and serves from your local database, so page loads stay fast.
- **Automatic synchronization** — A cron job keeps your dashboard fresh without manual intervention.
- **Resilient fetching** — Exponential-backoff retries and graceful fallbacks mean your dashboard never breaks when the upstream API is slow.

---

## Data Portability & Multi-Server Sync

Move configurations between staging, production, and backup installations with confidence.

- **Full JSON export/import** — Back up or clone an installation's campaigns, rules, and settings with one command.
- **Dry-run previews** — See exactly what will change before committing any sync.
- **Diff across servers** — Compare configurations between instances side-by-side.
- **Async job queue** — Long-running sync operations run server-side with full audit logging and retry for failed items.
- **Webhook callbacks** — External systems get notified when exports and syncs complete.

---

## Security Hardened

Multiple layers of security improvements protect your data and your installation.

- **Modern password hashing** — Passwords are now hashed with bcrypt via PHP's `password_hash()`. Legacy MD5 hashes are transparently upgraded on next login — no password resets required.
- **Installer lockdown** — The installer is disabled after setup to prevent privilege escalation on shared hosting.
- **Secure auto-update pipeline** — Package downloads and extractions are validated to prevent tampering.
- **API input hardening** — Attribution endpoints block `user_id` override attempts from JSON payloads.
- **Static analysis guardrails** — Custom PHPStan rules catch unchecked database calls, insecure password handling, and silent JSON failures before they ship.

---

## PHP 8.3 Compatibility

The entire codebase has been modernized for PHP 8.0–8.3 with Rector, including strict type safety, modern syntax, and elimination of deprecation warnings. If you've been waiting to upgrade your PHP version, Prosper202 is ready.

---

## Under the Hood

- **MySQL repository layer** — Database access is now organized through a clean repository pattern with type-safe prepared statements.
- **Centralized version management** — One source of truth for version info across the app.
- **Intercom removed** — No more third-party tracking scripts on your installation.
- **Comprehensive PHPUnit test suite** — Unit and integration tests for attribution, API, and core tracking components.
- **Continuous integration** — GitHub Actions workflows run tests and static analysis on every change.
- **Modular installer** — The setup flow has been rewritten for zero-friction onboarding.
- **Nginx + Apache support** — Deploy on whichever web server you prefer.
- **Business Source License 1.1** — Updated from GPL-2.0 to BSL 1.1.
