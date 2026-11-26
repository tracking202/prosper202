#!/usr/bin/env bash
#
# Tests for scripts/dependency-audit.sh: which runs are strict, against a
# throwaway repository, and how each answer of composer audit and
# govulncheck (stood in for by shims) is reported in a strict run and in one
# that only warns.
#
# Usage: scripts/tests/dependency-audit.test.sh
# Exits 0 when every case passes, 1 otherwise.

set -uo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
AUDIT="$HERE/../dependency-audit.sh"

pass=0
fail=0
ok() { echo "  ok   - $1"; pass=$((pass + 1)); }
no() { echo "  FAIL - $1"; echo "         $2"; fail=$((fail + 1)); }

# expect <label> <exit code> <text the output must hold> <args...>; the
# environment is the caller's.
expect() {
    local label=$1 want=$2 text=$3 out rc
    shift 3
    out=$( cd "$work" && "$AUDIT" "$@" 2>&1 ); rc=$?
    if [ "$rc" != "$want" ]; then
        no "$label" "expected exit $want, got $rc: $out"
    elif ! printf '%s' "$out" | grep -qF -- "$text"; then
        no "$label" "exit $rc as expected, but the output lacks '$text': $out"
    else
        ok "$label"
    fi
}

root=$(mktemp -d)
trap 'rm -rf "$root"' EXIT
remote="$root/remote.git"
work="$root/work"
shims="$root/shims"
mkdir -p "$shims"

(
    git init -q --bare "$remote"
    git init -q -b base "$work"
    cd "$work" || exit 1
    git config user.email test@example.com
    git config user.name test
    mkdir -p go-cli documentation
    echo '{}' > composer.lock
    echo 'module x' > go-cli/go.mod
    : > go-cli/go.sum
    echo a > documentation/a.md
    git add -A && git commit -q -m base
    git remote add origin "$remote"
    git push -q origin base
    git checkout -q -b docs base
    echo b > documentation/a.md
    git commit -q -am docs
    git checkout -q -b lock base
    echo '{"x":1}' > composer.lock
    git commit -q -am lock
    git checkout -q -b gosum base
    echo 'x v1 h1:x' > go-cli/go.sum
    git commit -q -am gosum
) >/dev/null 2>&1 || { echo "could not build the test repository" >&2; exit 1; }
base_sha=$(git -C "$work" rev-parse base)

echo "dependency-audit.sh mode"
unset P202_AUDIT_STRICT GITHUB_STEP_SUMMARY BEFORE_SHA BASE_REF
export GITHUB_EVENT_NAME=pull_request BASE_REF=base
git -C "$work" checkout -q docs
expect "a pull request changing docs only warns" 0 "strict=0" mode
git -C "$work" checkout -q lock
expect "a pull request changing composer.lock is strict" 0 "strict=1 (the change touches composer.lock)" mode
git -C "$work" checkout -q gosum
expect "a pull request changing go-cli/go.sum is strict" 0 "strict=1 (the change touches go-cli/go.sum)" mode
git -C "$work" checkout -q docs
BASE_REF=nosuch expect "a pull request whose base cannot be read is strict" 0 "strict=1 (the pull request's changes could not be read" mode
BASE_REF='' expect "a pull request with no base is strict" 0 "strict=1 (a pull request with no BASE_REF)" mode
export GITHUB_EVENT_NAME=push
BEFORE_SHA=$base_sha expect "a push changing docs only warns" 0 "strict=0" mode
git -C "$work" checkout -q lock
BEFORE_SHA=$base_sha expect "a push changing composer.lock is strict" 0 "strict=1 (the change touches composer.lock)" mode
BEFORE_SHA=0000000000000000000000000000000000000000 expect "a push that creates a branch is strict" 0 "strict=1 (a push with no earlier commit" mode
BEFORE_SHA=1111111111111111111111111111111111111111 expect "a push whose earlier commit is unknown is strict" 0 "strict=1 (the push's changes could not be read" mode
GITHUB_EVENT_NAME=schedule expect "a scheduled run is strict" 0 "strict=1 (a schedule run)" mode
GITHUB_EVENT_NAME=workflow_dispatch expect "an on-demand run is strict" 0 "strict=1 (a workflow_dispatch run)" mode
GITHUB_EVENT_NAME=merge_group expect "an event the script does not read is strict" 0 "strict=1 (event 'merge_group'" mode
GITHUB_EVENT_NAME='' expect "no event is strict" 0 "strict=1" mode

# composer stand-in: FAKE_COMPOSER picks the answer.
cat > "$shims/composer" <<'SH'
#!/usr/bin/env bash
case "$*" in *--format=plain*) echo "plain report: guzzlehttp/guzzle CVE-0000-0000"; exit 1 ;; esac
case "${FAKE_COMPOSER:-}" in
    clean) printf '{"advisories": [], "abandoned": []}\n'; exit 0 ;;
    vuln) printf '{"advisories": {"a/b": [{"title": "t"}, {"title": "u"}]}, "abandoned": []}\n'; exit 1 ;;
    neterr) echo "curl error 7 while downloading" >&2; exit 100 ;;
    errone) echo "something failed" >&2; exit 1 ;;
    cleanbutone) printf '{"advisories": [], "abandoned": []}\n'; exit 1 ;;
    *) exit 99 ;;
esac
SH
cat > "$shims/govulncheck" <<'SH'
#!/usr/bin/env bash
echo "govulncheck stand-in in $(basename "$PWD")"
exit "${FAKE_GOVULNCHECK_RC:-0}"
SH
chmod +x "$shims/composer" "$shims/govulncheck"
export PATH="$shims:$PATH" P202_GOVULNCHECK="$shims/govulncheck"

echo "dependency-audit.sh composer"
git -C "$work" checkout -q docs
export GITHUB_EVENT_NAME=pull_request BASE_REF=base
summary_file="$root/summary.md"
for strictness in 1 0; do
    if [ "$strictness" = 1 ]; then export P202_AUDIT_STRICT=1; s="strict"; else unset P202_AUDIT_STRICT; s="warning"; fi
    : > "$summary_file"
    GITHUB_STEP_SUMMARY=$summary_file FAKE_COMPOSER=clean expect "$s: a clean lock passes" 0 "composer audit: no advisory" composer
    grep -q "no known vulnerability" "$summary_file" && ok "$s: the clean run is in the job summary" || no "$s: the clean run is in the job summary" "$(cat "$summary_file")"
    if [ "$strictness" = 1 ]; then
        FAKE_COMPOSER=vuln expect "$s: advisories fail" 1 "::error title=composer audit::2 advisory(ies)" composer
        FAKE_COMPOSER=neterr expect "$s: an audit that could not reach Packagist fails" 1 "::error title=composer audit::did not run" composer
        FAKE_COMPOSER=errone expect "$s: exit 1 with no JSON is not a finding, and fails" 1 "::error title=composer audit::did not run" composer
        FAKE_COMPOSER=cleanbutone expect "$s: exit 1 with no advisory listed fails" 1 "did not run: composer audit exited 1 but listed 0" composer
    else
        : > "$summary_file"
        GITHUB_STEP_SUMMARY=$summary_file FAKE_COMPOSER=vuln expect "$s: advisories warn and pass" 0 "::warning title=composer audit::2 advisory(ies)" composer
        grep -q "plain report: guzzlehttp/guzzle" "$summary_file" && ok "$s: the advisories are in the job summary" || no "$s: the advisories are in the job summary" "$(cat "$summary_file")"
        FAKE_COMPOSER=neterr expect "$s: an audit that could not run says so and passes" 0 "::warning title=composer audit::did not run" composer
        FAKE_COMPOSER=errone expect "$s: exit 1 with no JSON is reported as not run" 0 "::warning title=composer audit::did not run" composer
    fi
done
unset P202_AUDIT_STRICT
git -C "$work" checkout -q lock
FAKE_COMPOSER=vuln expect "a pull request changing composer.lock fails on advisories" 1 "::error title=composer audit::" composer

echo "dependency-audit.sh go"
git -C "$work" checkout -q docs
FAKE_GOVULNCHECK_RC=0 expect "warning: clean passes" 0 "govulncheck: no reachable known vulnerability" go
FAKE_GOVULNCHECK_RC=0 expect "govulncheck runs in go-cli/" 0 "govulncheck stand-in in go-cli" go
FAKE_GOVULNCHECK_RC=3 expect "warning: a finding warns and passes" 0 "::warning title=govulncheck::a known vulnerability is reachable" go
FAKE_GOVULNCHECK_RC=1 expect "warning: an error is reported as not run" 0 "::warning title=govulncheck::did not run: govulncheck exited 1" go
git -C "$work" checkout -q gosum
FAKE_GOVULNCHECK_RC=3 expect "strict: a finding fails" 1 "::error title=govulncheck::a known vulnerability is reachable" go
FAKE_GOVULNCHECK_RC=1 expect "strict: an error fails" 1 "::error title=govulncheck::did not run" go
FAKE_GOVULNCHECK_RC=0 expect "strict: clean passes" 0 "govulncheck: no reachable known vulnerability" go

expect "an unknown command is bad usage" 2 "usage:" nosuch

echo
echo "passed: $pass  failed: $fail"
[ "$fail" -eq 0 ]
