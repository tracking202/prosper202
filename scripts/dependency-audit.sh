#!/usr/bin/env bash
#
# Known vulnerabilities in what the release ships, for
# .github/workflows/dependency-audit.yml:
#
#   scripts/dependency-audit.sh composer   composer audit on composer.lock
#   scripts/dependency-audit.sh go         govulncheck on go-cli/
#   scripts/dependency-audit.sh mode       print whether this run is strict
#
# A strict run fails on an advisory, and on an audit that could not run: a
# scheduled or on-demand run, a pull request or push that changes a file
# below, and any run whose changes cannot be read. Any other run reports
# what it found as a warning and passes, so an advisory published today
# does not turn unrelated work red. A warning says which of the two it was:
# "found" or "did not run" are never folded into a pass.
#
# Environment (GitHub's): GITHUB_EVENT_NAME; BASE_REF for a pull request
# (its base branch, fetched as origin/BASE_REF); BEFORE_SHA for a push;
# GITHUB_STEP_SUMMARY when set. P202_AUDIT_STRICT=1 forces a strict run.
# P202_GOVULNCHECK names a govulncheck binary instead of installing one.
# Run from the repo root. Exits 0 when the run passes, 1 when it fails,
# 2 on bad usage.

set -uo pipefail

GOVULNCHECK_VERSION=v1.9.0
WATCHED='^(composer\.json|composer\.lock|go-cli/go\.mod|go-cli/go\.sum|\.github/workflows/dependency-audit\.yml|scripts/dependency-audit\.sh)$'

summary() { [ -n "${GITHUB_STEP_SUMMARY:-}" ] && printf '%s\n' "$@" >> "$GITHUB_STEP_SUMMARY"; return 0; }

# Sets strict (1 or 0) and why.
decide() {
    local files
    strict=1
    if [ "${P202_AUDIT_STRICT:-}" = 1 ]; then why="P202_AUDIT_STRICT=1"; return; fi
    case "${GITHUB_EVENT_NAME:-}" in
        pull_request)
            if [ -z "${BASE_REF:-}" ]; then why="a pull request with no BASE_REF"; return; fi
            if ! files=$(git diff --name-only "origin/$BASE_REF...HEAD" 2>&1); then
                why="the pull request's changes could not be read: $files"; return
            fi
            ;;
        push)
            if [ -z "${BEFORE_SHA:-}" ] || ! printf '%s' "$BEFORE_SHA" | grep -Eq '^[0-9a-f]{40}$' \
                || [ "$BEFORE_SHA" = 0000000000000000000000000000000000000000 ]; then
                why="a push with no earlier commit to compare with"; return
            fi
            if ! files=$(git diff --name-only "$BEFORE_SHA" HEAD 2>&1); then
                why="the push's changes could not be read: $files"; return
            fi
            ;;
        schedule|workflow_dispatch)
            why="a ${GITHUB_EVENT_NAME} run"; return ;;
        *)
            why="event '${GITHUB_EVENT_NAME:-}' is not one this script reads"; return ;;
    esac
    local hit
    hit=$(printf '%s\n' "$files" | grep -E "$WATCHED" | head -3 | tr '\n' ' ')
    if [ -n "$hit" ]; then
        why="the change touches ${hit% }"
    else
        strict=0
        why="the change touches no manifest, lock file or this audit"
    fi
}

# found <tool> <message> <details file>
found() {
    local tool=$1 msg=$2 details=$3
    summary "### $tool: advisories found" "" "$msg" "" '```' "$(head -c 60000 "$details")" '```'
    if [ "$strict" = 1 ]; then
        echo "::error title=$tool::$msg (strict: $why)"
        cat "$details"
        exit 1
    fi
    echo "::warning title=$tool::$msg. Not failing: $why. The weekly run fails on it."
    cat "$details"
    exit 0
}

# did_not_run <tool> <message> <details file>
did_not_run() {
    local tool=$1 msg=$2 details=$3
    summary "### $tool: did not run" "" "$msg" "" '```' "$(head -c 20000 "$details")" '```'
    cat "$details" >&2
    if [ "$strict" = 1 ]; then
        echo "::error title=$tool::did not run: $msg (strict: $why)"
        exit 1
    fi
    echo "::warning title=$tool::did not run: $msg. Not failing: $why. This is no answer about the dependencies."
    exit 0
}

clean() {
    summary "### $1: no known vulnerability" "" "$2"
    echo "$1: $2"
    exit 0
}

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

case "${1:-}" in
    mode)
        decide
        echo "strict=$strict ($why)"
        exit 0
        ;;
    composer)
        decide
        echo "dependency-audit composer: strict=$strict ($why)"
        # Exit 0 clean, 1 with advisories (abandonment is reported only),
        # anything else an error; the JSON is read as well, so an error
        # that exits 1 is not taken for a finding.
        composer audit --locked --no-interaction --abandoned=report --format=json \
            > "$work/out.json" 2> "$work/err.txt"
        rc=$?
        count=$(python3 -I - "$work/out.json" <<'PY' 2>>"$work/err.txt"
import json, sys
with open(sys.argv[1]) as f:
    d = json.load(f)
a = d["advisories"]
if isinstance(a, dict):
    print(sum(len(v) for v in a.values()))
elif a == []:
    print(0)
else:
    raise SystemExit("advisories is neither an object nor an empty list")
PY
        ) || count=""
        if [ -z "$count" ] || { [ "$rc" != 0 ] && [ "$rc" != 1 ]; }; then
            cat "$work/out.json" >> "$work/err.txt"
            did_not_run "composer audit" "composer audit exited $rc without an answer it could read" "$work/err.txt"
        fi
        if [ "$count" = 0 ] && [ "$rc" = 0 ]; then
            clean "composer audit" "no advisory for any package in composer.lock"
        fi
        if [ "$count" != 0 ] && [ "$rc" = 1 ]; then
            composer audit --locked --no-interaction --abandoned=report --format=plain > "$work/plain.txt" 2>&1 || true
            found "composer audit" "$count advisory(ies) for packages in composer.lock" "$work/plain.txt"
        fi
        cat "$work/out.json" >> "$work/err.txt"
        did_not_run "composer audit" "composer audit exited $rc but listed $count advisory(ies)" "$work/err.txt"
        ;;
    go)
        decide
        echo "dependency-audit go: strict=$strict ($why)"
        bin=${P202_GOVULNCHECK:-}
        if [ -z "$bin" ]; then
            if ! GOBIN="$work/bin" go install "golang.org/x/vuln/cmd/govulncheck@$GOVULNCHECK_VERSION" > "$work/install.txt" 2>&1; then
                did_not_run govulncheck "govulncheck $GOVULNCHECK_VERSION could not be installed" "$work/install.txt"
            fi
            bin="$work/bin/govulncheck"
        fi
        # Exit 0 clean, 3 with a reachable vulnerability, anything else an error.
        (cd go-cli && "$bin" ./...) > "$work/out.txt" 2>&1
        rc=$?
        case "$rc" in
            0) cat "$work/out.txt"; clean govulncheck "no reachable known vulnerability in the Go CLI, its modules or its Go" ;;
            3) found govulncheck "a known vulnerability is reachable from the Go CLI (a finding in the standard library is fixed by bumping Go)" "$work/out.txt" ;;
            *) did_not_run govulncheck "govulncheck exited $rc" "$work/out.txt" ;;
        esac
        ;;
    *)
        echo "usage: scripts/dependency-audit.sh composer|go|mode" >&2
        exit 2
        ;;
esac
