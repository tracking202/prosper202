<?php

declare(strict_types=1);

namespace Tests\Database;

use mysqli;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaVersion;

/**
 * What SchemaVersion says of the stored version against the code's, read
 * from a real 202_version: an upgrade is named only for a schema older than
 * the code, by version_compare() (1.9.8 is older than 1.9.79, 1.9.100 newer,
 * neither as text). A schema newer than the code (files rolled back) is not
 * one an upgrade mends: pendingUpgrade() claims no cause, and mismatch(),
 * which still refuses to run, says to deploy the newer files.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS,
 * P202_TEST_DB_NAME); it puts back the 202_version it found and the mysqli
 * report mode it ran under. It sets errors to raise, so a statement it makes
 * that fails throws.
 *
 * @group integration
 */
final class SchemaVersionIntegrationTest extends TestCase
{
    private const CODE = '1.9.79';

    private static ?mysqli $db = null;

    /** @var list<string>|null the versions 202_version held, or null when this test made the table */
    private static ?array $found = null;

    private static ?int $reportMode = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        self::$reportMode = (new \mysqli_driver())->report_mode;
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $port = getenv('P202_TEST_DB_PORT');
        self::$db = new mysqli(
            $host,
            (string) getenv('P202_TEST_DB_USER'),
            (string) getenv('P202_TEST_DB_PASS'),
            (string) getenv('P202_TEST_DB_NAME'),
            $port !== false && $port !== '' ? (int) $port : 3306
        );
        $exists = self::$db->query("SHOW TABLES LIKE '202_version'");
        self::assertInstanceOf(\mysqli_result::class, $exists);
        if ($exists->num_rows === 1) {
            $rows = self::$db->query('SELECT version FROM 202_version');
            self::assertInstanceOf(\mysqli_result::class, $rows);
            self::$found = array_map(
                static fn (array $r): string => (string) $r['version'],
                $rows->fetch_all(MYSQLI_ASSOC)
            );
        } else {
            self::$db->query('CREATE TABLE 202_version (version varchar(50) NOT NULL) ENGINE=InnoDB');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        if (self::$found === null) {
            self::$db->query('DROP TABLE IF EXISTS 202_version');
        } else {
            self::$db->query('DELETE FROM 202_version');
            foreach (self::$found as $version) {
                $stmt = self::$db->prepare('INSERT INTO 202_version (version) VALUES (?)');
                $stmt->bind_param('s', $version);
                $stmt->execute();
                $stmt->close();
            }
        }
        self::$db->close();
        self::$db = null;
        if (self::$reportMode !== null) {
            mysqli_report(self::$reportMode);
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No scratch database configured (P202_TEST_DB_HOST).');
        }
    }

    /** @return iterable<string, array{string, bool, string|null}> stored, pending, what mismatch() begins with */
    public static function stored(): iterable
    {
        yield 'the version before' => ['1.9.78', true, 'the database needs an upgrade'];
        yield 'older, though greater as text' => ['1.9.8', true, 'the database needs an upgrade'];
        yield 'a rung the ladder wrote with a letter' => ['1.9.30b', true, 'the database needs an upgrade'];
        yield 'the other lettered rung' => ['1.9.30a', true, 'the database needs an upgrade'];
        yield 'the code\'s own' => [self::CODE, false, null];
        yield 'newer' => ['1.9.80', false, 'the database\'s schema is version 1.9.80, newer than this code'];
        yield 'newer, though smaller as text' => ['1.9.100', false, 'the database\'s schema is version 1.9.100, newer'];
        yield 'not a version' => ['latest', false, 'the database needs an upgrade'];
        yield 'an empty version recorded' => ['', false, 'the database needs an upgrade'];
    }

    /** @dataProvider stored */
    public function testAnUpgradeIsNamedOnlyForASchemaOlderThanTheCode(
        string $stored,
        bool $pending,
        ?string $mismatch
    ): void {
        $db = self::$db;
        self::assertNotNull($db);
        $db->query('DELETE FROM 202_version');
        $stmt = $db->prepare('INSERT INTO 202_version (version) VALUES (?)');
        $stmt->bind_param('s', $stored);
        $stmt->execute();
        $stmt->close();

        $said = SchemaVersion::pendingUpgrade($db, self::CODE);
        if ($pending) {
            self::assertSame(
                'the database needs an upgrade: its schema is version ' . $stored . ' and this code is version '
                    . self::CODE . '. Open 202-config/upgrade.php on the site (signed in) to upgrade it.',
                $said
            );
        } else {
            self::assertNull($said);
        }

        $refused = SchemaVersion::mismatch($db, self::CODE);
        if ($mismatch === null) {
            self::assertNull($refused);
        } else {
            self::assertIsString($refused);
            self::assertStringStartsWith($mismatch, $refused);
            self::assertStringContainsString('Nothing was run.', $refused);
        }
    }

    /** No row at all: no cause claimed, and the sync worker still refuses, naming none recorded. */
    public function testNoVersionRecordedClaimsNoUpgrade(): void
    {
        $db = self::$db;
        self::assertNotNull($db);
        $db->query('DELETE FROM 202_version');

        self::assertNull(SchemaVersion::pendingUpgrade($db, self::CODE));
        $refused = SchemaVersion::mismatch($db, self::CODE);
        self::assertIsString($refused);
        self::assertStringStartsWith('the database needs an upgrade: its schema is version (none recorded)', $refused);
    }

    /** No table: a read that fails claims no cause, and the sync worker refuses, saying it could not read it. */
    public function testAVersionThatCannotBeReadClaimsNoUpgrade(): void
    {
        $db = self::$db;
        self::assertNotNull($db);
        $db->query('RENAME TABLE 202_version TO 202_version_away');
        try {
            $pending = SchemaVersion::pendingUpgrade($db, self::CODE);
            $refused = SchemaVersion::mismatch($db, self::CODE);
        } finally {
            $db->query('RENAME TABLE 202_version_away TO 202_version');
        }

        self::assertNull($pending);
        self::assertIsString($refused);
        self::assertStringStartsWith('cannot read the schema version from 202_version', $refused);
    }
}
