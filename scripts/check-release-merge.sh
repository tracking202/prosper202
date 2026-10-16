#!/usr/bin/env bash
#
# Before the pull request that merges a version branch into master: the
# merge must produce exactly the version branch's tree. Master once took an
# accidental merge of 1.9.78 and reverted it (#218, #219), and git then
# counts the reverted commits as merged already: a plain merge of 1.9.78
# kept the revert, with version.php back at 1.9.77 and 58 files short, and
# nothing in the pull request showed it. The fix is to bring master's
# revert into the version branch and revert it there; this script says
# whether that (or anything like it) is still needed.
#
#   scripts/check-release-merge.sh 1.9.78            # origin/1.9.78 into origin/master
#   scripts/check-release-merge.sh <branch> <base>   # any two refs
#
# Fetch first. Exits 0 when the merged tree equals the branch's, 1 with the
# files that differ (or the conflict) otherwise, 2 on bad arguments.

set -euo pipefail

branch="${1:-}"
base="${2:-origin/master}"
if [ -z "$branch" ]; then
    echo "usage: scripts/check-release-merge.sh <version branch> [base ref]" >&2
    exit 2
fi
# A bare version name means the remote's branch; a local copy may be stale.
if [[ "$branch" != */* && "$branch" != HEAD ]] && git rev-parse -q --verify "origin/$branch^{commit}" >/dev/null; then
    branch="origin/$branch"
fi
for ref in "$branch" "$base"; do
    if ! git rev-parse -q --verify "$ref^{commit}" >/dev/null; then
        echo "check-release-merge: no such ref: $ref" >&2
        exit 2
    fi
done

if ! merged=$(git merge-tree --write-tree "$base" "$branch" 2>&1); then
    echo "check-release-merge: merging $branch into $base conflicts in:" >&2
    git merge-tree --write-tree --name-only --no-messages "$base" "$branch" 2>/dev/null | tail -n +2 | sed '/^$/,$d' >&2 || true
    echo "Bring $base's commits into $branch first (revert any revert of $branch's own commits there), then check again." >&2
    exit 1
fi
tree=$(printf '%s\n' "$merged" | head -n 1)
differs=$(git diff --name-only "$tree" "$branch")
if [ -n "$differs" ]; then
    echo "check-release-merge: merging $branch into $base would not give $branch's tree; these files would differ:" >&2
    printf '%s\n' "$differs" >&2
    echo "Bring $base's commits into $branch first (revert any revert of $branch's own commits there), then check again." >&2
    exit 1
fi
echo "check-release-merge: merging $branch into $base gives exactly $branch's tree."
