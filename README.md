# Prosper202 ClickServer

Self-hosted affiliate marketing tracking platform for PPC affiliate marketers. Provides click tracking, conversion monitoring, landing page management, traffic analysis, and campaign performance reporting with ROI tracking.

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

GPL-2.0-or-later
