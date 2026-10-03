# Upgrading Prosper202

## Upgrading Prosper202

Upgrading your Prosper202 software is extremely easy, but **back up your database and your 202-config.php file first, every time.** An upgrade changes the database in place and there is no downgrade: restoring the database backup taken before the upgrade is the only way back. Putting the old files back is not a rollback — from 1.9.76, the previous version's code can no longer record conversions against an upgraded database.

Take a full `mysqldump` of the Prosper202 database (or your host's database snapshot) after the site stops taking traffic and before you start. The upgrade page and the **1-Click Upgrade** page (from the new-version notice under the header) both say so above their button.

**READ**: Version 1.8.x and higher uses a different 202-config.php file.

Simply follow the instructions, please follow them exactly.

## Upgrading to 1.9.76

The upgrade takes a 1.9.55 (or older) database to 1.9.76 in one pass. The full list of changes is in [`changelogs.txt`](../../changelogs.txt).

- **Database version.** 1.9.76 needs MySQL 8.0 or newer, or MariaDB 10.6 or newer. The upgrade page checks before changing anything and refuses an older server. A 1-Click Upgrade from 1.9.55 replaces the files and then sends you to that page for the database step, so the same check applies; still check the version first, because the files will already have been replaced when it refuses.
- **Large installs.** The upgrade page answers only when the upgrade has finished; at a million conversions that is one to two minutes, which can outlast your proxy's or host's time limit and show a timeout error. The upgrade keeps running on the server regardless: wait a few minutes and reload the page. If it is still running the page says "An upgrade is already running" and changes nothing; once it has finished it sends you to sign in. There is nothing to redo.
- **Cron.** The attribution worker runs from the minutely cron (`202-cronjobs/index.php`), or on its own as `202-cronjobs/attribution-worker.php`. After the upgrade it brings your existing conversions into multi-touch attribution and builds the report rollup (about 20 minutes of worker time per million conversions); reports compute in full meanwhile and say how far it has got. Check that the minutely cron runs — `p202 attribution queue` should not keep growing — as described in [14-Multi-touch attribution](./14-advanced-attribution-engine.md). A cron job run against a database that still needs the upgrade now exits with "the database needs an upgrade" instead of succeeding silently.
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
