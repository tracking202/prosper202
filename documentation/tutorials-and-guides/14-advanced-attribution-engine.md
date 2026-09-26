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

The Attribution page in the account menu lists your models and the worker's
backlog; its charts are being rebuilt.
