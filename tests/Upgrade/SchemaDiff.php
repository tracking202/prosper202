<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use InvalidArgumentException;

/**
 * Compare two databases table by table, as `SHOW CREATE TABLE` prints them,
 * for the upgrade-equals-install check (plan §7.6, tests/live/upgrade-equals-install.sh).
 *
 * Only the differences SchemaReconciler's docblock names as metadata are
 * normalised away, and one more that is not schema at all:
 *
 *  - the ORDER of secondary indexes ("there is no ADD KEY ... AFTER, and index
 *    order has no effect"): KEY / UNIQUE KEY / FULLTEXT KEY / SPATIAL KEY
 *    lines are compared as a set. The PRIMARY KEY line, every column and every
 *    CONSTRAINT stay where they are, so a column added at the end instead of
 *    in its place is a difference;
 *  - the TABLE comment (the last `COMMENT='…'` table option). Column comments
 *    are compared;
 *  - the `AUTO_INCREMENT=N` table option, which is the next row id — data,
 *    not schema;
 *  - the individual RANGE partitions of 202_clicks and 202_dataengine. Both
 *    installers cut weekly partitions starting at the moment they ran, so
 *    two installs a second apart never share a boundary, and 1.9.55 cut one
 *    more week than the current installer does. The `PARTITION BY` line —
 *    the scheme, which is schema — is compared; each `PARTITION pN VALUES
 *    LESS THAN (…)` line is not.
 *
 * Those are exactly the differences SchemaReconciler's docblock lists as
 * left alone; a normalisation added here must be listed there too.
 *
 * Everything else — a type, a default, a nullability, a charset or collation,
 * an engine, a partition clause, a missing or extra table — is a difference,
 * because every one of them is something the reconciler does NOT repair
 * (its docblock says so), so an upgrade that leaves one behind leaves it
 * behind for good.
 *
 * Pure: it works on the strings, so its normalisation is unit-tested
 * (SchemaDiffTest) without a database.
 */
final class SchemaDiff
{
    /**
     * One range partition as MariaDB (`PARTITION \`p3\` VALUES LESS THAN (…)
     * ENGINE = InnoDB,`) and MySQL 8 (unquoted name, the list inside a
     * `/*!50100 … *\/` comment) print it, including the first, which opens
     * the list with `(`, and the last, which closes it.
     */
    private const PARTITION_RANGE =
        '/^\s*\(?PARTITION `?\w+`? VALUES LESS THAN (?:\(-?\d+\)|MAXVALUE)(?: ENGINE = \w+)?\)?,?(?: \*\/)?$/';

    /**
     * Normalise one `SHOW CREATE TABLE` statement to the lines that are
     * compared.
     *
     * @return list<string>
     */
    public static function normalise(string $createTable): array
    {
        $lines = preg_split('/\R/', trim($createTable));
        if ($lines === false || count($lines) < 2) {
            throw new InvalidArgumentException('not a CREATE TABLE statement: ' . substr($createTable, 0, 80));
        }

        $head = array_shift($lines);
        if (!preg_match('/^CREATE TABLE `[^`]+` \($/', (string) $head)) {
            throw new InvalidArgumentException('not a CREATE TABLE statement: ' . (string) $head);
        }

        // The closing ") ENGINE=… " line and anything after it (a partition
        // clause spans several lines) are the table options.
        $body = [];
        $options = [];
        $inOptions = false;
        foreach ($lines as $line) {
            if (!$inOptions && str_starts_with($line, ')')) {
                $inOptions = true;
            }
            if ($inOptions) {
                $options[] = $line;
            } else {
                $body[] = rtrim($line, ',');
            }
        }
        if ($options === []) {
            throw new InvalidArgumentException('CREATE TABLE statement has no closing line');
        }

        $columnsAndPrimary = [];
        $indexes = [];
        $constraints = [];
        foreach ($body as $line) {
            $trimmed = ltrim($line);
            if (preg_match('/^(UNIQUE |FULLTEXT |SPATIAL )?KEY `/', $trimmed)) {
                $indexes[] = $line;
            } elseif (str_starts_with($trimmed, 'CONSTRAINT ')) {
                $constraints[] = $line;
            } else {
                $columnsAndPrimary[] = $line;
            }
        }
        sort($indexes, SORT_STRING);

        $options[0] = self::stripTableOptions($options[0]);

        // Partition boundaries are the install's clock (see the class
        // docblock): the PARTITION BY line stays, the ranges go.
        $options = array_values(array_filter(
            $options,
            static fn(string $line): bool => preg_match(self::PARTITION_RANGE, $line) !== 1
        ));

        return array_merge([(string) $head], $columnsAndPrimary, $indexes, $constraints, $options);
    }

    /**
     * Drop AUTO_INCREMENT=N and the table COMMENT from the options line.
     */
    private static function stripTableOptions(string $line): string
    {
        $line = (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', $line);

        // COMMENT='…' is the last option MySQL and MariaDB print on this line;
        // a quote inside it is doubled (''), so match the quoted string as a
        // whole rather than up to the first quote.
        return (string) preg_replace("/ COMMENT='(?:[^']|'')*'\$/", '', $line);
    }

    /**
     * Compare two schemas given as table name => SHOW CREATE TABLE text.
     *
     * @param  array<string, string> $upgraded
     * @param  array<string, string> $fresh
     * @return list<string> one human-readable block per difference; empty
     *         when the two are the same up to the normalisation above
     */
    public static function compare(array $upgraded, array $fresh): array
    {
        $differences = [];

        $onlyUpgraded = array_diff(array_keys($upgraded), array_keys($fresh));
        $onlyFresh = array_diff(array_keys($fresh), array_keys($upgraded));
        sort($onlyUpgraded, SORT_STRING);
        sort($onlyFresh, SORT_STRING);
        foreach ($onlyUpgraded as $table) {
            $differences[] = 'table ' . $table . ' exists after the upgrade but not in a fresh install';
        }
        foreach ($onlyFresh as $table) {
            $differences[] = 'table ' . $table . ' exists in a fresh install but not after the upgrade';
        }

        $common = array_intersect(array_keys($upgraded), array_keys($fresh));
        sort($common, SORT_STRING);
        foreach ($common as $table) {
            $a = self::normalise($upgraded[$table]);
            $b = self::normalise($fresh[$table]);
            if ($a === $b) {
                continue;
            }

            $block = ['table ' . $table . ' differs (- upgraded, + fresh install):'];
            $missing = array_diff($b, $a);
            $extra = array_diff($a, $b);
            foreach ($extra as $line) {
                $block[] = '  - ' . trim($line);
            }
            foreach ($missing as $line) {
                $block[] = '  + ' . trim($line);
            }
            if ($missing === [] && $extra === []) {
                // Same lines, different order: a column added in the wrong
                // place. Show both orders so the misplaced one is visible.
                $block[] = '  same lines in a different order';
                $block[] = '  upgraded: ' . implode(' | ', array_map('trim', $a));
                $block[] = '  fresh:    ' . implode(' | ', array_map('trim', $b));
            }
            $differences[] = implode("\n", $block);
        }

        return $differences;
    }
}
