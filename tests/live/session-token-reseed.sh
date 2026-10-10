#!/bin/bash
# Live pass: a session whose anti-CSRF token is set but unusable gets a new
# one on its next page, and its forms work again.
#
# Every token guard goes through AUTH::csrf_token_matches(), which refuses a
# session token that is not a non-empty string (hash_equals('', '') is true,
# and the inline copies it replaced let a token-less POST through on such a
# session). connect.php used to seed only a token that was not set, so a
# session holding '' or false kept it: every form rendered an empty token and
# every post was refused until sign-out. It now seeds again whenever the
# token is not a non-empty string, from random_bytes().
#
# For each unusable shape (an empty string, false, an array) the pass blanks
# the token in the session file — and asserts the plant landed — then loads
# Setup › Categories, reads the token from the form that posts, submits that
# form with its own fields (form-body.py, error pattern #21) and reads the row
# back; a post without the token is refused in the page's own sentence and
# writes nothing.
#
# It writes categories named "RESEED …" for the signed-in account and removes
# them at the end. The session files are read where PHP keeps them
# (P202_SESSION_DIR, /var/lib/php/sessions by default); a server that keeps
# them elsewhere fails the "plant landed" step rather than passing.
BASE=${P202_BASE:-http://127.0.0.1:8097}
DB=${P202_DB:-p202_test}
DB_USER=${P202_DB_USER:-root}
DB_PASS=${P202_DB_PASS:-}
DB_HOST=${P202_DB_HOST:-}
DB_PORT=${P202_DB_PORT:-}
P202_USER=${P202_USER:-evalci}
P202_PASS=${P202_PASS:-}
SESSION_DIR=${P202_SESSION_DIR:-/var/lib/php/sessions}

if [ -z "$P202_PASS" ]; then
    echo "P202_PASS is not set: this pass signs in as $P202_USER and needs its password." >&2
    exit 2
fi
HERE="$(dirname "${BASH_SOURCE[0]}")"
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

# GET with the jar, body to $2, status to stdout.
get() { curl -sS -b "$JAR" -c "$JAR" -o "$2" -w '%{http_code}' "$BASE/$1"; }
# The token inside the form that carries MARKER, as a browser would post it.
form_token() { # PAGE MARKER
  python3 "$HERE/form-body.py" "$1" "$2" | tr '&' '\n' | sed -n 's/^token=//p' | head -1
}
# Submit the form carrying MARKER with its own fields (and overrides); status to stdout.
submit() { # PAGE MARKER URL OUT [name=value ...]
  local page="$1" marker="$2" url="$3" out="$4"; shift 4
  python3 "$HERE/form-body.py" "$page" "$marker" "$@" > "$OUT/.body" || { echo "000"; return; }
  curl -sS -b "$JAR" -c "$JAR" -o "$out" -w '%{http_code}' --data-binary @"$OUT/.body" \
    -H 'Content-Type: application/x-www-form-urlencoded' "$BASE/$url"
}
# The token entry of the session file, as PHP serialized it.
session_token() { python3 - "$1" <<'PY'
import re, sys
s = open(sys.argv[1], encoding='latin-1').read()
m = re.search(r'(?:^|;|\})token\|(s:\d+:"[^"]*";|b:[01];|N;|a:\d+:\{[^}]*\})', s)
print(m.group(1) if m else 'absent')
PY
}
# Replace the token entry with SHAPE (a serialized value).
plant_token() { python3 - "$1" "$2" <<'PY'
import re, sys
path, shape = sys.argv[1], sys.argv[2]
s = open(path, encoding='latin-1').read()
t, n = re.subn(r'((?:^|;|\})token\|)(s:\d+:"[^"]*";|b:[01];|N;|a:\d+:\{[^}]*\})', lambda m: m.group(1) + shape, s, count=1)
if n != 1:
    sys.exit(1)
open(path, 'w', encoding='latin-1').write(t)
PY
}

OWNER=$(Q "SELECT user_id FROM 202_users WHERE user_name='$P202_USER'")
Q "DELETE FROM 202_aff_networks WHERE user_id = '$OWNER' AND aff_network_name LIKE 'RESEED %'"

say "sign in"
get 202-login.php "$OUT/login.html" > /dev/null
code=$(submit "$OUT/login.html" user_name 202-login.php "$OUT/signed-in.html" "user_name=$P202_USER" "user_pass=$P202_PASS")
eq "$code" "302" "the sign-in form redirects"
PAGE="$OUT/categories.html"
eq "$(get tracking202/setup/aff_networks.php "$PAGE")" "200" "Setup › Categories answers 200 signed in"
SID=$(awk '$6=="PHPSESSID"{print $7}' "$JAR" | tail -1)
SESS="$SESSION_DIR/sess_$SID"
eq "$([ -n "$SID" ] && [ -f "$SESS" ] && echo yes)" "yes" "the session file is $SESS"

n=0
for shape in 's:0:"";' 'b:0;' 'a:1:{i:0;s:1:"x";}'; do
  n=$((n+1))
  say "a session token of $shape"
  get tracking202/setup/aff_networks.php "$PAGE" > /dev/null
  before=$(form_token "$PAGE" aff_network_name)
  eq "$(printf '%s' "$before" | grep -cE '^[0-9a-f]{32}$')" "1" "the form carries a 32-character token first"

  if plant_token "$SESS" "$shape"; then :; else bad "the session file holds a token entry to replace"; continue; fi
  eq "$(session_token "$SESS")" "$shape" "the plant landed: the session file holds $shape"

  eq "$(get tracking202/setup/aff_networks.php "$PAGE")" "200" "the next page answers 200"
  after=$(form_token "$PAGE" aff_network_name)
  eq "$(printf '%s' "$after" | grep -cE '^[0-9a-f]{32}$')" "1" "the form carries a new 32-character token"
  eq "$([ "$after" != "$before" ] && echo new)" "new" "not the one the session held before"
  eq "$(session_token "$SESS")" "s:32:\"$after\";" "the session file holds the token the form carries"

  code=$(submit "$PAGE" aff_network_name tracking202/setup/aff_networks.php "$OUT/add-$n.html" "aff_network_name=RESEED $n")
  eq "$code" "302" "the form, submitted as rendered, is accepted (post-redirect-get)"
  eq "$(Q "SELECT COUNT(*) FROM 202_aff_networks WHERE user_id = '$OWNER' AND aff_network_name = 'RESEED $n' AND aff_network_deleted = 0")" "1" "and its category was written"

  get tracking202/setup/aff_networks.php "$PAGE" > /dev/null
  code=$(submit "$PAGE" aff_network_name tracking202/setup/aff_networks.php "$OUT/forged-$n.html" "token=" "aff_network_name=RESEED forged $n")
  eq "$code" "200" "the same form without its token is re-rendered"
  has "$OUT/forged-$n.html" "Invalid token, please reload the page and try again." "in the page's own sentence"
  eq "$(Q "SELECT COUNT(*) FROM 202_aff_networks WHERE user_id = '$OWNER' AND aff_network_name = 'RESEED forged $n'")" "0" "and wrote nothing"
done

Q "DELETE FROM 202_aff_networks WHERE user_id = '$OWNER' AND aff_network_name LIKE 'RESEED %'"
rm -rf "$OUT"
printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
