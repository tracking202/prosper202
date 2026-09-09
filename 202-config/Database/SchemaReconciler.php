<?php

declare(strict_types=1);

namespace Prosper202\Database;

use mysqli_result;
use Prosper202\Database\Schema\SchemaDefinition;
use RuntimeException;

/**
 * Non-destructive reconciliation of a live table against its SchemaDefinition.
 *
 * `CREATE TABLE IF NOT EXISTS` converges nothing: once a table exists, the
 * statement is a no-op no matter how far the table has drifted from the
 * definition the installer would use today. Every upgrade step that adds a
 * column therefore hand-writes a `SHOW COLUMNS ... LIKE` probe and a guarded
 * `ALTER TABLE` (see the 1.9.64 LTV block in functions-upgrade.php). This
 * class is that idiom driven by the definition itself: it reads the live
 * table's columns and index names, compares them with the columns and keys
 * the definition's CREATE statement declares, and emits `ADD COLUMN` /
 * `ADD KEY` for whatever is missing.
 *
 * Only changes that cannot lose data are applied: adding a missing column,
 * adding a missing named index, and relaxing a column the definition made
 * nullable while the live table still has it NOT NULL. Nothing is dropped,
 * renamed or retyped. That makes reconciliation safe to re-run: the second
 * pass finds nothing to do.
 *
 * What it does NOT see, said plainly because a clean run reads as a verdict:
 * this class compares column NAMES, index names and nullability — never
 * types. A column that is present with the nullability the definition asks
 * for and a drifted type (`varchar(5)` where the definition says
 * `varchar(20)`) produces no statement and no getUnreconciled() note; it is
 * invisible here. Types are read in exactly one place, as a guard on the
 * nullability relaxation below, and an unequal string there means only
 * "leave this column alone": a server reports a type in its own normalised
 * form rather than as declared (on MariaDB 10.11 `int` comes back
 * `int(11)`, `bool` comes back `tinyint(1)`, `decimal` comes back
 * `decimal(10,0)`), so string inequality is not evidence of drift and
 * reporting it as drift would flag correct tables. A reconciliation that
 * ends clean therefore means the columns and indexes the definition names
 * now exist — not that the table matches the definition.
 *
 * Indexes are matched by name only: one whose name is already in the table
 * is left as it is, even if its columns differ. Two further differences are
 * metadata only and are also left alone: the order indexes appear in (there
 * is no ADD KEY ... AFTER, and index order has no effect) and the table
 * COMMENT.
 *
 * The nullability relaxation is not decoration. The 1.9.76 attribution
 * postback table was reshaped before release and `version` went from
 * NOT NULL to nullable, because AdAttributionKit postbacks carry no version
 * at all and their INSERT omits the column; against a table still holding
 * the pre-release NOT NULL, that INSERT dies with "Field 'version' doesn't
 * have a default value" (errno 1364) and the device's postback — the only
 * copy Apple sends — is dropped. Adding the missing columns alone does not
 * fix that, so the widening runs too.
 *
 * Every fallible call is checked (CLAUDE.md #1). A failed probe is never
 * treated as "the column is not there": a `SHOW COLUMNS` that returns false
 * would otherwise read as an empty table and produce an ALTER storm, so the
 * probes are tri-state and an unreadable table fails the reconciliation
 * instead of guessing (CLAUDE.md #11).
 */
final class SchemaReconciler
{
    /** @var callable(string): (mysqli_result|bool) */
    private $runner;

    /** @var array<int, string> */
    private array $applied = [];

    /** @var array<int, string> */
    private array $errors = [];

    /** @var array<int, string> */
    private array $unreconciled = [];

    /**
     * @param callable(string): (mysqli_result|bool) $runner Query runner with
     *        _upgrade_query()'s contract: a mysqli_result for statements that
     *        return rows, true for statements that do not, false on failure.
     */
    public function __construct(callable $runner)
    {
        $this->runner = $runner;
    }

    /**
     * Bring an existing table up to the columns and indexes its definition
     * declares. The table must already exist — the caller runs the
     * definition's CREATE first — so an unreadable table is an error here,
     * not an empty answer.
     *
     * @return bool True when the table already matched or every ALTER
     *         succeeded; false when anything could not be read or applied.
     */
    public function reconcile(SchemaDefinition $definition): bool
    {
        $table = $definition->tableName;

        $columns = $this->fetchColumnRows($table);
        if ($columns === null) {
            $this->errors[] = 'could not read the columns of ' . $table . ' (SHOW COLUMNS failed)';
            return false;
        }

        $indexes = $this->fetchColumnValues('SHOW INDEX FROM `' . $table . '`', 'Key_name');
        if ($indexes === null) {
            $this->errors[] = 'could not read the indexes of ' . $table . ' (SHOW INDEX failed)';
            return false;
        }

        try {
            $plan = self::planChanges($definition, $columns, $indexes);
        } catch (RuntimeException $e) {
            // A definition this class cannot parse must stop the upgrade, not
            // silently reconcile the part of it that did parse.
            $this->errors[] = 'could not read the definition of ' . $table . ': ' . $e->getMessage();
            return false;
        }

        foreach ($plan['unreconciled'] as $note) {
            $this->unreconciled[] = $note;
        }

        $ok = true;
        foreach ($plan['statements'] as $statement) {
            if (($this->runner)($statement) === false) {
                $this->errors[] = 'failed: ' . $statement;
                $ok = false;
                continue;
            }
            $this->applied[] = $statement;
        }

        return $ok;
    }

    /**
     * ALTER statements applied so far, across every reconcile() call.
     *
     * @return array<int, string>
     */
    public function getApplied(): array
    {
        return $this->applied;
    }

    /**
     * Failures recorded so far, across every reconcile() call.
     *
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Differences this class found but deliberately did not apply, because
     * applying them could lose data: a column the definition made NOT NULL
     * that the live table has nullable, and a column whose nullability the
     * definition widened but whose type is not the one the definition
     * declares. Not failures — a human has to decide what these mean — but
     * they must be logged rather than swallowed. A type difference on a
     * column whose nullability already agrees is NOT in here; see the class
     * docblock.
     *
     * @return array<int, string>
     */
    public function getUnreconciled(): array
    {
        return $this->unreconciled;
    }

    /**
     * Does a table exist?
     *
     * @return bool|null Null when the question could not be answered — the
     *         caller must not read that as "no" (CLAUDE.md #11).
     */
    public function tableExists(string $table): ?bool
    {
        // `_` and `%` are LIKE wildcards and every 202_ table name contains
        // one, so an unescaped pattern would match neighbouring tables.
        $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $table);

        $result = ($this->runner)("SHOW TABLES LIKE '" . $pattern . "'");
        if (!($result instanceof mysqli_result)) {
            return null;
        }

        return $result->num_rows > 0;
    }

    /**
     * How many rows does a table hold?
     *
     * @return int|null Null when the count could not be read. Zero and
     *         "unreadable" are different answers and must stay different.
     */
    public function tableRowCount(string $table): ?int
    {
        $result = ($this->runner)('SELECT COUNT(*) AS row_count FROM `' . $table . '`');
        if (!($result instanceof mysqli_result)) {
            return null;
        }

        $row = $result->fetch_assoc();
        if (!is_array($row) || !isset($row['row_count'])) {
            return null;
        }

        return (int) $row['row_count'];
    }

    /**
     * Work out the ALTERs that bring a table with these columns and indexes
     * up to the definition. Pure: no database access, so the planning is
     * testable without one.
     *
     * @param  array<string, array<string, string|null>> $liveColumns SHOW COLUMNS
     *         rows keyed by column name (each with Type / Null / Default / Extra)
     * @param  array<int, string> $liveIndexes Key names from SHOW INDEX
     * @return array{statements: array<int, string>, unreconciled: array<int, string>}
     *         statements run in the order given; unreconciled lists the
     *         differences that were found and deliberately left alone
     * @throws RuntimeException when the definition cannot be parsed
     */
    public static function planChanges(
        SchemaDefinition $definition,
        array $liveColumns,
        array $liveIndexes
    ): array {
        $live = [];
        foreach ($liveColumns as $name => $row) {
            $live[strtolower((string) $name)] = $row;
        }

        $haveIndex = [];
        foreach ($liveIndexes as $name) {
            $haveIndex[strtolower($name)] = true;
        }

        $table = $definition->tableName;
        $statements = [];
        $unreconciled = [];

        // Columns are added in definition order, each one positioned after
        // the column that precedes it in the definition, so a reconciled
        // table ends up identical to one the installer creates from scratch
        // rather than with the new columns bolted on at the end.
        $previous = null;
        foreach (self::requiredColumns($definition) as $name => $columnSql) {
            $key = strtolower($name);

            if (!isset($live[$key])) {
                $statements[] = 'ALTER TABLE `' . $table . '` ADD COLUMN ' . $columnSql
                    . ($previous === null ? ' FIRST' : ' AFTER `' . $previous . '`');
                // The column exists once the statement above has run, so it
                // is the anchor for the next missing one.
                $previous = $name;
                continue;
            }

            $previous = $name;

            // The column is there. The only in-place change that cannot lose
            // data is dropping a NOT NULL the definition no longer asks for:
            // it widens the set of accepted values and rewrites no row. It
            // is applied only when the declared type is character-for-
            // character what the server reports, so nothing is ever retyped
            // on the strength of this class's reading of the SQL.
            $liveNotNull = strtoupper((string) ($live[$key]['Null'] ?? 'YES')) === 'NO';
            $definitionNotNull = self::declaresNotNull($columnSql);

            if ($liveNotNull === $definitionNotNull) {
                continue;
            }

            if (!$liveNotNull) {
                // Definition wants NOT NULL, live column is nullable:
                // narrowing, which fails on any stored NULL. Report it.
                $unreconciled[] = $table . '.' . $name
                    . ' is nullable but the definition declares it NOT NULL; not narrowed automatically';
                continue;
            }

            $declaredType = self::declaredType($columnSql);
            $liveType = trim((string) ($live[$key]['Type'] ?? ''));
            if ($declaredType === null || strcasecmp($declaredType, $liveType) !== 0) {
                $unreconciled[] = $table . '.' . $name . ' is NOT NULL but the definition allows NULL, and its'
                    . ' type differs (live "' . $liveType . '", definition "' . (string) $declaredType . '");'
                    . ' not modified automatically';
                continue;
            }

            $statements[] = 'ALTER TABLE `' . $table . '` MODIFY COLUMN ' . $columnSql;
        }

        foreach (self::requiredIndexes($definition) as $name => $keySql) {
            if (isset($haveIndex[strtolower($name)])) {
                continue;
            }

            $statements[] = 'ALTER TABLE `' . $table . '` ADD ' . $keySql;
        }

        return ['statements' => $statements, 'unreconciled' => $unreconciled];
    }

    /**
     * Does a column definition declare NOT NULL? (`NULL` on its own, or no
     * nullability clause at all, both mean the column accepts NULL.)
     */
    private static function declaresNotNull(string $columnSql): bool
    {
        return preg_match('/\bNOT\s+NULL\b/i', $columnSql) === 1;
    }

    /**
     * The type a column definition declares, exactly as SHOW COLUMNS would
     * report it, or null when this class cannot read it.
     */
    private static function declaredType(string $columnSql): ?string
    {
        $afterName = preg_replace('/^`[^`]+`\s*/', '', $columnSql, 1);
        if (!is_string($afterName)) {
            return null;
        }

        $pattern = '/^([a-z]+(?:\s*\([^)]*\))?(?:\s+unsigned)?(?:\s+zerofill)?)/i';
        if (preg_match($pattern, $afterName, $match) !== 1) {
            return null;
        }

        return (string) preg_replace('/\s+/', ' ', trim($match[1]));
    }

    /**
     * Columns the definition declares, in declaration order.
     *
     * @return array<string, string> column name => its full SQL definition
     * @throws RuntimeException when the CREATE statement cannot be parsed
     */
    public static function requiredColumns(SchemaDefinition $definition): array
    {
        $columns = [];
        foreach (self::definitionItems($definition) as $item) {
            if (preg_match('/^`([^`]+)`\s+\S/', $item, $match) === 1) {
                $columns[$match[1]] = $item;
            }
        }

        return $columns;
    }

    /**
     * Named indexes the definition declares (PRIMARY KEY excluded — adding
     * one is not an additive change, and a table that exists always got its
     * primary key when it was created).
     *
     * @return array<string, string> key name => its full SQL key clause
     * @throws RuntimeException when the CREATE statement cannot be parsed
     */
    public static function requiredIndexes(SchemaDefinition $definition): array
    {
        $indexes = [];
        foreach (self::definitionItems($definition) as $item) {
            if (preg_match('/^(?:(?:UNIQUE|FULLTEXT|SPATIAL)\s+)?(?:KEY|INDEX)\s+`([^`]+)`\s*\(/i', $item, $match) === 1) {
                $indexes[$match[1]] = $item;
            }
        }

        return $indexes;
    }

    /**
     * Split a CREATE TABLE statement into its top-level definition items,
     * checking that every one of them is a shape this class understands. An
     * item it cannot classify throws rather than being skipped: a silently
     * ignored column is a column that never gets added (CLAUDE.md #4).
     *
     * @return array<int, string>
     * @throws RuntimeException
     */
    private static function definitionItems(SchemaDefinition $definition): array
    {
        $items = self::splitTopLevel(self::tableBody($definition));

        foreach ($items as $item) {
            $isColumn = preg_match('/^`([^`]+)`\s+\S/', $item) === 1;
            $isKey = preg_match('/^(?:(?:UNIQUE|FULLTEXT|SPATIAL)\s+)?(?:KEY|INDEX)\s+`([^`]+)`\s*\(/i', $item) === 1;
            $isPrimary = preg_match('/^PRIMARY\s+KEY\s*\(/i', $item) === 1;

            if (!$isColumn && !$isKey && !$isPrimary) {
                throw new RuntimeException(
                    'unsupported definition item in ' . $definition->tableName . ': ' . $item
                );
            }
        }

        return $items;
    }

    /**
     * The parenthesised body of a CREATE TABLE statement.
     *
     * @throws RuntimeException when the statement has no balanced body
     */
    private static function tableBody(SchemaDefinition $definition): string
    {
        $sql = $definition->createStatement;
        $length = strlen($sql);
        $depth = 0;
        $open = -1;
        $inBacktick = false;
        $inQuote = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($inBacktick) {
                if ($char === '`') {
                    $inBacktick = false;
                }
                continue;
            }

            if ($inQuote) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === "'") {
                    $inQuote = false;
                }
                continue;
            }

            if ($char === '`') {
                $inBacktick = true;
            } elseif ($char === "'") {
                $inQuote = true;
            } elseif ($char === '(') {
                if ($depth === 0) {
                    $open = $i;
                }
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($sql, $open + 1, $i - $open - 1);
                }
            }
        }

        throw new RuntimeException('no balanced CREATE TABLE body in ' . $definition->tableName);
    }

    /**
     * Split a CREATE TABLE body on the commas that separate its items,
     * ignoring commas inside `(20)`, backticked names and quoted defaults.
     *
     * @return array<int, string>
     */
    private static function splitTopLevel(string $body): array
    {
        $items = [];
        $current = '';
        $depth = 0;
        $inBacktick = false;
        $inQuote = false;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($inBacktick) {
                $current .= $char;
                if ($char === '`') {
                    $inBacktick = false;
                }
                continue;
            }

            if ($inQuote) {
                $current .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $body[++$i];
                } elseif ($char === "'") {
                    $inQuote = false;
                }
                continue;
            }

            if ($char === ',' && $depth === 0) {
                $items[] = trim($current);
                $current = '';
                continue;
            }

            if ($char === '`') {
                $inBacktick = true;
            } elseif ($char === "'") {
                $inQuote = true;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $items[] = trim($current);
        }

        return $items;
    }

    /**
     * The live table's SHOW COLUMNS rows, keyed by column name.
     *
     * @return array<string, array<string, string|null>>|null Null when the
     *         table could not be read — never an empty array, which would be
     *         indistinguishable from a table with no columns and would make
     *         every column look missing.
     */
    private function fetchColumnRows(string $table): ?array
    {
        $result = ($this->runner)('SHOW COLUMNS FROM `' . $table . '`');
        if (!($result instanceof mysqli_result)) {
            return null;
        }

        $rows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            if (!isset($row['Field'])) {
                continue;
            }
            /** @var array<string, string|null> $row */
            $rows[(string) $row['Field']] = $row;
        }

        return $rows;
    }

    /**
     * Run a row-returning statement and collect one column from every row.
     *
     * @return array<int, string>|null Null when the statement did not return
     *         a result set — never an empty array, which would be
     *         indistinguishable from a table with no columns.
     */
    private function fetchColumnValues(string $sql, string $column): ?array
    {
        $result = ($this->runner)($sql);
        if (!($result instanceof mysqli_result)) {
            return null;
        }

        $values = [];
        while (($row = $result->fetch_assoc()) !== null) {
            if (isset($row[$column])) {
                $values[] = (string) $row[$column];
            }
        }

        return $values;
    }
}
