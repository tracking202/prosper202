<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

use Prosper202\Database\Connection;

/**
 * Before 1.9.79 the API stored a redirector rule's criterion value as sent,
 * so `United States(US), Canada(CA)` kept its space and `all` its case. The
 * redirects read every value through Criterion::values(), so such a rule
 * matches as meant; the stored text is still what the API and the
 * Redirectors page hand back, and what a reader that does not go through
 * Criterion compares. The 1.9.81 upgrade stores each such value as the
 * writers store it now (Criterion::normalize()), and logs each one changed.
 *
 * normalize() and not stored(): stored() also rewrites spellings the
 * redirects read the same either way (a country code as its name, a device
 * in lower case) and an IP list as canonical addresses, which would rewrite
 * an address it cannot read. Trimming, dropping empty values and the case
 * of ALL change nothing values() reads, so no rule matches differently.
 */
final class CriterionRepair
{
    private function __construct()
    {
    }

    /** @return int the criteria rewritten */
    public static function run(Connection $conn): int
    {
        $select = $conn->prepareWrite(
            'SELECT id, rotator_id, rule_id, type, value FROM 202_rotator_rules_criteria ORDER BY id'
        );
        $rewritten = 0;
        foreach ($conn->fetchAll($select) as $row) {
            $value = (string) $row['value'];
            $normalized = Criterion::normalize($value);
            if ($normalized === $value) {
                continue;
            }
            // Compared as bytes: the column's collation reads `ALL` as `all`
            // and ignores trailing spaces, so it would also match a value
            // saved since the read. (executeUpdate() closes the statement.)
            $update = $conn->prepareWrite(
                'UPDATE 202_rotator_rules_criteria SET value = ?'
                . ' WHERE id = ? AND CAST(value AS BINARY) = CAST(? AS BINARY)'
            );
            $conn->bind($update, 'sis', [$normalized, (int) $row['id'], $value]);
            if ($conn->executeUpdate($update) !== 1) {
                continue;
            }
            $rewritten++;
            error_log(sprintf(
                'Prosper202 redirector rule repair: criterion %d (%s) of rule %d on redirector %d was stored as %s and is now %s',
                (int) $row['id'],
                (string) $row['type'],
                (int) $row['rule_id'],
                (int) $row['rotator_id'],
                self::quoted($value),
                self::quoted($normalized)
            ));
        }

        return $rewritten;
    }

    /** A value as one log line can show it: quoted, its line breaks escaped. */
    private static function quoted(string $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }
}
