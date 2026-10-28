#!/bin/bash
# Live pass: the GeoIP database's age and its refresh (Prosper202\GeoIp),
# against tests/fixtures/geoip/fake_maxmind.py standing in for MaxMind.
#
#   1. Settings, GET /system/info, GET /system/health (and p202 system info
#      when P202_BIN is set) show the shipped database's build date
#      (2018-07-03) and call it stale, with the refresh off
#   2. the MaxMind account ID and licence key are saved through Settings'
#      form, shown afterwards only as Stored, and never in the page or the API
#   3. the cron job's hourly tier, run on the command line, refreshes: the
#      file is the new build, the old one is kept as .previous, the
#      credentials went with the permalink request only, and Settings, system
#      info and health show the new date with no warning
#   4. Refresh now finds the same archive and asks for nothing more
#   5. a bad checksum keeps the file and says why, in Settings and the API
#   6. a wrong licence key is said as MaxMind says it, and keeps the file
#   7. removing the account turns the refresh off and keeps the file
#
# The instance must have been started with
#   P202_GEOIP_DOWNLOAD_ORIGIN=https://127.0.0.1:$P202_FAKE_MAXMIND_PORT
#   P202_GEOIP_CA_FILE=$P202_GEOIP_TLS_DIR/cert.pem
# in its environment (install-instance.sh passes its own on to php -S). The
# certificate need not exist yet: this pass makes it, and the instance reads
# the file only when a refresh runs. The pass runs the cron job from this
# checkout, so its 202-config.php must name the instance's database, and the
# instance must serve this checkout: the refresh replaces this checkout's
# 202-config/geo/GeoLite2-City.mmdb, which the pass puts back on exit (and
# checks). It empties 202_geoip_refresh.
#
# --- environment -------------------------------------------------------
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
P202_API_KEY=${P202_API_KEY:-}
FAKE_PORT=${P202_FAKE_MAXMIND_PORT:-8151}
TLS_DIR=${P202_GEOIP_TLS_DIR:-}
CLI=${P202_BIN:-}

if [ -z "$P202_PASS" ] || [ -z "$P202_API_KEY" ] || [ -z "$TLS_DIR" ]; then
    echo "P202_PASS, P202_API_KEY and P202_GEOIP_TLS_DIR are required: this pass signs in as $P202_USER, reads the API as the admin," >&2
    echo "and serves the fake MaxMind with the certificate the instance was told to trust (P202_GEOIP_CA_FILE=\$P202_GEOIP_TLS_DIR/cert.pem)." >&2
    exit 2
fi
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "$DB_HOST" ] && MYSQL_ARGS+=(-h "$DB_HOST")
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
Q() { mysql "${MYSQL_ARGS[@]}" -N "$DB" -e "$1"; }
# -----------------------------------------------------------------------

ACCOUNT=987654
LICENSE=livePass_0123456789abcdefghijklmnop_mmk
ORIGIN="https://127.0.0.1:$FAKE_PORT"
DATABASE="$ROOT/202-config/geo/GeoLite2-City.mmdb"
OUT=$(mktemp -d)
JAR="$OUT/jar"
SERVE="$OUT/serve"
mkdir -p "$SERVE" "$TLS_DIR"
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3"; else ok "$3"; fi; }
msgs() { grep -oE '<div class="p202-flash__body">[^<]*' "$1" | sed -E 's/<[^>]*>//' | sed 's/^/    | /'; }

# The bundled file, put back on exit whatever happened; a failed restore fails the pass.
KEEP=$(mktemp)
cp -p "$DATABASE" "$KEEP" || { echo "could not back up $DATABASE" >&2; exit 2; }
cmp -s "$DATABASE" "$KEEP" || { echo "the backup of $DATABASE does not match it" >&2; exit 2; }
FAKE_PID=
restore() {
    [ -n "$FAKE_PID" ] && kill "$FAKE_PID" 2>/dev/null
    cp -p "$KEEP" "$DATABASE.restore" && mv "$DATABASE.restore" "$DATABASE"
    rm -f "$DATABASE.previous" "$ROOT/202-config/geo/.GeoLite2-City.mmdb.lock" "$ROOT"/202-config/geo/.GeoLite2-City.mmdb.*.part
    Q "DELETE FROM 202_geoip_refresh" 2>/dev/null
    if cmp -s "$DATABASE" "$KEEP"; then
        printf '\n  restored %s\n' "$DATABASE"
    else
        printf '\n  \033[31mFAIL\033[0m %s was not restored; the original is %s\n' "$DATABASE" "$KEEP"
        exit 1
    fi
    rm -f "$KEEP"
}
trap restore EXIT

epoch() { (cd "$ROOT" && php -r 'require "vendor/autoload.php"; $r = new MaxMind\Db\Reader($argv[1]); echo $r->metadata()->buildEpoch;' -- "$1"); }
# A GeoLite2-shaped archive of MaxMind's test database built at $2, at $1.
archive() { (cd "$ROOT" && php -r 'require "vendor/autoload.php"; file_put_contents($argv[1], Tests\GeoIp\GeoIpFixture::archive(Tests\GeoIp\GeoIpFixture::mmdb((int) $argv[2])));' -- "$1" "$2"); }
serve() { # $1 = archive, $2 = the checksum to publish (its own when empty)
    cp "$1" "$SERVE/GeoLite2-City.tar.gz"
    printf '%s  GeoLite2-City_20261022.tar.gz\n' "${2:-$(sha256sum "$1" | cut -d' ' -f1)}" > "$SERVE/GeoLite2-City.tar.gz.sha256"
}
requests() { curl -sS --noproxy '*' --cacert "$TLS_DIR/cert.pem" "$ORIGIN/control/requests"; }
reset_requests() { curl -sS --noproxy '*' --cacert "$TLS_DIR/cert.pem" -X POST "$ORIGIN/control/reset" > /dev/null; }
api() { # $1 = path; the body lands in $OUT/api.json, the status is printed
    curl -sS --noproxy '*' -H "Authorization: Bearer $P202_API_KEY" -o "$OUT/api.json" -w '%{http_code}' "$BASE/api/v3$1"
}
field() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); d=d.get('data', d); print($1)" "$OUT/api.json" 2>&1; }
get() { curl -sS --noproxy '*' -b "$JAR" -c "$JAR" -L "$BASE/$1" -o "$2"; }
submit() { # $1 page, $2 marker, $3 out, then name=value overrides
    local page="$1" marker="$2" out="$3"; shift 3
    if ! python3 "$HERE/form-body.py" "$page" "$marker" "$@" > "$OUT/.body"; then
        bad "the form carrying '$marker' is on the page"; : > "$out"; return
    fi
    curl -sS --noproxy '*' -b "$JAR" -c "$JAR" -L -o "$out" --data-binary @"$OUT/.body" \
        -H 'Content-Type: application/x-www-form-urlencoded' "$BASE/202-account/administration.php"
}
cron() { # the cron job on the command line, both tiers due, pointed at the fake
    Q "DELETE FROM 202_cronjobs WHERE cronjob_type IN ('secon', 'hour', 'hourl')"
    (cd "$ROOT" && P202_GEOIP_DOWNLOAD_ORIGIN="$ORIGIN" P202_GEOIP_CA_FILE="$TLS_DIR/cert.pem" php 202-cronjobs/index.php) > "$OUT/cron.out" 2> "$OUT/cron.err"
}
panel() { python3 - "$1" <<'PY'
import re, sys, html
page = open(sys.argv[1]).read()
m = re.search(r'<section class="p202-panel" id="geoip">(.*?)</section>', page, re.S)
text = re.sub(r'<[^>]+>', ' ', m.group(1)) if m else ''
print(re.sub(r'\s+', ' ', html.unescape(text)).strip())
PY
}

python3 -I "$ROOT/tests/fixtures/geoip/fake_maxmind.py" --port "$FAKE_PORT" --tls-dir "$TLS_DIR" --serve-dir "$SERVE" \
    --account "$ACCOUNT" --license "$LICENSE" > "$OUT/fake.out" 2> "$OUT/fake.err" &
FAKE_PID=$!
for _ in $(seq 1 50); do grep -q READY "$OUT/fake.out" 2>/dev/null && break; sleep 0.2; done
grep -q READY "$OUT/fake.out" || { echo "the fake MaxMind server did not start: $(cat "$OUT/fake.err")" >&2; exit 2; }
Q "DELETE FROM 202_geoip_refresh"

say "login"
curl -sS --noproxy '*' -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(grep -oE 'name="token" value="[^"]+"' "$OUT/login.html" | head -1 | sed 's/.*value="//; s/"//')
curl -sS --noproxy '*' -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" --data-urlencode "token=$LT" \
    --data-urlencode "user_name=$P202_USER" --data-urlencode "user_pass=$P202_PASS" -o "$OUT/pl.html"
get "202-account/administration.php" "$OUT/s0.html"
has "$OUT/s0.html" 'id="geoip"' "signed in, on Settings, with the GeoIP database panel"

# ─────────────────────────────────────────────────────────────────────
say "1. the shipped database's age, everywhere it is shown"
P0=$(panel "$OUT/s0.html"); echo "    | $P0" | cut -c1-240
case "$P0" in *"Stale"*"Built Jul 3, 2018"*) ok "Settings: built Jul 3, 2018, stale" ;; *) bad "Settings: built Jul 3, 2018, stale" ;; esac
case "$P0" in *"Off Refresh no MaxMind account is stored"*) ok "Settings: the refresh is off" ;; *) bad "Settings: the refresh is off" ;; esac
eq "$(api /system/info)" 200 "GET /system/info"
eq "$(field "d['geoip_database']['state'], d['geoip_database']['built_on'], d['geoip_database']['built_at']")" "stale 2018-07-03 1530653216" \
   "system info: geoip_database stale, built 2018-07-03"
eq "$(field "d['geoip_refresh']['configured'], d['geoip_refresh']['next_due_at']")" "False None" "system info: no refresh configured"
eq "$(api /system/health)" 200 "GET /system/health"
eq "$(field "d['status'], d['geoip_database']['state'], len(d['warnings']), 'path' in d['geoip_database']")" "healthy stale 1 False" \
   "health: still healthy, one warning, the state and no path"
get "202-cronjobs/health.php" "$OUT/api.json"
eq "$(field "d['geoipDatabase']['state'], d['geoipDatabase']['built_on'], any('GeoIP database was built on 2018-07-03' in r for r in d['recommendations'])")" \
   "stale 2018-07-03 True" "202-cronjobs/health.php: stale, built 2018-07-03, and a recommendation"
if [ -n "$CLI" ]; then
    CLI_HOME="$OUT/cli-home"; mkdir -p "$CLI_HOME"
    HOME="$CLI_HOME" "$CLI" config set-url "$BASE" > /dev/null 2>&1
    HOME="$CLI_HOME" "$CLI" config set-key "$P202_API_KEY" > /dev/null 2>&1
    HOME="$CLI_HOME" "$CLI" system info --json > "$OUT/api.json" 2> "$OUT/cli.err"
    eq "$(field "d['geoip_database']['state'], d['geoip_database']['built_on']")" "stale 2018-07-03" "p202 system info: stale, built 2018-07-03"
else
    echo "  NOT RUN p202 system info: set P202_BIN to the Go CLI"
fi

# ─────────────────────────────────────────────────────────────────────
say "2. the account is saved through the form, and never shown again"
submit "$OUT/s0.html" geoip_action=save "$OUT/s1.html" geoip_account_id="$ACCOUNT" geoip_license_key="$LICENSE"
msgs "$OUT/s1.html"
has "$OUT/s1.html" 'Saved. The cron job refreshes the GeoIP database within the hour' "Saved, in the page's sentence"
eq "$(Q "SELECT account_id, license_key FROM 202_geoip_refresh WHERE refresh_id = 1" | tr '\t' ' ')" "$ACCOUNT $LICENSE" "both are stored"
hasnt "$OUT/s1.html" "$LICENSE" "the page never carries the licence key"
hasnt "$OUT/s1.html" "value=\"$ACCOUNT\"" "nor the account ID in a field"
has "$OUT/s1.html" 'Stored</span>' "it says Stored instead"
api /system/info > /dev/null
hasnt "$OUT/api.json" "$LICENSE" "GET /system/info never carries the licence key"
eq "$(field "d['geoip_refresh']['configured'], d['geoip_refresh']['license_key_stored'], d['geoip_refresh']['account_id_stored']")" "True True True" \
   "system info: configured, both stored"
submit "$OUT/s1.html" geoip_action=save "$OUT/s1b.html" geoip_account_id="12ab" geoip_license_key=""
has "$OUT/s1b.html" 'Enter the account ID MaxMind shows on your account page' "a malformed account ID is refused, naming the field"
eq "$(Q "SELECT account_id FROM 202_geoip_refresh WHERE refresh_id = 1")" "$ACCOUNT" "and the stored one is kept"

# ─────────────────────────────────────────────────────────────────────
say "3. the cron job's hourly tier refreshes"
NOW=$(date +%s)
NEW=$((NOW - 2 * 86400))
archive "$OUT/new.tar.gz" "$NEW"
serve "$OUT/new.tar.gz"
reset_requests
OLD_SHA=$(sha256sum "$DATABASE" | cut -d' ' -f1)
cron
if [ -s "$OUT/cron.err" ]; then echo "    cron stderr: $(head -c 300 "$OUT/cron.err")"; fi
has "$OUT/cron.out" 'GeoIP database: The GeoIP database was refreshed' "the cron job says it refreshed"
eq "$(epoch "$DATABASE")" "$NEW" "the file lookups read is the new build"
eq "$(sha256sum "$DATABASE.previous" 2>/dev/null | cut -d' ' -f1)" "$OLD_SHA" "the one it replaced is kept as .previous"
eq "$(stat -c '%a' "$DATABASE" | cut -c2-)" "$(stat -c '%a' "$KEEP" | cut -c2-)" "with the group and world bits the shipped file had"
eq "$(requests | python3 -c "import json,sys; print(' '.join(('A' if r['authorization'] else '-') + r['path'].split('/')[1] for r in json.load(sys.stdin)['requests']))")" \
   "Ageoip -r2 Ageoip -r2" "the credentials went to the permalinks only, never to the presigned URLs"
if ls "$ROOT"/202-config/geo/.GeoLite2-City.mmdb.*.part > /dev/null 2>&1; then bad "no temporary file is left beside it"; else ok "no temporary file is left beside it"; fi
get "202-account/administration.php" "$OUT/s2.html"
P2=$(panel "$OUT/s2.html"); echo "    | $P2" | cut -c1-240
case "$P2" in *"Current"*"Built $(date -u -d "@$NEW" '+%b %-d, %Y')"*) ok "Settings: current, built two days ago" ;; *) bad "Settings: current, built two days ago" ;; esac
case "$P2" in *"Refreshed Last refresh"*) ok "Settings: the last refresh refreshed" ;; *) bad "Settings: the last refresh refreshed" ;; esac
api /system/info > /dev/null
eq "$(field "d['geoip_database']['state'], d['geoip_database']['built_at'], d['geoip_database']['age_days'], d['geoip_refresh']['last_outcome']")" \
   "current $NEW 2 refreshed" "system info: current, the new build, two days old, refreshed"
api /system/health > /dev/null
eq "$(field "d['geoip_database']['state'], d['geoip_database']['built_at'], d['warnings']")" "current $NEW []" "health: current, the new date, no warning"
get "202-cronjobs/health.php" "$OUT/api.json"
eq "$(field "d['geoipDatabase']['state'], d['geoipDatabase']['built_at'], any('GeoIP' in r for r in d['recommendations'])")" \
   "current $NEW False" "202-cronjobs/health.php: current, the new date, no GeoIP recommendation"
if [ -n "$CLI" ]; then
    HOME="$CLI_HOME" "$CLI" system info --json > "$OUT/api.json" 2> "$OUT/cli.err"
    eq "$(field "d['geoip_database']['state'], d['geoip_database']['built_at']")" "current $NEW" "p202 system info: current, the new build"
fi

# ─────────────────────────────────────────────────────────────────────
say "4. Refresh now: the same archive is current, and only its checksum is asked"
reset_requests
submit "$OUT/s2.html" geoip_action=refresh "$OUT/s3.html"
msgs "$OUT/s3.html"
has "$OUT/s3.html" 'The GeoIP database is current' "Settings says it is current"
eq "$(requests | python3 -c "import json,sys; print(len(json.load(sys.stdin)['requests']))")" "2" "the checksum and its redirect, no archive"

# ─────────────────────────────────────────────────────────────────────
say "5. a bad checksum keeps the file and says why"
INSTALLED_SHA=$(sha256sum "$DATABASE" | cut -d' ' -f1)
archive "$OUT/newer.tar.gz" "$((NOW - 86400))"
serve "$OUT/newer.tar.gz" "$(printf '0%.0s' $(seq 1 64))"
submit "$OUT/s3.html" geoip_action=refresh "$OUT/s4.html"
msgs "$OUT/s4.html"
has "$OUT/s4.html" 'The GeoIP database was not refreshed. The download&#039;s SHA-256 begins' "Settings says it was not refreshed, and why"
eq "$(sha256sum "$DATABASE" | cut -d' ' -f1)" "$INSTALLED_SHA" "the installed file is unchanged"
P4=$(panel "$OUT/s4.html")
case "$P4" in *"Failed Last refresh"*"The last refresh failed: The download's SHA-256 begins"*) ok "the panel shows the failure with its reason" ;; *) bad "the panel shows the failure with its reason" ;; esac
api /system/info > /dev/null
eq "$(field "d['geoip_refresh']['last_outcome'], 'SHA-256' in (d['geoip_refresh']['last_error'] or ''), d['geoip_database']['built_at']")" \
   "failed True $NEW" "system info: failed, with the reason, and the database still the installed build"

# ─────────────────────────────────────────────────────────────────────
say "6. a wrong licence key is said as MaxMind says it"
serve "$OUT/newer.tar.gz"
submit "$OUT/s4.html" geoip_action=save "$OUT/s5.html" geoip_license_key="wrongKey_000000000000"
submit "$OUT/s5.html" geoip_action=refresh "$OUT/s6.html"
msgs "$OUT/s6.html"
has "$OUT/s6.html" 'MaxMind did not accept the account ID and licence key (401: &quot;Your account ID or license key could not be authenticated.&quot;)' \
    "Settings gives MaxMind's refusal and what to check"
eq "$(sha256sum "$DATABASE" | cut -d' ' -f1)" "$INSTALLED_SHA" "the installed file is unchanged"

# ─────────────────────────────────────────────────────────────────────
say "7. removing the account turns the refresh off and keeps the file"
submit "$OUT/s6.html" geoip_action=forget "$OUT/s7.html"
msgs "$OUT/s7.html"
has "$OUT/s7.html" 'were removed, so the GeoIP database is no longer refreshed' "Settings says so"
eq "$(Q "SELECT CONCAT('[', account_id, '][', license_key, ']') FROM 202_geoip_refresh WHERE refresh_id = 1")" "[][]" "both are cleared"
P7=$(panel "$OUT/s7.html")
case "$P7" in *"Off Refresh no MaxMind account is stored"*) ok "the panel says the refresh is off" ;; *) bad "the panel says the refresh is off" ;; esac
eq "$(sha256sum "$DATABASE" | cut -d' ' -f1)" "$INSTALLED_SHA" "the installed file stays"
reset_requests
cron
hasnt "$OUT/cron.out" 'GeoIP database:' "the cron job no longer refreshes"
eq "$(requests | python3 -c "import json,sys; print(len(json.load(sys.stdin)['requests']))")" "0" "and asks MaxMind nothing"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
