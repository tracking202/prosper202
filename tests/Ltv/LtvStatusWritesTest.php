<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Lifecycle\State;
use Prosper202\Ltv\DeliveryStatus;
use Prosper202\Ltv\SubscriptionStatus;
use Prosper202\Ltv\WebhookStatus;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * The status of a subscription, an LTV webhook and a webhook delivery is
 * written by its repository alone, and each UPDATE that sets it names the
 * status it moves from in its own WHERE: a lock or a claim taken earlier is
 * not the check (CLAUDE.md #22, #36). recordAttempt() wrote a delivery's
 * outcome by its id alone, so a failure recorded late made a delivered one
 * pending again, and it was sent twice.
 *
 * Read from every PHP string as PHP assembles it (AssembledStrings): the
 * statements that write one of the three tables, and every status literal in
 * a statement that names one, which must be a state of its enum. `disabled`
 * is in the webhook column's ENUM and is not one.
 *
 * Does not see: a statement whose SET list, column list or table is built at
 * run time and never spells `status` (Controller's generic UPDATE, the CRM
 * merge's table list; neither names these columns); a status compared in PHP
 * by a `match` arm or through a variable; a write on a raw handle whose SQL
 * is not a string in the source.
 */
final class LtvStatusWritesTest extends TestCase
{
    private const SUBSCRIPTIONS = '202-config/Ltv/MysqlSubscriptionRepository.php';
    private const WEBHOOKS = '202-config/Ltv/MysqlWebhookRepository.php';

    /** table => [its lifecycle, the one file that writes its status] */
    private const LIFECYCLES = [
        '202_subscriptions' => [SubscriptionStatus::class, self::SUBSCRIPTIONS],
        '202_ltv_webhooks' => [WebhookStatus::class, self::WEBHOOKS],
        '202_ltv_webhook_deliveries' => [DeliveryStatus::class, self::WEBHOOKS],
    ];

    /** How many statements write each status today: a scan gone blind reads fewer. */
    private const WRITES = [
        '202_subscriptions' => 5,
        '202_ltv_webhooks' => 3,
        '202_ltv_webhook_deliveries' => 7,
    ];

    /** INSERTs whose VALUES the scan cannot read, by file: [how many, what they insert]. */
    private const UNREAD_VALUES = [
        self::WEBHOOKS => [1, 'enqueueEach(): one tuple per delivery, each (…, \'pending\', 0, …), repeated'],
    ];

    /** Files that read a status in PHP and compare it with a case, never a literal. */
    private const PHP_READERS = [
        '202-config/Ltv/', '202-config/Lpo/PairingWebhook.php', 'api/v3/Controllers/LtvController.php',
        'tracking202/ajax/ltv_settings.php', 'tracking202/ajax/ltv_subscriptions.php',
    ];

    public function testEveryStatusWriteIsItsRepositorysAndNamesWhatItMovesFrom(): void
    {
        $problems = [];
        $writes = array_fill_keys(array_keys(self::LIFECYCLES), 0);
        $unread = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            foreach (self::read($file, $source) as [$kind, $what]) {
                if ($kind === 'write') {
                    $writes[$what]++;
                } elseif ($kind === 'unread') {
                    $unread[$file] = ($unread[$file] ?? 0) + 1;
                } else {
                    $problems[] = $what;
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertSame(self::WRITES, $writes, 'the statements that write each status: list a new one\'s count'
            . ' here once it names its from-state, and suspect the scan when one is missing');
        self::assertSame(
            array_map(static fn (array $listed): int => $listed[0], self::UNREAD_VALUES),
            $unread,
            'an INSERT of a status whose VALUES the scan cannot read: write the tuple where it can be read, or'
                . ' list it with what it inserts'
        );
    }

    public function testNoStatusIsComparedWithALiteralInPhp(): void
    {
        $found = [];
        $read = 0;
        foreach (SourceScan::phpFiles() as $file => $source) {
            $listed = false;
            foreach (self::PHP_READERS as $prefix) {
                $listed = $listed || str_starts_with($file, $prefix);
            }
            if (!$listed) {
                continue;
            }
            $read++;
            array_push($found, ...self::literalComparisons($file, $source));
        }
        self::assertGreaterThan(20, $read, 'the reader files were read');
        self::assertSame([], $found, "Compare a stored status with a case of its enum (DeliveryStatus::Pending):\n"
            . implode("\n", $found));
    }

    /** @return iterable<string, array{string, string, string}> file, PHP, the finding's words */
    public static function planted(): iterable
    {
        $delivered = "'UPDATE 202_ltv_webhook_deliveries SET status = \\'delivered\\' WHERE delivery_id = ?";
        yield 'a second writer' => [
            '202-cronjobs/other.php',
            "\$db->query('UPDATE 202_subscriptions SET status = \\'active\\' WHERE subscription_id = 1 AND status = \\'paused\\'');",
            'outside 202-config/Ltv/MysqlSubscriptionRepository.php',
        ];
        yield 'a second writer, the table a constant in backticks' => [
            '202-config/Ltv/LtvMaintenance.php',
            "use Prosper202\\Database\\Schema\\TableRegistry;\n\$s = 'UPDATE `' . TableRegistry::SUBSCRIPTIONS"
                . " . '` SET status = ? WHERE subscription_id = ? AND status = ?';",
            'outside 202-config/Ltv/MysqlSubscriptionRepository.php',
        ];
        yield 'a second writer, in a heredoc with an interpolated id' => [
            'api/v3/x.php',
            "\$s = <<<SQL\nUPDATE 202_ltv_webhooks\n   SET status = 'dead' WHERE webhook_id = {\$id} AND status = 'active'\nSQL;",
            'outside 202-config/Ltv/MysqlWebhookRepository.php',
        ];
        yield 'an insert elsewhere' => [
            'api/v3/x.php',
            "\$s = 'INSERT INTO 202_ltv_webhook_deliveries (webhook_id, status) VALUES (?, \\'pending\\')';",
            'outside 202-config/Ltv/MysqlWebhookRepository.php',
        ];
        yield 'no from-state' => [self::WEBHOOKS, '$s = ' . $delivered . "';", 'names no status it moves from'];
        yield 'a from-state on the joined table only' => [
            self::WEBHOOKS,
            "\$s = 'UPDATE 202_ltv_webhook_deliveries d JOIN 202_ltv_webhooks w ON w.webhook_id = d.webhook_id"
                . " SET d.status = \\'failed\\' WHERE d.delivery_id = ? AND w.status = \\'dead\\'';",
            'names no status it moves from',
        ];
        yield 'a from-state that excludes one' => [
            self::WEBHOOKS,
            "\$s = 'UPDATE 202_ltv_webhooks SET status = \\'active\\' WHERE webhook_id = ? AND status <> \\'active\\'';",
            'names no status it moves from',
        ];
        yield 'a state the enum does not name, written' => [
            self::WEBHOOKS,
            "\$s = 'UPDATE 202_ltv_webhooks SET status = \\'disabled\\' WHERE webhook_id = ? AND status = \\'active\\'';",
            '"disabled"',
        ];
        yield 'a state the enum does not name, read' => [
            'tracking202/x.php',
            "\$s = \"SELECT 1 FROM 202_subscriptions s WHERE s.status IN ('active', 'cancelled')\";",
            '"cancelled"',
        ];
        yield 'a state of another lifecycle' => [
            self::WEBHOOKS,
            "\$s = \"SELECT 1 FROM 202_ltv_webhook_deliveries WHERE status != 'dead'\";",
            '"dead"',
        ];
        yield 'an upsert that rewrites the status' => [
            self::SUBSCRIPTIONS,
            "\$s = 'INSERT INTO 202_subscriptions (user_id, status) VALUES (?, ?)"
                . " ON DUPLICATE KEY UPDATE status = VALUES(status)';",
            'ON DUPLICATE KEY UPDATE',
        ];
        yield 'a REPLACE' => [
            self::SUBSCRIPTIONS,
            "\$s = 'REPLACE INTO 202_subscriptions (user_id, status) VALUES (?, ?)';",
            'REPLACE',
        ];
        yield 'a row created in a state that is not initial' => [
            self::WEBHOOKS,
            "\$s = 'INSERT INTO 202_ltv_webhook_deliveries (webhook_id, status) VALUES (?, \\'failed\\')';",
            'creates a row as "failed"',
        ];
        yield 'an INSERT … SET' => [
            self::WEBHOOKS,
            "\$s = 'INSERT INTO 202_ltv_webhooks SET user_id = ?, status = \\'dead\\'';",
            'cannot be read',
        ];
        yield 'a table the scan cannot read' => [
            self::WEBHOOKS,
            "\$s = 'UPDATE ' . \$table . ' SET status = \\'failed\\' WHERE delivery_id = ?"
                . " /* 202_ltv_webhook_deliveries */';",
            'cannot be read',
        ];
    }

    /** @dataProvider planted */
    public function testTheScanRefusesEachShape(string $file, string $php, string $says): void
    {
        $problems = array_column(
            array_filter(self::read($file, "<?php\n" . $php), static fn (array $f): bool => $f[0] === 'problem'),
            1
        );
        self::assertNotSame([], $problems, 'not refused: ' . $php);
        self::assertStringContainsString($says, implode("\n", $problems));
    }

    public function testTheScanPassesTheShapesTheRepositoriesUse(): void
    {
        $subscriptions = "<?php\n"
            . "\$a = 'UPDATE 202_subscriptions SET status = ?, updated_at = ? WHERE subscription_id = ?\n"
            . "       AND status IN (' . implode(', ', \$marks) . ')';\n"
            . "\$b = 'DELETE FROM 202_subscriptions WHERE user_id = ?';\n"
            . "\$c = \"UPDATE 202_customers c LEFT JOIN (SELECT customer_id, SUM(CASE WHEN status = 'active' THEN 1\n"
            . "      ELSE 0 END) AS n FROM 202_subscriptions GROUP BY customer_id) s ON s.customer_id = c.customer_id\n"
            . "      SET c.active_subscription_count = COALESCE(s.n, 0) WHERE c.customer_id = ?\";\n";
        self::assertSame([['write', '202_subscriptions']], self::read(self::SUBSCRIPTIONS, $subscriptions));
        $webhooks = "<?php\n"
            . "\$a = \"UPDATE 202_ltv_webhook_deliveries d JOIN 202_ltv_webhooks w ON w.webhook_id = d.webhook_id\n"
            . "      SET d.status = 'failed' WHERE d.delivery_id = ? AND d.status = 'pending' AND w.status = 'dead'\";\n"
            . "\$b = \"UPDATE 202_ltv_webhook_deliveries SET status = IF(attempts + 1 >= ?, 'failed', 'pending'),\n"
            . "      attempts = attempts + 1 WHERE delivery_id = ? AND status = 'pending'\";\n"
            . "\$c = 'UPDATE 202_ltv_webhooks SET webhook_url = ? WHERE webhook_id = ?';\n"
            . "\$d = \"INSERT INTO 202_ltv_webhooks (user_id, status, created_at) VALUES (?, 'active', ?)\";\n";
        self::assertSame(
            [['write', '202_ltv_webhook_deliveries'], ['write', '202_ltv_webhook_deliveries'], ['write', '202_ltv_webhooks']],
            self::read(self::WEBHOOKS, $webhooks)
        );
        self::assertSame([], self::read('202-cronjobs/bridge_config.php', "<?php\n\$d = 'UPDATE 202_ltv_webhooks"
            . " SET webhook_url = ?, updated_at = ? WHERE webhook_id = ? AND user_id = ?';"));
        self::assertSame([], self::literalComparisons('x.php', '<?php $a = $row[\'status\'] === $s->value;'));
        self::assertCount(1, self::literalComparisons('x.php', '<?php if ((string) $row["status"] !== \'active\') {}'));
        self::assertCount(1, self::literalComparisons('x.php', '<?php if (\'dead\' == $d[\'webhook_status\']) {}'));
    }

    /**
     * What one file's strings say: ['write', table] for each statement that
     * writes a status as it should, ['unread', file] for an INSERT whose
     * VALUES cannot be read, ['problem', sentence] for the rest.
     *
     * @return list<array{string, string}>
     */
    private static function read(string $file, string $source): array
    {
        $found = [];
        foreach (AssembledStrings::of($source) as $string) {
            $sql = trim((string) preg_replace('/\s+/', ' ', $string['text']), ' ');
            $at = $file . ':' . $string['line'];
            $named = array_values(array_filter(
                array_keys(self::LIFECYCLES),
                static fn (string $table): bool => preg_match('/(?<![\w])' . $table . '(?![\w])/', $sql) === 1
            ));
            if ($named === []) {
                continue;
            }
            array_push($found, ...self::literalProblems($at, $sql, $named));
            if (preg_match('/\AUPDATE\b/i', $sql) === 1) {
                array_push($found, ...self::readUpdate($file, $at, $sql));
            } elseif (preg_match('/\A(INSERT|REPLACE)\b/i', $sql) === 1) {
                array_push($found, ...self::readInsert($file, $at, $sql));
            }
        }

        return $found;
    }

    /** @return list<array{string, string}> */
    private static function readUpdate(string $file, string $at, string $sql): array
    {
        if (preg_match('/\AUPDATE\s+(.+?)\s+SET\s+(.+?)(?:\s+WHERE\s+(.+))?\z/is', $sql, $m) !== 1) {
            return [['problem', "$at: an UPDATE naming a lifecycle table cannot be read: $sql"]];
        }
        [$refs, $set, $where] = [$m[1], $m[2], $m[3] ?? ''];
        if (preg_match_all('/(?:\b(\w+)\.)?\bstatus\s*=(?!=)/i', $set, $assigned, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }
        // alias => table, and '' => the first table named
        $tables = [];
        preg_match_all('/(?:\A|\bJOIN\b|,)\s*`?([\w\x00]+)`?(?:\s+(?:AS\s+)?(?!ON\b|SET\b|JOIN\b)(\w+))?/i', $refs, $r, PREG_SET_ORDER);
        foreach ($r as $ref) {
            $tables[''] ??= $ref[1];
            $tables[$ref[2] ?? ''] = $ref[1];
            $tables[$ref[1]] = $ref[1];
        }
        $found = [];
        foreach ($assigned[1] as $i => [$alias]) {
            $alias = is_string($alias) ? $alias : '';
            $table = $tables[$alias] ?? null;
            if ($table === null || str_contains($table, AssembledStrings::UNREAD)) {
                $found[] = ['problem', "$at: the table whose status this sets cannot be read: $sql"];
                continue;
            }
            if (!isset(self::LIFECYCLES[$table])) {
                continue;
            }
            [$lifecycle, $owner] = self::LIFECYCLES[$table];
            if ($file !== $owner) {
                $found[] = ['problem', "$at: writes $table.status outside $owner: $sql"];
                continue;
            }
            $column = '(?<![\w.])' . ($alias === '' ? '' : preg_quote($alias, '/') . '\.') . 'status';
            if (preg_match('/' . $column . '\s*(?:=\s*(?:\?|\'\w+\')|IN\s*\()/i', $where) !== 1) {
                $found[] = ['problem', "$at: sets $table.status and names no status it moves from in its WHERE: $sql"];
                continue;
            }
            // What is assigned, to the next assignment: its literals are states.
            $expression = substr($set, $assigned[0][$i][1]);
            $expression = (string) preg_replace('/,\s*(?:\w+\.)?\w+\s*=(?!=).*\z/s', '', $expression);
            preg_match_all("/'([^']*)'/", $expression, $literals);
            $bad = array_diff($literals[1], self::values($lifecycle));
            if ($bad !== []) {
                $found[] = ['problem', "$at: sets $table.status to \"" . implode('", "', $bad) . '", which '
                    . $lifecycle . ' does not name'];
                continue;
            }
            $found[] = ['write', $table];
        }

        return $found;
    }

    /** @return list<array{string, string}> */
    private static function readInsert(string $file, string $at, string $sql): array
    {
        if (preg_match('/\A(INSERT|REPLACE)\b(?:\s+IGNORE)?\s+INTO\s+`?([\w\x00]+)`?\s*(.*)\z/is', $sql, $m) !== 1) {
            return [['problem', "$at: an INSERT naming a lifecycle table cannot be read: $sql"]];
        }
        [$verb, $table, $rest] = [strtoupper($m[1]), $m[2], $m[3]];
        if (!isset(self::LIFECYCLES[$table])) {
            // INSERT … SELECT from a lifecycle table into another: not a write of one.
            return str_contains($table, AssembledStrings::UNREAD) && preg_match('/\bstatus\b/i', $rest) === 1
                ? [['problem', "$at: the table this inserts a status into cannot be read: $sql"]]
                : [];
        }
        [$lifecycle, $owner] = self::LIFECYCLES[$table];
        if (preg_match('/\A\(([^)]*)\)\s*VALUES\s*(.*)\z/is', $rest, $parts) !== 1) {
            return preg_match('/\bstatus\b/i', $rest) === 1 || str_contains($rest, AssembledStrings::UNREAD)
                ? [['problem', "$at: whether this writes $table.status cannot be read (name the columns, then"
                    . " VALUES): $sql"]]
                : [];
        }
        $columns = array_map(static fn (string $c): string => trim($c, " `"), explode(',', $parts[1]));
        $index = array_search('status', $columns, true);
        if ($index === false) {
            return str_contains($parts[1], AssembledStrings::UNREAD)
                ? [['problem', "$at: the columns this inserts into $table cannot be read: $sql"]]
                : [];
        }
        if ($file !== $owner) {
            return [['problem', "$at: writes $table.status outside $owner: $sql"]];
        }
        if ($verb === 'REPLACE') {
            return [['problem', "$at: a REPLACE rewrites $table.status whatever it was: $sql"]];
        }
        if (preg_match('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b(.*)\z/is', $parts[2], $upsert) === 1
            && preg_match('/\bstatus\s*=/i', $upsert[1]) === 1) {
            return [['problem', "$at: ON DUPLICATE KEY UPDATE rewrites $table.status with no status it moves"
                . " from: $sql"]];
        }
        if (preg_match('/\A\(([^()]*)\)/', $parts[2], $tuple) !== 1) {
            return str_starts_with($parts[2], AssembledStrings::UNREAD)
                ? [['unread', $file]]
                : [['problem', "$at: the VALUES this inserts into $table cannot be read: $sql"]];
        }
        $value = trim(explode(',', $tuple[1])[$index] ?? '');
        if ($value === '?') {
            return [['write', $table]];
        }
        $initial = array_map(static fn (State $s): string => (string) $s->value, $lifecycle::initial());
        if (preg_match("/\A'([^']*)'\z/", $value, $literal) !== 1) {
            return [['problem', "$at: the status this inserts into $table cannot be read: $sql"]];
        }
        if (!in_array($literal[1], $initial, true)) {
            return [['problem', "$at: creates a row as \"" . $literal[1] . "\", which is not a state $lifecycle"
                . ' starts in']];
        }

        return [['write', $table]];
    }

    /**
     * Every literal a statement compares a status with, or sets one to, is a
     * state of a lifecycle whose table it names.
     *
     * @param list<string> $named
     * @return list<array{string, string}>
     */
    private static function literalProblems(string $at, string $sql, array $named): array
    {
        $allowed = [];
        foreach ($named as $table) {
            array_push($allowed, ...self::values(self::LIFECYCLES[$table][0]));
        }
        $literals = [];
        preg_match_all("/\bstatus\s*(?:=|<>|!=)\s*'([^']*)'/i", $sql, $compared);
        array_push($literals, ...$compared[1]);
        preg_match_all('/\bstatus\s+(?:NOT\s+)?IN\s*\(([^)]*)\)/i', $sql, $lists);
        foreach ($lists[1] as $list) {
            preg_match_all("/'([^']*)'/", $list, $items);
            array_push($literals, ...$items[1]);
        }
        $bad = array_values(array_unique(array_diff($literals, $allowed)));

        return $bad === [] ? [] : [['problem', "$at: a status is compared with or set to \"" . implode('", "', $bad)
            . '", which no lifecycle of ' . implode(', ', $named) . ' names: ' . $sql]];
    }

    /** @return list<string> */
    private static function literalComparisons(string $file, string $source): array
    {
        $read = '\[\s*[\'"](?:status|webhook_status)[\'"]\s*\]\s*\)?';
        $literal = '[\'"]\w*[\'"]';
        SourceScan::countMatches(
            '/' . $read . '\s*[!=]==?\s*' . $literal . '|' . $literal . '\s*[!=]==?\s*(?:\(string\)\s*)?\$\w+' . $read . '/',
            $source,
            $file,
            $matches
        );

        return array_map(static fn (string $match): string => $file . ': ' . $match, $matches[0] ?? []);
    }

    /**
     * @param class-string<State> $lifecycle
     * @return list<string>
     */
    private static function values(string $lifecycle): array
    {
        return array_map(static fn (State $s): string => (string) $s->value, $lifecycle::cases());
    }
}
