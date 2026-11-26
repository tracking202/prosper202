<?php

declare(strict_types=1);

namespace Tests\Rotator;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Rotator\Criterion;
use Prosper202\Rotator\CriterionRepair;

/**
 * Criterion values the API stored as sent before 1.9.79 are stored as the
 * writers store them (Criterion::normalize()) by the 1.9.81 rung: each
 * rewrite logged, a value already stored that way left alone, and a second
 * run a no-op that logs nothing.
 *
 * @group integration
 */
final class CriterionRepairIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const ROTATOR = 981_000_001;

    /** @var array<string, string> stored value => as the writers store it */
    private const VALUES = [
        'United States(US), Canada(CA)' => 'United States(US),Canada(CA)',
        ' all ' => 'ALL',
        'All' => 'ALL',
        'mobile,,tablet,' => 'mobile,tablet',
        'Comcast ' => 'Comcast',
        "line\nbreak , other" => "line\nbreak,other",
        'ALL' => 'ALL',
        'United States(US)' => 'United States(US)',
    ];

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::cleanUp();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::cleanUp();
        self::q('DELETE FROM 202_version');
        foreach (self::$versions ?? [] as $version) {
            self::row('202_version', ['version' => $version]);
        }
        self::$db->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanUp();
    }

    private static function cleanUp(): void
    {
        self::q('DELETE FROM 202_rotator_rules_criteria WHERE rotator_id = ' . self::ROTATOR);
    }

    /** @return array<string, int> stored value => its criterion id */
    private static function seed(): array
    {
        $ids = [];
        $rule = 1;
        foreach (array_keys(self::VALUES) as $value) {
            $ids[$value] = self::insert('INSERT INTO 202_rotator_rules_criteria SET rotator_id = ' . self::ROTATOR
                . ', rule_id = ' . $rule++ . ", type = 'country', statement = 'is', value = " . self::str($value));
        }

        return $ids;
    }

    private static function value(int $id): string
    {
        $found = self::$db->query('SELECT value FROM 202_rotator_rules_criteria WHERE id = ' . $id);
        self::assertInstanceOf(\mysqli_result::class, $found);

        return (string) $found->fetch_row()[0];
    }

    /**
     * @return array{int, string} what run() returned, and what it logged
     */
    private static function repair(): array
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'p202-repair');
        $was = ini_set('error_log', $log);
        try {
            $count = CriterionRepair::run(new Connection(self::$db));
        } finally {
            ini_set('error_log', (string) $was);
        }
        $logged = (string) file_get_contents($log);
        unlink($log);

        return [$count, $logged];
    }

    public function testEachValueIsStoredAsTheWritersStoreItAndEachRewriteIsLogged(): void
    {
        $ids = self::seed();

        [$count, $logged] = self::repair();

        $changed = array_filter(self::VALUES, static fn (string $to, string $from): bool => $to !== $from, ARRAY_FILTER_USE_BOTH);
        self::assertSame(count($changed), $count);
        foreach (self::VALUES as $from => $to) {
            self::assertSame($to, self::value($ids[$from]), json_encode($from));
            self::assertSame(
                array_map(Criterion::normalize(...), Criterion::values($from)),
                Criterion::values($to),
                'the redirects read it as before'
            );
            self::assertSame(Criterion::matchesEveryone(Criterion::values($from)), Criterion::matchesEveryone(Criterion::values($to)));
        }
        $lines = array_values(array_filter(explode("\n", $logged), static fn (string $l): bool => $l !== ''));
        self::assertCount(count($changed), $lines, $logged);
        self::assertStringContainsString(
            sprintf('criterion %d (country) of rule 2 on redirector %d was stored as " all " and is now "ALL"', $ids[' all '], self::ROTATOR),
            $logged
        );
        self::assertStringContainsString('was stored as "Comcast " and is now "Comcast"', $logged);
        self::assertStringContainsString('was stored as "line\nbreak , other"', $logged, 'a line break is escaped');
        self::assertStringNotContainsString('stored as "ALL"', $logged);
        self::assertStringNotContainsString('stored as "United States(US)"', $logged);

        self::assertSame([0, ''], self::repair(), 'a second run changes and logs nothing');
    }

    /** A value saved since the read is not overwritten, though the collation reads it as the one read. */
    public function testAValueSavedSinceTheReadIsKept(): void
    {
        $id = self::insert('INSERT INTO 202_rotator_rules_criteria SET rotator_id = ' . self::ROTATOR
            . ", rule_id = 1, type = 'country', statement = 'is', value = 'all'");
        $conn = new Connection(self::$db);
        $update = $conn->prepareWrite(
            'UPDATE 202_rotator_rules_criteria SET value = ?'
            . ' WHERE id = ? AND CAST(value AS BINARY) = CAST(? AS BINARY)'
        );
        self::q("UPDATE 202_rotator_rules_criteria SET value = 'ALL ' WHERE id = $id");
        $conn->bind($update, 'sis', ['ALL', $id, 'all']);

        self::assertSame(0, $conn->executeUpdate($update));
        self::assertSame('ALL ', self::value($id));
        self::assertStringContainsString(
            'CAST(value AS BINARY) = CAST(? AS BINARY)',
            (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/Rotator/CriterionRepair.php'),
            'the repair asks what this test asks'
        );
    }

    public function testTheUpgradeFrom1980StoresTheValuesAsTheWritersDo(): void
    {
        $ids = self::seed();
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.80']);

        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=', dirname(__DIR__) . '/Upgrade/fixtures/ladder-runner.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            getenv()
        );
        self::assertIsResource($proc);
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'the rung converged');
        foreach (self::VALUES as $from => $to) {
            self::assertSame($to, self::value($ids[$from]), json_encode($from));
        }
        self::assertStringContainsString('Prosper202 redirector rule repair: criterion ' . $ids['All'], $err);
    }
}
