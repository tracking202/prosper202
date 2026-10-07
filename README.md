# Prosper202 ClickServer

Self-hosted campaign tracking and marketing analytics platform. Track clicks, conversions, and revenue across any traffic source with full data ownership — your data stays on your servers.

Since 2007, Prosper202 has helped marketers take control of their tracking with an open, self-hosted platform that works with any traffic source, any offer, and any network.

## Key Features

- **Self-Hosted & Full Source Code** — Run Prosper202 100% on your own servers for ultimate control of your proprietary data and marketing methods. Customize the full source code to meet your needs.
- **Click & Conversion Tracking** — Real-time click capture with sub-ID parameters, referrer tracking, and automatic IP/UA logging. Server-to-server postback and pixel tracking with revenue, payout, and status fields. Every conversion is a ledger row that records what produced it, so any click's value can be explained row by row, and a campaign chooses whether repeat conversions keep the latest payout or add up.
- **Goals & Web Events** — Define what counts as a conversion — an event with conditions, the Nth purchase, a running total, a step after another goal, within a time window — and what each is worth. Goals are versioned, shared by web campaigns and apps, and paid per campaign; events arrive as `event=` on your postbacks and pixels, through `POST /api/v3/events`, or from `p202.track()` on a landing page ([goals](documentation/api/22-goals.md), [events](documentation/api/23-events.md)).
- **12+ Report Types** — Keywords, geo, device, browser, OS, referrer, ISP, landing page, and custom dimension reports. Track profit and loss, conversion metrics, EPC per keyword, per text ad, per referrer, and more.
- **Multi-Touch Attribution** — Each conversion's value spread over the visitor's own clicks across campaigns (joined by first-party identity signals, never IP), under last-touch, first-touch, linear, time-decay and position-based models, with reports by campaign, source, keyword, landing page, country, device or day ([guide](documentation/tutorials-and-guides/14-advanced-attribution-engine.md)).
- **Mobile App Measurement (iOS and Android)** — On iOS, act as your app's SKAdNetwork and AdAttributionKit endpoint: receive Apple's signed install postbacks, verify their signatures, decode conversion values into goals and revenue, and report installs by ad network, source identifier, and country. The bundled P202Attribution Swift SDK evaluates your goals on the device against a mapping it fetches from your server at runtime, so changing it never requires an App Store resubmission ([guide](documentation/api/19-app-measurement.md)). On Android, the Prosper202 Android SDK reads the Google Play install referrer and turns each install, and the events after it, into a conversion on the click that sent the user to the store, reported by campaign and goal, with optional Play Integrity checks and click-injection and click-spamming signals ([installs](documentation/api/24-android-installs.md), [SDK](documentation/api/25-android-sdk.md)).
- **Customer LTV** — Customer records with identity resolution, a unified revenue ledger, products, subscriptions and companies; realized and predictive lifetime value, MRR and churn; next-offer recommendations; and LTV fields as dynamic tokens on your landing pages.
- **Split Testing** — Run unlimited weighted split tests to discover your best marketing message and offer. Pause non-converting tests and automatically send all traffic to the winner.
- **Smart Redirector & Traffic Rules** — Rule-based traffic distribution with weighted rotation, geo-targeting, and device filtering.
- **BlazerCache Technology** — Fast redirects that continue working even if the database goes down, preventing lost revenue.
- **Fraud Prevention** — Sentinel Traffic Quality Enforcer (T.Q.E.) redirects potentially fraudulent traffic away from your landing pages.
- **Landing Page Personalization** — Dynamically display ISP, device, postal code, geo location, keyword, UTM variables, browser, OS, and more on your landing pages.
- **Device Detection** — Automatically detect device types and models for full insights into mobile-targeted campaigns.
- **Multi-Currency & Timezone** — Automatically convert payouts into your local currency and display reports in your local timezone.
- **Google Ads Integration** — Offline conversion tracking with one-click CSV export. UTM parameters and GCLID values are automatically captured.
- **WordPress Integration** — Two-way communication between WordPress and Prosper202, instantly setting up posts and pages as landing pages.
- **Deep Linking** — Boost conversion rates by deep linking directly into apps, reducing friction for users.
- **Team Access** — Full role-based authentication with no limit on users and no per-seat costs.
- **API & CLI Tools** — Full REST API and CLI tools designed for both human developers and AI agents. Automate campaign management, pull reports, and integrate with your existing tools. CLI-first design works seamlessly with AI coding agents like Claude Code, Codex, and OpenClaw, and agents can be given least-privilege API keys and made to propose writes for a person to approve instead of performing them ([building agents](documentation/tutorials-and-guides/17-building-agents-on-prosper202.md)).
- **Forecasting** — `p202 forecast` projects any metric forward with calibrated bands, coherent multi-metric output, seasonality, and anomaly handling ([guide](documentation/cli/11-forecasting.md))

## Requirements

- PHP 8.3+
- MySQL 8.0+ or MariaDB 10.6+ (the installer and the upgrade page refuse older versions)
- Web server: **Nginx with PHP-FPM (recommended for manual installs)** or **Apache** (as used in the official Docker image)
- Composer (installs from source only; the release zip bundles `vendor/`)
- Go 1.22+ (optional, to build the Go CLI from source; the release zip bundles its binaries)

## Installation

There are two tracks. Pick by what you have access to:

| You have… | Use | What you do |
|-----------|-----|-------------|
| Shared/cPanel hosting, **no terminal** | **Download & upload** (below) | Upload a pre-built zip, click through the wizard |
| A terminal (VPS, local, Docker) | **From source** (below) | `git clone` + `./install.sh` or Docker |
| A VPS running [Coolify](https://coolify.io) | **Coolify** (below) | Point Coolify at this repo, click Deploy |

### Download & Upload (no terminal)

For shared hosting (cPanel/Plesk) where you can't run Composer. Use the **release
zip**, not a `git clone` — the release already bundles all PHP dependencies
(`vendor/`) and the Go CLI binaries, so nothing needs to be compiled on the server.

1. Download `prosper202-<version>.zip` from the
   [Releases page](https://github.com/tracking202/prosper202/releases).
2. Upload and extract it into your web root (cPanel **File Manager → Upload →
   Extract**, or FTP).
3. Browse to your site. The setup wizard runs automatically and walks you through
   every step — it checks requirements, **creates the database for you**, validates
   your API key, and creates your admin account. No config file editing, no terminal.

> Maintainers build this zip with `build/scripts/package-release.sh` (see
> [Building a release](#building-a-release)).

> **Coming soon:** a one-click install via Softaculous/Installatron, so you can
> install Prosper202 straight from your hosting control panel with no upload step.
> It is packaged from the same release zip; until it lands in those catalogs, use
> the download-and-upload steps above.

### From source

#### Quick Install

```bash
git clone https://github.com/tracking202/prosper202.git
cd prosper202
./install.sh
```

The install script will:
- Check for PHP and Composer (installs Composer if missing)
- Install PHP dependencies
- Create config file from sample
- Test/create the database and print the cron line to add

#### Docker

```bash
git clone https://github.com/tracking202/prosper202.git
cd prosper202
echo "MYSQL_ROOT_PASSWORD=$(openssl rand -hex 16)" > .env
docker compose up -d
```

Dependencies are automatically installed on container startup. Alternatively, run `./install.sh` and pick the Docker option — it generates the `.env` for you.

- Application: `http://localhost:8000`
- phpMyAdmin (optional): `docker compose --profile debug up -d`, then `http://127.0.0.1:8080` (user `root`, password from your `.env`)

#### Coolify (self-hosted PaaS)

Running [Coolify](https://coolify.io) on a VPS? The repo ships a production
stack for it — `docker-compose.coolify.yaml` — with TLS, the database password,
`202-config.php`, and cron all handled automatically:

1. [Install Coolify](https://coolify.io/docs/get-started/installation) on your server.
2. Add this repository as a **Docker Compose** resource with the compose
   location set to `/docker-compose.coolify.yaml`.
3. Set your domain on the `web` service and click **Deploy**, then open the
   domain and finish the setup wizard.

Full walkthrough: [documentation/deploying-on-coolify.md](documentation/deploying-on-coolify.md).

#### Security defaults

Because anyone can deploy this compose file as-is, it ships with no known credentials or unnecessarily exposed services:

- There is no default database password. `docker compose up` refuses to start until you set `MYSQL_ROOT_PASSWORD` (the value is baked into the database volume on first start; changing `.env` later won't change the actual MySQL password).
- MySQL and memcached are not published on any host port — they are reachable only from the other containers.
- phpMyAdmin does not start by default. It requires the `debug` profile and binds to `127.0.0.1` only, so it is never reachable from another machine.
- The web container denies all dotfile requests (keeping `/.well-known/` for ACME), so `.env` and `.git` in the bind-mounted document root are not servable over port 8000.
- The application itself is published on port 8000 with **no account until you finish the install wizard** — anyone who can reach the port before you do can claim the install. Complete the wizard immediately after `docker compose up`, especially on a machine with a public address.

This stack is a development configuration (PHP `display_errors` is on). For production click servers, use the Manual Installation below with the tuned Nginx or Apache configs.

#### Manual Installation

1. Clone and install dependencies:
   ```bash
   git clone https://github.com/tracking202/prosper202.git
   cd prosper202
   composer install --no-dev
   ```

2. Configure the application:
   ```bash
   cp 202-config-sample.php 202-config.php
   # Edit 202-config.php with your database credentials
   ```

3. Configure nginx to point to the project root. Example site configuration, tuned for click traffic (many small concurrent requests):
   ```nginx
   # Reuse FastCGI connections to PHP-FPM instead of opening one per request
   upstream php_fpm {
       server unix:/path/to/php-fpm.sock; # or server 127.0.0.1:9000; adjust to your PHP-FPM setup
       keepalive 16;
   }

   server {
       listen 80;
       server_name your-domain.com;
       root /path/to/prosper202;
       index index.php index.html;

       # Buffer access-log writes; under click bursts an unbuffered log
       # costs one write() per hit
       access_log /var/log/nginx/prosper202.access.log combined buffer=64k flush=5s;

       # Cache the stat() results behind the try_files lookups
       open_file_cache max=10000 inactive=30s;
       open_file_cache_valid 60s;

       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }

       location /api/v3/ {
           try_files $uri $uri/ /api/v3/index.php?$query_string;
       }

       location ~ \.php$ {
           fastcgi_pass php_fpm;
           fastcgi_keep_conn on;
           fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
           include fastcgi_params;
       }

       # Deny dotfiles (.git, .env, ...) but keep /.well-known/ reachable
       # for ACME (Let's Encrypt) HTTP-01 validation
       location ~ /\.(?!well-known/) {
           deny all;
       }

       # Attribution export CSVs and upgrade zips; served only through the
       # authenticated download endpoints (^~ wins over the .php regex)
       location ^~ /202-config/temp/ {
           deny all;
       }
   }
   ```

   In `nginx.conf`, make sure `worker_processes auto;` is set and raise `worker_connections` (e.g. `4096`) if you expect sustained click volume. Concurrency is ultimately capped by PHP-FPM, so size `pm.max_children` in your FPM pool to match.

4. Reload nginx:
   ```bash
   sudo nginx -t && sudo systemctl reload nginx
   ```

5. Access the application in your browser.

#### Apache Alternative

If you prefer Apache over Nginx, point your document root at the project directory and ensure `mod_rewrite` is enabled so the `.htaccess` files shipped in `api/v3/` and `tracking202/update/reports/` can handle routing. Those files only need `AllowOverride FileInfo Options=FollowSymLinks` (`FileInfo` for the rewrite rules, `Options=FollowSymLinks` for the `Options +FollowSymLinks` line in the reports rules) — avoid `AllowOverride All`, which is broader than required. Example virtual host:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /path/to/prosper202

    <Directory /path/to/prosper202>
        Options -Indexes +FollowSymLinks
        AllowOverride FileInfo Options=FollowSymLinks
        Require all granted
    </Directory>

    # Attribution export CSVs and upgrade zips, served only through the
    # authenticated download endpoints. The directory's own .htaccess needs
    # AllowOverride AuthConfig, so the deny lives here instead.
    <Directory /path/to/prosper202/202-config/temp>
        AllowOverride None
        Require all denied
    </Directory>

    # Deny dotfiles (.git, .env, ...) but keep /.well-known/ reachable for
    # ACME (Let's Encrypt) HTTP-01 validation, matching the Nginx example above
    <LocationMatch "/\.(?!well-known/)">
        Require all denied
    </LocationMatch>
</VirtualHost>
```

The example assumes PHP is already hooked up — either mod_php (`sudo a2enmod php8.3`) or PHP-FPM (`sudo a2enmod proxy_fcgi setenvif && sudo a2enconf php8.3-fpm`). Enable the rewrite module and restart:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

##### Apache tuned for click traffic

The simple vhost above prioritizes working out of the box with the repo's `.htaccess` files. For a production click server, two things in it cost real throughput:

- Any `AllowOverride` other than `None` makes Apache stat and re-parse `.htaccess` files in every directory along the request path, on every request.
- mod_php forces the prefork MPM — one heavyweight process per connection. The event MPM with PHP-FPM handles click-burst concurrency far better (the same model as the Nginx setup).

This variant sets `AllowOverride None` and inlines the shipped `.htaccess` rules into the vhost, so keep it in sync if those files change:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /path/to/prosper202

    <Directory /path/to/prosper202>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>

    # Inlined from api/v3/.htaccess
    <Directory /path/to/prosper202/api/v3>
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ index.php [QSA,L]
    </Directory>

    # Inlined from tracking202/update/reports/.htaccess
    <Directory /path/to/prosper202/tracking202/update/reports>
        RewriteEngine On
        RewriteRule .* /202-404.php [L]
    </Directory>

    # PHP via FPM so the event MPM stays in place
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Attribution export CSVs and upgrade zips: with AllowOverride None the
    # directory's .htaccess is never read, so this is the only deny
    <Directory /path/to/prosper202/202-config/temp>
        Require all denied
    </Directory>

    # Deny dotfiles (.git, .env, ...) but keep /.well-known/ for ACME
    <LocationMatch "/\.(?!well-known/)">
        Require all denied
    </LocationMatch>

    # Keep connections open across a visitor's click/redirect chain,
    # but release them quickly so workers aren't pinned by idle clients
    KeepAlive On
    MaxKeepAliveRequests 1000
    KeepAliveTimeout 2
</VirtualHost>
```

Switch the MPM and PHP handler to match:

```bash
sudo a2dismod php8.3 mpm_prefork   # skip php8.3 if mod_php was never enabled
sudo a2enmod mpm_event proxy_fcgi setenvif rewrite
sudo a2enconf php8.3-fpm
sudo systemctl restart apache2
```

As with Nginx, throughput is ultimately capped by PHP-FPM, so size `pm.max_children` in your FPM pool to your traffic; raise `MaxRequestWorkers` in `mpm_event.conf` alongside it.

### Upgrading

**Back up the database before you upgrade.** An upgrade changes the database in
place and there is no downgrade: restoring a backup taken before the upgrade is
the only way back. From 1.9.76, putting the old files back on an upgraded
database leaves an install that can no longer record conversions. Take a full
`mysqldump` (or your host's snapshot) of the Prosper202 database after the site
stops taking traffic, then upgrade — through `202-config/upgrade.php`, or the
**1-Click Upgrade** button in the new-version notice under the header. Both
pages say the same above their button. From 1.9.55, the 1-click page replaces
the files and then sends you to the upgrade page for the database step, which
checks the database server's version before it changes anything.

On a large install the upgrade page can outlast a proxy's time limit (a million
conversions takes one to two minutes). The upgrade keeps running on the server:
wait a few minutes and reload the page, which sends you to sign in once it has
finished. What changed in each release is in [`changelogs.txt`](changelogs.txt);
the step-by-step guide is
[Upgrading Prosper202](documentation/tutorials-and-guides/05-upgrading-prosper202.md).

## API v3

REST API under `/api/v3/` with bearer token authentication. Covers campaigns, affiliate and PPC networks, PPC accounts, trackers, landing pages, text ads, clicks, conversions (with each click's conversions explained row by row), reports, rotators, goals, web events, multi-touch attribution (models, reports, journeys and exports), app measurement (the iOS and Android app registry, SKAdNetwork and AdAttributionKit postbacks, SKAN encodings, Android installs and Play Integrity), customer LTV, forecast events, and users, plus server-side sync, capability discovery, and system operations.

Writes are built to be safe to automate: API keys can be scoped to read-only, one area, or propose-only; any write can be staged with `?staged=1` as a proposal someone approves later (`/api/v3/staged-changes`, or `p202 change`); deletes preview their cascade with `?dry_run=1`; and most creates honor an `Idempotency-Key`, so a retry does not make a duplicate. The full surface is described in [`docs/openapi.yaml`](docs/openapi.yaml) and the [API guides](documentation/api/).

```bash
curl -H "Authorization: Bearer <api-key>" https://your-server/api/v3/campaigns
```

## CLI Tools

Two command-line tools answer to `p202`. The **Go CLI** (`go-cli/`, below)
is the primary one: it has the full command set, profiles, staged writes,
dry-run deletes and agent-readable errors. The **legacy PHP CLI** (`bin/p202`)
covers a subset with `noun:verb` command names, and says so: its
`--version` line and `list` name it and point at the Go CLI. Both read
`~/.p202/config.json`, and the PHP CLI uses the Go CLI's active profile, so
a server set up with either is the one both use. Call each by its path
(`./go-cli/p202`, `./bin/p202`) when both are on your `PATH`.

### Legacy PHP CLI (`bin/p202`)

Symfony Console CLI for managing remote Prosper202 installations; a subset of
the Go CLI's commands. It stays at `bin/p202` for the scripts that call it.

```bash
# Configure
bin/p202 config:set-url https://your-server
bin/p202 config:set-key <api-key>

# Use
bin/p202 campaign:list
bin/p202 tracker:get 42
bin/p202 rotator:create --name "My Rotator"
```

### Go CLI (`go-cli/`)

A single static binary for humans and AI agents. Every command has a table
view for people and `--json` / `--csv` / `--ndjson` for scripts. Full
reference: [Go CLI (p202)](documentation/cli/10-go-cli.md).

**1. Set up once**

```bash
cd go-cli && make build
./p202 config set-url https://your-server
./p202 config set-key <api-key>
./p202 config test            # verifies URL + key against the instance
```

**2. Use it**

```bash
./p202 campaign list --json                 # any entity: list / get / create / update / delete
./p202 report summary --period last7
./p202 app report --group-by ad-network            # iOS SKAdNetwork / AdAttributionKit installs with decoded revenue
./p202 forecast --metric revenue --horizon 7
./p202 sync all --from prod --to staging    # replicate between instances
```

Housekeeping that used to take a script:

```bash
./p202 campaign list --with-stats --period last30   # every campaign with its clicks, leads, income, cost and net
./p202 campaign check-urls                          # offer URLs whose host is dead, found without sending a click
./p202 campaign replace-url --match old-network.example --with new-network.example --dry-run
                                                    # bulk-rewrite offer URLs; a real run saves an undo manifest
./p202 conversion import network-export.csv --dry-run
                                                    # record a network's conversion export against its clicks, re-runnable
./p202 analytics --group-by country --split-at 2026-09-04
                                                    # what changed before vs. after a date, per country
./p202 system health                                # API reachability and the TLS certificate's expiry
```

**3. Built for agents**

An agent should be able to find the right command, read its output, and
recover from a failure without a person in the loop. So:

- **JSON without asking.** When an AI agent runs p202 — Claude Code, Codex,
  Gemini CLI and Cursor's CLI agent set a marker such as `AI_AGENT` or
  `CLAUDECODE` in the environment — output is compact JSON with no `--json`
  needed. People at a terminal and existing scripts keep tables; `--table`,
  `P202_OUTPUT` or `p202 config set-default output.format` override it, and
  `p202 config show` says which format is in use and why.
- **Find a command by describing the task.** `p202 search find dead offer
  links` ranks commands, flags and flag values by your words, offline, and
  says when nothing matches well; `p202 commands --json` lists every command
  and flag, with its allowed values, in one call.
- **Valid values are always spelled out.** Every flag that takes a fixed set
  lists it in its help, and a wrong value is refused before any request with
  the full list: `--period must be one of: today, yesterday, last7, last14,
  last30, last90, thismonth, lastmonth, thisyear, lastyear, alltime; got
  "last31"`.
- **Writes can wait for a person.** `--staged` records any write as a
  proposal that someone reviews and runs with `p202 change apply`, `--dry-run`
  previews deletes and bulk edits, and API keys can be scoped down to
  read-only or propose-only. The agent reference is
  [`docs/cli-agent.md`](docs/cli-agent.md).

Errors are written for an agent to act on:

- **Exit codes mean something:** `1` bad input, `2` auth, `3` network,
  `4` server error, `5` partial failure, and they hold through error
  wrapping.
- **Every error carries a recovery hint.** In human mode:

  ```text
  Error [auth]: fetching historical data: API error (401): invalid api key
  Hint: Verify your API key: run `p202 config get`, then `p202 config set-key <key>` if it's wrong.
  ```

- **Under `--json` the error is a structured envelope on stderr** (stdout
  stays empty), so nothing has to be parsed from prose:

  ```json
  {"error":{"category":"auth","message":"fetching historical data: API error (401): invalid api key","hint":"Verify your API key: run `p202 config get`, then `p202 config set-key <key>` if it's wrong.","exit_code":2,"command":"p202 forecast","http_status":401}}
  ```

  Details and the hint sources: [Errors](documentation/cli/10-go-cli.md#errors).

**4. Forecasting**

`p202 forecast` projects any metric forward from its history, locally, with
no extra dependencies. Ask for one metric or all core metrics at once:

```bash
./p202 forecast --metric clicks --horizon 3 --json
```

```json
{
  "data": [
    { "date": "2026-07-31", "total_clicks": 497.94, "lower_bound": 329.14, "upper_bound": 635.98, "p50": 557.68 }
  ],
  "meta": {
    "method": "ensemble", "weights": { "linear": 0.305, "sma": 0.37, "wma": 0.325 },
    "bounds": "p05-p95 (90%)", "anomalies_masked": ["2026-07-25", "2026-07-26"], "rmse": 95.67
  }
}
```

What the output is telling you: an ensemble of three methods produced the
point forecast; the band is the empirical 90% range from rolling backtests
(not a normal-distribution guess); two days were recognised as a tracking
outage and excluded from fitting; and the model's typical error on this
series has been about 96 clicks. Beyond that, the engine keeps
`leads = clicks × conv_rate` and `net = income − cost` exact across
`--all-metrics`, applies weekday/hourly profiles only when the data really
repeats, and fits the new level after a detected shift. Each of these is
walked through with real output in the
[Forecasting Guide](documentation/cli/11-forecasting.md).

## Development Setup

```bash
composer install
```

Working on the Docker stack, CI, or release tooling itself? See
**[`build/README.md`](build/README.md)** — a standalone contributor reference for
the image, the three compose stacks (dev / staging / test-install), the entrypoint
and config-writing scripts, and how a container boots.

### Running Tests

```bash
# PHP tests
composer test

# Go CLI tests
cd go-cli && make test
```

### Linting

```bash
./scripts/php-lint.sh
```

### Building a release

Produce the self-contained zip used by the [Download & Upload](#download--upload-no-terminal)
track. It bundles `vendor/` (Composer `--no-dev`, installed from the committed
`composer.lock`) and cross-built Go CLI binaries so end users need no Composer or Go
toolchain, and it leaves out the test suites and developer tooling as listed in
[`build/release-manifest.php`](build/release-manifest.php) (two fixtures the
bundled CLI and SDKs point at ship with them):

```bash
build/scripts/package-release.sh
# -> dist/prosper202-<version>.zip  (+ printed SHA256)
```

The version comes from `202-config/version.php`, the single source of truth, and is
passed through to the Go build so the CLI's `--version` matches the zip name.

Tagged releases (`v*`) are built and published automatically by the
[`Release` workflow](.github/workflows/release.yml). For the full maintainer
process — versioning, tagging, CI, local builds, and troubleshooting — see
**[RELEASING.md](RELEASING.md)**.

## Configuration

- **Main config**: `202-config.php` (created from `202-config-sample.php`)
- **Database**: MySQL/MariaDB with optional read replica support
- **Caching**: Memcached integration available

## Directory Structure

- `202-config/` - Core configuration, database classes, utilities
- `202-account/` - User management and administration
- `api/` - REST API (v1, v2, and v3)
- `cli/` - PHP CLI commands and client
- `go-cli/` - Go CLI client
- `bin/` - Entry scripts (`p202`)
- `tracking202/` - Main tracking application (redirects, setup, reporting)
- `202-cronjobs/` - Background job processing
- `sdk/` - Mobile app SDKs: `ios-attribution/` (Swift) and `android-attribution/` (Kotlin)
- `documentation/` - User, API, and CLI guides
- `docs/` - OpenAPI spec (`openapi.yaml`) and CLI references (`cli.md`, `cli-agent.md`)
- `build/` - Docker, CI, and release tooling (see [`build/README.md`](build/README.md) for the contributor reference)
- `tests/` - PHPUnit test suite, plus live, browser, and agent-eval passes

## License

Business Source License 1.1 (BUSL-1.1) — see [LICENSE](LICENSE) for the full text.

- **Licensor:** Blue Terra LLC
- **Licensed Work:** Prosper202
- **Additional Use Grant:** You may use the Licensed Work for any purpose, including production use, except you may not offer it as a hosted or managed service to third parties.
- **Change Date:** 2031-02-22
- **Change License:** GPL-2.0-or-later
