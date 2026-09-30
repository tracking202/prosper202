#!/usr/bin/env bash
#
# Every action a workflow uses must be pinned to a full commit SHA, with the
# version it stands for in a trailing comment:
#
#     uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v4.4.0
#
# A tag such as @v4 can be moved to other code, and several jobs hold a
# write token or build the zip customers install. Dependabot keeps the pins
# current (.github/dependabot.yml) but only rewrites references that already
# exist; it does not refuse a new @v4, and actionlint does not either. This
# script does, so the invariant cannot regress in the workflow that adds the
# next action (it did once, in a merge that brought two new workflows in).
# Runs in CI from the actionlint job of .github/workflows/scripts-lint.yml
# and locally from the verify ladder's actionlint tier.
#
# Accepted shapes, and nothing else:
#   uses: owner/repo[/path]@<40 hex> # <version>    a pinned action or reusable workflow
#   uses: ./path                                    an action in this repository
#
# Exit 1 with one line per offending reference; exit 2 when no workflow has
# any `uses:` at all, because then the check has read nothing.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

shopt -s nullglob
files=(.github/workflows/*.yml .github/workflows/*.yaml)
if [ "${#files[@]}" -eq 0 ]; then
    echo "check-action-pins: no workflow files under .github/workflows" >&2
    exit 2
fi

# The whole line, quotes optional, the SHA, then the comment. Kept in a
# variable so the quotes inside the bracket expressions survive [[ =~ ]].
pinned='^[[:space:]]*(-[[:space:]]*)?uses:[[:space:]]*["'"'"']?[A-Za-z0-9_.-]+/[A-Za-z0-9_./-]+@[0-9a-f]{40}["'"'"']?[[:space:]]+#[[:space:]]*[^[:space:]]+'

seen=0
bad=0
while IFS= read -r hit; do
    seen=$((seen + 1))
    file="${hit%%:*}"
    rest="${hit#*:}"
    line="${rest%%:*}"
    text="${rest#*:}"
    # The value after `uses:`, without surrounding quotes or a trailing
    # comment. Anything the two accepted patterns do not match is reported,
    # so an unfamiliar shape fails rather than passing unread.
    value="$(printf '%s' "$text" | sed -E 's/^[[:space:]]*(-[[:space:]]*)?uses:[[:space:]]*//; s/[[:space:]]*(#.*)?$//; s/^"(.*)"$/\1/; s/^'"'"'(.*)'"'"'$/\1/')"
    if [[ "$value" =~ ^\./ ]]; then
        continue
    fi
    if [[ "$text" =~ $pinned ]]; then
        continue
    fi
    if [[ "$value" =~ @[0-9a-f]{40}$ ]]; then
        echo "${file}:${line}: pinned but without the version comment: ${value}" >&2
    else
        echo "${file}:${line}: not pinned to a commit SHA: ${value}" >&2
    fi
    bad=$((bad + 1))
done < <(grep -Hn -E '^[[:space:]]*(-[[:space:]]*)?uses:' "${files[@]}")

if [ "$seen" -eq 0 ]; then
    echo "check-action-pins: found no 'uses:' in ${#files[@]} workflow file(s); nothing was checked" >&2
    exit 2
fi
if [ "$bad" -ne 0 ]; then
    echo "check-action-pins: ${bad} of ${seen} action reference(s) are not pinned to a commit SHA with a version comment" >&2
    exit 1
fi
echo "check-action-pins: all ${seen} action references are pinned to a commit SHA"
