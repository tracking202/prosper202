#!/bin/bash
# A sync calls its two profiles' URLs only where this server may (4SRQ):
#
#   - POST /sync/jobs and /sync/plan refuse, with a 422 on source.url or
#     target.url, the cloud metadata address, a loopback or private address
#     the operator has not admitted, localhost, and a URL with a password;
#     nothing is queued and nothing is called;
#   - with the other install's address admitted by the operator
#     (P202_WEBHOOK_ALLOW_NETWORKS in 202-config.php, which this pass writes
#     and takes out on exit), a sync between two installs over loopback runs:
#     the queued job's file holds the keys sealed, never as sent, and once
#     the job ends it holds none;
#   - a job stored the way a version before 1.9.79 stored it (keys as sent)
#     naming an address not admitted fails at once when it runs, the
#     listener there hears nothing, and its keys are gone from the file;
#   - a remote that answers with a redirect elsewhere is not followed, one
#     that answers more than a sync reads (9 MB) or a list that never ends
#     stops the sync naming the cap, and none is retried.
#
# Two instances: the one that runs the sync (P202_BASE, its key P202_API_KEY,
# its scratch database P202_DB; run from its repository root) and the target
# (P202_TARGET_BASE, its key P202_TARGET_KEY). "Internal" listeners run on
# 127.0.0.3, which no allowlist here admits; the allowlist admits 127.0.0.1.

BASE=${P202_BASE:-http://127.0.0.1:8271}
TARGET_BASE=${P202_TARGET_BASE:-http://127.0.0.1:8272}
DB=${P202_DB:-p202_test}
P202_API_KEY=${P202_API_KEY:-}
P202_TARGET_KEY=${P202_TARGET_KEY:-}
PHP=${P202_PHP:-php}
INTERNAL_PORT=${P202_INTERNAL_PORT:-8281}
REDIRECT_PORT=${P202_REDIRECT_PORT:-8282}
BIG_PORT=${P202_BIG_PORT:-8283}
PAGES_PORT=${P202_PAGES_PORT:-8284}

if [ -z "$P202_API_KEY" ] || [ -z "$P202_TARGET_KEY" ]; then
    echo "P202_API_KEY and P202_TARGET_KEY must be set: the two installs' API keys" >&2
    exit 2
fi
HERE="$(dirname "${BASH_SOURCE[0]}")"
# shellcheck source=tests/live/guard.sh
. "$HERE/guard.sh"
p202_require_scratch_db "$DB" || exit 2
[ -f api/v3/index.php ] && [ -f 202-config.php ] || { echo "run from the repository root of the instance at $BASE" >&2; exit 2; }

OUT=$(mktemp -d)
PASS=0; FAIL=0
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }
hasnt(){ if grep -qF -- "$2" "$1"; then bad "$3"; else ok "$3"; fi; }
js()   { python3 -c "import json,sys; d=json.load(sys.stdin); print($1)"; }
# The API allows 30 /sync requests a minute; a 429 is waited out, not a result.
wait_out() { # $1 the 429's body
    local after
    after=$(printf '%s' "$1" | js "d.get('retry_after_seconds', 60)" 2>/dev/null || echo 60)
    sleep $((after + 1))
}
# post <path> <json> <out>: the status, the body to <out>
post() {
    local code
    while :; do
        code=$(curl -s -o "$3" -w '%{http_code}' -H "Authorization: Bearer $P202_API_KEY" \
            -H 'Content-Type: application/json' -X POST "$BASE/api/v3/$1" -d "$2")
        [ "$code" = 429 ] || break
        wait_out "$(cat "$3")"
    done
    printf '%s' "$code"
}
get() {
    local body
    while :; do
        body=$(curl -s -H "Authorization: Bearer $2" "$1")
        case "$body" in *'"status":429'*) wait_out "$body" ;; *) break ;; esac
    done
    printf '%s' "$body"
}
profile() { printf '{"url":"%s","api_key":"%s"}' "$1" "$2"; }
# job_body <target url> [entity] [source url]: the source is this install unless named
job_body() { printf '{"source":%s,"target":%s,"entity":"%s"}' "$(profile "${3:-$BASE}" "$P202_API_KEY")" "$(profile "$1" "$P202_TARGET_KEY")" "${2:-all}"; }

CONFIG_BACKUP="$OUT/202-config.php.orig"
cp 202-config.php "$CONFIG_BACKUP"
PIDS=()
STATE_ROOT=$("$PHP" -r 'echo rtrim(sys_get_temp_dir(), "/");')
job_file() { find "$STATE_ROOT" -maxdepth 3 -path '*p202-api-v3-state*/jobs/*' -name "$1.json" 2>/dev/null | head -1; }
NAME=sync-guard-pass

delete_named() { # $1 base, $2 key: the pass's campaigns and category
    local base="$1" key="$2" id
    for id in $(get "$base/api/v3/campaigns?limit=500" "$key" | js "' '.join(str(r['aff_campaign_id']) for r in d['data'] if r['aff_campaign_name'].startswith('$NAME'))" 2>/dev/null); do
        curl -s -o /dev/null -X DELETE -H "Authorization: Bearer $key" "$base/api/v3/campaigns/$id"
    done
    for id in $(get "$base/api/v3/aff-networks?limit=500" "$key" | js "' '.join(str(r['aff_network_id']) for r in d['data'] if r['aff_network_name'].startswith('$NAME'))" 2>/dev/null); do
        curl -s -o /dev/null -X DELETE -H "Authorization: Bearer $key" "$base/api/v3/aff-networks/$id"
    done
}
cleanup() {
    for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null; done
    cp "$CONFIG_BACKUP" 202-config.php
    delete_named "$BASE" "$P202_API_KEY"
    delete_named "$TARGET_BASE" "$P202_TARGET_KEY"
}
trap cleanup EXIT

# ─────────────────────────────────────────────────────────────────────
say "listeners on 127.0.0.3 (internal) and 127.0.0.1 (a redirect, a big answer, a list that never ends)"
cat > "$OUT/listener.py" <<'PY'
import http.server, json, sys
host, port, log, mode = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4]
page = json.dumps({'data': [{'aff_network_id': i + 1, 'aff_network_name': 'n%d' % i} for i in range(500)]}).encode()
class H(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def answer(self):
        with open(log, 'a') as f: f.write(self.command + ' ' + self.path + ' ' + self.headers.get('Authorization', '-') + '\n')
        if mode.startswith('redirect:'):
            self.send_response(302); self.send_header('Location', mode[9:]); self.send_header('Content-Length', '0'); self.end_headers(); return
        body = b'{"data":[]}'
        if mode == 'big':
            body = b'{"data":[' + b' ' * (9 * 1024 * 1024) + b']}'
        elif mode == 'pages':
            body = page
        self.send_response(200); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(body))); self.end_headers()
        try: self.wfile.write(body)
        except Exception: pass
    do_GET = do_POST = do_PUT = do_DELETE = answer
http.server.ThreadingHTTPServer((host, port), H).serve_forever()
PY
INTERNAL_LOG="$OUT/internal.log"; REDIRECT_LOG="$OUT/redirect.log"; BIG_LOG="$OUT/big.log"; PAGES_LOG="$OUT/pages.log"
touch "$INTERNAL_LOG" "$REDIRECT_LOG" "$BIG_LOG" "$PAGES_LOG"
python3 "$OUT/listener.py" 127.0.0.3 "$INTERNAL_PORT" "$INTERNAL_LOG" ok & PIDS+=($!)
python3 "$OUT/listener.py" 127.0.0.1 "$REDIRECT_PORT" "$REDIRECT_LOG" "redirect:http://127.0.0.3:$INTERNAL_PORT/api/v3/aff-networks" & PIDS+=($!)
python3 "$OUT/listener.py" 127.0.0.1 "$BIG_PORT" "$BIG_LOG" big & PIDS+=($!)
python3 "$OUT/listener.py" 127.0.0.1 "$PAGES_PORT" "$PAGES_LOG" pages & PIDS+=($!)
sleep 1
eq "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.3:$INTERNAL_PORT/probe")" 200 "the internal listener answers this machine"
: > "$INTERNAL_LOG"

# ─────────────────────────────────────────────────────────────────────
say "no allowlist: each URL is refused on its field, nothing is queued or called"
# This install is on loopback too, so until it is admitted the source named
# is a public one: the refusal of the target is what each request shows.
SOURCE=https://source.example.com
refuse() { # $1 label, $2 endpoint, $3 target url, $4 text the field error carries, $5 text it must not
    local code
    code=$(post "$2" "$(job_body "$3" all "$SOURCE")" "$OUT/r.json")
    eq "$code" 422 "$1: $2 answers 422"
    eq "$(js "list(d.get('field_errors', {}).keys())" < "$OUT/r.json")" "['target.url']" "$1: on target.url"
    has "$OUT/r.json" "$4" "$1: says why ($4)"
    [ -n "$5" ] && hasnt "$OUT/r.json" "$5" "$1: and not $5"
    hasnt "$OUT/r.json" "$P202_TARGET_KEY" "$1: the key is not in the answer"
    hasnt "$OUT/r.json" '"job_id"' "$1: no job"
}
for endpoint in sync/jobs sync/plan; do
    refuse "metadata" "$endpoint" "http://169.254.169.254/latest/meta-data" "link-local" "P202_WEBHOOK_ALLOW_NETWORKS"
    refuse "the other install over loopback" "$endpoint" "$TARGET_BASE" "add 127.0.0.1 (or its network) to P202_WEBHOOK_ALLOW_NETWORKS"
    refuse "an internal listener" "$endpoint" "http://127.0.0.3:$INTERNAL_PORT" "loopback"
    refuse "a private address" "$endpoint" "https://10.0.0.1" "private"
    refuse "localhost" "$endpoint" "http://localhost:${TARGET_BASE##*:}" "names this server"
    refuse "a password" "$endpoint" "https://admin:pw@sync.example.com" "user name or password"
done
eq "$(wc -l < "$INTERNAL_LOG" | tr -d ' ')" 0 "the internal listener heard nothing"

# ─────────────────────────────────────────────────────────────────────
say "the operator admits 127.0.0.1/32: a sync between the two installs runs"
printf "\n// tests/live/sync-guard.sh (removed on exit)\ndefine('P202_WEBHOOK_ALLOW_NETWORKS', '127.0.0.1/32');\n" >> 202-config.php
"$PHP" -l 202-config.php > /dev/null || { bad "202-config.php no longer parses"; exit 1; }
"$PHP" -r 'usleep(3500000);'

delete_named "$BASE" "$P202_API_KEY"
delete_named "$TARGET_BASE" "$P202_TARGET_KEY"
post aff-networks "{\"aff_network_name\":\"$NAME-net\"}" "$OUT/n.json" > /dev/null
NET=$(js "d['data']['aff_network_id']" < "$OUT/n.json")
post campaigns "{\"aff_campaign_name\":\"$NAME-A\",\"aff_network_id\":$NET,\"aff_campaign_url\":\"https://offer.example.com/a\",\"aff_campaign_payout\":2}" "$OUT/c1.json" > /dev/null
post campaigns "{\"aff_campaign_name\":\"$NAME-B\",\"aff_network_id\":$NET,\"aff_campaign_url\":\"https://offer.example.com/b\",\"aff_campaign_payout\":3}" "$OUT/c2.json" > /dev/null

eq "$(post sync/plan "$(job_body "$TARGET_BASE" campaigns)" "$OUT/plan.json")" 200 "the plan reads both installs"
eq "$(js "d['data']['data']['campaigns']['only_in_source_count']" < "$OUT/plan.json")" 2 "and finds the two campaigns only in the source"

eq "$(post sync/jobs "$(job_body "$TARGET_BASE")" "$OUT/job.json")" 202 "the job is queued"
JOB=$(js "d['data']['job_id']" < "$OUT/job.json")
FILE=$(job_file "$JOB")
if [ -n "$FILE" ]; then ok "its file is in the state directory"; else bad "its file is in the state directory"; fi
has "$FILE" '"api_key_sealed":"v1.' "the queued job holds the keys sealed"
hasnt "$FILE" "$P202_API_KEY" "and not the source's key as sent"
hasnt "$FILE" "$P202_TARGET_KEY" "nor the target's"

post sync/worker/run '{"limit":5}' "$OUT/w.json" > /dev/null
get "$BASE/api/v3/sync/jobs/$JOB" "$P202_API_KEY" > "$OUT/done.json"
eq "$(js "d['data']['status']" < "$OUT/done.json")" succeeded "the sync succeeded"
eq "$(get "$TARGET_BASE/api/v3/campaigns?limit=500" "$P202_TARGET_KEY" | js "sorted(r['aff_campaign_name'] for r in d['data'] if r['aff_campaign_name'].startswith('$NAME'))")" "['$NAME-A', '$NAME-B']" "the target install has both campaigns"
hasnt "$FILE" "api_key" "the finished job holds no key at all"

say "still refused with the allowlist: what it does not name, and what nothing admits"
SOURCE=$BASE
for endpoint in sync/jobs sync/plan; do
    refuse "metadata" "$endpoint" "http://169.254.169.254/latest/meta-data" "link-local" "P202_WEBHOOK_ALLOW_NETWORKS"
    refuse "an internal listener" "$endpoint" "http://127.0.0.3:$INTERNAL_PORT" "loopback"
    refuse "a private address" "$endpoint" "http://10.0.0.1" "private"
done

# ─────────────────────────────────────────────────────────────────────
say "a job stored before 1.9.79 (keys as sent) naming an address not admitted"
OLD=0123456789abcdef0123456789abcdef
OLD_FILE="$(dirname "$FILE")/$OLD.json"
python3 - "$FILE" "$OLD_FILE" "$OLD" "$BASE" "$P202_API_KEY" "http://127.0.0.3:$INTERNAL_PORT" "$P202_TARGET_KEY" <<'PY'
import json, sys, time
src, dst, jid, base, key, target, tkey = sys.argv[1:]
job = json.load(open(src))
job.update({'job_id': jid, 'status': 'queued', 'attempts': 0, 'next_run_at': 0, 'results': None, 'error': None})
job['request']['source'] = {'name': base, 'url': base, 'api_key': key}
job['request']['target'] = {'name': target, 'url': target, 'api_key': tkey}
json.dump(job, open(dst, 'w'))
open(dst.replace('.json', '.events.json'), 'w').write('{"items":[]}')
PY
has "$OLD_FILE" "$P202_TARGET_KEY" "planted with the keys as sent"
post sync/worker/run '{"limit":5}' "$OUT/w.json" > /dev/null
get "$BASE/api/v3/sync/jobs/$OLD" "$P202_API_KEY" > "$OUT/old.json"
eq "$(js "d['data']['status']" < "$OUT/old.json")" failed "it fails"
eq "$(js "d['data']['error_code']" < "$OUT/old.json")" sync_url_refused "as refused"
eq "$(js "d['data']['attempts']" < "$OUT/old.json")" 1 "at once, not tried again"
has "$OUT/old.json" "Not sent to the sync target http://127.0.0.3:$INTERNAL_PORT" "naming the remote by its origin"
eq "$(wc -l < "$INTERNAL_LOG" | tr -d ' ')" 0 "the internal listener heard nothing"
hasnt "$OLD_FILE" "api_key" "and the job file holds no key any more"

# ─────────────────────────────────────────────────────────────────────
say "a remote that redirects elsewhere, answers too much, or never ends its list"
stopped() { # $1 label, $2 target url, $3 error_code, $4 text of the error
    local job
    eq "$(post sync/jobs "$(job_body "$2")" "$OUT/s.json")" 202 "$1: queued (the address is admitted)"
    job=$(js "d['data']['job_id']" < "$OUT/s.json")
    post sync/worker/run '{"limit":5}' "$OUT/w.json" > /dev/null
    get "$BASE/api/v3/sync/jobs/$job" "$P202_API_KEY" > "$OUT/s.json"
    eq "$(js "d['data']['status']" < "$OUT/s.json")" failed "$1: the job fails"
    eq "$(js "d['data']['error_code']" < "$OUT/s.json")" "$3" "$1: as $3, not retried"
    has "$OUT/s.json" "$4" "$1: naming why"
    has "$OUT/s.json" "Nothing was written." "$1: and that nothing was written"
}
stopped "a redirect" "http://127.0.0.1:$REDIRECT_PORT" sync_url_refused "a redirect to http://127.0.0.3:$INTERNAL_PORT, which a sync does not follow"
eq "$(wc -l < "$REDIRECT_LOG" | tr -d ' ')" 1 "the redirecting remote was asked once"
eq "$(wc -l < "$INTERNAL_LOG" | tr -d ' ')" 0 "where it pointed heard nothing"
stopped "a 9 MB answer" "http://127.0.0.1:$BIG_PORT" sync_limit "larger than 8388608 bytes"
stopped "a list that never ends" "http://127.0.0.1:$PAGES_PORT" sync_limit "more than 200 pages of 500 records"
eq "$(grep -c 'GET /api/v3/aff-networks' "$PAGES_LOG")" 200 "it read 200 pages of it, no more"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
