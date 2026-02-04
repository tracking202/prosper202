# Prosper202 ClickServer

Self-hosted affiliate marketing tracking platform for PPC affiliate marketers. Provides click tracking, conversion monitoring, landing page management, traffic analysis, and campaign performance reporting with ROI tracking.

## Requirements

- PHP 8.3+
- MySQL 8.0+
- Apache with mod_rewrite
- Composer

## Installation

### Quick Install (Any Environment)

```bash
git clone https://github.com/user/prosper202.git
cd prosper202
./install.sh
```

The install script will:
- Check for PHP and Composer (installs Composer if missing)
- Install PHP dependencies
- Create config file from sample

### Docker

```bash
git clone https://github.com/user/prosper202.git
cd prosper202
docker compose up -d
```

Dependencies are automatically installed on container startup.

- Application: `http://localhost:8000`
- phpMyAdmin: `http://localhost:8080`

### Manual Installation

1. Clone and install dependencies:
   ```bash
   git clone https://github.com/user/prosper202.git
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

## Development Setup

For development, install all dependencies including dev tools:

```bash
composer install
```

### Running Tests

```bash
composer test
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
- `tracking202/` - Main tracking application
- `202-account/` - User management and administration
- `api/` - REST API endpoints (v1 & v2)

## License

GPL-2.0-or-later
