# Attribution troubleshooting

| Symptom | Check | Fix |
| ------- | ----- | --- |
| Reports are empty | `p202 attribution queue` — is `pending` growing? | The worker is not running: schedule `202-cronjobs/index.php` (or `attribution-worker.php`) every minute and run it once by hand to see its output |
| Some conversions never get credits | `p202 attribution queue` lists them with `attempts` and `last_error` | Fix what the error names, then run `php 202-cronjobs/attribution-worker.php --retry-now` |
| The worker exits with a database error | Its output names the table or connection | Every row stays queued; once the database is fixed the next run processes them — nothing is lost |
| Journeys do not join visitors the tracker found to be one person | `p202 attribution queue` — `merges_failing` and `merges_retired`, and `merge_rows` with each merge's `last_error` | A failing merge is tried again by itself (a minute, doubling to an hour) while the rest carry on; `last_error` names the database's error. A retired one says why its own data refuses the re-queue (a person with more visitor keys than one statement can name), which no retry changes; `php 202-cronjobs/attribution-worker.php --retry-now` makes failed and retired merges due again |
| A model shows `status: invalid` | `p202 attribution model get <id>` shows `status_reason` | Send a corrected `--weighting-config` with `p202 attribution model update`; the other models keep computing meanwhile |
| Every journey is one touch | `p202 attribution journeys` — look at `unidentified` and `one_touch_by_browser` | Clicks without a visitor key cannot be joined: check the campaign's identity setting, that landing pages load the tracking script, and that clicks go through your tracking domain. Some browsers clear redirect-domain cookies; the by-browser share shows how much |
| A report answers 409 | The message names the model and its status | Report on an active model, or activate/fix it |
| Credits under a model do not match the conversion value | They always do for counted conversions; a conversion that was superseded, deleted or fully reversed has none | `p202 attribution journey <conv_id>` shows the conversion's journey, its credits, and whether it is still queued |
| An export stays `pending` | `p202 attribution export get <id>` — is `run_at` in the future? | The minutely cron runs due exports; run `php 202-cronjobs/attribution-exports.php` by hand to see its output |
| An export `failed` | `last_error` names the reason (a model switched off, a refused or redirecting webhook, a receiver answering 4xx) | Fix it, then `p202 attribution export retry <id>`; the file is downloadable whatever the webhook did |
| A webhook URL is refused | The error names the address the host resolved to and why | Use the receiver's public https address. A receiver on your own network needs `P202_WEBHOOK_ALLOW_NETWORKS` in `202-config.php`; metadata and link-local addresses are never allowed |
| Every webhook fails with "P202_WEBHOOK_ALLOW_NETWORKS entry … is not a network" | `202-config.php` | Write each entry in CIDR form (`10.20.0.0/16`); until then no webhook is sent, on purpose |

The worker's output line (cron log) reads, for example:

```
attribution-worker: processed 42 (cleared=2, credited=40); merges re-queued 1; model changes fanned out 0; still due 0
```

A run in which a merge failed or was retired says so after the count:
`merges re-queued 1 (1 failed, 0 retired)`.
