#!/usr/bin/env bash
#
# Hold a pull request to the branch rules in CLAUDE.md ("Branches").
#
#   into a version branch (1.9.80): the branch is the version version.php
#       names, and that version is not tagged yet (a tagged version is frozen).
#   into master: the version branch itself, before its tag; a Dependabot
#       bump; or a change to nothing but workflows and documentation.
#   into any other branch (a stacked pull request): not held.
#
# Environment: BASE_REF, HEAD_REF, PR_AUTHOR, HEAD_REPO, BASE_REPO.
# P202_TAGS_REMOTE names the remote asked for tags (default origin).
# Run from the repo root, on the pull request's checkout.
# Exits 0 when allowed, 1 when refused, 2 when it could not tell.

set -uo pipefail

BASE_REF=${BASE_REF:-}
HEAD_REF=${HEAD_REF:-}
PR_AUTHOR=${PR_AUTHOR:-}
HEAD_REPO=${HEAD_REPO:-}
BASE_REPO=${BASE_REPO:-}
TAGS_REMOTE=${P202_TAGS_REMOTE:-origin}

refuse() { echo "::error::$1"; exit 1; }
cannot() { echo "::error::check-pr-branch could not run: $1"; exit 2; }

[ -n "$BASE_REF" ] || cannot "BASE_REF is empty"
[ -n "$HEAD_REF" ] || cannot "HEAD_REF is empty"

version=$(sed -n "s/^\$version_string = '\([0-9][0-9.]*[0-9]\)';.*/\1/p" 202-config/version.php 2>/dev/null)
[ -n "$version" ] || cannot "no version read from 202-config/version.php"

# 0 when v<version> is tagged on the remote, 1 when not. An unanswered
# question is not "no tag": it ends the run.
tagged() {
    local out
    out=$(git ls-remote --tags "$TAGS_REMOTE" "refs/tags/v$version" 2>&1) \
        || cannot "git ls-remote --tags $TAGS_REMOTE failed: $out"
    [ -n "$out" ]
}

is_version() { printf '%s' "$1" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; }

if [ "$BASE_REF" = "master" ]; then
    if [ "$PR_AUTHOR" = "dependabot[bot]" ]; then
        echo "into master: a Dependabot bump"
        exit 0
    fi
    if [ "$HEAD_REF" = "$version" ] && [ "$HEAD_REPO" = "$BASE_REPO" ]; then
        if tagged; then
            refuse "v$version is already tagged. A tagged version is frozen: this work belongs to the next version."
        fi
        echo "into master: version branch $version, not tagged yet"
        exit 0
    fi
    files=$(git diff --name-only "origin/$BASE_REF...HEAD" 2>&1) \
        || cannot "git diff against origin/$BASE_REF failed: $files"
    [ -n "$files" ] || cannot "the diff against origin/$BASE_REF lists no file"
    other=$(printf '%s\n' "$files" | grep -Ev '^(\.github/|docs/|documentation/)|\.md$' || true)
    if [ -n "$other" ]; then
        refuse "master takes a finished version branch, a Dependabot bump, or a change to workflows and documentation only. Open this against the version branch ($version). Outside those: $(printf '%s' "$other" | head -5 | tr '\n' ' ')"
    fi
    echo "into master: workflows and documentation only"
    exit 0
fi

if is_version "$BASE_REF"; then
    if [ "$BASE_REF" != "$version" ]; then
        refuse "this pull request is into $BASE_REF, and 202-config/version.php names $version. Open it against the branch its version names."
    fi
    if tagged; then
        refuse "v$version is already tagged. A tagged version is frozen: start the next version from master and bump version.php."
    fi
    echo "into version branch $version, not tagged yet"
    exit 0
fi

echo "into $BASE_REF: not master or a version branch, not held"
exit 0
