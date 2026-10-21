#!/bin/bash
# Live pass: Account › Settings › AutoCron, against the cron ping every
# signed-in page sends.
#
# Every page's footer pings 202-cronjobs/ as it loads. That run stamped
# 202_cronjob_logs like a cron job's, and the AutoCron switch refuses when
# "your own cron job ran in the last five minutes" -- so the ping Settings had
# just sent refused AutoCron on every install, with or without a cron job.
# This pass, signed in:
#
#   1. sends the ping the Settings footer carries, as the browser does (a POST
#      with no body): the cron's minute tier runs, and no cron job run is
#      recorded; the "Cron job last ran" tile still says never
#   2. turns AutoCron on right after it: not refused as unneeded, and the
#      service's answer is said (P202_AUTOCRON_ANSWER=refused requires the
#      refusal of a local address, the AutoCron service's 402)
#   3. runs the cron as a scheduler does, over HTTP and on the command line:
#      each is recorded, and turning AutoCron on is then refused as unneeded,
#      in the page's sentence, with the switch unchanged
#   4. with AutoCron already on, saving On again asks the service again
#      rather than reading AutoCron's own runs as a cron job
#
# It puts the account's auto_cron back on exit. It deletes 202_cronjob_logs
# and the minute tier's 202_cronjobs rows, so the database must be a scratch
# one.
#
# --- environment -------------------------------------------------------
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
# The command that runs 202-cronjobs/index.php on the command line against
# the instance (default: this checkout's, whose 202-config.php must name the
# instance's database).
CRON_CLI=${P202_CRON_CLI:-}
EXPECT_ANSWER=${P202_AUTOCRON_ANSWER:-any}

if [ -z "$P202_PASS" ]; then
    echo "P202_PASS is not set: this pass logs in as $P202_USER and needs its password." >&2
    exit 2
fi
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2
[ -n "$CRON_CLI" ] || CRON_CLI="php $ROOT/202-cronjobs/index.php"

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
mysql_q() { mysql "${MYSQL_ARGS[@]}" "$@"; }
# -----------------------------------------------------------------------

JAR=$(mktemp)
OUT=$(mktemp -d)
PASS=0; FAIL=0
Q() { mysql_q -N "$DB" -e "$1"; }

say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3"; else ok "$3"; fi; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
msgs() { grep -oE '<div class="p202-flash__body">[^<]*' "$1" | sed -E 's/<[^>]*>//' | sed 's/^/    | /'; }

UNNEEDED='Your own cron job ran in the last five minutes, so AutoCron is not needed and was left off.'
# Every sentence the switch says once the service has answered (or not).
ANSWERS=('AutoCron is on.'
         "The AutoCron service refused this install&#039;s address: AutoCron works only for installs at a public web address."
         'The AutoCron service turned the change away just now'
         'The AutoCron service could not be reached')

get() { curl -sS -b "$JAR" -c "$JAR" -L "$BASE/$1" -o "$2" -w '%{http_code}' > "$OUT/.code"; }

# Submit one form of a page the way a browser would (see account-pages.sh).
submit() {
  local page="$1" marker="$2" url="$3" out="$4"; shift 4
  if ! python3 "$HERE/form-body.py" "$page" "$marker" "$@" > "$OUT/.body"; then
    bad "the form carrying '$marker' is on the page"
    : > "$out"
    FIRST_STATUS=missing
    return
  fi
  FIRST_STATUS=$(curl -sS -b "$JAR" -c "$JAR" -o "$out" -D "$OUT/.headers" -w '%{http_code}' \
    --data-binary @"$OUT/.body" -H 'Content-Type: application/x-www-form-urlencoded' "$BASE/$url")
  if [ "$FIRST_STATUS" = "303" ] || [ "$FIRST_STATUS" = "302" ]; then
    local location
    location=$(grep -i '^location:' "$OUT/.headers" | head -1 | sed 's/^[Ll]ocation: *//' | tr -d '\r')
    location="${location%%#*}"
    case "$location" in
      http*) ;;
      /*) location="${BASE%/}$location" ;;
      *) location="$BASE/$location" ;;
    esac
    curl -sS -b "$JAR" -c "$JAR" -L "$location" -o "$out"
  fi
  printf '    first=%s\n' "$FIRST_STATUS"
}

# One of the service's answers is on the page; with P202_AUTOCRON_ANSWER=refused,
# the refusal of this install's (local) address.
said_an_answer() { # $1 = page, $2 = label
  local found=""
  for a in "${ANSWERS[@]}"; do
    if grep -qF -- "$a" "$1"; then found="$a"; fi
  done
  if [ -z "$found" ]; then bad "$2: the service's answer is said"; return; fi
  ok "$2: the service's answer is said ($found)"
  if [ "$EXPECT_ANSWER" = refused ]; then
    eq "$found" "${ANSWERS[1]}" "$2: a local address is refused in the address sentence"
  fi
}

# Settings' "Cron job last ran" tile.
tile() { grep -oE 'Cron job last ran</div><div class="p202-tile__value">[^<]*' "$1" | sed 's/.*>//'; }

say "login"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" \
  --data-urlencode "token=$LT" --data-urlencode "user_name=$P202_USER" \
  --data-urlencode "user_pass=$P202_PASS" -o "$OUT/pl.html"
get "202-account/administration.php" "$OUT/settings.html"
eq "$(cat "$OUT/.code")" "200" "Settings answers"
has "$OUT/settings.html" 'id="autocron"' "signed in, on Settings, with the AutoCron panel"
OWNER=$(Q "SELECT user_id FROM 202_users WHERE user_name='$P202_USER'")
ORIG_AUTOCRON=$(Q "SELECT auto_cron FROM 202_users_pref WHERE user_id=$OWNER")
restore() { Q "UPDATE 202_users_pref SET auto_cron='$ORIG_AUTOCRON' WHERE user_id=$OWNER"; }
trap restore EXIT
Q "UPDATE 202_users_pref SET auto_cron='0' WHERE user_id=$OWNER"

# ─────────────────────────────────────────────────────────────────────
say "the page's ping runs the cron and is not recorded as a cron job's run"
Q "DELETE FROM 202_cronjob_logs; DELETE FROM 202_cronjobs WHERE cronjob_type='secon'"
get "202-account/administration.php" "$OUT/settings.html"
PING=$(grep -oE 'navigator\.sendBeacon\("[^"]+"\)' "$OUT/settings.html" | head -1 | sed -E 's/^navigator\.sendBeacon\("//; s/"\)$//')
if [ -z "$PING" ]; then bad "the footer carries the cron ping"; else ok "the footer carries the cron ping: $PING"; fi
case "$PING" in
  //*) PING_URL="${BASE%%//*}$PING" ;;
  *) PING_URL="$PING" ;;
esac
# As sendBeacon() sends it: a POST with no body, the session cookie and the page as referer.
code=$(curl -sS -X POST -b "$JAR" -H 'Content-Length: 0' -H "Referer: $BASE/202-account/administration.php" \
  -o "$OUT/ping.html" -w '%{http_code}' "$PING_URL")
eq "$code" "200" "the ping answers 200"
eq "$(Q "SELECT COUNT(*) FROM 202_cronjobs WHERE cronjob_type='secon'")" "1" "the ping ran the cron's minute tier"
eq "$(Q "SELECT COUNT(*) FROM 202_cronjob_logs")" "0" "and recorded no cron job run"
get "202-account/administration.php" "$OUT/settings.html"
eq "$(tile "$OUT/settings.html")" "never" "the Cron job last ran tile still says never"

say "AutoCron On, right after the page's ping, is not refused as unneeded"
submit "$OUT/settings.html" autocron "202-account/administration.php" "$OUT/on.html" autocron=1
msgs "$OUT/on.html"
eq "$FIRST_STATUS" "303" "the switch redirects (post-redirect-get)"
hasnt "$OUT/on.html" "$UNNEEDED" "not refused as unneeded"
said_an_answer "$OUT/on.html" "AutoCron On"
if [ "$EXPECT_ANSWER" = refused ]; then
  eq "$(Q "SELECT auto_cron FROM 202_users_pref WHERE user_id=$OWNER")" "0" "a refused address leaves AutoCron off"
fi

# ─────────────────────────────────────────────────────────────────────
say "a scheduler's run over HTTP is recorded, and AutoCron is then refused as unneeded"
Q "UPDATE 202_users_pref SET auto_cron='0' WHERE user_id=$OWNER"
Q "DELETE FROM 202_cronjob_logs; DELETE FROM 202_cronjobs WHERE cronjob_type='secon'"
code=$(curl -sS -o "$OUT/cron.html" -w '%{http_code}' "$BASE/202-cronjobs/index.php")
eq "$code" "200" "the cron answers a crontab's GET"
eq "$(Q "SELECT COUNT(*) FROM 202_cronjob_logs WHERE id = 1 AND last_execution_time >= UNIX_TIMESTAMP() - 60")" "1" "the run is recorded as the cron job's"
get "202-account/administration.php" "$OUT/settings.html"
case "$(tile "$OUT/settings.html")" in
  "just now"|*second*) ok "the tile says it ran just now ($(tile "$OUT/settings.html"))" ;;
  *) bad "the tile says it ran just now (got '$(tile "$OUT/settings.html")')" ;;
esac
submit "$OUT/settings.html" autocron "202-account/administration.php" "$OUT/on2.html" autocron=1
msgs "$OUT/on2.html"
eq "$FIRST_STATUS" "303" "the switch redirects"
has "$OUT/on2.html" "$UNNEEDED" "refused as unneeded, in the page's sentence"
eq "$(Q "SELECT auto_cron FROM 202_users_pref WHERE user_id=$OWNER")" "0" "AutoCron left off"

say "the command line is a scheduler"
Q "DELETE FROM 202_cronjob_logs; DELETE FROM 202_cronjobs WHERE cronjob_type='secon'"
if $CRON_CLI > "$OUT/cli.out" 2> "$OUT/cli.err"; then ok "the cron exits 0 on the command line"; else bad "the cron exits 0 on the command line: $(head -c 300 "$OUT/cli.err")"; fi
eq "$(Q "SELECT COUNT(*) FROM 202_cronjob_logs WHERE id = 1 AND last_execution_time >= UNIX_TIMESTAMP() - 60")" "1" "the run is recorded as the cron job's"

# ─────────────────────────────────────────────────────────────────────
say "with AutoCron already on, saving On asks the service again"
Q "UPDATE 202_users_pref SET auto_cron='1' WHERE user_id=$OWNER"
get "202-account/administration.php" "$OUT/settings.html"
submit "$OUT/settings.html" autocron "202-account/administration.php" "$OUT/on3.html" autocron=1
msgs "$OUT/on3.html"
hasnt "$OUT/on3.html" "$UNNEEDED" "AutoCron's own runs are not read as a cron job"
said_an_answer "$OUT/on3.html" "AutoCron On again"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
