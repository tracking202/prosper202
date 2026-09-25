# Multi-touch attribution

Prosper202 spreads every conversion's value over the clicks that led to it —
the same visitor's clicks, across your campaigns — under each of your
attribution models, so you can see which traffic sources and campaigns start
journeys and which close them.

## 1. Make sure the worker runs

Credits are computed by the attribution worker. The minutely cron you set up
at install (`202-cronjobs/index.php`) already runs it. If you schedule workers
separately, add:

```
* * * * * /usr/bin/php /path/to/prosper202/202-cronjobs/attribution-worker.php >> /var/log/prosper202/attribution.log 2>&1
```

Two copies never run at once (a database lock), so running both is safe.
`p202 attribution queue` shows what is waiting; a healthy install shows a
handful of rows at most.

## 2. Let the tracker recognise returning visitors

A journey is only as long as the tracker can tell that two clicks came from
one person. Identity capture is on by default: a first-party cookie on your
tracking domain, an id stored by your landing pages' tracking script, and —
for cross-device journeys — a customer id your own server signs (see the
identity section of the tracking docs). IP addresses and user agents are never
used to join clicks. A campaign can switch identity capture off; its clicks
are then one-touch journeys.

## 3. Choose models

Every account starts with a **Last touch** default. Add others:

```bash
p202 attribution model create --model-name "Linear" --model-type linear
p202 attribution model create --model-name "Decay 24h" --model-type time_decay --weighting-config '{"half_life_hours":24}'
p202 attribution model create --model-name "U-shaped" --model-type position_based --weighting-config '{"first_weight":0.4,"last_weight":0.4}' --lookback-days 60
p202 attribution model update 2 --default
```

A new or changed model is computed for your existing conversions by the next
worker runs. A campaign can use a model of its own (the campaign form's
*Attribution Model* field); reports without an explicit model read each
conversion under its campaign's model, else the default.

## 4. Read the reports

```bash
p202 attribution breakdown --group-by traffic_source
p202 attribution breakdown --group-by campaign --model 1 --compare-model 2
p202 attribution journeys --period last30
p202 attribution journey 1234
```

The breakdown's revenue under every model adds up to the same total — the
value of the conversions in the range; models differ in *where* it lands.
Cost is each dimension's own click cost, so ROI per source is real.

## 5. The Attribution page

**Attribution** in the header opens the same reports without a terminal:

- **Report** — the breakdown by any dimension, under the effective model or
  one you choose, with a second model side by side under *Advanced*, and a
  CSV of exactly what is on screen.
- **Journeys** — journey length, time to convert, and the share of one-touch
  journeys by browser: Safari and Chrome with third-party cookies blocked
  clear a redirect domain's cookie, so their journeys are undercounted, and
  this is where you see by how much. The newest conversions open to their
  journey: each touch, the signal that linked it, and every model's credit,
  each column summing to the whole conversion.
- **Models** — add, edit, switch off, make default, delete; the same rules as
  the API (the default must stay active and cannot be deleted).
- **Exports** — every group of a breakdown as a CSV, now or at a time you
  choose, optionally sent to your server as a signed POST.

## 6. Exports and webhooks

```bash
p202 attribution export create --group-by traffic_source --period last7 --webhook-url https://hooks.example.com/p202
p202 attribution export download 7 --output last7.csv
```

The minutely cron runs due exports (or `202-cronjobs/attribution-exports.php`
on its own). A webhook must be `https` on a public address; the server checks
every address the name resolves to, connects only to the one it checked, and
never follows a redirect. Verify each delivery: `X-P202-Signature` is
`sha256=` and the HMAC-SHA256 of `X-P202-Timestamp`, a dot and the body,
under the secret shown once when you created the export. If your receiver is
on your own network, allow that network in `202-config.php`:

```php
define('P202_WEBHOOK_ALLOW_NETWORKS', '10.20.0.0/16');
```

Export files are kept under `202-config/temp/attribution-exports/` (set
`P202_EXPORT_DIR` to keep them outside the web root) until you delete the
export.
