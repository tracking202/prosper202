#!/bin/bash
# Live pass: the re-click backfill as the minutely cron job runs it
# (202-cronjobs/index.php), not as a test calls ReClickBackfill::run().
#
# The cron's call sits inside a try/catch that logs any throwable and moves
# on, so a fault in the wiring (the connection, the deadline, its place among
# the jobs) would read as a walk that never moves. This pass writes three
# clicks re-clicked as rtr.php wrote them before 1.9.79, the second with a
# first visit the walk refuses (click_filtered 2, which no writer stores),
# marks a walk over them and runs the cron job:
#
#   1. the first click is brought to the rule; the walk stops at the second
#      and names it (GET /system/dataengine: stalled_at_click_id);
#   2. with that failure an hour of runs old, the next run steps over it,
#      brings the third over and finishes, and the answer still names the
#      click it stepped over (in_progress false, skipped_clicks 1).
#
# Runs the cron job from this checkout, so its 202-config.php must name the
# instance's database. The instance's own walk is put back on exit.
#
#   P202_BASE=http://127.0.0.1:8301 P202_DB=p202_window_test P202_DB_USER=p202t \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_API_KEY=... \
#   bash tests/live/reclick-backfill-cron.sh

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_API_KEY=${P202_API_KEY:-}
PHP=${P202_PHP:-php}

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2

if [ -z "$P202_API_KEY" ]; then
    echo "P202_API_KEY is not set: this pass reads GET /system/dataengine" >&2
    exit 2
fi
if [ ! -f "$ROOT/202-config.php" ]; then
    echo "$ROOT/202-config.php is missing; this pass runs the checkout's cron job against the instance's database" >&2
    exit 2
fi
CONFIGURED=$("$PHP" -r 'require $argv[1]; echo $dbname ?? "";' "$ROOT/202-config.php" 2>/dev/null)
if [ "$CONFIGURED" != "$DB" ]; then
    echo "this checkout's 202-config.php names database '$CONFIGURED', not P202_DB=$DB" >&2
    exit 2
fi

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST" --protocol=TCP)
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
Q() { mysql "${MYSQL_ARGS[@]}" -N "$DB" -e "$1"; }

PASS=0; FAIL=0
OUT=$(mktemp -d)
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }

USER_ID=$(Q "SELECT user_id FROM 202_users WHERE user_deleted = 0 ORDER BY user_id LIMIT 1")
CAMPAIGN=$(Q "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE user_id = '$USER_ID' ORDER BY aff_campaign_id LIMIT 1")
if [ -z "$USER_ID" ] || [ -z "$CAMPAIGN" ]; then
    echo "the instance needs a user with a campaign (seed it first)" >&2
    exit 2
fi
# Above every click the instance has, so the walk names only these.
FIRST=$(( $(Q "SELECT COALESCE(MAX(click_id), 0) FROM 202_clicks") + 1000 ))
BAD=$((FIRST + 1)); LAST=$((FIRST + 2))
CLICKS="$FIRST,$BAD,$LAST"
AT=$(( $(date +%s) - 86400 ))

Q "CREATE TABLE IF NOT EXISTS p202_live_reclick_walk_kept AS SELECT * FROM 202_reclick_backfill WHERE 1 = 0" || exit 2
Q "DELETE FROM p202_live_reclick_walk_kept; INSERT INTO p202_live_reclick_walk_kept SELECT * FROM 202_reclick_backfill" || exit 2
cleanup() {
    Q "DELETE FROM 202_reclick_backfill; INSERT INTO 202_reclick_backfill SELECT * FROM p202_live_reclick_walk_kept;
       DROP TABLE p202_live_reclick_walk_kept"
    for t in 202_clicks 202_clicks_spy 202_clicks_advance 202_click_latest_visits 202_dataengine 202_attribution_rollup_dirty_clicks; do
        Q "DELETE FROM $t WHERE click_id IN ($CLICKS)"
    done
    rm -rf "$OUT"
}
trap cleanup EXIT

# The minute tier runs once a minute (202_cronjobs); each run here is its own.
cron() {
    Q "DELETE FROM 202_cronjobs WHERE cronjob_type = 'secon' AND cronjob_time > UNIX_TIMESTAMP() - 120"
    rm -f "$ROOT/202-cronjobs/cron.lock"
    (cd "$OUT" && "$PHP" "$ROOT/202-cronjobs/index.php" >"$OUT/cron.out" 2>"$OUT/cron.err")
}
walk() { Q "SELECT CONCAT_WS('/', next_click_id, IFNULL(finished_at, 'open'), reclicked_clicks, IFNULL(failed_click_id, '-'),
           failed_runs, skipped_clicks, IFNULL(last_skipped_click_id, '-')) FROM 202_reclick_backfill"; }
recorded() { Q "SELECT COUNT(*) FROM 202_click_latest_visits WHERE click_id = $1"; }
dataengine() {
    curl -sS -H "Authorization: Bearer $P202_API_KEY" "$BASE/api/v3/system/dataengine" |
        "$PHP" -r '$b = json_decode(stream_get_contents(STDIN), true); $r = $b["data"]["reclick_backfill"] ?? null;
            echo $r === null ? "null" : json_encode([$r["in_progress"], $r["stalled_at_click_id"], $r["skipped_clicks"], $r["last_skipped_click_id"]]);'
}

say "three clicks re-clicked as rtr.php wrote them before 1.9.79, the second refused"
for click in $FIRST $BAD $LAST; do
    filtered=0; [ "$click" = "$BAD" ] && filtered=2
    Q "INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout,
         click_lead, click_filtered, click_bot, click_alp, click_time) VALUES
         ($click, $USER_ID, $CAMPAIGN, 0, 0, 0.25, 3, 1, $filtered, 0, 0, $AT),
         ($click, $USER_ID, $CAMPAIGN, 0, 0, 7.00, 3, 0, 0, 0, 0, $((AT + 3600)))" || exit 2
done
Q "DELETE FROM 202_reclick_backfill;
   INSERT INTO 202_reclick_backfill (backfill_id, next_click_id, through_click_id, started_at) VALUES (1, $FIRST, $LAST, UNIX_TIMESTAMP())" || exit 2

say "a run of the cron job stops at the click it cannot bring over"
cron
eq "$?" 0 "the cron job exits 0"
eq "$(walk)" "$BAD/open/1/$BAD/1/0/-" "the first is brought over; the walk is at the second, failed once"
eq "$(recorded $FIRST)/$(recorded $BAD)" "1/0" "the first has its latest visit recorded, the second not"
eq "$(Q "SELECT GROUP_CONCAT(CONCAT(click_cpc, ':', click_lead) ORDER BY click_time) FROM 202_clicks WHERE click_id = $FIRST")" \
    "0.25000:1,0.25000:1" "its visits carry the first visit's cost and the click's lead"
eq "$(dataengine)" "[true,$BAD,0,null]" "GET /system/dataengine names the click it is stopped at"
eq "$(grep -c "p202 re-click backfill: click $BAD " "$OUT/cron.err")" 1 "the error log names it"

say "an hour of runs later it is stepped over, and the walk finishes"
Q "UPDATE 202_reclick_backfill SET failed_runs = 9, failing_since = UNIX_TIMESTAMP() - 3700"
cron
eq "$?" 0 "the cron job exits 0"
eq "$(walk | cut -d/ -f2 | sed 's/^[0-9]*$/done/')" "done" "the walk is finished"
eq "$(walk | cut -d/ -f3-)" "2/-/0/1/$BAD" "the third is brought over, the second counted as stepped over"
eq "$(recorded $BAD)/$(recorded $LAST)" "0/1" "the stepped-over click keeps no record: it counts at each visit"
eq "$(dataengine)" "[false,null,1,$BAD]" "GET /system/dataengine says it finished, stepping over that click"
eq "$(grep -c "p202 re-click backfill: click $BAD .*stepped over after failing with its own error in 10 runs" "$OUT/cron.err")" 1 "the error log says so"

printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
