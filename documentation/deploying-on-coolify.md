# Deploying Prosper202 on Coolify

[Coolify](https://coolify.io) is a self-hosted, open-source platform that turns
any VPS into your own Heroku/Netlify: it builds from git, routes domains,
issues Let's Encrypt certificates, and restarts things that die. Prosper202
ships a ready-made stack for it — [`docker-compose.coolify.yaml`](../docker-compose.coolify.yaml)
— that runs the web app, MySQL, memcached, and the cron poller with one click.

## 1. Install Coolify on your server

Follow the official guide: <https://coolify.io/docs/get-started/installation>.

You need a server (VPS or dedicated) with SSH root access and at least
**2 CPU cores, 2 GB RAM, and 30 GB of disk**. On Ubuntu LTS the whole install
is one command:

```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | sudo bash
```

Then open `http://<your-server-ip>:8000`, create the Coolify admin account
immediately (the first visitor claims the instance), and finish Coolify's
onboarding — let it manage **localhost** unless you want to deploy to a
separate server.

## 2. Create the Prosper202 resource

1. In the Coolify dashboard, open a project and click **+ New** →
   **Public Repository** (or **Private Repository (GitHub App)** if you deploy
   a private fork).
2. Repository URL: `https://github.com/tracking202/prosper202`, branch `master`
   (or your fork/branch).
3. Set **Build Pack** to **Docker Compose**.
4. Set **Docker Compose Location** to `/docker-compose.coolify.yaml`.
   *(The default `/docker-compose.yaml` is the development stack — bind
   mounts, port 8000, `display_errors` on. Don't deploy that one.)*
5. Continue. Coolify parses the compose file and shows the services.

## 3. Set the domain

On the **web** service, set the domain your tracker will run on, e.g.
`https://track.example.com` (the compose file's `SERVICE_FQDN_WEB_80` variable
tells Coolify to route the domain to Apache on port 80). Point the domain's
DNS A record at your server first; Coolify then provisions the Let's Encrypt
certificate automatically.

Everything else is hands-off:

- **Database password** — Coolify generates `SERVICE_PASSWORD_MYSQL` on first
  deploy and injects it into both the web and db containers. The container
  writes its own `202-config.php` from it at startup; you never edit a config
  file. Note the password is baked into the MySQL data volume on first start,
  so don't rotate the variable later without also changing it inside MySQL.
- **Cron** — the `cron` service polls `202-cronjobs/index.php` once a minute,
  and the `attribution-cron` service runs the standalone CLI workers that
  `index.php` does not cover: the attribution export queue and the API v3 sync
  worker every minute, the attribution snapshot rebuild hourly. No host
  crontab needed.
- **HTTPS detection** — Coolify's proxy terminates TLS; the image maps
  `X-Forwarded-Proto` back to `HTTPS=on` so secure cookies and generated URLs
  are correct.
- **MaxMind ISP database** — if you buy the optional ISP/carrier database,
  upload it to `/var/lib/prosper202/geo/` inside the web container (the
  `geo_isp` volume), not the docroot — the admin page shows this path when
  `P202_GEO_DIR` is set. Files there survive redeploys; anything placed in
  the container's docroot does not.

## 4. Deploy and finish the wizard

Click **Deploy**. Coolify builds the image from git (the app and its Composer
dependencies are baked in — first build takes a few minutes) and starts the
stack; the web service reports healthy once `/health/` responds.

Then open your domain. The setup wizard runs with the database step already
completed — you only validate your API key and create the admin account.

> **Do this immediately after deploying.** Until the wizard is finished the
> install has no account, and anyone who reaches the domain first can claim it.

## Day-2 operations

- **Upgrades** — click **Redeploy** (or enable auto-deploy on push). The image
  is rebuilt from git; your data lives in the `db_data` volume and survives
  redeploys. `202-config.php` is regenerated from the environment on boot when
  missing, so a fresh container wires itself back to the same database. After
  a redeploy that ships a new Prosper202 version, the app walks you through
  the one-click **database** upgrade on your next login.
- **In-app 1-click upgrade is disabled** — Prosper202's built-in auto-upgrade
  downloads files over HTTP and overwrites the docroot, which on this
  deployment would be silently reverted by the next redeploy. The stack sets
  `P202_DISABLE_AUTO_UPGRADE=1` (and the app also detects Coolify's own
  `COOLIFY_*` variables), so the upgrade pages refuse to run and the "new
  version" banner points at the redeploy flow instead.
- **Backups** — nearly all state is MySQL (`db_data` volume). Use Coolify's
  scheduled backup feature, or run `mysqldump` in the db container. Note the
  single quotes: the password variable only exists *inside* the container, so
  it must be expanded by the container's shell, not your host shell:

  ```bash
  docker exec <db-container> sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > prosper202-backup.sql
  ```
  Generated attribution export files live in the `attribution_exports` volume
  (they can be regenerated by re-running an export, so backing them up is
  optional), REST API v3 sync-job state lives in the `api_state` volume, and
  an uploaded MaxMind ISP database lives in the `geo_isp` volume.
- **Scaling note** — this stack is a single-server deployment, which is the
  standard Prosper202 topology. For a tuned bare-metal click server, see the
  Nginx/Apache configs referenced in the main [README](../README.md).

## Running the stack without Coolify

The same file works with plain docker compose — supply the password Coolify
would have generated. Store it in `.env` (which compose reads automatically,
and which is gitignored), **not** as a one-off shell variable: the password is
baked into the MySQL volume on first start, and every later compose command
needs the same value or the containers will regenerate `202-config.php` with
credentials that can't connect.

The generate-if-absent guard makes the command safe to re-run: appending a
second, different password would win compose's interpolation while the
database volume keeps the original.

```bash
grep -qs '^SERVICE_PASSWORD_MYSQL=' .env || echo "SERVICE_PASSWORD_MYSQL=$(openssl rand -hex 16)" >> .env
docker compose -f docker-compose.coolify.yaml up -d --build
```

There are no host port mappings, so front it with your own reverse proxy that
forwards to the `web` container (and sets `X-Forwarded-Proto`).
