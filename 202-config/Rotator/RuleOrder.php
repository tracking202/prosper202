<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

use Prosper202\Database\Connection;

/**
 * The order a redirector's rules are tried in: `position`, then id. The
 * redirects send a visitor through the first active rule whose criteria all
 * match, so the order is part of what a rule set says.
 *
 * Before 1.9.79 there was no position and the rules were read in table
 * order, which is id order: a rule could only be added after the others.
 * The upgrade gives every rule 0, so the id order holds until a rule set is
 * saved (PUT /rotators/{id}/rules, or Setup › Redirectors), which numbers
 * the rules from 1 in the order it lists them; a rule added on its own goes
 * after the last.
 */
final class RuleOrder
{
    private function __construct()
    {
    }

    /** The ORDER BY of a read of the rules, with the rules table's alias. */
    public static function orderBy(string $alias): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/iD', $alias) !== 1) {
            throw new \InvalidArgumentException('Not a table alias: ' . $alias);
        }

        return "ORDER BY {$alias}.position, {$alias}.id";
    }

    /**
     * The ids of a redirector's active rules, in the order the redirects try
     * them, read with $read (the click path's cached list read).
     *
     * Code served before the 1.9.79 upgrade has added position reads them in
     * id order, as 1.9.78 did, rather than failing the visitor's click: a
     * 1054 can only be that column, since the statement names no other
     * column the schema has ever lacked.
     *
     * @param callable(string): list<array<string, mixed>> $read
     * @return list<array<string, mixed>> rows of rule_id
     */
    public static function activeRules(callable $read, int $rotatorId): array
    {
        $sql = 'SELECT ru.id AS rule_id FROM 202_rotator_rules AS ru'
            . ' WHERE ru.rotator_id = ' . $rotatorId . " AND ru.status = '1'";
        try {
            return $read($sql . ' ' . self::orderBy('ru'));
        } catch (\Throwable $e) {
            if (!Connection::isMysqlError($e, 1054, 'Unknown column')) {
                throw $e;
            }

            return $read($sql);
        }
    }
}
