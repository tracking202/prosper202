# Forecasting Guide (`p202 forecast`)

`p202 forecast` projects any tracked metric forward from its history and
returns, for every future period, a point forecast, a prediction band, and
the metadata you need to trust (or distrust) it. This guide explains how
the engine works, walks through real outputs, and shows how to read every
field. It applies to any funnel tracked in Prosper202: paid media, lead
generation, e-commerce, SaaS trials, newsletters.

The short reference lives in [Go CLI (p202)](10-go-cli.md#forecasting); the
event calendar it can consume is documented under
[Forecast Events](../api/18-forecast-events.md).

---

## 1. Quick start

```bash
p202 forecast --metric revenue --horizon 7            # next 7 days of income
p202 forecast --metric clicks --interval hour --horizon 24
p202 forecast --all-metrics --horizon 14 --json       # clicks, leads, income, cost, net together
p202 forecast --metric profit --seasonal --events     # weekday profile + calendar events
```

Every run fetches `reports/timeseries` for the chosen `--history` window
(default `last90`), fits the models locally, and renders a table (or `--json`
/ `--csv`). No model runs on the server.

---

## 2. What happens to your data, in order

Understanding the pipeline makes every output field predictable. For one
metric, `Run` does the following, in this order:

| Step | What it does | Where you see it |
| --- | --- | --- |
| 1. Parse | Reads every bucket from the API response. Buckets with an unparseable time or a non-numeric value are dropped and counted. | `buckets_rejected` in meta |
| 2. Log transform | Count metrics (clicks, click-throughs, leads) are fitted on `log1p` scale and inverted on output, so 3%-a-day growth is a straight line, not a curve. | (silent; trends are reported on the original scale) |
| 3. Transient masking | Short outlier runs that are abnormal *for this series at that point of its week* are removed from fitting. Section 7. | `anomalies_masked` |
| 4. Level-shift handling | If the series changed level (offer paused, new source), the model fits the new regime. Section 6. | `level_shift_at`, `data_points_used` |
| 5. Fit | The chosen method, or the ensemble of all of them. Section 3. | `method`, `weights` |
| 6. Seasonal profiles | Weekday / hourly / day-of-month multipliers, if requested and supported by the data, applied inside the fit and inside every backtest fold. Section 8. | `seasonal_applied`, `seasonal_profiles` |
| 7. Bounds | Rolling-origin backtest residuals of the forecaster as emitted (profiles included) become empirical prediction bands. Section 4. | `lower_bound`, `upper_bound`, `p05`…`p95`, `bounds`, `bounds_source`, `mae`, `rmse` |
| 8. Events | Calendar events scale the affected days. Section 9. | `events_in_horizon` |
| 9. Clip | Metrics that cannot go negative are floored at zero. | |

`--all-metrics` and derived metrics run this pipeline once per *driver*
series, compose the totals, and backtest each composition for its own
bands and errors (section 5).

---

## 3. Methods and the ensemble

| `--method` | Model | Good for |
| --- | --- | --- |
| `linear` | Least-squares line through the history | Steady trends |
| `sma` | Mean of the last window (`--window`, default ~¼ of history, 3–30) | Stable levels |
| `wma` | Same window, recent points weighted more | Levels that drift |
| `holtwinters` | Damped-trend exponential smoothing (level + trend, trend fades with horizon) | Trends that may flatten |
| `ensemble` (default; `auto` is an alias) | Weighted average of the above | Everything else |

The ensemble weights each member by how well it has *recently* forecast this
series in a rolling backtest (inverse squared error, older cuts discounted
0.85 per point of age), and drops any member that trails the best by more
than 15%. Weights are reported so you can see who contributed. Bands and
`mae`/`rmse` are measured out of sample: each backtest fold's ensemble uses
weights derived only from the folds before it, so a fold's own error never
shapes the weights that predict it.

**Worked example.** Sixty days of clicks with a weekly pattern:

```json
"meta": {
  "method": "ensemble",
  "weights": { "linear": 0.305, "sma": 0.37, "wma": 0.325 },
  "mae": 84.58, "rmse": 96.17, ...
}
```

Reading it: three members shared the forecast almost equally; Holt-Winters is
absent because its recent rolling error was more than 15% worse than the
best member, so it was dropped rather than diluting the mix. `mae`/`rmse`
are the ensemble's average errors across all rolling cuts, on the metric's
own scale, so they answer "how far off has this forecaster typically been
on this series".

The ensemble is deterministic: the same input always gives the same weights
and numbers.

---

## 4. Prediction bands and how to read them

Bands are **conformal**: the model is re-fit at up to 50 cut points stepping
back through the history, each time forecasting the points it has not seen,
and the errors are collected per horizon step. The band around a forecast is
the empirical spread of those errors, with the finite-sample rank correction
that makes the nominal coverage hold on future data. No normality or symmetry
is assumed, so bands are asymmetric when the errors are.

### Which quantiles you get

Every conformal prediction carries seven quantiles. `--confidence` picks the
pair shown as `lower_bound`/`upper_bound`; the others are still emitted as
columns:

| `--confidence` | Band | Nominal coverage | Meta `bounds` |
| --- | --- | --- | --- |
| below 0.65 | p25 – p75 | 50% | `p25-p75 (50%)` |
| 0.65 – 0.85 | p10 – p90 | 80% | `p10-p90 (80%)` |
| 0.85 and above (default 0.95, and 0.99) | p05 – p95 | 90% | `p05-p95 (90%)` |

The 90% band is the widest a 50-cut residual pool supports honestly, which
is why 0.95 and 0.99 both map to it; the meta always says which band is in
use so a requested level is never mistaken for the delivered one.

**Worked example.** Same clicks series, default confidence, first forecast
row:

```json
{
  "date": "2026-07-31",
  "total_clicks": 497.94,
  "lower_bound": 318.64, "upper_bound": 640.12,
  "p10": 355.99, "p25": 402.73, "p50": 554.77, "p75": 587.52, "p90": 626.25
}
```

and with `--confidence 0.5`:

```json
{ "date": "2026-07-31", "total_clicks": 497.94, "lower_bound": 402.73, "upper_bound": 587.52, "p50": 554.77 }
```

Reading it:

- `total_clicks` (497.94) is the point forecast: the ensemble's value.
- `p50` (554.77) is the **bias-corrected median**: the point forecast plus
  the median of the model's recent errors. When it sits far from the point
  forecast, as here, the model has been systematically off in that
  direction lately. In this example the series has a strong weekday pattern
  and no seasonal profile was requested, so the ensemble's flat level has
  been under-predicting weekdays; `p50` is telling you that. Running with
  `--seasonal` (section 8) closes most of that gap.
- The 90% band is `[329, 636]`; the 50% band is `[403, 591]`. Nine days in
  ten the actual should fall inside the wide band, one day in two inside
  the narrow one, *provided the series keeps behaving as it has*.
- Bands never narrow with horizon: each step's offsets are at least as wide
  as the previous step's.

### When you get Gaussian bands instead

Conformal bands need at least 8 held-out residuals, which takes about 12
points at a horizon of 4 or more and 16 at `--horizon 1`. Shorter histories
fall back to symmetric bands from a single holdout, which do honor the exact
confidence level but carry no quantile columns:

```json
"meta": { "bounds_source": "gaussian", "data_points_used": 8, "method": "linear", ... }
```

Look at `bounds_source` before comparing bands across runs of different
lengths.

### Bands as an alerting primitive

Because the bands are empirical and per-step, "today's observed value fell
below `lower_bound`" is a calibrated anomaly signal: at the 90% band it
happens about 5% of the time by chance. Section 7 explains why transient
masking matters for this use.

---

## 5. Coherent multi-metric forecasts

Forecasting income and cost independently can produce a pair of paths whose
implied ROI nothing supports. Derived metrics are therefore forecast by
**ratio decomposition**: the engine forecasts the drivers and composes the
totals from them, so these identities hold exactly in every output row:

```
leads  = clicks × conv_rate
cost   = clicks × avg_cpc
income = leads  × avg_payout
net    = income − cost
```

The rates are derived per bucket from the totals in the same response
(`conv_rate` here means leads ÷ clicks, not the API's click-through-based
rate), which is what makes the identities hold against the data you supplied.
Rates are far more stationary than totals, which is where the accuracy comes
from.

### `--all-metrics`

```bash
p202 forecast --all-metrics --horizon 3 --json
```

```json
{
  "data": [
    {
      "date": "2026-07-31",
      "total_clicks": 497.94, "total_clicks_lower": 318.64, "total_clicks_upper": 640.12,
      "total_leads":   39.72, "total_leads_lower":   25.03, "total_leads_upper":   52.92,
      "total_cost":   212.28, "total_cost_lower":   139.95, "total_cost_upper":   273.95,
      "total_income": 382.93, "total_income_lower": 223.68, "total_income_upper": 512.31,
      "total_net":    170.65, "total_net_lower":    83.6,  "total_net_upper":    263.25
    }
  ],
  "meta": {
    "composition": {
      "total_clicks": "direct", "total_leads": "derived", "total_cost": "derived",
      "total_income": "derived", "total_net": "derived"
    },
    "bounds": {
      "total_clicks": "p05-p95 (90%)", "total_leads": "p05-p95 (90%)", "total_cost": "p05-p95 (90%)",
      "total_income": "p05-p95 (90%)", "total_net": "p05-p95 (90%)"
    },
    "bounds_source": {
      "total_clicks": "conformal", "total_leads": "conformal", "total_cost": "conformal",
      "total_income": "conformal", "total_net": "conformal"
    },
    "anomalies_masked": {
      "total_clicks": ["2026-07-25", "2026-07-26"], "total_leads": ["2026-07-25", "2026-07-26"],
      "total_cost": ["2026-07-25", "2026-07-26"], "total_income": ["2026-07-25", "2026-07-26"],
      "total_net": ["2026-07-25", "2026-07-26"]
    },
    "data_points_used": {
      "total_clicks": 58, "total_leads": 58, "total_cost": 58, "total_income": 58, "total_net": 58
    },
    "weights": { "linear": 0.305, "sma": 0.37, "wma": 0.325 }, ...
  }
}
```

Check the identities yourself: 382.93 − 212.28 = 170.65 = `total_net`, and
39.72 ÷ 497.94 = 0.0798, the forecast conversion rate.

### Bands on derived metrics are backtested too

Multiplying or subtracting the drivers' band endpoints would not give a band
of known coverage (two 90% bands jointly cover as little as 80%, and pairing
their worst corners over-covers instead). So the engine backtests the
composition itself: at every rolling cut where both drivers made a
prediction for the same date, it composes those predictions the same way
(`clicks × conv_rate`, `income − cost`), takes the error against the
observed derived value, and turns those residuals into the derived metric's
own empirical quantiles, band, `mae`, and `rmse`. `total_net_lower` above is
therefore a calibrated 90% floor for net in its own right (the worst-corner
pairing of the income and cost bands would have put it below −100), and
`bounds` and `bounds_source` name each metric's band and how it was made,
so a consumer of the payload never needs to know the invocation.

Measured on the rolling suite, composed p05–p95 bands now cover 88–97% of
held-out values against the 90% nominal, matching direct forecasts of the
same metrics; the worst-corner pairing covered 94–100%, too wide to be a
useful alerting threshold.

When a history is too short to pair at least 8 rolling predictions, the
worst-corner pairing is kept and labelled `"bounds_source": "composed"`
with `"bounds": "p05-p95 (composed from operand bands, not calibrated)"`;
treat such a band as valid but wide.

The masked anomalies of every driver are inherited by the metrics built
from them, which is why the clicks outage above is listed under all five
metrics: an agent reading only `total_net` still learns which observations
were excluded.

### A single derived metric

`--metric leads`, `income`, `cost`, or `net` on its own uses the same
composition automatically and returns just that metric:

```json
"meta": {
  "metric": "total_leads", "composition": "derived",
  "bounds": "p05-p95 (90%)", "bounds_source": "conformal", "mae": 7.01, "rmse": 8.31,
  "anomalies_masked": ["2026-07-25", "2026-07-26"], ...
}
```

`mae`/`rmse` are the composed forecast's own rolling errors (leads here,
not clicks or the conversion rate), and `anomalies_masked` carries the
drivers' masked dates, as described above.

### When composition is not possible

If a driver is defined on too few buckets (conversion rate needs clicks > 0
on at least 70% of days) the affected metric is forecast directly and
labeled `"composition": "direct"`. If the response lacks the companion
metrics altogether, the direct path serves the forecast and says why:

```json
"meta": {
  "composition": "direct",
  "composition_fallback": "coherent forecast needs at least 3 data points for total_clicks, got 0", ...
}
```

`--seasonal`, `--seasonal-monthly`, `--events`, and `--event-tag` always use
the direct path for a single metric (those layers operate on the metric's
own series) and cannot be combined with `--all-metrics`.

---

## 6. Level shifts

A paused offer, a new traffic source, or a cap change moves a series to a
new level. Fitting all history equally would then land the forecast between
the old and new levels. The engine detects the strongest level change
(a CUSUM statistic on one-step residuals, immune to steady trends), checks
that the post-shift segment is a consistent new regime rather than a
transient burst, and then:

- if the post-shift segment is long enough to model alone (14 daily points,
  48 hourly), fits only that segment;
- otherwise re-levels older history to the new regime so its shape still
  helps without dragging the level.

Detection and handling run again inside every backtest fold on that fold's
own history, so bands never see the points they hold out and never know
about a shift before the data could reveal it.

**Worked example.** Ninety days of revenue at ~1,200/day that jumped to
~1,900/day twenty days before the end:

```json
"data": [ { "date": "2026-08-30", "total_income": 1915.71, "lower_bound": 1845.49, "upper_bound": 2043.2, "p50": 1904.14 } ],
"meta": {
  "level_shift_at": "2026-08-10",
  "data_points_used": 20,
  "weights": { "sma": 0.516, "wma": 0.484 }, ...
}
```

Reading it: the shift was placed on 2026-08-10, the 20 post-shift points
were long enough to fit alone (`data_points_used` counts what was actually
fitted, not the 90 supplied), and the forecast sits at the new level with
a band of ±5%. Without shift handling the forecast would have been near
1,350 with a band wide enough to be useless.

`--no-level-shift` fits the full history as-is if the detector misreads a
known change. Series shorter than 20 points are never checked.

---

## 7. Transient masking (outages and spikes)

A two-day tracking outage or an untagged promo spike is not information
about next week, but fitting it (on log scale, fitting a zero) drags the
forecast and collapses the band exactly when you most need a healthy
baseline. Short outlier runs are therefore **masked** before fitting.

The test is "abnormal for this series at this point of its cycle": each
point is compared with the same weekday (daily data) or the same hour
(hourly) in the four surrounding cycles, the deviation is measured in robust
units of this series' own noise, and the point must also be at least halved
or doubled relative to that reference. Only runs shorter than five points
are masked; a longer run is a regime change and goes to the level-shift
detector instead. Masking applies to non-negative metrics only.

That definition is what makes it safe across very different funnels:

| Series | Result |
| --- | --- |
| 500 clicks/day, two zero days from a broken postback | Both days masked |
| A store closed every Sunday (0 on Sundays) | Nothing masked: zero is normal for a Sunday here |
| A low-volume tracker averaging 3/day with frequent zeros | Nothing masked: zeros are within its spread |
| A campaign paused for six days | Nothing masked: handled as a level shift |
| A 15% dip on one Monday | Nothing masked: below the halved-or-doubled floor |

**Worked example.** The clicks series above has a two-day outage on
2026-07-25/26. Default run:

```json
"data": [ { "date": "2026-07-31", "total_clicks": 497.94, "lower_bound": 318.64, "upper_bound": 640.12 } ],
"meta": { "anomalies_masked": ["2026-07-25", "2026-07-26"], "data_points_used": 58, "rmse": 96.17, ... }
```

The same run with `--no-anomaly-mask`:

```json
"data": [ { "date": "2026-07-31", "total_clicks": 206.22, "lower_bound": 1.64, "upper_bound": 817.87 } ],
"meta": { "data_points_used": 60, "rmse": 174.81, ... }
```

Reading it: with the outage treated as data, the forecast collapses to 206
(the log-scale fit is pulled hard by two zeros), the band runs from 0 to
652, and the rolling error jumps from 96 to 160. With masking, the forecast
stays at the established level, predictions still start after the outage,
and the two days are listed so nobody is surprised.

For the alerting use, note what this buys: the band stays at the healthy
level, so an outage keeps tripping "observed below `lower_bound`" for as
long as it lasts, instead of the model quietly adapting to the broken state.

Knobs, for installs where the defaults prove too eager or too lax:

| Flag | Default | Effect |
| --- | --- | --- |
| `--no-anomaly-mask` | off | Fit every point as data |
| `--anomaly-sigma` | 5 | Deviation threshold in robust sigmas; lower masks more |
| `--anomaly-cycles` | 4 | Weeks (or days, hourly) on each side supplying the reference |

Tagging known outages and promos as [forecast events](../api/18-forecast-events.md)
remains the explicit override: event days are removed from training before
any of this runs.

---

## 8. Seasonality

### Weekday profile (`--seasonal`, day or hour interval)

The profile is learned from the fetched history itself (each weekday's mean
over the overall mean; no separate report call), then three safeguards
apply:

1. **Shrinkage.** Each multiplier is pulled toward 1.0 by `n / (n + 4)`,
   where `n` is that weekday's sample count in the history it was estimated
   from. Two Mondays showing 1.9× become 1.3×; thirteen Mondays keep 1.69×.
   Thin histories cannot produce extreme weights.
2. **Gating.** The profile is applied only if the history, after removing
   its trend, actually repeats week over week (lag-7 autocorrelation of at
   least 0.3). Weights built from a series with no weekly structure are
   ignored, and `seasonal_applied: false` says so. A history too short to
   measure the lag (under 8 points or 4 weekly pairs) applies the profile
   as supplied.
3. **Calibration.** The profile is re-estimated, gate included, from each
   backtest fold's own history and applied inside the model there, as it is
   in the deployed fit. `mae`/`rmse` and the bands therefore describe the
   seasonally adjusted forecast you receive, and no held-out day ever
   contributes to the multiplier that scales it. Multiplicative profiles
   need non-negative data, so signed metrics (net, ROI) get none.

**Worked example.** The clicks series with `--seasonal --horizon 7`:

```json
"data": [
  { "date": "2026-07-31", "total_clicks": 489.91 },   // Friday
  { "date": "2026-08-01", "total_clicks": 398.54 },   // Saturday
  { "date": "2026-08-02", "total_clicks": 413.23 },   // Sunday
  { "date": "2026-08-03", "total_clicks": 532.59 },   // Monday
  { "date": "2026-08-04", "total_clicks": 556.89 }    // Tuesday
],
"meta": { "seasonal": true, "seasonal_applied": true, "seasonal_profiles": ["weekday"], "rmse": 56.09, ... }
```

Compare with the unseasonal run's flat 498/day: the weekend now dips and the
week's peak lands on Tuesday, matching the history, and the rolling error
falls from 96.17 to 56.09: the flat ensemble was missing the weekly swing
by ~96 clicks a day, and the adjusted forecaster, measured as such, is
within ~56. That figure is honest in a specific sense: each backtest fold
estimated its own profile from the history before it, so no held-out day
helped shape the multiplier that scaled it.

### Hourly profile (`--seasonal` with `--interval hour`)

Hourly forecasts additionally get an hour-of-day profile learned from the
fetched series itself, with the same shrinkage and a lag-24h gate. Signed
metrics (net, ROI) get no hourly profile.

### Day-of-month profile (`--seasonal-monthly`, day interval)

For budgets and payouts that reset monthly. Learned from the series, same
shrinkage, no gate (it is an explicit opt-in), non-negative metrics only.

---

## 9. Events

`--events` (all stored events) or `--event-tag promos,us-holidays` folds in
the [forecast event calendar](../api/18-forecast-events.md): past
occurrences are removed from training, their impact on lead/core/lag days is
learned from history (blended with any `expected_impact_pct` hint), and
future occurrences in the horizon scale the affected predictions and their
bands. Requires `--interval day`. Events named in `events_unquantified` were
seen but had neither history nor a hint, so they did not move the numbers.

Events and transient masking are complementary: tag what you know, let the
mask catch what you did not.

---

## 10. Output reference

### Row columns

| Column | Meaning |
| --- | --- |
| `date` | Period start (`YYYY-MM-DD`, `YYYY-MM-DD HH:MM` hourly, `YYYY-MM` monthly, `... (wk)` weekly) |
| `<metric>` | Point forecast |
| `lower_bound`, `upper_bound` | The band selected by `--confidence` |
| `p05` … `p95` | Remaining quantiles (conformal runs only); `p50` is the bias-corrected median |
| `trend` | First row only: per-period trend as a percent of the history's mean |
| `<metric>_lower`, `<metric>_upper` | `--all-metrics` only, per metric |

### Meta keys

| Key | Meaning |
| --- | --- |
| `method`, `weights` | Method used; ensemble member shares (sum to 1) |
| `mae`, `rmse` | Rolling-backtest errors on the metric's scale (for derived metrics, of the composed forecast) |
| `bounds`, `bounds_source` | Band in use (`p05-p95 (90%)` …); `conformal` (rolling residuals, for derived metrics of the composition itself), `gaussian` (short history), or `composed` (derived metric with too few paired rolling predictions: worst-corner pairing of the drivers' bands, not calibrated). Per metric under `--all-metrics` |
| `data_points_used` | Points actually fitted (after masking and level-shift truncation); for a derived metric, the fewest any of its drivers was fitted on |
| `buckets_rejected` | Response rows the parser could not use |
| `anomalies_masked` | Dates removed as transients (per metric under `--all-metrics`) |
| `level_shift_at` (`level_shifts`) | First period of the new regime |
| `composition`, `composition_fallback` | `derived` / `direct`, and why direct if composition was intended |
| `seasonal`, `seasonal_applied`, `seasonal_profiles` | Requested; actually applied; which profiles |
| `events_active`, `events_in_horizon`, `events_unquantified` | Event layer status |
| `trend_per_period`, `trend_pct` | Trend in metric units and as a percent of the history's mean |

### Flags

| Flag | Default | Notes |
| --- | --- | --- |
| `--metric`, `-m` | required unless `--all-metrics` | Aliases: clicks, leads/conversions, revenue/income, cost, profit/net |
| `--all-metrics` | off | Coherent clicks/leads/income/cost/net; excludes `--metric`, seasonal and event flags |
| `--horizon`, `-n` | 7 | Up to 365 |
| `--interval`, `-i` | day | hour, day, week, month |
| `--history` (`--period`, `--days`) | last90 (last30 hourly) | Hourly is limited to today/yesterday/last7/last30 |
| `--method` | auto | auto, ensemble, linear, sma, wma, holtwinters |
| `--window` | auto | SMA/WMA window |
| `--confidence` | 0.95 | Snaps to 50/80/90% bands (section 4) |
| `--seasonal`, `--seasonal-monthly` | off | Section 8 |
| `--events`, `--event-tag` | off | Section 9 |
| `--no-level-shift` | off | Section 6 |
| `--no-anomaly-mask`, `--anomaly-sigma`, `--anomaly-cycles` | off / 5 / 4 | Section 7 |
| entity filters | | `--aff_campaign_id`, `--ppc_account_id`, `--aff_network_id`, `--ppc_network_id`, `--landing_page_id`, `--country_id` |

---

## 11. Troubleshooting

Every failure carries a category, exit code, and usually a recovery hint;
under `--json` it arrives as a structured envelope on stderr (see
[Errors](10-go-cli.md#errors)). For example, asking for a metric the
response did not contain:

```json
{"error":{"category":"validation","message":"no valid data points found for metric \"epc\" (response carried: total_clicks)","hint":"Pick one of the metrics the response carried with --metric, or widen --history.","exit_code":1,"command":"p202 forecast"}}
```

The symptoms below are the ones that produce *successful* runs whose output
needs interpreting.


**No `p05`…`p95` columns and `bounds_source` is `gaussian`.** The history is
too short for a rolling backtest (section 4). Use a longer `--history`.

**`seasonal_applied` is false although I passed `--seasonal`.** The history
does not repeat week over week after detrending, so the profile would only
add error. If you are certain the pattern exists, eyeball the weekday totals
(`p202 report weekpart` for the same window) and check the history length;
a signed metric (net, ROI) never gets a multiplicative profile.

**`p50` is far from the point forecast.** The model has been systematically
biased on this series recently. Usually a missing seasonal profile
(section 4's example) or a level shift too small or too recent for the
detector; `--seasonal` or a shorter `--history` typically fixes it.

**A date in `anomalies_masked` was a real day.** Raise `--anomaly-sigma`, or
pass `--no-anomaly-mask` for that run. If it happens systematically on an
install, that is the signal to change the defaults there.

**`composition` is `direct` for leads/income/cost/net.** Either a driver was
too sparse (many zero-click days) or the response lacked companion metrics;
`composition_fallback` says which. The forecast is still valid, it just is
not guaranteed consistent with a separate clicks forecast.

**`level_shift_at` on a series that did not shift.** The detector requires a
consistent new regime of at least five points at 3σ from the old level, so
this usually means the change is real but small in your eyes. `--no-level-shift`
overrides it; `data_points_used` shows how much history it kept.

---

## 12. Measuring accuracy yourself

The package ships two synthetic suites that run rolling backtests over the
whole pipeline and print per-series accuracy and band coverage:

```bash
cd go-cli
go test ./internal/forecast/ -run 'TestMeasureSuite|TestMeasureTransientSuite' -v -measure
```

The first covers trends, plateaus, weekly patterns, flat noise, level
shifts, short histories, skewed noise, and multiplicative growth; the second
covers outages, spikes, closed-Sundays, low-volume Poisson counts, and a
weekly pattern with an outage, each measured with masking on and off. The
same suites back the acceptance tests that run on every `go test`, so a
change that degrades accuracy or band calibration fails the build.

---

## 13. Using the engine from Go

The forecaster is a dependency-free package, `p202/internal/forecast`:

```go
result, err := forecast.Run(series, forecast.Config{
    Method:       forecast.MethodAuto,
    Horizon:      7,
    Interval:     forecast.IntervalDay,
    NonNegative:  true,   // clip at zero, enable transient masking
    LogTransform: true,   // count metric
})
// result.Predictions[i].Value / LowerBound / UpperBound / Quantiles["p50"]
// result.Weights, result.LevelShiftAt, result.AnomaliesMasked, result.BoundsSource

results, err := forecast.RunCoherent(map[string]forecast.Series{
    forecast.MetricClicks: clicks, forecast.MetricLeads: leads,
    forecast.MetricIncome: income, forecast.MetricCost: cost,
}, forecast.Config{Horizon: 7, Interval: forecast.IntervalDay})
// results[forecast.MetricNet].Predictions[i].Value == income − cost, exactly
```

`Config` also exposes `SeasonalWeights`, `HourlyWeights`, `MonthDayWeights`,
`ConfidenceLevel`, `Anchor`, `DisableLevelShift`, `DisableAnomalyMask`,
`AnomalySigma`, and `AnomalyCycles`; `Result` mirrors every meta key listed
in section 10. All fields added since the original engine are additive, so
earlier consumers of `Run` and `Result` keep working unchanged.
