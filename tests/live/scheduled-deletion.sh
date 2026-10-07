#!/bin/bash
# Live pass for Settings › Click data › "Delete click data from before"
# (202-account/administration.php), its API (POST /system/retention/
# delete-before) and the cron job that carries it out (ClearOldClicks() in
# 202-cronjobs/index.php, Prosper202\Click\ClickRetention): a click is deleted
# when every one of its rows is from before the day, whatever its id.
#
# The deletion used to store MAX(click_id) of the clicks at or before the day
# and delete every click below it. Ids are not in time order, and a rotator
# re-click (rtr.php ?lpr=) gives an old click a new row at the time of the
# re-click, so it deleted a click visited again after the day (whole, the new
# row with it) and kept the newest click from before the day. Here:
#
#   A  a redirector click, moved back before the day, then re-clicked now
#   B  a direct-link click after A, moved back before the day — the newest
#      click from before it, which the id marker named and kept
#   C  a direct-link click now
#
# and only B may go. The day is in 2001, so nothing the instance recorded
# itself is that old, and the pass refuses to run if anything is: the
# deletion is install-wide.
#
# It creates its own campaign, rotator and trackers (named "sd-pass <time>"),
# fires real clicks, moves two of them back in time in the database, schedules
# through the page, previews and reads the state through the API, runs the
# cron job over HTTP, and puts user 1's schedule back as it found it.
#
#   P202_BASE=http://127.0.0.1:8127 P202_DB=p202_wn_live P202_DB_USER=p202 \
#   P202_DB_PASS=... P202_DB_HOST=127.0.0.1 P202_USER=evalci P202_PASS=... \
#   P202_API_KEY=... bash tests/live/scheduled-deletion.sh

BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
P202_API_KEY=${P202_API_KEY:-}

if [ -z "$P202_API_KEY" ] || [ -z "$P202_PASS" ]; then
    echo "P202_API_KEY and P202_PASS must be set: this pass creates records through the API and schedules through the page as $P202_USER." >&2
    exit 2
fi
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST" --protocol=TCP)
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
Q() { mysql "${MYSQL_ARGS[@]}" -N "$DB" -e "$1"; }

OUT=$(mktemp -d)
JAR="$OUT/jar"
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }

# api METHOD PATH [JSON-BODY] — the body lands in $OUT/body; the status is printed.
api() {
    local args=(-s -o "$OUT/body" -w '%{http_code}' -X "$1" -H "Authorization: Bearer $P202_API_KEY")
    [ -n "${3:-}" ] && args+=(-H 'Content-Type: application/json' --data "$3")
    curl "${args[@]}" "$BASE/api/v3$2"
}
# field EXPR — a value out of the last API body (d = the JSON).
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); v=$1; print('' if v is None else v)" "$OUT/body" 2>/dev/null; }
must() { # status what
    case "$1" in 2??) ;; *) echo "$2 failed: HTTP $1 $(head -c 300 "$OUT/body")" >&2; exit 2 ;; esac
}
UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
click() { # url address — fires one click from that address
    curl -sS -o /dev/null -A "$UA" -H "X-Forwarded-For: $2" "$1"
}

DAY=2001-02-01
OWNER=$(Q "SELECT user_id FROM 202_users WHERE user_name='$P202_USER'")
ZONE=$(Q "SELECT IFNULL(NULLIF(user_timezone, ''), 'UTC') FROM 202_users WHERE user_id=$OWNER")
CUTOFF=$(php -r '$d = DateTimeImmutable::createFromFormat("!Y-m-d", $argv[1], new DateTimeZone($argv[2])); echo $d->getTimestamp();' "$DAY" "$ZONE")
OLD=$(Q "SELECT COUNT(*) FROM 202_clicks WHERE click_time < $((CUTOFF + 86400))")
if [ "$OLD" != "0" ]; then
    echo "this instance already has $OLD click row(s) from before $DAY; the deletion is install-wide, so the pass will not run here" >&2
    exit 2
fi
ORIG=$(Q "SELECT CONCAT_WS('|', IFNULL(user_delete_data_before, 'NULL'), IFNULL(user_delete_data_clickid, 'NULL')) FROM 202_users_pref WHERE user_id=1")
A=''; B=''
restore() {
    local before marker
    IFS='|' read -r before marker <<< "$ORIG"
    Q "UPDATE 202_users_pref SET user_delete_data_before = $before, user_delete_data_clickid = $marker WHERE user_id = 1"
    # A row this pass moved back and the deletion kept (A's) goes back to
    # the present, so the next run finds nothing from before the day.
    for id in $A $B; do
        Q "UPDATE 202_clicks SET click_time = UNIX_TIMESTAMP() - 3 WHERE click_id = $id AND click_time < $((CUTOFF + 86400))"
    done
}
trap restore EXIT

TAG="sd-pass $(date +%s)"
say "setup: a campaign, a rotator, a redirector tracker and a direct-link tracker ($TAG)"
must "$(api POST /aff-networks "{\"aff_network_name\":\"$TAG\"}")" "category"
NET=$(field "d['data']['aff_network_id']")
must "$(api POST /campaigns "{\"aff_campaign_name\":\"$TAG\",\"aff_campaign_url\":\"https://example.com/sd\",\"aff_campaign_payout\":\"1\",\"aff_network_id\":$NET}")" "campaign"
CAMP=$(field "d['data']['aff_campaign_id']")
must "$(api POST /rotators "{\"name\":\"$TAG\",\"default_campaign\":$CAMP}")" "rotator"
ROT=$(field "d['data']['id']")
must "$(api POST /trackers "{\"aff_campaign_id\":$CAMP,\"rotator_id\":$ROT}")" "redirector tracker"
must "$(api GET "/trackers/$(field "d['data']['tracker_id']")/url")" "redirector tracker url"
RTR_URL=$(field "d['data']['direct_url']")
case "$RTR_URL" in *rtr.php*) ;; *) echo "the redirector tracker's URL is not rtr.php: $RTR_URL" >&2; exit 2 ;; esac
must "$(api POST /trackers "{\"aff_campaign_id\":$CAMP}")" "direct-link tracker"
must "$(api GET "/trackers/$(field "d['data']['tracker_id']")/url")" "direct-link tracker url"
DL_URL=$(field "d['data']['direct_url']")
case "$DL_URL" in *dl.php*) ;; *) echo "the direct-link tracker's URL is not dl.php: $DL_URL" >&2; exit 2 ;; esac
OTHERS=$(Q "SELECT COUNT(DISTINCT click_id) FROM 202_clicks")

say "clicks: A visited again, A's first row and B moved back before $DAY, C now"
N=$((RANDOM % 200 + 20))
ADDR_A="198.51.100.$N"
click "$RTR_URL&t202kw=sd-pass" "$ADDR_A"
A=$(Q "SELECT MAX(click_id) FROM 202_clicks WHERE rotator_id=$ROT")
click "$DL_URL&t202kw=sd-pass" "198.51.100.$((N + 1))"
B=$(Q "SELECT MAX(click_id) FROM 202_clicks WHERE aff_campaign_id=$CAMP AND rotator_id=0")
# The re-click finds the address's last click within 30 days, so it comes
# before A is moved back; a second later, so its row is a second row (the
# key is click_id and click_time).
sleep 1
click "$RTR_URL&t202kw=sd-pass&lpr=1" "$ADDR_A"
Q "UPDATE 202_clicks SET click_time = $((CUTOFF - 20 * 86400)) WHERE click_id = $A ORDER BY click_time LIMIT 1"
Q "UPDATE 202_clicks SET click_time = $((CUTOFF - 10 * 86400)) WHERE click_id = $B"
click "$DL_URL&t202kw=sd-pass" "198.51.100.$((N + 2))"
C=$(Q "SELECT MAX(click_id) FROM 202_clicks WHERE aff_campaign_id=$CAMP AND rotator_id=0")
eq "$(Q "SELECT COUNT(*) FROM 202_clicks WHERE click_id = $A AND click_time < $CUTOFF")" "1" "A has a row from before the day"
eq "$([ "$A" -lt "$B" ] && [ "$B" -lt "$C" ] && echo ordered)" "ordered" "ids in firing order: A=$A < B=$B < C=$C"
eq "$(Q "SELECT COUNT(*) FROM 202_clicks WHERE click_id = $A AND click_time >= $CUTOFF")" "1" \
  "the re-click gave A a row after the day (else this pass proves nothing about re-clicks)"
eq "$(Q "SELECT MAX(click_id) FROM 202_clicks WHERE click_time <= $CUTOFF")" "$B" \
  "B is the newest click from before the day — the id the old marker stored, and kept"

say "the API previews by the rule the cron job deletes by"
must "$(api POST "/system/retention/delete-before?dry_run=1" "{\"before\":\"$DAY\"}")" "preview"
eq "$(field "d['data']['cutoff_time']")" "$CUTOFF" "cutoff_time is midnight that begins $DAY in $ZONE"
eq "$(field "d['data']['clicks']")" "1" "one click: B (A was visited again after the day)"
eq "$(field "d['data']['rows']['202_clicks']")" "1" "B's one 202_clicks row"
eq "$(field "d['data'].get('through_click_id', 'absent')")" "absent" "no id in the answer"

say "the page schedules the day"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" --data-urlencode "token=$LT" \
  --data-urlencode "user_name=$P202_USER" --data-urlencode "user_pass=$P202_PASS" -o /dev/null
curl -sS -b "$JAR" -c "$JAR" -L "$BASE/202-account/administration.php" -o "$OUT/ad.html"
if ! python3 "$HERE/form-body.py" "$OUT/ad.html" database_management database_management="$DAY" > "$OUT/form"; then
    bad "the deletion form is on the page"
    exit 1
fi
curl -sS -b "$JAR" -c "$JAR" -L -o "$OUT/ad-done.html" --data-binary @"$OUT/form" \
  -H 'Content-Type: application/x-www-form-urlencoded' "$BASE/202-account/administration.php"
has "$OUT/ad-done.html" "Click data from before Feb 1, 2001 is scheduled for deletion." "the page says it is scheduled"
has "$OUT/ad-done.html" "Currently set: clicks from before Feb 1, 2001 are deleted." "and shows what is set"
eq "$(Q "SELECT CONCAT_WS('|', IFNULL(user_delete_data_before, 'NULL'), IFNULL(user_delete_data_clickid, 'NULL')) FROM 202_users_pref WHERE user_id=1")" \
  "$CUTOFF|NULL" "user 1 holds the day's time, and no id"
must "$(api GET /system/retention)" "retention state"
eq "$(field "d['data']['scheduled_deletion']['cutoff_time']")|$(field "d['data']['scheduled_deletion']['clicks_remaining']")" "$CUTOFF|1" \
  "GET /system/retention reads the same schedule, one click to go"

say "the cron job deletes B, and only B"
curl -sS -o "$OUT/cron.html" "$BASE/202-cronjobs/index.php"
has "$OUT/cron.html" "Clear Old Clicks: 1 click(s) from before" "the cron job reports one click"
eq "$(Q "SELECT COUNT(*) FROM 202_clicks WHERE click_id = $B")|$(Q "SELECT COUNT(*) FROM 202_clicks_advance WHERE click_id = $B")|$(Q "SELECT COUNT(*) FROM 202_dataengine WHERE click_id = $B")" \
  "0|0|0" "B is gone from the click tables"
eq "$(Q "SELECT COUNT(*) FROM 202_clicks WHERE click_id = $A")" "2" "A, visited again after the day, keeps both rows"
eq "$(Q "SELECT COUNT(*) FROM 202_clicks_advance WHERE click_id = $A")" "1" "and its other tables' row"
eq "$(Q "SELECT COUNT(*) FROM 202_clicks WHERE click_id = $C")" "1" "C, from after the day, is kept"
eq "$(Q "SELECT COUNT(DISTINCT click_id) FROM 202_clicks")" "$((OTHERS + 2))" "every other click on the install is kept"
must "$(api GET /system/retention)" "retention state"
eq "$(field "d['data']['scheduled_deletion']['clicks_remaining']")" "0" "nothing is left to delete"
curl -sS -o "$OUT/cron2.html" "$BASE/202-cronjobs/index.php"
if grep -qF "Clear Old Clicks" "$OUT/cron2.html"; then bad "a second run deletes nothing more"; else ok "a second run deletes nothing more"; fi

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
