# Upgrading Prosper202

## Upgrading Prosper202

Upgrading your Prosper202 software is extremely easy. Make sure you back up your DB and config file just in case.

**READ**: Version 1.8.x and higher uses a different 202-config.php file.

Simply follow the instructions, please follow them exactly.

## What’s new in 1.9.56
- Installs the Advanced Attribution Engine schema and registers the rebuild cron job (`202-cronjobs/attribution-rebuild.php`).
- Adds new permissions (`view_attribution_reports`, `manage_attribution_models`). After upgrading, review role assignments under **Administration → User Management**.
- Re-run composer install if you maintain custom deployments; PHPUnit and GeoIP libraries were updated.

After upgrading, visit **Dashboard → System Checks** to confirm the attribution cron job passes the health check, then schedule the cron as described in [14-Advanced Attribution Engine](./14-advanced-attribution-engine.md).

## How-to Upgrade Video

**Video:** [Upgrading Your Prosper202 Installation To Version 1.8.3](https://www.youtube.com/watch?v=lc16taRyV3I&feature=youtu.be)

## Upgrade Instructions

1. Begin by downloading the latest version
2. Backup the 202-config.php file
3. Delete all the previous files on the domain (this is extremely important as the old files may have vulnerabilities)
4. Upload all of the new files (you should be uploading to an EMPTY directory after the previous delete)
5. Copy the database setting from your old 202-config.php into 202-config-sample.php file
6. Rename the 202-config-sample.php file as 202-config.php
7. Navigate to your Prosper202 url and follow the prompts.
8. You should now be done.

And that's it! Prosper202 should now be upgraded.

Please note newer versions now come with an auto-upgrade feature.

## Additional Support

If you require additional assistance, you will need to be on a paid support plan. Please check out our support plans here:
**http://join.tracking202.com**
