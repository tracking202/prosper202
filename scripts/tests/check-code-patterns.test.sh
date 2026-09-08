#!/usr/bin/env bash
#
# Regression tests for scripts/check-code-patterns.sh.
#
# The hook selected its work with `git diff --name-only HEAD`, which lists
# tracked modifications only. A brand-new .php file was therefore invisible:
# the hook exited 0 having examined nothing and reported clean. That is a
# check that appeared to run and did not (CLAUDE.md error pattern #10), so it
# gets a test rather than only a fix.
#
# Runs the real script against a throwaway git repository. Part 2 (PHPStan)
# self-skips there because ./vendor/bin/phpstan is absent, so the behavioural
# cases below cover file selection and the diff-versus-whole-file decision.
# The PHPStan invocation is covered by static assertions at the end.
#
# Usage: scripts/tests/check-code-patterns.test.sh
# Exits 0 when every case passes, 1 otherwise.

set -uo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SCRIPT="$HERE/../check-code-patterns.sh"

if [ ! -f "$SCRIPT" ]; then
    echo "cannot find check-code-patterns.sh at $SCRIPT" >&2
    exit 1
fi

REPO=$(mktemp -d)
trap 'rm -rf "$REPO"' EXIT

pass=0
fail=0

cd "$REPO" || exit 1
git init -q .
git config user.email test@example.com
git config user.name test

# A committed file whose legacy body already violates pattern 1. Nothing may
# report it unless a *changed* line reintroduces the pattern.
mkdir -p src tests
printf '<?php\nfunction legacy($stmt) {\n    $stmt->execute();\n}\n' > src/legacy.php
printf '<?php\n// placeholder\n' > src/clean.php
git add -A
git commit -qm "initial"

cp "$SCRIPT" ./check.sh
chmod +x ./check.sh

# Runs the hook and reports both its exit status and whether it named the
# pattern. Checking only the exit status would pass on an unrelated violation.
expect() {
    local name="$1" want_exit="$2" want_caught="$3"
    local got_exit got_caught

    ./check.sh >/dev/null 2>"$REPO/stderr.txt"
    got_exit=$?

    if grep -qF 'Direct $stmt->execute()' "$REPO/stderr.txt"; then
        got_caught=yes
    else
        got_caught=no
    fi

    if [ "$got_exit" = "$want_exit" ] && [ "$got_caught" = "$want_caught" ]; then
        printf '  ok    %-46s exit=%s caught=%s\n' "$name" "$got_exit" "$got_caught"
        pass=$((pass + 1))
    else
        printf '  FAIL  %-46s exit=%s caught=%s (wanted exit=%s caught=%s)\n' \
            "$name" "$got_exit" "$got_caught" "$want_exit" "$want_caught"
        fail=$((fail + 1))
    fi
}

echo "check-code-patterns.sh"

expect "clean tree" 0 no

# The regression. Before the fix this returned exit 0 with nothing caught.
printf '<?php\nfunction fresh($stmt) {\n    $stmt->execute();\n}\n' > src/brand_new.php
expect "untracked new file WITH violation" 2 yes
rm -f src/brand_new.php

printf '<?php\nfunction ok() {\n    return 1;\n}\n' > src/brand_new_ok.php
expect "untracked new file, clean" 0 no
rm -f src/brand_new_ok.php

# tests/ is exempt from this pattern; whole-file treatment must not bypass that.
printf '<?php\nfunction t($stmt) {\n    $stmt->execute();\n}\n' > tests/NewTest.php
expect "untracked new file under tests/ (exempt)" 0 no
rm -f tests/NewTest.php

printf '<?php\nfunction c($stmt) {\n    $stmt->execute();\n}\n' > src/clean.php
expect "tracked file, violation in ADDED line" 2 yes
git checkout -q -- src/clean.php

# The reason the diff scoping exists: legacy lines must stay invisible.
printf '\n// harmless trailing comment\n' >> src/legacy.php
expect "tracked file, violation only in LEGACY lines" 0 no
git checkout -q -- src/legacy.php

printf '<?php\nfunction s($stmt) {\n    $stmt->execute();\n}\n' > src/staged.php
git add src/staged.php
expect "staged-but-uncommitted new file" 2 yes
git reset -q
rm -f src/staged.php

# ── Static assertions on the PHPStan invocation ──
#
# PHPStan under --error-format=raw prints bare "path:line:message" lines and
# never the "[ERROR]" marker the default table renderer uses. The hook used to
# grep for that literal, so the whole PHPStan gate passed regardless of what
# was found. Guard both halves of that fix.
assert_script() {
    local name="$1" pattern="$2" want="$3" got=no
    if grep -qE -- "$pattern" "$SCRIPT"; then got=yes; fi
    if [ "$got" = "$want" ]; then
        printf '  ok    %-46s\n' "$name"
        pass=$((pass + 1))
    else
        printf '  FAIL  %-46s (found=%s wanted=%s)\n' "$name" "$got" "$want"
        fail=$((fail + 1))
    fi
}

assert_script "does not grep for the [ERROR] marker" 'grep -qF .\[ERROR\]' no
assert_script "uses PHPStan exit status" 'phpstan_status' yes
assert_script "pins the committed config (baseline)" '\-c phpstan\.neon\.dist' yes
assert_script "selects untracked files too" 'ls-files --others --exclude-standard' yes

echo "  ---- $pass passed, $fail failed"
[ "$fail" -eq 0 ]
