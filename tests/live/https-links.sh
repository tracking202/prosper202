#!/bin/bash
# Live pass: the links the pages hand out are the ones the API and the CLI
# hand out, on https unless the install is explicitly http-only.
#
# Get Links built every direct and redirector link as 'http://' . domain, so
# an install served over HTTPS (or behind a TLS-terminating proxy) handed out
# http:// links while GET /trackers/{id}/url and `p202 tracker get-url`
# handed out https:// ones; the Postback page and API integrations took the
# scheme from the request and never from the saved tracking domain. This
# pass signs in, makes a campaign and a redirector through the API and a
# direct link and a redirector link through Get Links' own request, and for
# each way the scheme can be decided reads, as the signed-in user:
#   Get Links' answer to "Get tracking link" and its list of links,
#   Setup > Postback / Pixel's postback URL, API integrations' ClickBank URL,
# and compares each with the API's answer to the same request, and with the
# CLI's where the CLI can make that request (it sends no X-Forwarded-Proto).
#
# The scheme is decided by the saved tracking domain when it is saved with
# one (PUT /users/{id}/preferences, as Personal settings saves it), and
# otherwise by the request: plain HTTP, or X-Forwarded-Proto: https as a
# TLS-terminating proxy sends it.
#
# --- environment -------------------------------------------------------
# P202_DB must be a SCRATCH database: this pass rewrites the account's
# tracking domain (put back at the end) and adds setup rows.
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
API_KEY=${P202_API_KEY:-}
CLI=${P202_CLI:-}

if [ -z "$P202_PASS" ] || [ -z "$API_KEY" ]; then
    echo "P202_PASS and P202_API_KEY are required: this pass signs in as $P202_USER and calls the API." >&2
    exit 2
fi
# shellcheck source=tests/live/guard.sh
. "$(dirname "${BASH_SOURCE[0]}")/guard.sh"
p202_require_scratch_db "$DB" || exit 2

MYSQL_ARGS=(-u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=("-p$DB_PASS")
[ -n "${P202_DB_HOST:-}" ] && MYSQL_ARGS+=(-h "$P202_DB_HOST" --protocol=TCP)
[ -n "${P202_DB_PORT:-}" ] && MYSQL_ARGS+=(-P "$P202_DB_PORT")
Q() { mysql "${MYSQL_ARGS[@]}" -N "$DB" -e "$1"; }
# -----------------------------------------------------------------------

JAR=$(mktemp)
OUT=$(mktemp -d)
CLI_HOME=$(mktemp -d)
PASS=0; FAIL=0

say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
eq()   { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got '$1' want '$2')"; fi; }
has()  { if grep -qF -- "$2" "$1"; then ok "$3"; else bad "$3"; fi; }

token_of() { grep -oE 'name="token" value="[^"]+"' "$1" | head -1 | sed 's/.*value="//; s/"//'; }
page() { # url out [curl args...]
    local url="$1" out="$2"; shift 2
    curl -sS -b "$JAR" -c "$JAR" "$@" "$url" -o "$out" -w '%{http_code}' > "$OUT/.code"
}
api() { # method path out [curl args...]
    local method="$1" path="$2" out="$3"; shift 3
    curl -sS -X "$method" -H "Authorization: Bearer $API_KEY" -H 'Content-Type: application/json' "$@" \
        "$BASE/api/v3$path" -o "$out" -w '%{http_code}' > "$OUT/.code"
}
json() { python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); [d := d[int(k)] if isinstance(d, list) else d[k] for k in sys.argv[2].split(".")]; print(d)' "$1" "$2"; }
# The text of the first element in an HTML file matching a regex group.
grab() { python3 - "$1" "$2" <<'PY'
import html, re, sys
m = re.search(sys.argv[2], open(sys.argv[1], encoding='utf-8', errors='replace').read(), re.S)
print(html.unescape(m.group(1)) if m else '')
PY
}
# The link Get Links' list offers to copy for a tracker id.
listed() { grab "$1" 'data-tracker-id="'"$2"'">.*?data-p202-copy="([^"]*)"'; }

say "sign in, and the records a link needs"
curl -sS -c "$JAR" -b "$JAR" "$BASE/202-login.php" -o "$OUT/login.html"
LT=$(token_of "$OUT/login.html")
curl -sS -c "$JAR" -b "$JAR" -L "$BASE/202-login.php" --data-urlencode "token=$LT" \
    --data-urlencode "user_name=$P202_USER" --data-urlencode "user_pass=$P202_PASS" -o "$OUT/signed-in.html"
USER_ID=$(Q "SELECT user_id FROM 202_users WHERE user_name = '$P202_USER'")
SAVED=$(Q "SELECT user_tracking_domain FROM 202_users_pref WHERE user_id = $USER_ID")

api POST /aff-networks "$OUT/net.json" -d '{"aff_network_name":"https links"}'
NET=$(json "$OUT/net.json" data.aff_network_id)
api POST /campaigns "$OUT/camp.json" -d '{"aff_campaign_name":"https links","aff_campaign_url":"https://offer.example/?s=[[subid]]","aff_campaign_payout":2,"aff_network_id":'"$NET"'}'
CAMP=$(json "$OUT/camp.json" data.aff_campaign_id)
api POST /rotators "$OUT/rot.json" -d '{"name":"https links","default_url":"https://offer.example/r"}'
ROT=$(json "$OUT/rot.json" data.id)
[ -n "$CAMP" ] && [ -n "$ROT" ] && ok "a campaign ($CAMP) and a redirector ($ROT) made through the API" || bad "the campaign or redirector was not made: $(cat "$OUT/camp.json" "$OUT/rot.json")"
page "$BASE/tracking202/setup/get_trackers.php" "$OUT/gl.html"
eq "$(cat "$OUT/.code")" "200" "Get Links opens signed in"
T=$(token_of "$OUT/gl.html")
[ -n "$T" ] && ok "with its form and the form's token" || bad "with its form and the form's token"

# Get Links' "Get tracking link", as the page posts it.
generate() { # out [curl args...] -- form fields follow the page's names
    local out="$1"; shift
    curl -sS -b "$JAR" -c "$JAR" -H 'X-Requested-With: XMLHttpRequest' "$@" \
        "$BASE/tracking202/ajax/generate_tracking_link.php" -o "$out" -w '%{http_code}' > "$OUT/.code"
}
before=$(Q "SELECT COALESCE(MAX(tracker_id), 0) FROM 202_trackers")
generate "$OUT/gen-dl.html" --data-urlencode "token=$T" --data-urlencode "tracker_type=0" --data-urlencode "aff_network_id=$NET" \
    --data-urlencode "aff_campaign_id=$CAMP" --data-urlencode "method_of_promotion=directlink" --data-urlencode "click_cloaking=-1" \
    --data-urlencode "cost_type=cpc" --data-urlencode "cpc_dollars=0" --data-urlencode "cpc_cents=10"
DL=$(Q "SELECT MAX(tracker_id) FROM 202_trackers WHERE tracker_id > $before AND aff_campaign_id = $CAMP")
generate "$OUT/gen-rtr.html" --data-urlencode "token=$T" --data-urlencode "tracker_type=2" --data-urlencode "tracker_rotator=$ROT" \
    --data-urlencode "cost_type=cpc" --data-urlencode "cpc_dollars=0" --data-urlencode "cpc_cents=10"
RTR=$(Q "SELECT MAX(tracker_id) FROM 202_trackers WHERE tracker_id > $before AND rotator_id = $ROT")
[ -n "$DL" ] && [ "$DL" != "NULL" ] && [ -n "$RTR" ] && [ "$RTR" != "NULL" ] \
    && ok "Get Links made a direct link ($DL) and a redirector link ($RTR)" || bad "Get Links made no trackers: $(head -c 400 "$OUT/gen-dl.html")"
DL_PUB=$(Q "SELECT tracker_id_public FROM 202_trackers WHERE tracker_id = $DL")
RTR_PUB=$(Q "SELECT tracker_id_public FROM 202_trackers WHERE tracker_id = $RTR")

if [ -n "$CLI" ]; then
    HOME="$CLI_HOME" "$CLI" config set-url "$BASE" > /dev/null && HOME="$CLI_HOME" "$CLI" config set-key "$API_KEY" > /dev/null \
        && ok "the CLI is configured against the instance" || bad "the CLI could not be configured"
fi

# One way of deciding the scheme: the saved domain, the request's headers,
# and the base every link should start with.
scenario() { # name saved-domain expected-base why [curl header args...]
    local name="$1" saved="$2" expect="$3" why="$4"; shift 4
    say "$name"
    api PUT "/users/$USER_ID/preferences" "$OUT/pref.json" -d "$(python3 -c 'import json,sys; print(json.dumps({"user_tracking_domain": sys.argv[1]}))' "$saved")"
    eq "$(cat "$OUT/.code")" "200" "the tracking domain is saved as '$saved'"

    local dl="${expect}tracking202/redirect/dl.php?t202id=$DL_PUB&t202kw="
    local rtr="${expect}tracking202/redirect/rtr.php?t202id=$RTR_PUB&t202kw="

    T=$(token_of "$OUT/gl.html")
    generate "$OUT/g.html" "$@" --data-urlencode "token=$T" --data-urlencode "tracker_type=0" --data-urlencode "aff_network_id=$NET" \
        --data-urlencode "aff_campaign_id=$CAMP" --data-urlencode "method_of_promotion=directlink" --data-urlencode "click_cloaking=-1" \
        --data-urlencode "cost_type=cpc" --data-urlencode "cpc_dollars=0" --data-urlencode "cpc_cents=10" \
        --data-urlencode "edit_tracker=1" --data-urlencode "tracker_id=$DL_PUB"
    eq "$(grab "$OUT/g.html" '<pre class="p202-code__value">([^<]*)</pre>')" "$dl" "Get tracking link answers $dl"
    has "$OUT/g.html" "This link uses ${expect%%://*}://, $why" "and says why"
    # An edit stores the link again under a new tracker_id, its public id kept.
    DL=$(Q "SELECT tracker_id FROM 202_trackers WHERE tracker_id_public = $DL_PUB")

    page "$BASE/tracking202/setup/get_trackers.php" "$OUT/gl.html" "$@"
    eq "$(listed "$OUT/gl.html" "$DL")" "$dl" "Get Links lists the direct link on it"
    eq "$(listed "$OUT/gl.html" "$RTR")" "$rtr" "and the redirector link"
    has "$OUT/gl.html" "Direct and redirector links use ${expect%%://*}://, $why" "and says why"

    api GET "/trackers/$DL/url" "$OUT/u-dl.json" "$@"
    eq "$(json "$OUT/u-dl.json" data.direct_url)" "$dl" "GET /trackers/$DL/url answers the same direct link"
    api GET "/trackers/$RTR/url" "$OUT/u-rtr.json" "$@"
    eq "$(json "$OUT/u-rtr.json" data.direct_url)" "$rtr" "and the same redirector link"

    page "$BASE/tracking202/setup/get_postback.php" "$OUT/pb.html" "$@"
    api GET "/conversions/postback-code" "$OUT/pb.json" "$@"
    local postback="${expect}tracking202/static/gpb.php?amount=&subid="
    eq "$(grab "$OUT/pb.html" 'id="unsecure_postback">([^<]*)</pre>')" "$postback" "Postback / Pixel shows $postback"
    eq "$(json "$OUT/pb.json" data.simple.postback_url)" "$postback" "as GET /conversions/postback-code does"

    page "$BASE/202-account/api-integrations.php" "$OUT/ai.html" "$@"
    api GET "/system/integrations" "$OUT/ai.json" "$@"
    local ins="${expect}tracking202/static/cb202.php"
    eq "$(grab "$OUT/ai.html" '<label class="form-label">INS URL</label><div class="p202-code mb-3"><pre class="p202-code__value">([^<]*)</pre>')" "$ins" "API integrations shows the ClickBank INS URL $ins"
    eq "$(python3 -c 'import json,sys; print(next(e["url"] for e in json.load(open(sys.argv[1]))["data"]["integrations"] if e["integration"] == "clickbank"))' "$OUT/ai.json")" "$ins" "as GET /system/integrations does"

    if [ -n "$CLI" ] && [ "$#" -eq 0 ]; then
        eq "$(HOME="$CLI_HOME" "$CLI" tracker get-url "$DL" --json | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["direct_url"])')" "$dl" "p202 tracker get-url $DL answers the same link"
        eq "$(HOME="$CLI_HOME" "$CLI" tracker get-url "$RTR" --json | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["direct_url"])')" "$rtr" "p202 tracker get-url $RTR too"
    fi
}

HOSTPORT=${BASE#*://}
HOSTPORT=${HOSTPORT%%/*}
PROXY=(-H 'X-Forwarded-Proto: https')
scenario "nothing saved, plain HTTP: http, the scheme the install is reached on" \
    "" "http://$HOSTPORT/" "because this page was served over HTTP."
scenario "nothing saved, behind a TLS-terminating proxy: https" \
    "" "https://$HOSTPORT/" "because this page was served over HTTPS." "${PROXY[@]}"
scenario "a host saved without a scheme, behind the proxy: https" \
    "$HOSTPORT" "https://$HOSTPORT/" "because this page was served over HTTPS." "${PROXY[@]}"
scenario "saved with https://, reached over plain HTTP: https" \
    "https://$HOSTPORT" "https://$HOSTPORT/" "because your tracking domain is saved with it."
scenario "saved with http://, behind the proxy: explicitly http-only" \
    "http://$HOSTPORT/" "http://$HOSTPORT/" "because your tracking domain is saved with it." "${PROXY[@]}"

say "put back"
api PUT "/users/$USER_ID/preferences" "$OUT/pref.json" -d "$(python3 -c 'import json,sys; print(json.dumps({"user_tracking_domain": sys.argv[1]}))' "$SAVED")"
eq "$(Q "SELECT user_tracking_domain FROM 202_users_pref WHERE user_id = $USER_ID")" "$SAVED" "the tracking domain is as it was"

rm -rf "$JAR" "$OUT" "$CLI_HOME"
printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
