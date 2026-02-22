#!/bin/bash
set -e

# Cron worker for Prosper202.
# Runs the cronjob endpoint on a configurable interval (default 60s).
# Designed to run as a dedicated container — one cron worker per stack.

INTERVAL="${CRON_INTERVAL:-60}"

echo "Prosper202 cron worker starting (interval: ${INTERVAL}s)"

while true; do
    php /var/www/html/202-cronjobs/index.php 2>&1 || echo "[cron] WARNING: cronjob exited with error ($?)"
    sleep "$INTERVAL"
done
