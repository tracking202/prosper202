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
                     delete the pending row only if its enqueue_seq is unchanged
```

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
| `AttributionReports` | The reads behind the reports API |

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

## Honest limits

Browsers erode a redirect domain's cookies, so journeys undercount on some
browsers; `GET /attribution/reports/journeys` shows the one-touch share by
browser. Cross-device journeys need a signed customer id. Clicks recorded
before visitor capture existed have no visitor key and are one-touch
journeys. See the plan's §6.2 for the full list.
