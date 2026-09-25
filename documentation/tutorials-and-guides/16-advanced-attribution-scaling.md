# Attribution at scale

- **Worker throughput.** Per conversion the worker runs one indexed journey
  query (`202_clicks_visitor (user_id, visitor_key, click_time)`) and writes
  at most 25 touches × active models credit rows. One run processes for up
  to 50 seconds (`--budget=N` changes it); a backlog drains over successive
  minutes. Each extra active model adds its credit rows to every conversion,
  so keep inactive the models you are not reading.
- **Model changes** are fanned out 1,000 conversions per model per run, so a
  change on a large account settles over several runs; `recompute_pending`
  on the model says when it has.
- **Reports** group `202_attribution_credits` by `(model_id, conv_time)`
  joined to the clicks' dimensions. The target is under two seconds for 30
  days at a million conversions; an hourly rollup is added only if
  measurement shows the grouped query missing it.
- **Retention.** Credits and journeys live as long as the conversions they
  describe; deleting a user removes their models, credits and journeys.
