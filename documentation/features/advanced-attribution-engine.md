# Multi-touch attribution engine

The engine behind `/api/v3/attribution/*` and `p202 attribution`. It was
rewritten in the 1.9.76 release (plan: `measurement-rewrite-plan.md` §6); the
engine it replaced built "journeys" from the account's recent clicks on the
same campaign, so every model reported the same totals over strangers'
clicks.

## Flow

```
conversion path (gpb, gpx, upx, px, pb, cb202, API, uploads)
   └─ MysqlConversionRepository::record() / softDelete() / clearClicks()
        └─ same transaction: ledger row + 202_attribution_pending (the outbox)

identity capture (dl.php, rtr.php, LP beacons, signed cust)
   └─ IdentityGraph: 202_clicks_visitor, merges → 202_identity_merges (requeued_at NULL)

attribution worker (every minute; MySQL named lock, one at a time)
   1. merges      → re-queue the merged person's conversions (identity_merge)
   2. model edits → re-queue the account's attributed conversions (rebuild_journey / model_changed)
   3. outbox      → per conversion, one transaction:
                     counted? → journey (stored or rebuilt) → credits for every active model
                     mark the report rollup's hours the rows sit in dirty
                     delete the pending row only if its enqueue_seq is unchanged
   4. rollup      → resolve changed clicks into dirty hours, re-sum dirty hours,
                     sum hours that sealed (ended more than two hours ago)

report (GET /attribution/reports/breakdown, the dashboard, exports)
   └─ one statement per part: the rollup's summed, clean hours
        UNION ALL the exact rows of everything else (range edges, dirty or
        unsummed hours), guarded in the same snapshot; the full computation
        when the rollup can serve none of it
```

A cron job run against a database that needs an upgrade stops before doing
anything, writes the reason (both versions and the page that upgrades it) to
stderr and exits 1; on the web the same request still redirects to
`202-config/upgrade.php`.

Nothing on the conversion path knows the engine exists: a broken engine
(worker not running, a table missing, a model with an invalid config) only
lets the outbox grow, and the backlog is processed once it is fixed.

## Code

| Class | Role |
| ----- | ---- |
| `Prosper202\Attribution\ModelType` | The only list of models |
| `ModelConfig` | Validates a definition on write (API) and on load (worker, reports) |
| `ModelRepository` | Model rows; writes request recomputes |
| `DefaultModel` | The one statement that gives every account its default, run by the installer, user creation and the 1.9.56 rung |
| `JourneyBuilder` | A conversion's journey from the identity graph |
| `CreditCalculator` | Pure: credits in 1e-8 units summing to exactly 1, revenue in 1e-5 units summing to exactly the amount |
| `AttributionStore` | Rewrites a conversion's journey, journey meta and credits |
| `AttributionWorker` | The run described above |
| `AttributionReports` | The reads behind the reports API; a breakdown reads the rollup where it can, and the full computation (`new AttributionReports($conn, false)`) is the reference it is tested against |
| `AttributionRollup` | Sums the report rollup: hours as they seal, dirty hours again, changed clicks into hours, overrides kept in step |
| `Prosper202\Report\RollupDirty` | The marks a writer of summed data leaves in its own transaction — outside the engine's namespace, so click and conversion paths write them the way they write the outbox |

## Tables

| Table | Holds |
| ----- | ----- |
| `202_attribution_models` | Models; `model_type` is an enum column, `is_default` is 1 or NULL under `UNIQUE (user_id, is_default)` |
| `202_attribution_journeys` | `(conv_id, position) → click_id, click_time` |
| `202_attribution_journey_meta` | What each journey was built under: `built_lookback_days`, `truncated`, `identified` |
| `202_attribution_credits` | `(conv_id, model_id, click_id) → position, credit, revenue, conv_time` |
| `202_attribution_pending` | The outbox (conversion schema): `reason`, `enqueue_seq`, and the worker's `attempts`, `last_error`, `retry_at` |
| `202_attribution_audit` | Model changes |
| `202_attribution_exports` | Export jobs (the pipeline arrives with the rebuilt dashboard) |
| `202_attribution_rollup` | The report rollup: `(user, part, dimension, model, grain, bucket, key)` → sums; hours and UTC days; no names (looked up when read) |
| `202_attribution_rollup_state` | Per account: `built_through_hour` (every hour below it is summed) and the default the effective rows were summed under |
| `202_attribution_rollup_overrides` | The per-campaign model overrides the effective rows were summed under |
| `202_attribution_rollup_dirty` | Hour ranges whose sums are stale; a report computes them exactly |
| `202_attribution_rollup_dirty_clicks` | Clicks rewritten after the fact, until the rollup turns them into hours; the account's reports are exact meanwhile |

## Failure handling

- A row that throws (an inconsistent identity row, an amount out of range)
  is kept with `attempts`, `last_error` and an exponential `retry_at`
  (1 minute doubling to a day); the rest of the queue carries on. A
  re-queue by the ledger resets it. `--retry-now` makes every failed row
  due again after a fix.
- A database error (a missing table, a lost connection) stops the run and
  leaves every row untouched, so nothing is charged a backoff for the
  engine's failure.
- A stored model definition that no longer validates is marked
  `invalid` with the reason; the other models keep computing.
- The report rollup never answers differently from the full computation: an
  hour it has not summed, or that a change has made dirty, is computed
  exactly in the same statement. What changes is speed: after a campaign's
  model override or the account default changes, every summed hour is
  re-summed (minutes at a million conversions), and the reports compute in
  full meanwhile.

## Honest limits

Browsers erode a redirect domain's cookies, so journeys undercount on some
browsers; `GET /attribution/reports/journeys` shows the one-touch share by
browser. Cross-device journeys need a signed customer id. Clicks recorded
before visitor capture existed have no visitor key and are one-touch
journeys. See the plan's §6.2 for the full list.
