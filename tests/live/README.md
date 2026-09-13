# Live passes

What a page answers over HTTP, against a running instance and a real
database. These run **locally, on demand** — like `tests/browser`, they are
not wired into CI and nothing in the pipeline depends on them.

They sit between the PHP suites and the browser passes:

| | answers | lives in |
|---|---|---|
| PHPUnit | what the code computes from given input | `tests/<Area>/` |
| **live pass** | **what the page returns, and whether it matches the database** | **here** |
| browser pass | layout, theme, and the JavaScript a request never runs | `tests/browser/` |

The middle row is the one a unit test cannot reach: a report's numbers are
only correct relative to the rows that produced them, and the seam between
the page controller, the API controller and MySQL is exactly where a mock
stops proving anything (CLAUDE.md error pattern #9).

## Running

You need a running instance and a **scratch** database — these passes
`TRUNCATE` the attribution tables and rewrite the account's currency. Each
script refuses a database whose name does not read as disposable, so
pointing one at a real install fails with a sentence rather than deleting
data.

```sh
P202_BASE=http://127.0.0.1:8097 \
P202_DB=p202_live P202_DB_USER=root \
P202_USER=evalci P202_PASS=... \
bash tests/live/analyze-mobile-apps.sh
```

| variable | default | |
|---|---|---|
| `P202_BASE` | `http://127.0.0.1:8097` | where the instance answers |
| `P202_DB` | `p202_live` | scratch database; truncated |
| `P202_DB_USER` / `P202_DB_PASS` | `root` / empty | MySQL credentials |
| `P202_USER` / `P202_PASS` | `evalci` / **required** | the account to log in as |

Every variable is exported, because `analyze-mobile-apps.sh` runs the seeder
as a child process — an unexported one would leave the child on its own
defaults, truncating a different database than the pass then reads.

`tests/fixtures/agent-eval/ci/install-instance.sh` stands up an instance from
nothing if you do not have one.

## The passes

| script | |
|---|---|
| `seed-mobile-apps.sh` | postbacks spread wide enough that every grouping, signature class and page of the pager has something real to show. Run by the analyze pass; standalone for the browser pass. |
| `analyze-mobile-apps.sh` | Analyze › Mobile Apps: the three views, every grouping, the filters and the window each preset means, the totals against `SELECT COUNT(*)`, the CSV, the pager, and the permission gate. |
| `setup-mobile-apps.sh` | Setup › Mobile Apps: registering, editing and removing an app, the conversion-value rules, the currency, and CSRF. |

Each prints `N passed, M failed` and exits non-zero on a failure.
