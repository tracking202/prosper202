<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\AdPlatforms\AdConnectionStatus;
use Prosper202\Attribution\ExportStatus;
use Prosper202\Attribution\ModelStatus;
use Prosper202\Database\Tables\AttributionTables;
use Prosper202\Database\Tables\ConversionTables;
use Tests\Attribution\Support\StatusWrites;
use Tests\Support\SourceScan;

/**
 * Three lifecycles are one enum and one writer each: an attribution model's
 * status (ModelStatus, ModelRepository), an export job's (ExportStatus,
 * ExportStore) and an ad connection's (AdConnectionStatus,
 * AdConnectionStore). Read from the whole served tree:
 *
 * - every statement that writes the status column of one of those tables is
 *   in its owner, or listed with why;
 * - every UPDATE there that sets the status names a state in its own WHERE,
 *   so the statement is the check and a move from any other state changes
 *   nothing;
 * - every quoted value a statement naming the table compares the status
 *   with is a state of the enum.
 *
 * An UPDATE of one of those tables whose SET holds a column the scan cannot
 * read (a variable or `%s` where a column goes) is refused unless the part is
 * listed in READ_FOR with the one literal it holds.
 *
 * Does not see: a statement whose table and SET are both built at runtime
 * (the CRUD controllers' `UPDATE %s SET %s`, which reach none of these
 * tables); a statement no single string starts (`$a . $b`, each a variable);
 * an INSERT whose column list is built at runtime; which state a `?` in a
 * WHERE is bound to (each store binds it from the enum's own move, and the
 * integration tests refuse each wrong one); the value an INSERT's VALUES
 * gives the column; a status compared in a statement that does not name the
 * table; and anything outside PHP.
 */
final class StatusColumnsAreWrittenOnlyByTheirOwnersTest extends TestCase
{
    /** @var array<string, array{enum: class-string<\BackedEnum>, owners: list<string>, others: array<string, string>}> */
    private const LIFECYCLES = [
        '202_attribution_models' => [
            'enum' => ModelStatus::class,
            'owners' => ['202-config/Attribution/ModelRepository.php', '202-config/Attribution/DefaultModel.php'],
            'others' => [
                '202-config/functions-upgrade.php' => 'the 1.9.56 rung runs DefaultModel::SEED_ALL_SQL, the owner\'s own statement',
            ],
        ],
        '202_attribution_exports' => [
            'enum' => ExportStatus::class,
            'owners' => ['202-config/Attribution/ExportStore.php'],
            'others' => [],
        ],
        '202_ad_connections' => [
            'enum' => AdConnectionStatus::class,
            'owners' => ['202-config/AdPlatforms/AdConnectionStore.php'],
            'others' => [],
        ],
    ];

    /** What the scan reads in each owner, so a finding of nothing elsewhere is not a scan that read nothing. */
    private const OWNERS_WRITES = [
        '202-config/Attribution/ModelRepository.php' => ['update', 'insert', 'update'],
        '202-config/Attribution/DefaultModel.php' => ['insert', 'insert', 'insert'],
        '202-config/Attribution/ExportStore.php' => ['insert', 'update', 'update', 'update', 'update', 'update', 'update'],
        '202-config/AdPlatforms/AdConnectionStore.php' => ['insert', 'update', 'update', 'update'],
    ];

    /**
     * A part of a SET the scan cannot read, read here instead: the variable,
     * and the one statement that gives it its value, which names no status.
     *
     * @var array<string, array<string, string>>
     */
    private const READ_FOR = [
        '202-config/Attribution/AttributionWorker.php' => [
            '$cleared' => "\$cleared = 'recompute_failures = 0, recompute_retry_at = 0, recompute_error = NULL';",
        ],
    ];

    public function testOnlyTheOwnersWriteTheStatus(): void
    {
        $problems = [];
        $seen = [];
        $readFor = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            foreach (StatusWrites::of($source) as $write) {
                foreach ($write['tables'] as $table) {
                    if ($write['unread'] !== []) {
                        // With the table unread as well it is a generic writer, which the scan does not follow.
                        $unlisted = isset(self::LIFECYCLES[$table]) ? array_diff($write['unread'], array_keys(self::READ_FOR[$path] ?? [])) : [];
                        if ($unlisted !== []) {
                            $problems[] = "$path:{$write['line']} sets columns of $table the scan cannot read (" . implode(', ', array_map(
                                static fn (string $part): string => $part === '' ? 'what follows the string' : $part,
                                $unlisted
                            )) . "): {$write['text']}";
                        } elseif (isset(self::LIFECYCLES[$table])) {
                            $readFor[$path] = array_merge($readFor[$path] ?? [], $write['unread']);
                        }
                        continue;
                    }
                    if ($table === StatusWrites::UNREAD_TABLE) {
                        $problems[] = "$path:{$write['line']} writes a status column of a table the scan cannot read: {$write['text']}";
                        continue;
                    }
                    $lifecycle = self::LIFECYCLES[$table] ?? null;
                    if ($lifecycle === null || in_array($path, $lifecycle['owners'], true)) {
                        continue;
                    }
                    if (isset($lifecycle['others'][$path])) {
                        $seen[$table][$path] = true;
                        continue;
                    }
                    $problems[] = "$path:{$write['line']} writes $table.status outside " . implode(' and ', $lifecycle['owners']);
                }
            }
        }

        self::assertSame([], $problems, "A status is written outside its lifecycle's owner:\n  " . implode("\n  ", $problems)
            . "\nMove the write into the owner, where the move is checked against the enum; name the table and the columns where the scan could not read them.");
        foreach (self::LIFECYCLES as $table => $lifecycle) {
            self::assertSame([], array_values(array_diff(array_keys($lifecycle['others']), array_keys($seen[$table] ?? []))), "listed writers of $table the scan no longer finds; drop them");
        }
        foreach (self::READ_FOR as $path => $parts) {
            self::assertSame(array_keys($parts), array_values(array_unique($readFor[$path] ?? [])), "the parts of a SET listed for $path are the ones the scan meets there");
        }
    }

    public function testEachPartReadForTheScanHoldsTheOneLiteralListed(): void
    {
        foreach (self::READ_FOR as $path => $parts) {
            $source = self::source($path);
            foreach ($parts as $variable => $statement) {
                self::assertSame(1, substr_count($source, $statement), "$path gives $variable its value in the one statement listed");
                self::assertSame(1, preg_match_all('/' . preg_quote($variable, '/') . '(?!\w)\s*(?:[.+\-*\/%&|^]|\?\?|<<|>>)?=(?!=)/', $source), "$path assigns $variable once");
                self::assertSame(0, preg_match('/(?<![\w`])status(?![\w`])/i', $statement), "what $variable holds names no status");
            }
        }
    }

    public function testEveryMoveNamesTheStateItLeaves(): void
    {
        $problems = [];
        foreach (self::LIFECYCLES as $table => $lifecycle) {
            foreach ($lifecycle['owners'] as $owner) {
                foreach (StatusWrites::of(self::source($owner)) as $write) {
                    if ($write['kind'] === 'update' && in_array($table, $write['tables'], true) && !$write['guarded']) {
                        $problems[] = "$owner:{$write['line']} sets $table.status with no status in its WHERE: {$write['text']}";
                    }
                }
            }
        }

        self::assertSame([], $problems, "A move that does not name the state it leaves overwrites whatever is there:\n  " . implode("\n  ", $problems)
            . "\nAdd `AND status = ?` (or IN) bound from the enum's move, and read the affected rows.");
    }

    public function testAStatusAStatementComparesIsAState(): void
    {
        $problems = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            foreach (self::LIFECYCLES as $table => $lifecycle) {
                $states = array_column($lifecycle['enum']::cases(), 'value');
                foreach (StatusWrites::literals($source, $table) as $literal) {
                    if (!in_array($literal['value'], $states, true)) {
                        $problems[] = "$path:{$literal['line']} compares $table.status with '{$literal['value']}', which {$lifecycle['enum']} does not name";
                    }
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems) . "\nBind or interpolate a case of the enum instead of retyping a value.");
    }

    public function testTheScanReadsTheOwnersOwnWrites(): void
    {
        $read = [];
        foreach (array_keys(self::OWNERS_WRITES) as $owner) {
            $read[$owner] = array_column(StatusWrites::of(self::source($owner)), 'kind');
        }

        self::assertSame(self::OWNERS_WRITES, $read);
    }

    public function testEachColumnHoldsExactlyTheStates(): void
    {
        foreach ([
            [AttributionTables::attributionModels()->createStatement, ModelStatus::class],
            [AttributionTables::attributionExports()->createStatement, ExportStatus::class],
        ] as [$sql, $enum]) {
            self::assertSame(1, preg_match("/`status` enum\\(([^)]*)\\)/", $sql, $m), $enum);
            preg_match_all("/'([^']+)'/", $m[1], $values);
            self::assertSame(array_column($enum::cases(), 'value'), $values[1], $enum);
        }
        self::assertSame(1, preg_match('/`status` varchar\((\d+)\)/', ConversionTables::adConnections()->createStatement, $m));
        foreach (AdConnectionStatus::cases() as $case) {
            self::assertLessThanOrEqual((int) $m[1], strlen($case->value), $case->value . ' fits the column');
        }
    }

    /** @dataProvider plantedWrites */
    public function testTheScanReadsEachWriteShape(string $code, string $kind, string $table, bool $guarded): void
    {
        $found = StatusWrites::of("<?php\n" . $code);

        self::assertSame([[$kind, [$table], $guarded]], array_map(static fn (array $w): array => [$w['kind'], $w['tables'], $w['guarded']], $found), $code);
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function plantedWrites(): iterable
    {
        yield 'an update with no from-state' => ['$db->query("UPDATE 202_attribution_exports SET status = \'failed\' WHERE export_id = 5");', 'update', '202_attribution_exports', false];
        yield 'an update that names one' => ['$c->prepareWrite("UPDATE 202_attribution_exports SET status = ? WHERE export_id = ? AND status = ?");', 'update', '202_attribution_exports', true];
        yield 'an update that names several' => ['$c->prepareWrite(\'UPDATE 202_ad_connections SET status = ? WHERE connection_id = ? AND status IN (\' . $held . \')\');', 'update', '202_ad_connections', true];
        yield 'backticks, an alias and lower case' => ["\$db->query('update `202_attribution_models` m set m.`status` = ? where m.model_id = ?');", 'update', '202_attribution_models', false];
        yield 'the table from the registry' => ['$c->prepareWrite(\'UPDATE `\' . \Prosper202\Database\Schema\TableRegistry::AD_CONNECTIONS . \'` SET status = ? WHERE connection_id = ?\');', 'update', '202_ad_connections', false];
        yield 'a status that only looks guarded' => ['$db->query("UPDATE 202_attribution_models SET status = ? WHERE status_reason = ?");', 'update', '202_attribution_models', false];
        yield 'a status compared only inside a subquery' => ['$db->query("UPDATE 202_attribution_models SET status = ? WHERE model_id IN (SELECT model_id FROM 202_x WHERE status = ? AND kind IN (1, 2))");', 'update', '202_attribution_models', false];
        yield 'a guard beside a subquery' => ['$db->query("UPDATE 202_attribution_models SET status = ? WHERE model_id IN (SELECT model_id FROM 202_x WHERE kind IN (1, 2)) AND status IN (?, ?)");', 'update', '202_attribution_models', true];
        yield 'a statement after a comment' => ['$db->query("/* nightly */ UPDATE 202_attribution_exports SET status = ? WHERE export_id = ?");', 'update', '202_attribution_exports', false];
        yield 'a heredoc over lines' => ["\$sql = <<<SQL\nUPDATE 202_attribution_exports\n   SET attempts = 0,\n       status = 'pending'\n WHERE export_id = 1\nSQL;\n", 'update', '202_attribution_exports', false];
        yield 'a table read at runtime' => ['$db->query("UPDATE " . $table . " SET status = ? WHERE id = ?");', 'update', StatusWrites::UNREAD_TABLE, false];
        yield 'an insert naming the column' => ['$db->query("INSERT INTO 202_ad_connections (user_id, status) VALUES (1, \'connected\')");', 'insert', '202_ad_connections', false];
        yield 'an insert with no column list' => ['$db->query("INSERT INTO 202_attribution_exports VALUES (1, 2, 3)");', 'insert', '202_attribution_exports', false];
        yield 'an insert by assignments' => ['$db->query("INSERT INTO 202_attribution_models SET user_id = 1, status = \'active\'");', 'insert', '202_attribution_models', false];
        yield 'a replace' => ['$db->query("REPLACE INTO 202_attribution_models (model_id, status) VALUES (1, \'active\')");', 'insert', '202_attribution_models', false];
        yield 'an upsert that rewrites it' => ['$db->query("INSERT INTO 202_ad_connections (connection_id) VALUES (1) ON DUPLICATE KEY UPDATE status = \'connected\'");', 'insert', '202_ad_connections', false];
    }

    /**
     * @dataProvider setsTheScanCannotRead
     * @param list<string> $unread
     */
    public function testAColumnTheScanCannotReadIsAWriteThatSaysSo(string $code, array $unread): void
    {
        self::assertSame([$unread], array_column(StatusWrites::of("<?php\n" . $code), 'unread'), $code);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function setsTheScanCannotRead(): iterable
    {
        yield 'the assignments in a variable' => ['$db->query("UPDATE 202_attribution_models SET " . $sets . " WHERE model_id = ?");', ['$sets']];
        yield 'one more after those read' => ['$db->query(\'UPDATE 202_attribution_models SET model_name = ?, \' . $more . \' WHERE model_id = ?\');', ['$more']];
        yield 'a column interpolated' => ['$db->query("UPDATE 202_attribution_models SET model_name = ?, {$column} = ? WHERE model_id = ?");', ['{$column}']];
        yield 'a column formatted in' => ['$db->query(sprintf("UPDATE 202_ad_connections SET %s = ? WHERE connection_id = ?", $column));', ['%s']];
        yield 'a SET that runs on past the string' => ['$sql = "UPDATE 202_attribution_exports SET attempts = 0"; $sql .= ", status = ?";', ['']];
        yield 'a status the scan reads is not unread' => ['$db->query("UPDATE 202_attribution_models SET " . $sets . ", status = ? WHERE model_id = ?");', []];
    }

    public function testAValueTheScanCannotReadIsNotAColumn(): void
    {
        self::assertSame([], StatusWrites::of("<?php\n" . '$db->query("UPDATE 202_attribution_models SET model_name = \'" . $name . "\', lookback_days = {$days} WHERE model_id = ?");'));
    }

    /** @dataProvider notWrites */
    public function testTheScanReadsNoStatusWriteInto(string $code): void
    {
        self::assertSame([], StatusWrites::of("<?php\n" . $code), $code);
    }

    /** @return iterable<string, array{string}> */
    public static function notWrites(): iterable
    {
        yield 'a read' => ['$db->query("SELECT status FROM 202_attribution_exports WHERE status = \'pending\'");'];
        yield 'another column of the table' => ['$db->query("UPDATE 202_attribution_exports SET file_path = ? WHERE export_id = ? AND status = ?");'];
        yield 'a longer column name' => ['$db->query("UPDATE 202_attribution_models SET status_reason = ? WHERE model_id = ?");'];
        yield 'an insert that leaves it to its default' => ['$db->query("INSERT INTO 202_attribution_exports (user_id) VALUES (1)");'];
        yield 'an insert by assignments without it' => ['$db->query("INSERT INTO 202_attribution_models SET user_id = 1, status_reason = NULL");'];
        yield 'a delete' => ['$db->query("DELETE FROM 202_attribution_exports WHERE status <> ?");'];
        yield 'prose' => ['echo "Update the status = active of " . $name;'];
    }

    public function testTheScanReadsEachComparedValue(): void
    {
        $code = "<?php\n" . '$a = "SELECT 1 FROM 202_attribution_models om WHERE om.status = \'paused\' OR `status` IN (\'active\', \'gone\') OR status <> \'x\'";'
            . "\n" . '$b = "SELECT 1 FROM 202_attribution_models WHERE status = \'" . $state->value . "\' AND status_reason = \'why\'";'
            . "\n" . '$c = "SELECT 1 FROM 202_other WHERE status = \'elsewhere\'";';

        self::assertSame(['paused', 'x', 'active', 'gone'], array_column(StatusWrites::literals($code, '202_attribution_models'), 'value'));
    }

    private static function source(string $path): string
    {
        return SourceScan::phpFiles()[$path] ?? throw new \RuntimeException($path . ' is not in the tree');
    }
}
