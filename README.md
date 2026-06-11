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
- Web server: **Nginx with PHP-FPM (recommended for manual installs)** or **Apache** (as used in the official Docker image)
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
echo "MYSQL_ROOT_PASSWORD=$(openssl rand -hex 16)" > .env
docker compose up -d
```

Dependencies are automatically installed on container startup. Alternatively, run `./install.sh` and pick the Docker option — it generates the `.env` for you.

- Application: `http://localhost:8000`
- phpMyAdmin (optional): `docker compose --profile debug up -d`, then `http://127.0.0.1:8080` (user `root`, password from your `.env`)

#### Security defaults

Because anyone can deploy this compose file as-is, it ships with no known credentials or unnecessarily exposed services:

- There is no default database password. `docker compose up` refuses to start until you set `MYSQL_ROOT_PASSWORD` (the value is baked into the database volume on first start; changing `.env` later won't change the actual MySQL password).
- MySQL and memcached are not published on any host port — they are reachable only from the other containers.
- phpMyAdmin does not start by default. It requires the `debug` profile and binds to `127.0.0.1` only, so it is never reachable from another machine.
- The application itself is published on port 8000 with **no account until you finish the install wizard** — anyone who can reach the port before you do can claim the install. Complete the wizard immediately after `docker compose up`, especially on a machine with a public address.

This stack is a development configuration (PHP `display_errors` is on). For production click servers, use the Manual Installation below with the tuned Nginx or Apache configs.

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

       location /api/v2/ {
           try_files $uri $uri/ /api/v2/index.php?$query_string;
       }

       location ~ \.php$ {
           fastcgi_pass php_fpm;
           fastcgi_keep_conn on;
           fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
           include fastcgi_params;
       }

       location ~ /\. {
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

If you prefer Apache over Nginx, point your document root at the project directory and ensure `mod_rewrite` is enabled so the `.htaccess` files shipped in `api/v2/`, `api/v3/`, and `tracking202/update/reports/` can handle routing. Those files only need `AllowOverride FileInfo Options=FollowSymLinks` (`FileInfo` for the rewrite rules, `Options=FollowSymLinks` for the `Options +FollowSymLinks` line in the reports rules) — avoid `AllowOverride All`, which is broader than required. Example virtual host:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /path/to/prosper202

    <Directory /path/to/prosper202>
        Options -Indexes +FollowSymLinks
        AllowOverride FileInfo Options=FollowSymLinks
        Require all granted
    </Directory>

    # Deny dotfiles (.git, .env, ...), matching the Nginx example above
    <LocationMatch "/\.">
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

    # Inlined from api/v2/.htaccess
    <Directory /path/to/prosper202/api/v2>
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteRule ^ index.php [QSA,L]
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

    # Deny dotfiles (.git, .env, ...)
    <LocationMatch "/\.">
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
