#!/usr/bin/env bash
#
# Tests for scripts/check-pr-branch.sh and scripts/check-claude-md.py, run
# against throwaway repositories.
#
# Usage: scripts/tests/check-pr-branch.test.sh
# Exits 0 when every case passes, 1 otherwise.

set -uo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
BRANCH_CHECK="$HERE/../check-pr-branch.sh"
MD_CHECK="$HERE/../check-claude-md.py"

pass=0
fail=0
ok() { echo "  ok   - $1"; pass=$((pass + 1)); }
no() { echo "  FAIL - $1"; echo "         $2"; fail=$((fail + 1)); }

# expect <label> <exit code> <text the output must hold> -- the command runs
# with the environment already exported by the caller.
expect() {
    local label=$1 want=$2 text=$3 out rc
    out=$( cd "$work" && "$BRANCH_CHECK" 2>&1 ); rc=$?
    if [ "$rc" != "$want" ]; then
        no "$label" "expected exit $want, got $rc: $out"
    elif ! printf '%s' "$out" | grep -q -- "$text"; then
        no "$label" "exit $rc as expected, but the output lacks '$text': $out"
    else
        ok "$label"
    fi
}

root=$(mktemp -d)
trap 'rm -rf "$root"' EXIT
remote="$root/remote.git"
work="$root/work"

(
    git init -q --bare "$remote"
    git init -q -b master "$work"
    cd "$work" || exit 1
    git config user.email test@example.com
    git config user.name test
    mkdir -p 202-config .github/workflows documentation
    printf "<?php\n\$version_string = '1.9.79';\n" > 202-config/version.php
    : > index.php
    git add -A && git commit -q -m "1.9.79"
    git tag v1.9.79
    git remote add origin "$remote"
    git push -q origin master v1.9.79
    # The next version: branched from master, first commit the bump.
    git checkout -q -b 1.9.80
    printf "<?php\n\$version_string = '1.9.80';\n" > 202-config/version.php
    git commit -q -am "1.9.80" && git push -q origin 1.9.80
    git checkout -q -b feat/code 1.9.80
    echo "<?php // change" > index.php
    git commit -q -am "code"
    git checkout -q -b docs/only master
    echo "note" > documentation/note.md
    echo "name: x" > .github/workflows/x.yml
    git add -A && git commit -q -m "docs"
    git checkout -q -b fix/on-tagged master
    echo "<?php // late" > index.php
    git commit -q -am "late"
) >/dev/null 2>&1 || { echo "could not build the test repositories" >&2; exit 1; }

export BASE_REPO=o/r HEAD_REPO=o/r PR_AUTHOR=someone

echo "check-pr-branch.sh"

git -C "$work" checkout -q feat/code
BASE_REF=1.9.80 HEAD_REF=feat/code expect "a feature branch into its version branch is allowed" 0 "into version branch 1.9.80"
export BASE_REF HEAD_REF
BASE_REF=master HEAD_REF=feat/code expect "code into master is refused" 1 "Open this against the version branch (1.9.80)"
BASE_REF=master HEAD_REF=feat/code PR_AUTHOR='dependabot[bot]' expect "a Dependabot bump into master is allowed" 0 "Dependabot"
BASE_REF=1.9.81 HEAD_REF=feat/code expect "a version branch version.php does not name is refused" 1 "version.php names 1.9.80"
BASE_REF=feat/base HEAD_REF=feat/code expect "a stacked pull request is not held" 0 "not held"

git -C "$work" checkout -q 1.9.80
BASE_REF=master HEAD_REF=1.9.80 expect "the version branch into master is allowed before its tag" 0 "not tagged yet"
BASE_REF=master HEAD_REF=1.9.80 HEAD_REPO=fork/r expect "a fork's branch of the same name is not the version branch" 1 "master takes a finished version branch"

git -C "$work" checkout -q docs/only
BASE_REF=master HEAD_REF=docs/only expect "workflows and documentation into master are allowed" 0 "workflows and documentation only"

git -C "$work" checkout -q fix/on-tagged
BASE_REF=1.9.79 HEAD_REF=fix/on-tagged expect "work on a tagged version is refused" 1 "v1.9.79 is already tagged"

git -C "$work" checkout -q master
BASE_REF=master HEAD_REF=1.9.79 expect "a tagged version branch into master is refused" 1 "v1.9.79 is already tagged"

git -C "$work" checkout -q feat/code
BASE_REF=1.9.80 HEAD_REF=feat/code P202_TAGS_REMOTE="$root/nowhere" expect "an unreachable remote is not read as no tag" 2 "could not run"
BASE_REF='' HEAD_REF=feat/code expect "an empty base is not read as allowed" 2 "BASE_REF is empty"
unset BASE_REF HEAD_REF

echo
echo "check-claude-md.py"
# The backticks below are CLAUDE.md's own, not command substitutions.
# shellcheck disable=SC2016

md() { # <label> <want exit> <text> ; reads $work/CLAUDE.md
    local label=$1 want=$2 text=$3 out rc
    out=$( cd "$work" && python3 -I "$MD_CHECK" 2>&1 ); rc=$?
    if [ "$rc" = "$want" ] && printf '%s' "$out" | grep -q -- "$text"; then
        ok "$label"
    else
        no "$label" "expected exit $want holding '$text', got $rc: $out"
    fi
}

(
    cd "$work" || exit 1
    mkdir -p tests go-cli/cmd
    echo "<?php" > tests/RealTest.php
    printf 'package cmd\nfunc TestRealThing(t *testing.T) {}\n' > go-cli/cmd/x_test.go
    git add -A && git commit -q -m "tests"
) >/dev/null 2>&1

printf 'Held by `RealTest`, `TestRealThing` and `202-config/version.php`.\n' > "$work/CLAUDE.md"
md "names that are in the tree pass" 0 "3 names resolved"
printf 'Held by `RealTest` and `GoneTest`.\n' > "$work/CLAUDE.md"
md "a test that is not in the tree is reported" 1 "GoneTest"
printf 'Held by `RealTest` and `TestGoneThing`.\n' > "$work/CLAUDE.md"
md "a Go test that is not in the tree is reported" 1 "TestGoneThing"
printf 'See `RealTest` and `tests/live/gone.sh`.\n' > "$work/CLAUDE.md"
md "a file that is not in the tree is reported" 1 "tests/live/gone.sh"
{ printf 'Held by `RealTest`.\n'; head -c 61000 /dev/zero | tr '\0' 'x'; } > "$work/CLAUDE.md"
md "a file over the budget is reported" 1 "byte budget"
printf 'No names here.\n' > "$work/CLAUDE.md"
md "a file naming nothing is not read as clean" 2 "found no test"

echo
echo "passed: $pass  failed: $fail"
[ "$fail" -eq 0 ]
