<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\AffNetworksController;
use Api\V3\ErrorCode;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\RequestContext;
use Api\V3\Support\ServerStateStore;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * A bulk-upsert claims its Idempotency-Key before it writes (CLAUDE.md #13).
 * Read first and recorded after, a retry that raced the first request found
 * nothing recorded and stored the batch again; and a failure between the
 * rows and the record left the key free for the same.
 *
 * The race is widened by sending the retry from inside the first request's
 * own insert, on a connection of its own.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_*); it installs
 * the schema there and writes user 990802's 202_aff_networks rows.
 *
 * @group integration
 */
final class BulkUpsertClaimsItsKeyIntegrationTest extends TestCase
{
    private const USER = 990802;

    private static ?\mysqli $db = null;

    private string $stateDir = '';

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) '
                . '{ return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = self::connect();
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::$db->query('DELETE FROM 202_aff_networks WHERE user_id = ' . self::USER);
            self::$db->close();
        }
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::$db->query('DELETE FROM 202_aff_networks WHERE user_id = ' . self::USER);
        $this->stateDir = sys_get_temp_dir() . '/p202-bulk-claim-it-' . bin2hex(random_bytes(4));
        mkdir($this->stateDir, 0700, true);
        putenv('P202_SERVER_STATE_DIR=' . $this->stateDir);
        RequestContext::setHeaders(['Idempotency-Key' => 'bulk-claim-it-' . bin2hex(random_bytes(6))]);
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        putenv('P202_SERVER_STATE_DIR');
        if ($this->stateDir !== '') {
            exec('rm -rf ' . escapeshellarg($this->stateDir));
        }
    }

    public function testARetryThatRacesTheFirstRequestStoresNothingAndIsToldToWait(): void
    {
        $rows = ['rows' => [['aff_network_name' => 'raced-0'], ['aff_network_name' => 'raced-1']]];
        $retryDb = self::connect();
        $retry = null;
        $first = new class (self::$db, self::USER) extends AffNetworksController {
            public ?\Closure $whileWriting = null;

            protected function beforeCreate(array $payload): array
            {
                $run = $this->whileWriting;
                $this->whileWriting = null;
                if ($run !== null) {
                    $run();
                }

                return parent::beforeCreate($payload);
            }
        };
        $first->whileWriting = static function () use ($retryDb, $rows, &$retry): void {
            try {
                $retry = (new AffNetworksController($retryDb, self::USER))->bulkUpsert($rows);
            } catch (\Throwable $e) {
                $retry = $e;
            }
        };

        $answer = $first->bulkUpsert($rows);

        self::assertSame(2, $answer['summary']['created'], json_encode($answer));
        self::assertSame(['raced-0' => 1, 'raced-1' => 1], self::names(), 'the retry stored the batch a second time');
        self::assertInstanceOf(ConflictException::class, $retry, 'the retry was answered as if it were the first');
        self::assertSame(ErrorCode::IDEMPOTENCY_IN_FLIGHT, $retry->errorCode());

        $after = (new AffNetworksController($retryDb, self::USER))->bulkUpsert($rows);
        self::assertTrue($after['idempotent_replay']);
        self::assertSame($answer['data'], $after['data']);
        self::assertSame(['raced-0' => 1, 'raced-1' => 1], self::names());
        $retryDb->close();
    }

    public function testAFailureAfterRowsWereStoredSpendsTheKeyAsUnknown(): void
    {
        $rows = ['rows' => [['aff_network_name' => 'kept-0']]];
        $failing = new class ($this->stateDir . '/held') extends ServerStateStore {
            #[\Override]
            public function incrementMetric(string $name, int $delta = 1): void
            {
                throw new DatabaseException('Unable to open state lock file');
            }
        };
        $controller = new class (self::$db, self::USER) extends AffNetworksController {
            public ?ServerStateStore $held = null;

            #[\Override]
            protected function stateStore(): ServerStateStore
            {
                return $this->held ?? parent::stateStore();
            }
        };
        $controller->held = $failing;

        try {
            $controller->bulkUpsert($rows);
            self::fail('the failure after the rows did not reach the caller');
        } catch (DatabaseException) {
        }
        self::assertSame(['kept-0' => 1], self::names(), 'the row was stored before the failure');

        // The same store, counting again: the key is spent, not free.
        $again = new class (self::$db, self::USER) extends AffNetworksController {
            public ?ServerStateStore $held = null;

            #[\Override]
            protected function stateStore(): ServerStateStore
            {
                return $this->held ?? parent::stateStore();
            }
        };
        $again->held = new ServerStateStore($this->stateDir . '/held');
        try {
            $again->bulkUpsert($rows);
            self::fail('the key of a batch that was stored was free for the same batch');
        } catch (ConflictException $e) {
            self::assertSame(ErrorCode::IDEMPOTENCY_INDETERMINATE, $e->errorCode());
        } finally {
            self::assertSame(['kept-0' => 1], self::names(), 'the retry stored the batch a second time');
        }
    }

    public function testAFailureWithNothingStoredFreesTheKey(): void
    {
        $rows = ['rows' => [['aff_network_name' => 'freed-0']]];
        $gone = self::connect();
        $failing = new class ($gone, self::USER) extends AffNetworksController {
            public ?ServerStateStore $held = null;

            #[\Override]
            protected function stateStore(): ServerStateStore
            {
                return $this->held ?? parent::stateStore();
            }
        };
        $failing->held = new ServerStateStore($this->stateDir . '/held');
        // The chunk's transaction cannot begin: nothing of the batch is written.
        $gone->close();

        try {
            $failing->bulkUpsert($rows);
            self::fail('a batch on a closed connection was answered');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(ConflictException::class, $e, $e->getMessage());
        }
        self::assertSame([], self::names());

        $again = new class (self::$db, self::USER) extends AffNetworksController {
            public ?ServerStateStore $held = null;

            #[\Override]
            protected function stateStore(): ServerStateStore
            {
                return $this->held ?? parent::stateStore();
            }
        };
        $again->held = new ServerStateStore($this->stateDir . '/held');
        $answer = $again->bulkUpsert($rows);

        self::assertFalse($answer['idempotent_replay']);
        self::assertSame(1, $answer['summary']['created'], (string) json_encode($answer));
        self::assertSame(['freed-0' => 1], self::names());
    }

    /** @return array<string, int> */
    private static function names(): array
    {
        $result = self::$db->query(
            'SELECT aff_network_name, COUNT(*) FROM 202_aff_networks WHERE user_id = ' . self::USER
            . ' GROUP BY aff_network_name'
        );
        self::assertInstanceOf(\mysqli_result::class, $result);
        $names = [];
        foreach ($result->fetch_all(MYSQLI_NUM) as [$name, $count]) {
            $names[(string) $name] = (int) $count;
        }
        ksort($names, SORT_STRING);

        return $names;
    }

    private static function connect(): \mysqli
    {
        $db = mysqli_connect(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        self::assertInstanceOf(\mysqli::class, $db);

        return $db;
    }
}
