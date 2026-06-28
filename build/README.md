# `build/` — Developer & Contributor Reference

This directory holds the **Docker, CI, and release tooling** for Prosper202. It is
for people who hack on the project — running it locally, debugging the container,
spinning up extra environments, or cutting a release. **Nothing here is needed to
*use* a deployed Prosper202.** If you just want to install and run it, see the root
[`README.md`](../README.md) instead.

This file is self-contained: you should be able to work on the build system from
here without reading anything else first.

---

## TL;DR — get a dev environment running

From the repo root (not from `build/`):

```bash
./start.sh                 # generates .env, builds the image, brings the stack up
# ...or do it by hand:
echo "MYSQL_ROOT_PASSWORD=$(openssl rand -hex 16)" > .env
docker compose up -d
```

Then open <http://localhost:8000>. The container writes its own `202-config.php`
on first boot, so the setup wizard opens with the database step already done.

| Want to… | Command |
|----------|---------|
| Tail logs | `docker compose logs -f web` |
| Open a shell in the app container | `docker compose exec web bash` |
| Run the PHP test suite | `docker compose exec web composer test` |
| Re-install Composer deps | `docker compose exec web composer install` |
| phpMyAdmin (loopback only) | `docker compose --profile debug up -d` → <http://127.0.0.1:8080> |
| Rebuild after Dockerfile change | `docker compose build web` |
| Tear down (keep DB) | `docker compose down` |
| Tear down **and wipe DB** | `docker compose down -v` |

> The DB password is baked into the MySQL data volume on first start. Changing
> `.env` later does **not** change the running database password — `docker compose
> down -v` to wipe the volume and re-init, or `ALTER USER` inside MySQL.

---

## What each file does

### Image

| File | Purpose |
|------|---------|
| `Dockerfile` | The single app image, `prosper202-web` (`php:8.3-apache` base). Installs `mysqli`, `pdo`, `pdo_mysql`, Composer, and `mod_rewrite`. Bakes the app in via `COPY . .` and a `composer install --no-dev` for image-only (no-volume) use; the dev compose stack mounts the source over the top and lets the entrypoint install deps instead. The `memcached` PECL extension is intentionally commented out to avoid build failures on platforms where it won't compile. |
| `php/conf.d/error-reporting.ini` | Dev-only PHP overrides — `display_errors = On`, `error_reporting = E_ALL`, errors to stderr (`/proc/self/fd/2`) so they land in `docker compose logs`. Mounted into the container; **never ship this to production**, where errors should be logged, not displayed. |

### Scripts (`scripts/`)

| File | Purpose |
|------|---------|
| `docker-entrypoint.sh` | Container entrypoint. (1) Runs `composer install` if `vendor/` is missing (dev vs. `--no-dev` keyed off `APP_ENV`). (2) Calls `write-config.php` to self-generate `202-config.php` from the env vars compose passes in. Then `exec`s Apache. `set -e` means a real config failure stops the container loudly instead of booting with broken DB settings. |
| `write-config.php` | Renders `202-config.php` from `202-config-sample.php`, substituting the DB/memcached placeholders with environment values (`MYSQL_DATABASE`, `DB_HOST`, `MYSQL_ROOT_PASSWORD`, `MC_HOST`, …). No-op if `202-config.php` already exists, so it's safe on every boot. Uses single-pass `strtr` (a credential containing a placeholder token can't be double-substituted) and hands the credentials file to the web user at `0640`, failing loudly rather than relaxing permissions. Mirrors the rewrite logic in `202-config/setup-config.php` — keep the two in sync if the sample's placeholder tokens change. |
| `package-release.sh` | Maintainer release builder. Produces `dist/prosper202-<version>.zip` bundling `vendor/` (`--no-dev`) and cross-built Go CLI binaries so end users need no Composer/Go toolchain. Version comes from `202-config/version.php` (single source of truth). See [`RELEASING.md`](../RELEASING.md) for the full process. |

### Environment configs

| File | Purpose |
|------|---------|
| `test-install-config.php` | Pre-baked `202-config.php` mounted into the **test-install** stack (`docker-compose.test-install.yml`). Points at the `db-test` service. Credentials (`root` / `root_password`) are throwaway container defaults reachable only inside the compose network — not secrets. Committed on purpose so the test stack is one command. |
| `staging-config.php` | Same idea for the **staging** stack (`docker-compose.staging.yml`), pointing at the `db2` service. *Gitignored* — create it locally if you use the staging stack. |
| `apache/my-tracking-proxy.conf` | Example **host-side** Apache reverse-proxy vhost (port 80/443 → the app container) so you can hit a real hostname like `my.tracking202.com` with TLS during local dev. Not part of any compose file; drop it into a host Apache and adjust the `ProxyPass` target port to whatever you bound the app to. |

---

## The three compose stacks

All three mount the working tree at `/var/www/html`, so source edits are live
without a rebuild. They live at the **repo root**, not in `build/`.

| Stack | File | App port | DB | Use it for |
|-------|------|----------|----|------------|
| **Dev** (default) | `docker-compose.yaml` | `8000` | `db` (volume `db_data`), memcached, cron, optional phpMyAdmin | Day-to-day development. Generates `202-config.php` from `.env`. |
| **Staging** | `docker-compose.staging.yml` | `8001` | `db2` on host `13307` | A second long-lived instance alongside dev (e.g. comparing behavior). Uses `staging-config.php` + `P202_URL_MAP`. |
| **Test-install** | `docker-compose.test-install.yml` | `8002` | `db-test` on host `13308` | Exercising the install/upgrade path against a clean DB. Uses `test-install-config.php`. |

Run a non-default stack with `-f`:

```bash
docker compose -f docker-compose.test-install.yml up -d   # → http://localhost:8002
docker compose -f docker-compose.staging.yml up -d          # → http://localhost:8001
```

The staging and test-install stacks reference the `prosper202-web:dev` image. Build
it once from the dev stack (`docker compose build web`) or tag your image to match
before bringing them up.

---

## Generated / gitignored artifacts

These appear under `build/` (or the repo root) at runtime and are **not** tracked —
don't commit them:

- `build/logs/` — PHPUnit testdox / JUnit output from CI.
- `build/debug/` — ad-hoc screenshots and HTML dumps from browser debugging.
- `build/staging-config.php`, `*.DS_Store` — local-only.
- `mysql_data_*/` (repo root) — MySQL data volumes for the staging/test stacks.

If you add a new generated path here, add it to `.gitignore` in the same change so
it never lands in a commit.

---

## How a container boots (the full chain)

```
docker compose up
   └─ web service builds/uses prosper202-web image (Dockerfile)
        └─ ENTRYPOINT docker-entrypoint.sh
             ├─ composer install   (if vendor/ missing; dev vs --no-dev by APP_ENV)
             ├─ php write-config.php → writes 202-config.php from env  (skipped if it exists)
             └─ exec apache2-foreground
   └─ db service (mysql) waits healthy before web starts
   └─ cron service polls 202-cronjobs/index.php once a minute
```

Knowing this chain is usually enough to debug a boot problem: a 500 on every page
almost always means `write-config.php` couldn't write/own `202-config.php`, and a
missing-dependency fatal means `composer install` didn't run (delete `vendor/` and
restart, or run it by hand in the container).
