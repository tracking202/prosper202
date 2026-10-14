<?php

declare(strict_types=1);

namespace Prosper202\Repository\Mysql;

use InvalidArgumentException;
use Prosper202\Database\Connection;
use Prosper202\Repository\PositiveId;
use Prosper202\Repository\StoredText;
use Prosper202\Repository\TrackingRepositoryInterface;
use RuntimeException;

final readonly class MysqlTrackingRepository implements TrackingRepositoryInterface
{
    /**
     * The c1-c4, UTM and variable columns: VARCHAR(350), which counts
     * characters. Cut at 350 bytes, a value of 88 four-byte characters lost
     * the rest, and two values sharing their first 350 bytes were one row.
     */
    public const VALUE_CHARS = 350;

    public const KEYWORD_CHARS = 150;

    private const UTM_TYPES = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
    ];

    private IdQuery $ids;

    public function __construct(private Connection $conn)
    {
        $this->ids = new IdQuery($conn);
    }

    /**
     * A c1-c4, UTM or variable value as it is stored. A caller that also
     * sends the value on (an offer URL's token) sends this, so the two agree.
     */
    public static function storedValue(string $value): string
    {
        return StoredText::chars($value, self::VALUE_CHARS);
    }

    /**
     * A keyword as it is stored: its column is a VARCHAR(150), which the
     * server cut on its own (and refused under a strict sql_mode) while the
     * lookup asked for the whole value and found nothing.
     */
    public static function storedKeyword(string $keyword): string
    {
        return StoredText::chars($keyword, self::KEYWORD_CHARS);
    }

    #[\Override]
    public function findOrCreateKeyword(string $name): int
    {
        if ($name === '') {
            return 0;
        }
        $name = self::storedKeyword($name);

        return $this->ids->first(
            'SELECT keyword_id FROM 202_keywords WHERE keyword = ?',
            's',
            [$name],
            'keyword_id'
        ) ?? $this->ids->insert(
            'INSERT INTO 202_keywords SET keyword = ?',
            's',
            [$name]
        );
    }

    #[\Override]
    public function findOrCreateC1(string $value): int
    {
        return $this->findOrCreateValue('c1', self::storedValue($value));
    }

    #[\Override]
    public function findOrCreateC2(string $value): int
    {
        return $this->findOrCreateValue('c2', self::storedValue($value));
    }

    #[\Override]
    public function findOrCreateC3(string $value): int
    {
        return $this->findOrCreateValue('c3', self::storedValue($value));
    }

    #[\Override]
    public function findOrCreateC4(string $value): int
    {
        return $this->findOrCreateValue('c4', self::storedValue($value));
    }

    #[\Override]
    public function findOrCreateVariable(string $value, int $ppcVariableId): int
    {
        $value = self::storedValue($value);

        return $this->ids->first(
            'SELECT custom_variable_id FROM 202_custom_variables'
                . ' WHERE ppc_variable_id = ? AND variable = ?',
            'is',
            [$ppcVariableId, $value],
            'custom_variable_id'
        ) ?? $this->ids->insert(
            'INSERT INTO 202_custom_variables SET ppc_variable_id = ?, variable = ?',
            'is',
            [$ppcVariableId, $value]
        );
    }

    /**
     * $variables is the comma-joined custom variable ids of one click. A new
     * set also gets one 202_variable_sets2 row per id, which the custom
     * variable report joins on; without them the set is in no report, and a
     * later lookup finds the set and writes none. So the set and its rows
     * are one transaction, which refuses to begin inside one opened by
     * Connection::transaction() (a nested begin would commit it): the
     * callers look a click's values up before the click's own.
     */
    #[\Override]
    public function findOrCreateVariableSet(string $variables): int
    {
        $ids = self::variableIds($variables);

        $found = $this->ids->first(
            'SELECT variable_set_id FROM 202_variable_sets WHERE variables = ?',
            's',
            [$variables],
            'variable_set_id'
        );
        if ($found !== null) {
            return $found;
        }

        return $this->conn->outermostTransaction(function () use ($variables, $ids): int {
            $setId = $this->ids->insert(
                'INSERT INTO 202_variable_sets SET variables = ?',
                's',
                [$variables]
            );
            foreach ($ids as $variableId) {
                $this->ids->insertRow(
                    'INSERT INTO 202_variable_sets2 (variable_set_id, variables) VALUES (?, ?)',
                    'is',
                    [$setId, (string) $variableId]
                );
            }

            return $setId;
        });
    }

    #[\Override]
    public function findOrCreateCustomVar(string $name, string $data): int
    {
        return $this->findOrCreateValue(self::customVarName($name), self::storedValue($data));
    }

    #[\Override]
    public function findOrCreateUtm(string $value, string $type): int
    {
        $type = self::utmType($type);

        return $this->findOrCreateDynamic(
            '202_' . $type,
            $type . '_id',
            $type,
            self::storedValue($value)
        );
    }

    /**
     * A custom variable's name, which names its table and column: refused
     * unless it is letters, digits and underscores. The cache keys on it
     * before any lookup, so it is read there too.
     *
     * @throws RuntimeException for any other name
     */
    public static function customVarName(string $name): string
    {
        // \z, not $: $ also matches before a final newline, which then
        // reached the table and column names.
        if (preg_match('/\A[a-zA-Z0-9_]+\z/', $name) !== 1) {
            throw new RuntimeException('Invalid custom variable name: ' . $name);
        }

        return $name;
    }

    /**
     * A UTM type, which names its table: one of the five.
     *
     * @throws RuntimeException for any other type
     */
    public static function utmType(string $type): string
    {
        if (!in_array($type, self::UTM_TYPES, true)) {
            throw new RuntimeException('Invalid UTM type: ' . $type);
        }

        return $type;
    }

    /**
     * Every id in a variable set, refused before anything is written.
     *
     * @return list<int>
     * @throws InvalidArgumentException for anything but comma-joined row ids
     */
    public static function variableIds(string $variables): array
    {
        $ids = [];
        foreach (explode(',', $variables) as $part) {
            $id = PositiveId::of($part);
            if ($id === null || (string) $id !== $part) {
                throw new InvalidArgumentException(sprintf(
                    'A variable set is comma-joined custom variable ids;'
                        . ' "%s" in "%s" is not one',
                    $part,
                    $variables
                ));
            }
            // The mapping is a set: a repeated id would break its key.
            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /** A c1-c4 value, or a custom variable's, in 202_tracking_$column. */
    private function findOrCreateValue(string $column, string $value): int
    {
        return $this->findOrCreateDynamic(
            '202_tracking_' . $column,
            $column . '_id',
            $column,
            $value
        );
    }

    /**
     * Installs upgraded through 1.4.1 carry UNIQUE KEY (c(191)) on
     * 202_tracking_c1-c4, so a first click that loses the race for a new
     * value to another is refused as a duplicate: firstOrInsert() reads the
     * winner's row.
     */
    private function findOrCreateDynamic(
        string $table,
        string $idColumn,
        string $valueColumn,
        string $value
    ): int {
        return $this->ids->firstOrInsert(
            "SELECT {$idColumn} FROM {$table} WHERE {$valueColumn} = ?",
            "INSERT INTO {$table} SET {$valueColumn} = ?",
            's',
            [$value],
            $idColumn
        );
    }
}
