#!/usr/bin/env bash
#
# Regression tests for .claude/skills/p202-verify/scripts/verify.sh.
#
# Two bugs, both of which made the ladder report something untrue while
# looking like an ordinary run:
#
#   1. Vendor state was decided by `[ -d vendor/bin ]`. One hand-written
#      phpstan shim in that directory — what an agent working around a
#      partial vendor actually does — flipped the answer to "full", which
#      switched off the partial-vendor handling in the phpstan and unit
#      tiers. The unit tier then blamed 76 missing-class errors on the
#      interpreter version instead of the missing packages.
#
#   2. `--changed` read the working tree only. Once the change was
#      committed the selection was empty, so every tier it picks from the
#      diff skipped and the scope report read like a run that had verified
#      nothing. `--since REF` compares against a ref instead.
#
# Both are "a check that appeared to run and did not" (CLAUDE.md error
# pattern #10), so they get tests rather than only fixes.
#
# Runs the real script against throwaway git repositories.
#
# Usage: scripts/tests/verify-ladder.test.sh
# Exits 0 when every case passes, 1 otherwise.

set -uo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SCRIPT="$HERE/../../.claude/skills/p202-verify/scripts/verify.sh"

if [ ! -f "$SCRIPT" ]; then
    echo "cannot find verify.sh at $SCRIPT" >&2
    exit 1
fi

pass=0
fail=0

ok() { echo "  ok   - $1"; pass=$((pass + 1)); }
no() { echo "  FAIL - $1"; echo "         $2"; fail=$((fail + 1)); }

check() {
    local label=$1 expected=$2 actual=$3
    if [ "$actual" = "$expected" ]; then
        ok "$label"
    else
        no "$label" "expected '$expected', got '$actual'"
    fi
}

# A throwaway repository with a vendor/ in the requested shape. Echoes its
# path; the caller removes it.
make_repo() {
    local shape=$1 dir
    dir=$(mktemp -d)
    (
        cd "$dir" || exit 1
        git init -q .
        git config user.email test@example.com
        git config user.name test
        mkdir -p vendor
        case "$shape" in
            absent) ;;
            partial-no-manifest)
                # composer never finished: no manifest at all
                : > vendor/autoload.php
                mkdir -p vendor/acme/pkg && : > vendor/acme/pkg/file.php
                ;;
            partial-empty-package)
                # manifest present, but a package directory never landed
                : > vendor/autoload.php
                mkdir -p vendor/composer && echo '{"packages":[]}' > vendor/composer/installed.json
                mkdir -p vendor/acme/pkg && : > vendor/acme/pkg/file.php
                mkdir -p vendor/phpunit/phpunit
                ;;
            full)
                : > vendor/autoload.php
                mkdir -p vendor/composer && echo '{"packages":[]}' > vendor/composer/installed.json
                mkdir -p vendor/acme/pkg && : > vendor/acme/pkg/file.php
                ;;
        esac
        : > README.md
        git add -A
        git commit -q -m "initial"
    ) >/dev/null 2>&1
    echo "$dir"
}

vendor_state_of() {
    local dir=$1
    ( cd "$dir" && "$SCRIPT" --probe 2>/dev/null | awk '/^vendor:/ {print $2}' )
}

echo "verify.sh regression tests"
echo

# ---------------------------------------------------------------------
echo "vendor state is decided by composer's manifest, not by a directory"
# ---------------------------------------------------------------------

repo=$(make_repo absent)
check "no autoload.php reports absent" "absent" "$(vendor_state_of "$repo")"
rm -rf "$repo"

repo=$(make_repo full)
check "manifest plus populated packages reports full" "full" "$(vendor_state_of "$repo")"

# The regression itself: a hand-written shim in vendor/bin must not change
# the answer. Before the fix this single file reported "full".
mkdir -p "$repo/vendor/bin"
printf '#!/bin/sh\nexit 0\n' > "$repo/vendor/bin/phpstan"
chmod +x "$repo/vendor/bin/phpstan"
check "a shim in vendor/bin does not turn partial into full" "full" "$(vendor_state_of "$repo")"
rm -rf "$repo"

repo=$(make_repo partial-no-manifest)
check "no installed.json reports partial" "partial" "$(vendor_state_of "$repo")"
# Same repo, now with the shim that used to be enough to claim full.
mkdir -p "$repo/vendor/bin"
printf '#!/bin/sh\nexit 0\n' > "$repo/vendor/bin/phpstan"
chmod +x "$repo/vendor/bin/phpstan"
check "a shim cannot claim full without a manifest" "partial" "$(vendor_state_of "$repo")"
rm -rf "$repo"

repo=$(make_repo partial-empty-package)
check "a manifest with an empty package directory reports partial" "partial" "$(vendor_state_of "$repo")"
rm -rf "$repo"

echo

# ---------------------------------------------------------------------
echo "--since selects tiers from a committed change"
# ---------------------------------------------------------------------

repo=$(make_repo full)
(
    cd "$repo" || exit 1
    mkdir -p api/v3
    cat > api/v3/Thing.php <<'PHP'
<?php
class Thing {}
PHP
    git add -A >/dev/null 2>&1
    git commit -q -m "add a php file" >/dev/null 2>&1
) >/dev/null 2>&1

plan_of() { ( cd "$repo" && "$SCRIPT" "$@" --plan 2>/dev/null | tr '\n' ' ' | sed 's/ *$//' ); }

# The bug: with the change committed, the working tree is clean and the
# php-implied tiers vanish from the plan.
clean_plan=$(plan_of)
if printf '%s' "$clean_plan" | grep -qw unit; then
    no "a clean tree selects no php tiers" "expected no 'unit' in '$clean_plan'"
else
    ok "a clean tree selects no php tiers (the case --since exists for)"
fi

since_plan=$(plan_of --since HEAD~1)
if printf '%s' "$since_plan" | grep -qw unit && printf '%s' "$since_plan" | grep -qw phpstan; then
    ok "--since HEAD~1 selects the php tiers for the committed file"
else
    no "--since HEAD~1 selects the php tiers" "got '$since_plan'"
fi

# api/v3 is a schema-edit path, so the committed file must pull in schema too.
if printf '%s' "$since_plan" | grep -qw schema; then
    ok "--since carries the path-implied tiers (schema for api/v3)"
else
    no "--since carries the path-implied tiers" "got '$since_plan'"
fi

# An uncommitted change on top must still be included: --since compares the
# ref against the WORKING TREE, not against HEAD.
( cd "$repo" && mkdir -p go-cli && : > go-cli/main.go ) >/dev/null 2>&1
both_plan=$(plan_of --since HEAD~1)
if printf '%s' "$both_plan" | grep -qw go && printf '%s' "$both_plan" | grep -qw unit; then
    ok "--since includes uncommitted changes as well as commits"
else
    no "--since includes uncommitted changes as well as commits" "got '$both_plan'"
fi

# A bad ref must fail loudly. Selecting nothing would be the same silent
# empty scope report this option exists to remove.
out=$( cd "$repo" && "$SCRIPT" --since no-such-ref --plan 2>&1 ); rc=$?
check "an unknown ref exits 2" "2" "$rc"
if printf '%s' "$out" | grep -q 'no such commit'; then
    ok "an unknown ref says which ref"
else
    no "an unknown ref says which ref" "got '$out'"
fi

out=$( cd "$repo" && "$SCRIPT" --since 2>&1 ); rc=$?
check "--since with no value exits 2" "2" "$rc"
rm -rf "$repo"

echo
echo "passed: $pass  failed: $fail"
[ "$fail" -eq 0 ]
