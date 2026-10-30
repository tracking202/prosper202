#!/usr/bin/env bash
#
# Regression tests for scripts/changelog.py (changelog fragments).
#
# Every pull request used to append to the same lines of changelogs.txt and
# 202-config/changelogs.json, so each merge made every other open pull request
# conflict. Fragments fix that only if: check refuses a fragment the release
# could not fold in; assemble folds them in merge order and leaves the files
# byte-identical otherwise (the JSON writer must keep the file's own format);
# and guard refuses a pull request that goes back to editing the files.
#
# Runs the real script against throwaway git repositories seeded with the
# repository's own two changelog files.
#
# Usage: scripts/tests/changelog.test.sh
# Exits 0 when every case passes, 1 otherwise.

set -uo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT="$HERE/../.."
SCRIPT="$ROOT/scripts/changelog.py"

pass=0
fail=0
ok() { echo "  ok   - $1"; pass=$((pass + 1)); }
bad() { echo "  FAIL - $1"; fail=$((fail + 1)); }

TMP=$(mktemp -d "${TMPDIR:-/tmp}/changelog-test.XXXXXX") || { echo "cannot make a temp directory" >&2; exit 1; }
trap 'rm -rf "$TMP"' EXIT

# A repository with the real changelog files, committed on "base".
new_repo() {
    local dir="$TMP/$1"
    rm -rf "$dir"
    mkdir -p "$dir/202-config"
    cp "$ROOT/changelogs.txt" "$dir/changelogs.txt"
    cp "$ROOT/202-config/changelogs.json" "$dir/202-config/changelogs.json"
    git -C "$dir" init -q -b base
    git -C "$dir" -c user.name=t -c user.email=t@example.com add -A
    git -C "$dir" -c user.name=t -c user.email=t@example.com commit -qm base
    echo "$dir"
}

commit_at() { # dir epoch message
    git -C "$1" add -A
    GIT_COMMITTER_DATE="$2 +0000" GIT_AUTHOR_DATE="$2 +0000" \
        git -C "$1" -c user.name=t -c user.email=t@example.com commit -qm "$3"
}

run() { (cd "$1" && shift && python3 -I "$SCRIPT" "$@") >"$TMP/out" 2>&1; }

VERSION=$(sed -n 's/^Version \([0-9.]*\)$/\1/p' "$ROOT/changelogs.txt" | head -1)

echo "check"

R=$(new_repo check)
if run "$R" check; then ok "no fragments: clean"; else bad "no fragments: $(cat "$TMP/out")"; fi

mkdir -p "$R/changelogs.d/$VERSION"
printf -- '-- Fixed: A thing.\napp: Fixed: Short.\n\n# note\n-- New: Another.\n' > "$R/changelogs.d/$VERSION/a.txt"
if run "$R" check; then ok "two entries, one with an app line"; else bad "valid fragment refused: $(cat "$TMP/out")"; fi

printf 'Fixed: no dashes\n' > "$R/changelogs.d/$VERSION/b.txt"
if run "$R" check; then bad "a line without '-- ' passed"; else ok "a line without '-- ' is refused"; fi
rm "$R/changelogs.d/$VERSION/b.txt"

printf 'app: Fixed: orphan\n-- Fixed: X.\n' > "$R/changelogs.d/$VERSION/b.txt"
if run "$R" check; then bad "an app line before its entry passed"; else ok "an app line before its entry is refused"; fi
rm "$R/changelogs.d/$VERSION/b.txt"

printf -- '-- Removed: Y.\n' > "$R/changelogs.d/$VERSION/b.txt"
if run "$R" check; then bad "an unknown kind passed"; else ok "an unknown kind is refused"; fi
rm "$R/changelogs.d/$VERSION/b.txt"

printf -- '-- Fixed: A thing.\n' > "$R/changelogs.d/$VERSION/b.txt"
if run "$R" check; then bad "an entry in two fragments passed"; else ok "an entry in two fragments is refused"; fi
rm "$R/changelogs.d/$VERSION/b.txt"

existing=$(grep -m1 '^-- ' "$R/changelogs.txt")
printf '%s\n' "$existing" > "$R/changelogs.d/$VERSION/b.txt"
if run "$R" check; then bad "an entry already in changelogs.txt passed"; else ok "an entry already in changelogs.txt is refused"; fi
rm "$R/changelogs.d/$VERSION/b.txt"

printf -- '-- Fixed: Z.\n' > "$R/changelogs.d/$VERSION/b.md"
if run "$R" check; then bad "a .md fragment passed"; else ok "a fragment must be .txt"; fi
rm "$R/changelogs.d/$VERSION/b.md"

mkdir -p "$R/changelogs.d/next"
printf -- '-- Fixed: Z.\n' > "$R/changelogs.d/next/c.txt"
if run "$R" check; then bad "a folder that is not a version passed"; else ok "the folder must be a version"; fi

echo "assemble"

R=$(new_repo assemble)
cp "$R/changelogs.txt" "$TMP/txt.before"; cp "$R/202-config/changelogs.json" "$TMP/json.before"
if run "$R" assemble "$VERSION" && cmp -s "$R/changelogs.txt" "$TMP/txt.before" && cmp -s "$R/202-config/changelogs.json" "$TMP/json.before"; then
    ok "no fragments: both files byte-identical"
else
    bad "no fragments changed the files: $(cat "$TMP/out")"
fi

mkdir -p "$R/changelogs.d/$VERSION"
# Named so that file order and merge order disagree: merge order must win.
printf -- '-- Fixed: Merged second.\napp: Fixed: Second, short.\n' > "$R/changelogs.d/$VERSION/a-second.txt"
commit_at "$R" 2000000200 second
printf -- '-- New: Merged first.\napp: New: First, short.\n-- Changed: No app line.\n' > "$R/changelogs.d/$VERSION/z-first.txt"
commit_at "$R" 2000000100 first
if run "$R" assemble "$VERSION"; then ok "assemble runs"; else bad "assemble failed: $(cat "$TMP/out")"; fi

block=$(awk -v v="Version $VERSION" '$0==v{on=1;next} /^Version /{on=0} on && /^-- /' "$R/changelogs.txt" | tail -3)
want=$(printf -- '-- New: Merged first.\n-- Changed: No app line.\n-- Fixed: Merged second.')
if [ "$block" = "$want" ]; then ok "entries end the version's block, in merge order"; else bad "block ends with: $block"; fi

logs=$(python3 -I -c 'import json,sys; print("\n".join(json.load(open(sys.argv[1]))[sys.argv[2]]["logs"][-2:]))' "$R/202-config/changelogs.json" "$VERSION")
if [ "$logs" = "$(printf 'New: First, short.\nFixed: Second, short.')" ]; then ok "app lines end the version's logs, in merge order"; else bad "logs end with: $logs"; fi

if [ ! -e "$R/changelogs.d/$VERSION" ]; then ok "fragments and their folder are deleted"; else bad "fragments left: $(ls "$R/changelogs.d/$VERSION")"; fi

lines_before=$(wc -l < "$TMP/txt.before"); lines_after=$(wc -l < "$R/changelogs.txt")
if [ $((lines_after - lines_before)) -eq 3 ]; then ok "changelogs.txt gains exactly the three lines"; else bad "changelogs.txt changed by $((lines_after - lines_before)) lines"; fi

R=$(new_repo fresh)
mkdir -p "$R/changelogs.d/99.0.0"
printf -- '-- New: A new version.\napp: New: Short.\n' > "$R/changelogs.d/99.0.0/x.txt"
run "$R" assemble 99.0.0
first=$(grep -m1 '^Version ' "$R/changelogs.txt")
firstkey=$(python3 -I -c 'import json,sys; print(next(iter(json.load(open(sys.argv[1])))))' "$R/202-config/changelogs.json")
if [ "$first" = "Version 99.0.0" ] && [ "$firstkey" = "99.0.0" ]; then
    ok "a version with no block yet goes first in both files"
else
    bad "new version placed as '$first' / '$firstkey'"
fi

echo "guard"

R=$(new_repo guard)
git -C "$R" checkout -q -b pr
if run "$R" guard base; then ok "nothing changed: passes"; else bad "empty pull request refused: $(cat "$TMP/out")"; fi

mkdir -p "$R/changelogs.d/$VERSION"
printf -- '-- Fixed: Via a fragment.\n' > "$R/changelogs.d/$VERSION/f.txt"
commit_at "$R" 2000000300 fragment
if run "$R" guard base; then ok "a fragment passes"; else bad "a fragment was refused: $(cat "$TMP/out")"; fi

awk -v v="Version $VERSION" '{print} $0==v{print "-- Fixed: Written straight in."}' "$R/changelogs.txt" > "$TMP/t" && cp "$TMP/t" "$R/changelogs.txt"
commit_at "$R" 2000000400 direct
if run "$R" guard base; then bad "an entry written into changelogs.txt passed"; else ok "an entry written into changelogs.txt is refused"; fi
git -C "$R" reset -q --hard HEAD~1

python3 -I -c '
import json,sys
p,v=sys.argv[1],sys.argv[2]
d=json.load(open(p)); d[v]["logs"].append("Fixed: Straight into the JSON.")
open(p,"w").write(json.dumps(d,indent=2,ensure_ascii=False)+"\n")' "$R/202-config/changelogs.json" "$VERSION"
commit_at "$R" 2000000500 direct-json
if run "$R" guard base; then bad "an entry written into changelogs.json passed"; else ok "an entry written into changelogs.json is refused"; fi
git -C "$R" reset -q --hard HEAD~1

awk '!done && /^-- /{$0=$0 " (reworded)"; done=1} {print}' "$R/changelogs.txt" > "$TMP/t" && cp "$TMP/t" "$R/changelogs.txt"
commit_at "$R" 2000000600 reword
if run "$R" guard base; then ok "rewording an existing entry passes"; else bad "a reword was refused: $(cat "$TMP/out")"; fi

# A release: the fragments are already on the version branch; the release
# pull request folds them in and deletes them.
R=$(new_repo release)
mkdir -p "$R/changelogs.d/$VERSION"
printf -- '-- Fixed: Landed earlier.\napp: Fixed: Short.\n' > "$R/changelogs.d/$VERSION/f.txt"
commit_at "$R" 2000000700 "fragment on the version branch"
git -C "$R" checkout -q -b release
run "$R" assemble "$VERSION"
commit_at "$R" 2000000800 release
if run "$R" guard base; then ok "a release folding its fragments in passes"; else bad "the release was refused: $(cat "$TMP/out")"; fi

# Deleting a fragment is not a licence to write entries straight in.
R=$(new_repo delete-one)
mkdir -p "$R/changelogs.d/$VERSION"
printf -- '-- Fixed: Landed earlier.\napp: Fixed: Short.\n' > "$R/changelogs.d/$VERSION/f.txt"
commit_at "$R" 2000000900 "fragment on the version branch"
git -C "$R" checkout -q -b pr
rm "$R/changelogs.d/$VERSION/f.txt"
awk -v v="Version $VERSION" '{print} $0==v{print "-- Fixed: Written straight in."}' "$R/changelogs.txt" > "$TMP/t" && cp "$TMP/t" "$R/changelogs.txt"
commit_at "$R" 2000001000 "delete a fragment, add an entry directly"
if run "$R" guard base; then bad "deleting a fragment let a direct entry pass"; else ok "a deleted fragment whose entries are not folded in is refused"; fi

git -C "$R" reset -q --hard HEAD~1
run "$R" assemble "$VERSION"
awk -v v="Version $VERSION" '{print} $0==v{print "-- Fixed: Written straight in."}' "$R/changelogs.txt" > "$TMP/t" && cp "$TMP/t" "$R/changelogs.txt"
commit_at "$R" 2000001100 "fold in, plus an entry directly"
if run "$R" guard base; then bad "a direct entry beside a fold passed"; else ok "a direct entry beside a fold is refused"; fi

echo
echo "passed: $pass  failed: $fail"
[ "$fail" -eq 0 ]
