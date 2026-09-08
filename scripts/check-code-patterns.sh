#!/usr/bin/env bash
set -euo pipefail

# Check new and modified PHP files for anti-patterns defined in CLAUDE.md.
# Used as a Claude Code Stop hook — exit 2 blocks Claude from finishing
# until violations are fixed.
#
# For a file that git already tracks, only ADDED/MODIFIED lines are checked,
# so legacy code below the change does not produce false positives.
#
# For a file git has never seen, the whole file is checked. `git diff` emits
# nothing for an untracked path, so selecting work from the diff alone let a
# brand-new .php file through every check while the hook still reported clean
# — a check that appeared to run and did not (CLAUDE.md error pattern #10).
# There is no legacy code in a new file, so whole-file treatment cannot
# reintroduce the false positives the diff scoping exists to avoid.
#
# Also runs PHPStan on those files to catch type errors.

violations=""
violation_count=0

add_violation() {
    local file="$1"
    local pattern="$2"
    local fix="$3"
    violations+="  - ${file}: ${pattern} -> ${fix}"$'\n'
    violation_count=$((violation_count + 1))
}

# Collect the PHP files this run is responsible for. `git diff --name-only
# HEAD` covers tracked files whether the change is staged or not, and it
# already reports a newly `git add`ed file. It cannot report a file that was
# never added, hence the second listing. --exclude-standard keeps .gitignore
# in force, so vendor/ and friends stay out.
tracked_php=$(git diff --name-only HEAD -- '*.php' 2>/dev/null || true)
untracked_php=$(git ls-files --others --exclude-standard -- '*.php' 2>/dev/null || true)

files=$(printf '%s\n%s\n' "$tracked_php" "$untracked_php" | awk 'NF' | sort -u)
if [ -z "$files" ]; then
    exit 0
fi

# A path git has never seen. Exact whole-line match so that `foo.php` is not
# mistaken for `src/foo.php`.
is_untracked() {
    printf '%s\n' "$untracked_php" | grep -qxF -- "$1"
}

# ═══════════════════════════════════════════════════════
# Part 1: Pattern checks on diff output
# ═══════════════════════════════════════════════════════

while IFS= read -r file; do
    [ -f "$file" ] || continue

    # Tracked: only the new/modified lines. Untracked: every line is new, and
    # `git diff` would return nothing at all for this path.
    if is_untracked "$file"; then
        added=$(cat "$file")
    else
        added=$(git diff HEAD -- "$file" | grep '^+' | grep -v '^+++' || true)
    fi
    [ -z "$added" ] && continue

    # Write to temp file so grep reads from file, not stdin (avoids option parsing issues)
    tmpfile=$(mktemp)
    printf '%s\n' "$added" > "$tmpfile"

    # Skip test files for some patterns (mocking is OK in tests)
    is_test=false
    if [[ "$file" == tests/* ]]; then
        is_test=true
    fi

    # ── Pattern 1: Direct $stmt->execute() bypassing Connection ──
    # CLAUDE.md #1: Unchecked return values after fallible calls
    if [ "$is_test" = false ]; then
        if grep -qF '$stmt->execute()' "$tmpfile"; then
            add_violation "$file" \
                'Direct $stmt->execute()' \
                'Use $this->conn->execute($stmt) for checked execution'
        fi
    fi

    # ── Pattern 2: Direct $stmt->bind_param() bypassing Connection::bind() ──
    # Consistency: Connection::bind() stores refs to prevent premature GC
    if [ "$is_test" = false ]; then
        if grep -qF '$stmt->bind_param(' "$tmpfile"; then
            add_violation "$file" \
                'Direct $stmt->bind_param()' \
                'Use $this->conn->bind($stmt, $types, $values) for ref safety'
        fi
    fi

    # ── Pattern 3: PASSWORD_BCRYPT hard-coded ──
    # CLAUDE.md #5: Inconsistent security patterns
    if grep -qF 'PASSWORD_BCRYPT' "$tmpfile"; then
        add_violation "$file" \
            'PASSWORD_BCRYPT' \
            'Use hash_user_pass() from functions-auth.php for consistent hashing'
    fi

    # ── Pattern 4: Direct password_hash() call ──
    # CLAUDE.md #5: Inconsistent security patterns
    if [ "$is_test" = false ]; then
        # Exclude the definition in functions-auth.php itself
        if [[ "$file" != *"functions-auth.php" ]]; then
            if grep -qF 'password_hash(' "$tmpfile"; then
                add_violation "$file" \
                    'Direct password_hash()' \
                    'Use hash_user_pass() for centralized hashing policy'
            fi
        fi
    fi

    # ── Pattern 5: json_decode() ?? [] silent fallback ──
    # CLAUDE.md #4: Silent data loss on malformed input
    if grep -qE 'json_decode\(.+\)\s*\?\?\s*\[\]' "$tmpfile"; then
        add_violation "$file" \
            'json_decode(...) ?? []' \
            'Malformed JSON must produce errors, not silent empty arrays'
    fi

    # ── Pattern 6: $stmt->close() after executeInsert() ──
    # executeInsert() already closes the statement
    if [ "$is_test" = false ]; then
        if grep -qF 'executeInsert(' "$tmpfile"; then
            if grep -qF '$stmt->close()' "$tmpfile"; then
                add_violation "$file" \
                    '$stmt->close() after executeInsert()' \
                    'executeInsert() already closes the statement (double close)'
            fi
        fi
    fi

    # ── Pattern 7: execute() before fetchOne()/fetchAll() ──
    # fetchOne/fetchAll already call execute() internally
    if [ "$is_test" = false ]; then
        if grep -qE -- '->execute\(\$stmt\)' "$tmpfile"; then
            if grep -qE -- '->fetch(One|All)\(' "$tmpfile"; then
                add_violation "$file" \
                    'execute() before fetchOne()/fetchAll()' \
                    'fetchOne/fetchAll already execute (double-executes the query)'
            fi
        fi
    fi

    # ── Pattern 8: Digit-prefixed SQL alias ──
    # MySQL rejects unquoted aliases starting with a digit
    if grep -qE '\bAS\s+[0-9][a-zA-Z_]+\b' "$tmpfile"; then
        add_violation "$file" \
            'SQL alias starting with digit' \
            'MySQL requires aliases to start with a letter (e.g., AS cv2 not AS 2cv)'
    fi

    rm -f "$tmpfile"

done <<< "$files"

# ═══════════════════════════════════════════════════════
# Part 2: PHPStan on modified files
# ═══════════════════════════════════════════════════════

phpstan_bin="./vendor/bin/phpstan"
if [ -x "$phpstan_bin" ] || [ -f "$phpstan_bin" ]; then
    # Build list of files that exist and are under analysed paths
    analyse_files=()
    while IFS= read -r file; do
        if [ -f "$file" ]; then
            analyse_files+=("$file")
        fi
    done <<< "$files"

    if [ "${#analyse_files[@]}" -gt 0 ]; then
        # PHPStan reports findings through its exit status: 1 when it has
        # something to say, 0 when clean. Ask it that question directly.
        #
        # This used to grep the output for the literal "[ERROR]". That marker
        # belongs to the default table renderer and is never emitted under
        # --error-format=raw, which prints bare "path:line:message" lines, so
        # the grep never matched and this entire PHPStan gate passed no matter
        # what was found. `|| true` on the assignment hid the exit status that
        # would have given the game away.
        #
        # -c is not optional. Without it PHPStan picks up phpstan.neon, which
        # is gitignored, machine-local, and does not include
        # phpstan-baseline.neon -- so every pre-existing error in a legacy file
        # would block the hook the moment anyone touched that file, and the
        # hook would be switched off within a day. The dist config carries the
        # baseline and is what CI runs, so only genuinely new findings fire.
        set +e
        phpstan_output=$(php -d memory_limit=512M "$phpstan_bin" analyse \
            -c phpstan.neon.dist \
            --no-progress --error-format=raw --memory-limit=512M \
            "${analyse_files[@]}" 2>&1)
        phpstan_status=$?
        set -e

        if [ "$phpstan_status" -ne 0 ]; then
            error_lines=$(echo "$phpstan_output" | grep -v '^\s*$' | grep -v '^\s*Note:' | grep -v '^\s*\[OK\]' || true)
            if [ -n "$error_lines" ]; then
                violations+="  PHPStan errors:"$'\n'
                while IFS= read -r line; do
                    [ -n "$line" ] && violations+="    $line"$'\n'
                done <<< "$error_lines"
                violation_count=$((violation_count + 1))
            else
                # PHPStan said something is wrong and the filters left nothing
                # to show: a crash, a bad config, an out-of-memory kill. Report
                # the status rather than falling through to a pass, because an
                # unreadable answer is not a clean one (CLAUDE.md #11).
                violations+="  PHPStan exited $phpstan_status with no parseable output; run it directly to see why."$'\n'
                violation_count=$((violation_count + 1))
            fi
        fi
    fi
fi

# ═══════════════════════════════════════════════════════
# Report
# ═══════════════════════════════════════════════════════

if [ "$violation_count" -gt 0 ]; then
    {
        echo "Found $violation_count code pattern violation(s) in new and modified PHP files:"
        echo ""
        echo "$violations"
        echo "Ref: CLAUDE.md 'Error patterns to avoid'"
        echo "Fix the violations above before finishing."
    } >&2
    exit 2
fi

exit 0
