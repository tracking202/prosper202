# Attribution troubleshooting

| Symptom | Check | Fix |
| ------- | ----- | --- |
| Reports are empty | `p202 attribution queue` — is `pending` growing? | The worker is not running: schedule `202-cronjobs/index.php` (or `attribution-worker.php`) every minute and run it once by hand to see its output |
| Some conversions never get credits | `p202 attribution queue` lists them with `attempts` and `last_error` | Fix what the error names, then run `php 202-cronjobs/attribution-worker.php --retry-now` |
| The worker exits with a database error | Its output names the table or connection | Every row stays queued; once the database is fixed the next run processes them — nothing is lost |
| A model shows `status: invalid` | `p202 attribution model get <id>` shows `status_reason` | Send a corrected `--weighting-config` with `p202 attribution model update`; the other models keep computing meanwhile |
| Every journey is one touch | `p202 attribution journeys` — look at `unidentified` and `one_touch_by_browser` | Clicks without a visitor key cannot be joined: check the campaign's identity setting, that landing pages load the tracking script, and that clicks go through your tracking domain. Some browsers clear redirect-domain cookies; the by-browser share shows how much |
| A report answers 409 | The message names the model and its status | Report on an active model, or activate/fix it |
| Credits under a model do not match the conversion value | They always do for counted conversions; a conversion that was superseded, deleted or fully reversed has none | `p202 attribution journey <conv_id>` shows the conversion's journey, its credits, and whether it is still queued |

The worker's output line (cron log) reads, for example:

```
attribution-worker: processed 42 (cleared=2, credited=40); merges re-queued 1; model changes fanned out 0; still due 0
```
