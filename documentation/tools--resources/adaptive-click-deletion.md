# Adaptive manual click deletion

`scripts/p202-adaptive-delete.py` is a standalone Python 3.9+ operator tool for
the **Linux server running the local MariaDB database**. It uses the installed
`mariadb` client and its normal root client configuration (including
`/root/.my.cnf`). It needs no Python packages or application bootstrap. Run it
in the root terminal, outside `public_html`; it is not a web endpoint.

Verify a restorable backup first. Completed transactions permanently delete
data. Do not run the earlier SQL deletion alongside this controller. Its local
file lock prevents duplicate copies of this tool; the app's cron does not use
that lock. This tool does not change cron preferences or global server settings.

## Run

Upload the standalone file to `/root/p202-adaptive-delete.py` on the database
server. Specify the database, cutoff date, and IANA time zone explicitly.
The cutoff is midnight at the start of that date, and applies to every account.
Replace the example values below with the intended database and retention date.

Read-only preview (shows the next batch's eligible ID count per account, not
the entire backlog):

```bash
python3 /root/p202-adaptive-delete.py --database example_database --before 2025-01-01 --timezone UTC
```

Run all remaining eligible clicks:

```bash
python3 /root/p202-adaptive-delete.py --database example_database --before 2025-01-01 --timezone UTC --execute
```

An optional bounded run commits only one batch:

```bash
python3 /root/p202-adaptive-delete.py --database example_database --before 2025-01-01 --timezone UTC --execute --max-batches 1
```

Batch limits can be reduced explicitly:

```bash
python3 /root/p202-adaptive-delete.py --database example_database --before 2025-01-01 --timezone UTC --execute --batch 250 --max-batch 1000
```

`--help` lists the database/date/zone and batch options. Keep the terminal
connected, or run inside an existing `screen`/`tmux` session. Logs flush after
each committed batch or pause. Counts are distinct click IDs processed this
run, not raw rows across the click tables and not a percentage of the backlog.

Ctrl+C/SIGTERM requests a stop **between database calls**. The child database
client is isolated from terminal signals so an in-flight call can finish.
Rerunning the same command selects whatever remains. It does not repeat
committed deletions or require a saved cursor. No automatic reconnect occurs
after a lost connection; inspect the error and rerun.

## Default controller

Starts at **500 IDs**, minimum **100**, maximum **5,000**. Three healthy full
batches taking at most one second each permit 25% growth. A batch over two
seconds halves the next size. Moderate resource pressure reduces the batch by
25% and increases the pause. Between-batch pauses range from 0.5 to 5 seconds.
Severe pressure halves the next size and waits five seconds before resampling;
it starts no deletion transaction until pressure subsides.

| Measurement | Slow down | Pause |
| --- | --- | --- |
| CPU busy (interval) | 65% | 85% |
| CPU time waiting on I/O (interval) | 5% | 15% |
| Available RAM | below max(1 GiB, 15% of RAM) | below max(768 MiB, 10% of RAM) |
| Active swap reads + writes | 0.1 MiB/s | 1 MiB/s |
| One-minute system load | 0.8 × CPU count | 1.25 × CPU count |
| Other running MariaDB threads | max(2, CPU count ÷ 2) | max(4, CPU count) |
| Current InnoDB row-lock waits | — | any |
| New InnoDB log/buffer-pool waits | — | any since previous sample |
| Free data/temp filesystem space | — | below min(5 GiB, max(512 MiB, 5% of filesystem)) |

Swap already allocated is **not** a pause signal; actual counter deltas are.
Filesystem checks cover `datadir`, each `tmpdir`, and `innodb_tmpdir` (or its
fallback). Missing required counters, unreadable filesystem paths, or a detected
database/swap counter reset stop the controller rather than assuming low load.

These are conservative starting thresholds, not measured performance guarantees
for every machine. Linux iowait is an imperfect signal; CPU, memory, swap,
database activity, batch elapsed time, and InnoDB waits also affect decisions.
The script samples host counters; run directly on the database VM, not in a
container whose host counters describe different CPU/memory limits. It does
not measure HTTP response latency or replication lag.

## Deletion contract and errors

The same date rule as `ClickRetention` selects IDs: a click with **any** visit
at/after the cutoff is retained whole. A row exactly at midnight is retained.
Every batch deletes matching IDs from the same 15 click tables, with
`202_clicks` last. Conversion, attribution-credit/journey, customer, and revenue
ledgers stay. A PHPUnit test holds the tool's deletion list to
`ClickRetention::TABLES`.

Before deletion, affected click, journey-conversion, and credit-conversion hours
are marked in `202_attribution_rollup_dirty`, for accounts having attribution
models. The markers and every related-table deletion commit in one transaction.
The normal attribution worker later consumes these markers; summaries can
temporarily require recomputation. This controller does not run that worker.

The tool checks all required tables exist and use InnoDB before deleting.
Only its own connections set `innodb_lock_wait_timeout=3`,
`lock_wait_timeout=5`, and `max_statement_time=15`. Each batch has a SQL exception
handler which rolls back and rethrows. Deadlock/lock-timeout/query-timeout errors
reduce batch size and pause, with at most five consecutive failed attempts.
Other SQL errors stop immediately. Prior committed batches remain deleted.

Resource decisions happen **between transactions**, not during a DELETE.
`max_statement_time` is a best-effort MariaDB query limit, not a guaranteed
wall-clock bound on a whole batch, rollback, or commit. Do not interpret the
controller as a guarantee that tracking will never slow down. Deleted InnoDB
rows can free space for reuse without reducing the table file's size on disk;
this tool does not run `OPTIMIZE TABLE` or repartition anything.

## Verification

```bash
python3 scripts/tests/p202-adaptive-delete-test.py
vendor/bin/phpunit --configuration phpunit.ci.xml --filter AdaptiveDeletionScriptTest tests/Click
```

Source references: [Linux /proc counters](https://www.kernel.org/doc/html/latest/filesystems/proc.html),
[MariaDB query timeout limitations](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/query-optimizations/aborting-statements).

Local validation used MariaDB 11.4.13 with repository-derived schemas, 3,006
expired synthetic IDs across three accounts, cutoff-boundary and repeat-visit
fixtures, and retained ledger rows. The Linux preview, bounded commit, induced
SQL failure/rollback, resumption, exact attribution hour coverage, duplicate-run
refusal, and SIGTERM between transactions were exercised. Docker's shared
filesystem was below the production disk threshold: the unmodified tool
correctly paused; a separate test harness simulated healthy free space to
exercise deletion while keeping the delivered guard unchanged. CPU, memory,
swap, and database measurements came from the test Linux instance. This was
not a production load test or a verification of the live backup.
