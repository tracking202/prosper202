# Upgrading Prosper202

## Upgrading Prosper202

Upgrading your Prosper202 software is extremely easy, but **back up your database and your 202-config.php file first, every time.** An upgrade changes the database in place and there is no downgrade: restoring the database backup taken before the upgrade is the only way back. Putting the old files back is not a rollback — from 1.9.76, the previous version's code can no longer record conversions against an upgraded database.

After restoring a database, restart memcached (or flush it) before taking traffic. Tracking links cache the ids of the rows they look up for 30 days, and a row the backup does not hold may be named by a cached id that the restored database gives to another value.

Take a full `mysqldump` of the Prosper202 database (or your host's database snapshot) after the site stops taking traffic and before you start. The upgrade page and the **1-Click Upgrade** page (from the new-version notice under the header) both say so above their button.

**READ**: Version 1.8.x and higher uses a different 202-config.php file.

Simply follow the instructions, please follow them exactly.

## Upgrading to 1.9.78

The upgrade takes a 1.9.55 (or older) database to 1.9.78 in one pass. The full list of changes is in [`changelogs.txt`](../../changelogs.txt).

- **Chat history.** From 1.9.77, chat with the Tracking202 team is held by the chat service, and the upgrade removes the old chat's two local tables (`202_messaging_conversations`, `202_messaging_messages`). This is one of the changes only your backup can undo.
- **Click data deletion.** From 1.9.77, "Delete click data older than … days" in **Account › Settings** deletes what it says, starting with the first cron run after the upgrade; before, it deleted almost nothing. If you want to keep older clicks, set it to 0 (keep every click) or a larger number before you upgrade.
- **Database version.** Prosper202 needs MySQL 8.0 or newer, or MariaDB 10.6 or newer. The upgrade page checks before changing anything and refuses an older server. A 1-Click Upgrade from 1.9.55 replaces the files and then sends you to that page for the database step, so the same check applies; still check the version first, because the files will already have been replaced when it refuses.
- **Large installs.** The upgrade page answers only when the upgrade has finished; at a million conversions that is one to two minutes, which can outlast your proxy's or host's time limit and show a timeout error. The upgrade keeps running on the server regardless: wait a few minutes and reload the page. If it is still running the page says "An upgrade is already running" and changes nothing; once it has finished it sends you to sign in. There is nothing to redo.
- **Cron.** The attribution worker runs from the minutely cron (`202-cronjobs/index.php`), or on its own as `202-cronjobs/attribution-worker.php`. After the upgrade it brings your existing conversions into multi-touch attribution and builds the report rollup (about 20 minutes of worker time per million conversions); reports compute in full meanwhile and say how far it has got. Check that the minutely cron runs — `p202 attribution queue` should not keep growing — as described in [14-Multi-touch attribution](./14-advanced-attribution-engine.md). A cron job run against a database that still needs the upgrade now exits with "the database needs an upgrade" instead of succeeding silently.
- **Re-clicked clicks.** From 1.9.79 a click a visitor comes back to through a redirector link (`?lpr=`) counts once in every report, under its latest visit, with the traffic source and cost of its first visit; it used to count at each visit ([Reports](../api/11-reports.md#a-click-a-redirector-link-re-clicked)). The clicks re-clicked before the upgrade are brought to that rule by the minutely cron job, not by the upgrade: the upgrade notes the newest click, and each cron run then spends about 10 seconds walking the clicks up to it (at least one click per run), reading 5,000 click ids at a time, so a large install takes many runs. Until the walk reaches a click, the reports count it at each of its visits, as 1.9.78 did, each under the traffic source and cost that visit was recorded with. `p202 system dataengine` (`GET /api/v3/system/dataengine`, its `reclick_backfill`) shows how far it has got, and answers `null` there once it is done. A click it cannot bring over is written to the server's error log (`p202 re-click backfill: click …`) and the next run starts with it, and `reclick_backfill` names it (`stalled_at_click_id`, with `failed_runs` and `failing_since`) until a run gets past it, or until it has failed in 10 runs in a row over an hour and is stepped over (`skipped_clicks`, `last_skipped_click_id`), so it counts at each visit and holds back no other click; a click whose visits belong to more than one account is left as it is and logged.
- **`tracking202/Redirect`.** Up to 1.9.78 the files had two folders whose names differ only in case: `tracking202/Redirect` (one helper class) and `tracking202/redirect` (the tracking links' scripts). A Mac or Windows computer sees the two as one folder, so files extracted or checked out there and then uploaded to a Linux server put every script under `tracking202/Redirect`, and every tracking link answered 404. From 1.9.79 there is only `tracking202/redirect`. Uploading into an empty directory, as the steps below say, takes care of it. If you replace the files over the old ones instead and `tracking202` then holds both a `Redirect` and a `redirect` folder, delete `Redirect`; 1.9.79 uses nothing in it. A git checkout made on a Mac before 1.9.79 keeps the `Redirect` spelling after a pull: rename that folder to `redirect` before uploading from it, by way of another name (`Redirect` to `redirect-tmp`, then `redirect-tmp` to `redirect`): not every tool on a Mac takes a rename that changes only the case.
- **`tracking202/Setup` and `tracking202/Analyze`.** From 1.9.79 the Setup and Analyze pages are at `tracking202/Setup/` and `tracking202/Analyze/`; they were at `tracking202/setup/` and `tracking202/analyze/`. A bookmark or link to an old address answers "Page not found", which, when you are signed in, links the page at its new address (the rest of the address kept) and the dashboard. For an address with no page to reach it, the web server sends its 404s to `202-404.php`: `ErrorDocument 404 /202-404.php` on Apache, `error_page 404 /202-404.php;` and `try_files $uri =404;` in the PHP location on nginx (the [README](../../README.md) shows both, and the Docker image does it). The upgrade page lists either folder it finds out of place, and what to do:
  - Files replaced over the old ones on Linux leave `setup` beside `Setup` and `analyze` beside `Analyze`. Delete `setup` and `analyze`: 1.9.79 uses nothing in them, and until you do, their pages answer "Page not found" rather than run.
  - Files extracted or copied over the old ones on a Mac or Windows computer, then uploaded to Linux, keep the old names `setup` and `analyze`. Rename them on the server to `Setup` and `Analyze` (by way of another name, if your tool refuses a rename that changes only the case). Until you do, the Setup and Analyze menus answer "Page not found".
  - A git pull of 1.9.79 on a Mac or Windows computer can delete both folders from the checkout (`git status` lists their files as deleted). Run `git checkout -- tracking202/Setup tracking202/Analyze` there before uploading from it.

  Uploading into an empty directory, as the steps below say, avoids all three.
- **Replace the files and upgrade together.** Between replacing the files and running the upgrade, the report rollup of every click and conversion fails, because the upgrade creates a table the rollup reads. Each such click's hour is queued, and the cron job rolls it up once the upgrade has run, so run the upgrade straight after replacing the files.
- **Permissions.** The upgrade adds `view_attribution_reports` and `manage_attribution_models`; review role assignments under **User Management** in the account menu. The Mobile Apps pages use the same two permissions.
- **Mobile202.** The separate 202-Mobile pages are retired; their addresses redirect to the responsive pages.
- **Custom deployments.** If you install from source rather than the release zip, run `composer install --no-dev` after replacing the files.

## How-to Upgrade Video

**Video:** [Upgrading Your Prosper202 Installation To Version 1.8.3](https://www.youtube.com/watch?v=lc16taRyV3I&feature=youtu.be)

## Upgrade Instructions

1. Begin by downloading the latest version (the `prosper202-<version>.zip` release asset, not GitHub's "Source code" archive)
2. Back up the database: a full `mysqldump` or your host's snapshot, taken after the site stops taking traffic. This is the only way back if anything goes wrong
3. Backup the 202-config.php file
4. Delete all the previous files on the domain (this is extremely important as the old files may have vulnerabilities)
5. Upload all of the new files (you should be uploading to an EMPTY directory after the previous delete)
6. Copy the database setting from your old 202-config.php into 202-config-sample.php file
7. Rename the 202-config-sample.php file as 202-config.php
8. Navigate to your Prosper202 url and follow the prompts.
9. You should now be done.

And that's it! Prosper202 should now be upgraded.

Please note newer versions now come with an auto-upgrade feature.

## Additional Support

If you require additional assistance, you will need to be on a paid support plan. Please check out our support plans here:
**http://join.tracking202.com**
