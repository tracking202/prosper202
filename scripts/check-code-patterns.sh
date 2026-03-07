#!/usr/bin/env bash
set -euo pipefail

# Check modified PHP files for anti-patterns defined in CLAUDE.md.
# Used as a Claude Code Stop hook — exit 2 blocks Claude from finishing
# until violations are fixed.
#
# Only checks ADDED/MODIFIED lines (git diff), not entire files,
# to avoid false positives on legacy code.
#
# Also runs PHPStan on modified files to catch type errors.

violations=""
violation_count=0

add_violation() {
    local file="$1"
    local pattern="$2"
    local fix="$3"
    violations+="  - ${file}: ${pattern} -> ${fix}"$'\n'
    ((violation_count++))
}

# Check if there are modified PHP files
files=$(git diff --name-only HEAD -- '*.php' 2>/dev/null || true)
if [ -z "$files" ]; then
    exit 0
fi

# ═══════════════════════════════════════════════════════
# Part 1: Pattern checks on diff output
# ═══════════════════════════════════════════════════════

while IFS= read -r file; do
    [ -f "$file" ] || continue

    # Only check new/modified lines in the diff
    added=$(git diff HEAD -- "$file" | grep '^+' | grep -v '^+++' || true)
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
        phpstan_output=$(php -d memory_limit=512M "$phpstan_bin" analyse \
            --no-progress --error-format=raw --memory-limit=512M \
            "${analyse_files[@]}" 2>&1 || true)

        if echo "$phpstan_output" | grep -qF '[ERROR]'; then
            error_lines=$(echo "$phpstan_output" | grep -v '^\s*$' | grep -v '^\s*Note:' | grep -v '^\s*\[OK\]' || true)
            if [ -n "$error_lines" ]; then
                violations+="  PHPStan errors:"$'\n'
                while IFS= read -r line; do
                    [ -n "$line" ] && violations+="    $line"$'\n'
                done <<< "$error_lines"
                ((violation_count++))
            fi
        fi
    fi
fi

# ═══════════════════════════════════════════════════════
# Report
# ═══════════════════════════════════════════════════════

if [ "$violation_count" -gt 0 ]; then
    {
        echo "Found $violation_count code pattern violation(s) in modified PHP files:"
        echo ""
        echo "$violations"
        echo "Ref: CLAUDE.md 'Error patterns to avoid'"
        echo "Fix the violations above before finishing."
    } >&2
    exit 2
fi

exit 0
