# Prosper202 ClickServer

Self-hosted campaign tracking and marketing analytics platform. Track clicks, conversions, and revenue across any traffic source with full data ownership — your data stays on your servers.

Since 2007, Prosper202 has helped marketers take control of their tracking with an open, self-hosted platform that works with any traffic source, any offer, and any network.

## Key Features

- **Self-Hosted & Full Source Code** — Run Prosper202 100% on your own servers for ultimate control of your proprietary data and marketing methods. Customize the full source code to meet your needs.
- **Click & Conversion Tracking** — Real-time click capture with sub-ID parameters, referrer tracking, and automatic IP/UA logging. Server-to-server postback and pixel tracking with revenue, payout, and status fields.
- **12+ Report Types** — Keywords, geo, device, browser, OS, referrer, ISP, landing page, and custom dimension reports. Track profit and loss, conversion metrics, EPC per keyword, per text ad, per referrer, and more.
- **Multi-Touch Attribution** — Five attribution models including last-touch, time-decay, position-based, and algorithmic.
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
- **API & CLI Tools** — Full REST API and CLI tools designed for both human developers and AI agents. Automate campaign management, pull reports, and integrate with your existing tools. CLI-first design works seamlessly with AI coding agents like Claude Code, Codex, and OpenClaw.

## Requirements

- PHP 8.3+
- MySQL 8.0+
- Apache with mod_rewrite
- Composer
- Go 1.22+ (optional, for the Go CLI)

## Installation

### Quick Install

```bash
git clone https://github.com/tracking202/prosper202.git
cd prosper202
./install.sh
```

The install script will:
- Check for PHP and Composer (installs Composer if missing)
- Install PHP dependencies
- Create config file from sample

### Docker

```bash
git clone https://github.com/tracking202/prosper202.git
cd prosper202
docker compose up -d
```

Dependencies are automatically installed on container startup.

- Application: `http://localhost:8000`
- phpMyAdmin: `http://localhost:8080`

### Manual Installation

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

3. Configure your web server to point to the project root.

4. Access the application in your browser.

## API v3

REST API under `/api/v3/` with bearer token authentication. Covers all Prosper202 entities: campaigns, networks, traffic sources, trackers, landing pages, text ads, clicks, conversions, rotators, attribution models, users, and system operations.

```bash
curl -H "Authorization: Bearer <api-key>" https://your-server/api/v3/campaigns
```

## CLI Tools

### PHP CLI (`bin/p202`)

Symfony Console CLI for managing remote Prosper202 installations.

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

Cross-platform Go CLI with `--json` output for scripting and agent consumption.

```bash
cd go-cli
make build

./p202 config set-url https://your-server
./p202 config set-key <api-key>
./p202 campaign list --json
./p202 sync all
```

## Development Setup

```bash
composer install
```

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
- `build/` - Docker and build configuration
- `tests/` - PHPUnit test suite

## License

Business Source License 1.1 (BUSL-1.1) — see [LICENSE](LICENSE) for the full text.

- **Licensor:** Blue Terra LLC
- **Licensed Work:** Prosper202
- **Additional Use Grant:** You may use the Licensed Work for any purpose, including production use, except you may not offer it as a hosted or managed service to third parties.
- **Change Date:** 2031-02-22
- **Change License:** GPL-2.0-or-later
