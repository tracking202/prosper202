<?php

declare(strict_types=1);

namespace Tests\Database;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\InstallId;
use Prosper202\Database\Tables\SecretTables;

/**
 * The install's id, which names the API state directory, on a real server:
 * minted once when the database has none, never replaced, refused when it
 * cannot be read or is not one, and never minted inside a transaction whose
 * rollback would take it. Read inside a transaction, it leaves the
 * transaction's snapshot to its first plain read (CLAUDE.md #36).
 *
 * @group integration
 */
final class InstallIdIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const TABLE = '202_deployment_secrets';
    private const PROBE = 's276_probe';

    private static ?string $kept = null;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $found = self::$db->query("SELECT secret_value FROM " . self::TABLE . " WHERE secret_name = 'install_id'");
        $row = $found instanceof \mysqli_result ? $found->fetch_assoc() : null;
        self::$kept = is_array($row) ? (string) $row['secret_value'] : null;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::q(SecretTables::deploymentSecrets()->createStatement);
        self::q("DELETE FROM " . self::TABLE . " WHERE secret_name IN ('install_id', '" . self::PROBE . "')");
        if (self::$kept !== null) {
            self::q("INSERT INTO " . self::TABLE . " (secret_name, secret_value, created_at) VALUES ('install_id', "
                . self::str(self::$kept) . ', ' . time() . ')');
        }
        self::$db->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q(SecretTables::deploymentSecrets()->createStatement);
        self::q("DELETE FROM " . self::TABLE . " WHERE secret_name IN ('install_id', '" . self::PROBE . "')");
    }

    private static function stored(): ?string
    {
        $found = self::$db->query("SELECT secret_value FROM " . self::TABLE . " WHERE secret_name = 'install_id'");
        self::assertInstanceOf(\mysqli_result::class, $found);
        $rows = $found->fetch_all(MYSQLI_ASSOC);
        self::assertLessThanOrEqual(1, count($rows));

        return $rows === [] ? null : (string) $rows[0]['secret_value'];
    }

    public function testADatabaseWithNoIdIsGivenOneThatStays(): void
    {
        self::assertNull(self::stored(), 'the database starts with none, as one installed before 1.9.79 does');

        $id = InstallId::of(self::$db);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $id);
        self::assertSame($id, self::stored(), 'it is stored');
        self::assertSame($id, InstallId::of(self::$db), 'and read again, not minted again');
        self::q(InstallId::mintStatement());
        self::assertSame($id, self::stored(), 'a second mint never replaces it');
        InstallId::ensure(self::$db);
        self::assertSame($id, self::stored());
    }

    public function testAnotherDatabaseIsAnotherInstall(): void
    {
        $first = InstallId::of(self::$db);
        self::q("DELETE FROM " . self::TABLE . " WHERE secret_name = 'install_id'");

        self::assertNotSame($first, InstallId::of(self::$db), 'the database installed again has another id');
    }

    public function testAValueThatIsNotAnIdIsRefused(): void
    {
        self::q('INSERT INTO ' . self::TABLE . ' (secret_name, secret_value, created_at)'
            . " VALUES ('install_id', 'not-an-id', 0)");

        try {
            InstallId::of(self::$db);
            self::fail('a stored value that is not an id was read as one');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('not 64 lower-case hex characters', $e->getMessage());
        }
        self::assertSame('not-an-id', self::stored(), 'and is not replaced');
    }

    public function testATableThatCannotBeReadIsRefusedNotMintedInto(): void
    {
        self::q('DROP TABLE ' . self::TABLE);
        try {
            InstallId::of(self::$db);
            self::fail('an unreadable id was answered');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('The install id could not be read', $e->getMessage());
            self::assertStringContainsString(
                "doesn't exist",
                $e->getMessage() . ($e->getPrevious()?->getMessage() ?? ''),
                'the cause is kept for the log'
            );
        } finally {
            self::q(SecretTables::deploymentSecrets()->createStatement);
        }
        self::assertNull(self::stored());
    }

    public function testInsideATransactionWithNoIdNoneIsMinted(): void
    {
        $conn = new Connection(self::$db);
        try {
            $conn->transaction(static fn (): string => InstallId::of(self::$db));
            self::fail('an id was minted inside a transaction');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('no install id yet', $e->getMessage());
        }
        self::assertNull(self::stored(), 'nothing was written');
    }

    /**
     * Read first in a transaction, the id does not fix the snapshot the
     * transaction's later plain reads answer from: a row another connection
     * commits after it is seen. Planted as a plain read, the row is not seen.
     */
    public function testReadInsideATransactionItTakesNoSnapshot(): void
    {
        $id = InstallId::of(self::$db);
        $other = new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        $conn = new Connection(self::$db);
        try {
            [$read, $snapshot, $seen] = $conn->transaction(static function () use ($conn, $other): array {
                $read = InstallId::of(self::$db);
                $snapshot = $conn->snapshotTaken();
                $probe = 'INSERT INTO ' . self::TABLE . ' (secret_name, secret_value, created_at)'
                    . " VALUES ('" . self::PROBE . "', 'x', 0)";
                if ($other->query($probe) === false) {
                    throw new \RuntimeException($other->error);
                }
                $stmt = $conn->prepareWrite('SELECT COUNT(*) AS n FROM ' . self::TABLE . ' WHERE secret_name = ?');
                $conn->bind($stmt, 's', [self::PROBE]);

                return [$read, $snapshot, (int) ($conn->fetchOne($stmt)['n'] ?? -1)];
            });
        } finally {
            $other->close();
        }

        self::assertSame($id, $read);
        self::assertSame(1, $seen, 'a row committed after the read is seen by the transaction\'s first plain read');
        self::assertFalse($snapshot, 'and Connection does not count the read as one');
    }
}
