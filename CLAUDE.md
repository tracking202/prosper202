# CLAUDE.md

## Project
Prosper202 — PHP 8.3 affiliate tracking platform with REST API v3 (mysqli, PSR-4) and Symfony Console CLI.

## Error patterns to avoid

### 1. Unchecked return values after fallible calls
`$stmt->execute()` can return false without throwing. Every execute() must be checked: `if (!$stmt->execute()) { $stmt->close(); throw ...; }`. Same applies to `$db->query()`, `json_encode()`, and any call that signals failure via return value rather than exception. This is especially critical inside transactions — an unchecked failure means partial operations get committed.

### 2. Dead code referencing nonexistent schema
Never reference DB columns, tables, or config keys without verifying they exist in the actual schema. Code that calls `prepare()` with nonexistent columns fails silently or crashes depending on the error handling path. When adding features that touch the DB, confirm the schema first.

### 3. Ordering dependencies — using values before they're initialized
Config constants, DB connections, and other resources must be initialized before use. Example: checking `defined('SOME_CONSTANT')` before the file that defines it is loaded guarantees false. Trace the initialization order when adding code that depends on global state.

### 4. Silent data loss on malformed input
Never use `json_decode(...) ?? []` or similar fallbacks that silently discard bad input. Malformed JSON, invalid formats, and parse failures must produce explicit errors. The user needs to know their input was rejected, not silently ignored.

### 5. Inconsistent security patterns across similar operations
If create has secure password input, update must too. If one delete command has confirmation, all must. When implementing a security measure, grep for every analogous code path and apply the same pattern. Spot-checking misses these — review exhaustively.

### 6. Empty response rendering for void operations
DELETE/204 responses return empty arrays. Rendering an empty array produces no output. Void operations (delete, remove, revoke) need explicit success messages, not render calls.

### 7. bind_param type string mismatches
When building `bind_param($types, ...)` with many parameters, count types against values one-by-one. Integer timestamps bound as 's' work due to MySQL coercion but indicate sloppy code and can cause subtle issues with strict modes.

## Review discipline
- Review every file individually. Batch scanning causes context overload and misses real bugs.
- Read the file first, then think about what each line does, especially error paths.
- After writing code, re-read it as a skeptic looking for the failure mode, not as the author expecting it to work.
- When fixing a pattern (e.g., unchecked execute), grep the entire codebase for every instance — don't fix one and assume the rest are fine.
