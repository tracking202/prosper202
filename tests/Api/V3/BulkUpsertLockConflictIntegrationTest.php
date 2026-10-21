<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controller;
use Api\V3\Controllers\AffNetworksController;
use Api\V3\Controllers\CampaignsController;
use Api\V3\RequestContext;
use Api\V3\Support\ServerStateStore;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Exceptions\QueryException;
use Prosper202\Database\SchemaInstaller;

/**
 * Bulk-upsert under lock conflicts and failed rows, on the real controllers
 * against a real database (CLAUDE.md #9: the transaction, the savepoints and
 * what the server does to them are the seam).
 *
 * A chunk's rows share one transaction, and a deadlock rolls all of it back
 * on the server: the rows before it in the chunk were answered `created`
 * while nothing of them was stored, and the rows after it ran outside any
 * transaction. A lock conflict now runs the chunk again, up to three more
 * times, and then answers every row of it not stored and `retryable`; a row
 * that fails otherwise is undone alone; and the change feed hears only of
 * rows that committed, once each.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_*); it installs
 * the schema there, adds triggers to 202_aff_networks and 202_aff_campaigns
 * that act only on the row names below, and writes users 990801's rows and
 * its own p202_bulk_* tables.
 *
 * @group integration
 */
final class BulkUpsertLockConflictIntegrationTest extends TestCase
{
    private const USER = 990801;

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
        // The API's own error mode: index.php never calls connect.php's
        // mysqli_report(), so PHP's default ERROR|STRICT applies.
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = self::connect();
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        self::$db = $db;

        self::q('DROP TABLE IF EXISTS p202_bulk_locks, p202_bulk_weight, p202_bulk_attempts');
        self::q('CREATE TABLE p202_bulk_locks (id INT PRIMARY KEY, v INT NOT NULL) ENGINE=InnoDB');
        self::q('CREATE TABLE p202_bulk_weight (n INT NOT NULL) ENGINE=InnoDB');
        // Counted outside the transaction, so a rolled-back attempt is counted too.
        self::q('CREATE TABLE p202_bulk_attempts (n INT NOT NULL, fail_first INT NOT NULL) ENGINE=MyISAM');
        self::q('DROP TRIGGER IF EXISTS p202_bulk_lock_conflicts');
        self::q("CREATE TRIGGER p202_bulk_lock_conflicts BEFORE INSERT ON 202_aff_networks FOR EACH ROW
            BEGIN
                IF NEW.aff_network_name = 'signal-deadlock' THEN
                    UPDATE p202_bulk_attempts SET n = n + 1;
                    IF (SELECT n FROM p202_bulk_attempts) <= (SELECT fail_first FROM p202_bulk_attempts) THEN
                        SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'signalled by the test';
                    END IF;
                ELSEIF NEW.aff_network_name = 'wait-for-lock' THEN
                    UPDATE p202_bulk_attempts SET n = n + 1;
                    UPDATE p202_bulk_locks SET v = v + 1 WHERE id = 1;
                ELSEIF NEW.aff_network_name = 'cross-locks' THEN
                    UPDATE p202_bulk_locks SET v = v + 1 WHERE id = 2;
                    UPDATE p202_bulk_locks SET v = v + 1 WHERE id = 1;
                END IF;
            END");
        self::q('DROP TRIGGER IF EXISTS p202_bulk_refuse_rollup');
        self::q("CREATE TRIGGER p202_bulk_refuse_rollup BEFORE INSERT ON 202_dirty_hours FOR EACH ROW
            BEGIN
                IF NEW.user_id = " . self::USER . " THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rollup refused by the test';
                END IF;
            END");
        self::q('DROP TRIGGER IF EXISTS p202_bulk_refuse_public_id');
        self::q("CREATE TRIGGER p202_bulk_refuse_public_id BEFORE UPDATE ON 202_aff_campaigns FOR EACH ROW
            BEGIN
                IF NEW.aff_campaign_name = 'public-id-refused' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public id refused by the test';
                END IF;
            END");
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::$db->query('DROP TRIGGER IF EXISTS p202_bulk_lock_conflicts');
            self::$db->query('DROP TRIGGER IF EXISTS p202_bulk_refuse_public_id');
            self::$db->query('DROP TRIGGER IF EXISTS p202_bulk_refuse_rollup');
            self::$db->query('DROP TABLE IF EXISTS p202_bulk_locks, p202_bulk_weight, p202_bulk_attempts');
            foreach (['202_aff_networks', '202_aff_campaigns'] as $table) {
                self::$db->query("DELETE FROM $table WHERE user_id = " . self::USER);
            }
            self::$db->close();
        }
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        RequestContext::reset();
        foreach (['202_aff_networks', '202_aff_campaigns'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
        self::q('DELETE FROM p202_bulk_locks');
        self::q('INSERT INTO p202_bulk_locks VALUES (1, 0), (2, 0)');
        self::q('DELETE FROM p202_bulk_attempts');
        self::q('INSERT INTO p202_bulk_attempts VALUES (0, 0)');
        $this->stateDir = sys_get_temp_dir() . '/p202-bulk-lock-it-' . bin2hex(random_bytes(4));
        mkdir($this->stateDir, 0700, true);
        putenv('P202_SERVER_STATE_DIR=' . $this->stateDir);
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        putenv('P202_SERVER_STATE_DIR');
        if (self::$db !== null) {
            self::$db->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }
    }

    public function testAChunkALockConflictRolledBackIsRunAgainAndEachRowStoredOnce(): void
    {
        self::q('UPDATE p202_bulk_attempts SET fail_first = 2');
        $networks = new AffNetworksController(self::db(), self::USER);

        $result = $this->bulk($networks, [
            ['aff_network_name' => 'before-1'],
            ['aff_network_name' => 'before-2'],
            ['aff_network_name' => 'signal-deadlock'],
            ['aff_network_name' => 'after'],
        ]);

        self::assertSame(3, self::attempts(), 'two attempts refused, the third stored');
        self::assertSame(array_fill(0, 4, 'created'), array_column($result['data'], 'status'), json_encode($result));
        self::assertSame(self::summary(created: 4), $result['summary']);
        $ids = self::ids($result['data'], 'aff_network_id');
        self::assertSame(
            ['after' => 1, 'before-1' => 1, 'before-2' => 1, 'signal-deadlock' => 1],
            self::networkNames()
        );
        self::assertSame(
            array_combine($ids, ['before-1', 'before-2', 'signal-deadlock', 'after']),
            self::storedIds('202_aff_networks', 'aff_network_id', 'aff_network_name'),
            'each answer names the row stored'
        );
        self::assertSame(
            self::creates($ids),
            $this->changes('aff-networks', 'aff_network_id'),
            'one change record per stored row, none for the attempts rolled back'
        );
    }

    /**
     * A real deadlock: another connection holds the row the trigger asks for
     * second and asks for the one it took first. The server rolls this
     * transaction back, the row before it included, and the chunk is run
     * again.
     */
    public function testARealDeadlockRollsTheChunkBackAndTheRetryStoresEveryRowOnce(): void
    {
        $networks = new AffNetworksController(self::db(), self::USER);
        $marker = (int) $networks->create(['aff_network_name' => 'marker'])['data']['aff_network_id'];
        self::q('DELETE FROM 202_aff_networks WHERE aff_network_id = ' . $marker);

        [$proc, $pipes] = self::startLockCrosser(1000);
        try {
            $result = $this->bulk(new AffNetworksController(self::db(), self::USER), [
                ['aff_network_name' => 'plain-0'],
                ['aff_network_name' => 'cross-locks'],
                ['aff_network_name' => 'plain-2'],
            ]);
        } finally {
            $other = self::finish($proc, $pipes);
        }

        self::assertSame("locked\nwon\n", $other, 'the other connection won the deadlock');
        self::assertSame(array_fill(0, 3, 'created'), array_column($result['data'], 'status'), json_encode($result));
        $ids = self::ids($result['data'], 'aff_network_id');
        // The first attempt inserted plain-0 as $marker + 1 and the deadlock
        // took it back; an id is not handed out twice.
        self::assertGreaterThan($marker + 1, $ids[0], 'the chunk was run again');
        self::assertSame(['cross-locks' => 1, 'plain-0' => 1, 'plain-2' => 1], self::networkNames());
        self::assertSame(
            array_combine($ids, ['plain-0', 'cross-locks', 'plain-2']),
            self::storedIds('202_aff_networks', 'aff_network_id', 'aff_network_name')
        );
        $changes = $this->changes('aff-networks', 'aff_network_id');
        self::assertSame([['create', $marker]], array_slice($changes, 0, 1));
        self::assertSame(self::creates($ids), array_slice($changes, 1));
    }

    public function testAChunkDeadlockedOnEveryAttemptIsAnsweredNotStoredAndTheNextChunkStored(): void
    {
        self::q('UPDATE p202_bulk_attempts SET fail_first = 99');
        $rows = self::networkRows('chunk1-', 99);
        $rows[] = ['aff_network_name' => 'signal-deadlock'];
        $rows[] = ['aff_network_name' => 'chunk2-a'];
        $rows[] = [];
        $rows[] = ['aff_network_name' => 'chunk2-c'];
        $key = 'bulk-lock-it-' . bin2hex(random_bytes(6));

        $result = $this->bulk(new AffNetworksController(self::db(), self::USER), $rows, $key);

        self::assertSame(4, self::attempts(), 'the first attempt and three more');
        self::assertSame(self::summary(created: 2, skipped: 1, error: 100, retryable: 100), $result['summary']);
        foreach (array_slice($result['data'], 0, 100) as $offset => $row) {
            self::assertSame($offset, $row['index']);
            self::assertSame('error', $row['status']);
            self::assertTrue($row['retryable'] ?? null, json_encode($row));
            self::assertStringContainsString('deadlock', $row['message']);
            self::assertStringContainsString('on each of 4 attempts', $row['message']);
            self::assertStringContainsString('new Idempotency-Key', $row['message']);
            self::assertArrayNotHasKey('data', $row);
            self::assertArrayNotHasKey('unconfirmed', $row);
        }
        self::assertSame(['created', 'skipped', 'created'], array_column(array_slice($result['data'], 100), 'status'));
        self::assertSame(['chunk2-a' => 1, 'chunk2-c' => 1], self::networkNames(), 'none of the first chunk');
        $stored = [$result['data'][100], $result['data'][102]];
        self::assertSame(
            self::creates(self::ids($stored, 'aff_network_id')),
            $this->changes('aff-networks', 'aff_network_id')
        );

        // The answer is the key's: the same rows under it replay it, and
        // write nothing; the rows not stored go again under a new key.
        $replay = $this->bulk(new AffNetworksController(self::db(), self::USER), $rows, $key);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame($result['data'], $replay['data']);
        self::assertSame(4, self::attempts());
        self::q('UPDATE p202_bulk_attempts SET fail_first = 0');
        $again = $this->bulk(new AffNetworksController(self::db(), self::USER), array_slice($rows, 0, 100));
        self::assertSame(self::summary(created: 100), $again['summary'], json_encode($again['data'][99]));
        self::assertCount(102, self::networkNames());
    }

    /**
     * A lock wait timeout is not run again (each attempt would wait it out
     * once more), and the chunks after it are not attempted.
     */
    public function testALockWaitTimeoutIsAnsweredAtOnceAndTheRowsAfterItAreNotAttempted(): void
    {
        $holder = self::connect();
        self::assertTrue($holder->begin_transaction());
        $holder->query('UPDATE p202_bulk_locks SET v = 9 WHERE id = 1');
        self::q('SET SESSION innodb_lock_wait_timeout = 1');
        $rows = self::networkRows('chunk1-', 99);
        $rows[] = ['aff_network_name' => 'wait-for-lock'];
        $rows[] = ['aff_network_name' => 'chunk2-a'];
        $rows[] = ['aff_network_name' => 'chunk2-b'];
        $key = 'bulk-lock-it-' . bin2hex(random_bytes(6));
        try {
            $result = $this->bulk(new AffNetworksController(self::db(), self::USER), $rows, $key);
        } finally {
            $holder->rollback();
            $holder->close();
        }

        self::assertSame(1, self::attempts(), 'the lock was waited for once');
        self::assertSame(self::summary(error: 102, retryable: 102), $result['summary']);
        foreach ($result['data'] as $offset => $row) {
            self::assertSame($offset, $row['index']);
            self::assertSame('error', $row['status']);
            self::assertTrue($row['retryable'] ?? null, json_encode($row));
            self::assertStringContainsString(
                $offset < 100 ? 'lock wait timeout, and the rows written with it were rolled back' : 'not attempted',
                $row['message']
            );
        }
        self::assertSame([], self::networkNames());
        self::assertSame([], $this->changes('aff-networks', 'aff_network_id'));

        $replay = $this->bulk(new AffNetworksController(self::db(), self::USER), $rows, $key);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame($result['data'], $replay['data']);
        $again = $this->bulk(new AffNetworksController(self::db(), self::USER), $rows);
        self::assertSame(self::summary(created: 102), $again['summary']);
    }

    /**
     * Once a chunk has committed, a failure after it is answered row by row
     * and recorded under the key: thrown, it left the key unspent, and the
     * same rows sent again under it stored the first chunk a second time.
     */
    public function testAFailureAfterAChunkCommittedAnswersEveryRowAndSpendsTheKey(): void
    {
        $db = self::connectFailing(beginFailsAt: 2);
        $rows = [
            ...self::networkRows('chunk1-', 100),
            ...self::networkRows('chunk2-', 100),
            ['aff_network_name' => 'chunk3'],
        ];
        $key = 'bulk-lock-it-' . bin2hex(random_bytes(6));
        $log = ini_set('error_log', '/dev/null');
        try {
            $result = $this->bulk(new AffNetworksController($db, self::USER), $rows, $key);
        } finally {
            ini_set('error_log', (string) $log);
        }

        self::assertSame(2, $db->begins, 'the third chunk was not begun');
        self::assertSame(self::summary(created: 100, error: 101, retryable: 101), $result['summary']);
        foreach (array_slice($result['data'], 100) as $offset => $row) {
            self::assertSame('error', $row['status']);
            self::assertTrue($row['retryable'] ?? null, json_encode($row));
            self::assertStringContainsString(
                $offset < 100 ? 'The database failed while the rows written with it were stored' : 'not attempted',
                $row['message']
            );
            self::assertStringNotContainsString('Unable to begin', $row['message'], 'the cause is the log\'s');
        }
        $expected = array_fill_keys(array_column(self::networkRows('chunk1-', 100), 'aff_network_name'), 1);
        ksort($expected, SORT_STRING);
        self::assertSame($expected, self::networkNames(), 'the first chunk, once');
        self::assertSame(
            self::creates(self::ids(array_slice($result['data'], 0, 100), 'aff_network_id')),
            $this->changes('aff-networks', 'aff_network_id')
        );

        $replay = $this->bulk(new AffNetworksController(self::db(), self::USER), $rows, $key);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame($result['data'], $replay['data']);
        self::assertCount(100, self::networkNames(), 'the first chunk once');
    }

    public function testAFailureBeforeAnyChunkCommittedIsThrownAndLeavesTheKeyUnspent(): void
    {
        $db = self::connectFailing(beginFailsAt: 1);
        $rows = self::networkRows('only-', 2);
        $key = 'bulk-lock-it-' . bin2hex(random_bytes(6));
        try {
            $this->bulk(new AffNetworksController($db, self::USER), $rows, $key);
            self::fail('a bulk-upsert nothing of which was written answered');
        } catch (QueryException $e) {
            self::assertStringContainsString('Unable to begin transaction', $e->getMessage());
        }
        self::assertSame([], self::networkNames());

        $again = $this->bulk(new AffNetworksController(self::db(), self::USER), $rows, $key);
        self::assertFalse($again['idempotent_replay'], 'the key was not spent');
        self::assertSame(self::summary(created: 2), $again['summary']);
    }

    /**
     * A commit the server did not confirm may have landed (here it did): its
     * rows are answered unconfirmed, never retryable, since sent again they
     * would be stored twice, and the chunks after it are not attempted.
     */
    public function testACommitTheServerDidNotConfirmAnswersItsRowsUnconfirmed(): void
    {
        $db = self::connectFailing(commitUnconfirmedAt: 1);
        $rows = [...self::networkRows('chunk1-', 100), ['aff_network_name' => 'chunk2']];
        $key = 'bulk-lock-it-' . bin2hex(random_bytes(6));
        $log = ini_set('error_log', '/dev/null');
        try {
            $result = $this->bulk(new AffNetworksController($db, self::USER), $rows, $key);
        } finally {
            ini_set('error_log', (string) $log);
        }

        self::assertSame(self::summary(error: 101, retryable: 1, unconfirmed: 100), $result['summary']);
        foreach (array_slice($result['data'], 0, 100) as $row) {
            self::assertSame('error', $row['status']);
            self::assertTrue($row['unconfirmed'] ?? null, json_encode($row));
            self::assertArrayNotHasKey('retryable', $row);
            self::assertStringStartsWith('Unconfirmed:', $row['message']);
            self::assertStringContainsString('Look it up before sending it again', $row['message']);
        }
        self::assertTrue($result['data'][100]['retryable'] ?? null);
        self::assertStringContainsString('not attempted', $result['data'][100]['message']);
        self::assertCount(100, self::networkNames(), 'the commit landed');
        // The feed hears only of rows answered stored.
        self::assertSame([], $this->changes('aff-networks', 'aff_network_id'));

        $replay = $this->bulk(new AffNetworksController(self::db(), self::USER), $rows, $key);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame($result['data'], $replay['data']);
        self::assertCount(100, self::networkNames());
    }

    public function testARowThatFailsAfterItsInsertIsUndoneAloneAndItsNeighboursStored(): void
    {
        $network = self::category('category');

        [$result, $log] = self::logged(fn (): array => $this->bulk(new CampaignsController(self::db(), self::USER), [
            self::campaignRow('c-before', $network),
            self::campaignRow('public-id-refused', $network),
            self::campaignRow('c-after', $network),
        ]));

        $statuses = array_column($result['data'], 'status');
        self::assertSame(['created', 'error', 'created'], $statuses, json_encode($result));
        self::assertSame(self::summary(created: 2, error: 1), $result['summary']);
        $refused = $result['data'][1];
        self::assertStringStartsWith('Not stored:', $refused['message']);
        self::assertStringContainsString('the server log has the cause', $refused['message']);
        self::assertStringNotContainsString(
            'refused by the test',
            $refused['message'],
            'the server\'s words are the log\'s'
        );
        self::assertStringContainsString('bulk-upsert row 1 undone', $log);
        self::assertArrayNotHasKey('retryable', $refused);
        self::assertSame(
            ['c-after' => 1, 'c-before' => 1],
            self::storedNames('202_aff_campaigns', 'aff_campaign_name'),
            'the refused row\'s insert was undone'
        );
        $stored = [$result['data'][0]['data'], $result['data'][2]['data']];
        self::assertSame(
            self::creates(self::ids($stored, 'aff_campaign_id')),
            $this->changes('campaigns', 'aff_campaign_id')
        );
        foreach ($stored as $record) {
            self::assertGreaterThan(0, (int) $record['aff_campaign_id_public'], 'its neighbours have their public ids');
        }
    }

    public function testAnUpdateUndoneAfterItsChangeWasHeldLeavesNoChangeRecord(): void
    {
        $from = self::category('category');
        $to = self::category('other category');
        $campaigns = new CampaignsController(self::db(), self::USER);
        $moved = (int) $campaigns->create(self::campaignRow('moved', $from))['data']['aff_campaign_id'];
        $renamed = (int) $campaigns->create(self::campaignRow('renamed', $from))['data']['aff_campaign_id'];
        $before = $this->changes('campaigns', 'aff_campaign_id');

        // A campaign moved to another category queues its report rows for a
        // rollup after its update, and the trigger refuses the queue row.
        [$result, $log] = self::logged(fn (): array => $this->bulk(new CampaignsController(self::db(), self::USER), [
            ['aff_campaign_id' => $moved, 'aff_network_id' => $to],
            ['aff_campaign_id' => $renamed, 'aff_campaign_name' => 'renamed again'],
        ]));

        self::assertSame(['error', 'updated'], array_column($result['data'], 'status'), json_encode($result));
        self::assertStringStartsWith('Not stored:', $result['data'][0]['message']);
        self::assertStringContainsString('the server log has the cause', $result['data'][0]['message']);
        self::assertStringContainsString('bulk-upsert row 0 undone', $log);
        $category = self::value('SELECT aff_network_id FROM 202_aff_campaigns WHERE aff_campaign_id = ' . $moved);
        self::assertSame($from, (int) $category, 'the move was undone');
        self::assertSame(
            [...$before, ['update', $renamed]],
            $this->changes('campaigns', 'aff_campaign_id'),
            'the undone update\'s change record went with it'
        );
    }

    public function testAChangeRecordThatCannotBeWrittenLeavesTheRowStoredAndSaysSo(): void
    {
        // A directory where the feed's lock file goes: the record cannot be written.
        mkdir($this->stateDir . '/changes/aff-networks.json.lock', 0700, true);
        $log = ini_set('error_log', '/dev/null');
        try {
            $result = $this->bulk(new AffNetworksController(self::db(), self::USER), [
                ['aff_network_name' => 'stored-1'],
                ['aff_network_name' => 'stored-2'],
            ]);
        } finally {
            ini_set('error_log', (string) $log);
        }

        self::assertSame(['stored-1' => 1, 'stored-2' => 1], self::networkNames());
        self::assertSame(self::summary(error: 2), $result['summary']);
        foreach ($result['data'] as $row) {
            self::assertSame('error', $row['status']);
            self::assertStringContainsString('The record exists', $row['message'], 'it does not invite a second copy');
            self::assertArrayNotHasKey('retryable', $row);
        }
    }

    /**
     * A rollback that fails leaves what the transaction wrote pending, so
     * its rows are answered unconfirmed, and nothing more is begun on the
     * handle: the deadlock is not run again.
     */
    public function testARollbackThatFailsAnswersTheChunkUnconfirmed(): void
    {
        self::q('UPDATE p202_bulk_attempts SET fail_first = 1');
        $db = self::connectFailing(rollbackFailsAt: 1);
        $rows = [['aff_network_name' => 'before'], ['aff_network_name' => 'signal-deadlock']];
        $log = ini_set('error_log', '/dev/null');
        try {
            $result = $this->bulk(new AffNetworksController($db, self::USER), $rows);
        } finally {
            ini_set('error_log', (string) $log);
        }

        self::assertSame(1, self::attempts());
        self::assertSame(1, $db->begins);
        self::assertSame(self::summary(error: 2, unconfirmed: 2), $result['summary'], json_encode($result));
        self::assertSame([true, true], array_column($result['data'], 'unconfirmed'));
        self::assertSame([], self::networkNames(), 'here the deadlock had rolled it back');
    }

    /**
     * What $fn answers, and what it wrote to the error log.
     *
     * @template T
     * @param callable(): T $fn
     * @return array{T, string}
     */
    private static function logged(callable $fn): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'p202-bulk-log-');
        $previous = ini_set('error_log', $file);
        try {
            $answer = $fn();
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $log = (string) file_get_contents($file);
        unlink($file);

        return [$answer, $log];
    }

    private static function category(string $name): int
    {
        $created = (new AffNetworksController(self::db(), self::USER))->create(['aff_network_name' => $name]);

        return (int) $created['data']['aff_network_id'];
    }

    /** @return array<string, mixed> */
    private static function campaignRow(string $name, int $network): array
    {
        return [
            'aff_campaign_name' => $name, 'aff_campaign_url' => 'https://o.example', 'aff_campaign_payout' => 1,
            'aff_network_id' => $network,
        ];
    }

    /** @return array<string, int> */
    private static function summary(
        int $created = 0,
        int $skipped = 0,
        int $error = 0,
        int $retryable = 0,
        int $unconfirmed = 0
    ): array {
        return [
            'created' => $created, 'updated' => 0, 'skipped' => $skipped, 'error' => $error,
            'retryable' => $retryable, 'unconfirmed' => $unconfirmed,
        ];
    }

    /** @return list<array{aff_network_name: string}> */
    private static function networkRows(string $prefix, int $count): array
    {
        return array_map(
            static fn (int $i): array => ['aff_network_name' => $prefix . $i],
            range(0, $count - 1)
        );
    }

    private static function connectFailing(
        int $beginFailsAt = 0,
        int $commitUnconfirmedAt = 0,
        int $rollbackFailsAt = 0
    ): FailingBoundaryMysqli {
        $db = new FailingBoundaryMysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $db->beginFailsAt = $beginFailsAt;
        $db->commitUnconfirmedAt = $commitUnconfirmedAt;
        $db->rollbackFailsAt = $rollbackFailsAt;

        return $db;
    }

    /**
     * The ids the answers or records carry.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private static function ids(array $rows, string $idColumn): array
    {
        return array_map(static fn (array $row): int => (int) ($row['data'] ?? $row)[$idColumn], $rows);
    }

    /**
     * @param list<int> $ids
     * @return list<array{string, int}>
     */
    private static function creates(array $ids): array
    {
        return array_map(static fn (int $id): array => ['create', $id], $ids);
    }

    /** @return array<string, int> */
    private static function networkNames(): array
    {
        return self::storedNames('202_aff_networks', 'aff_network_name');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function bulk(Controller $controller, array $rows, ?string $key = null): array
    {
        RequestContext::setHeaders(['Idempotency-Key' => $key ?? 'bulk-lock-it-' . bin2hex(random_bytes(6))]);
        try {
            return $controller->bulkUpsert(['rows' => $rows]);
        } finally {
            RequestContext::reset();
        }
    }

    /**
     * The change feed's records for $entity, as [operation, id].
     *
     * @return list<array{string, int}>
     */
    private function changes(string $entity, string $idColumn): array
    {
        $items = (new ServerStateStore($this->stateDir))->listChanges($entity, null, 5000, 60)['data'];

        return array_map(
            static fn (array $item): array => [(string) $item['operation'], (int) $item['record'][$idColumn]],
            $items
        );
    }

    /** @return array{resource, array<int, resource>} */
    private static function startLockCrosser(int $waitMs): array
    {
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr',
                __DIR__ . '/fixtures/lock-crossing-runner.php', (string) $waitMs,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc);
        $read = [$pipes[1]];
        $write = $except = null;
        self::assertSame(1, stream_select($read, $write, $except, 15), 'the other connection never took its lock');
        $line = (string) fgets($pipes[1]);
        if ($line !== "locked\n") {
            $stderr = (string) stream_get_contents($pipes[2]);
            self::fail("the other connection did not take its lock: $line$stderr");
        }

        return [$proc, $pipes];
    }

    /**
     * @param resource $proc
     * @param array<int, resource> $pipes
     */
    private static function finish($proc, array $pipes): string
    {
        $stdout = "locked\n" . (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc), $stdout . $stderr);

        return $stdout;
    }

    private static function attempts(): int
    {
        return (int) self::value('SELECT n FROM p202_bulk_attempts');
    }

    /** @return array<string, int> each name this user holds, and how many times */
    private static function storedNames(string $table, string $nameColumn): array
    {
        $result = self::db()->query(
            "SELECT $nameColumn, COUNT(*) FROM $table WHERE user_id = " . self::USER . " GROUP BY $nameColumn"
        );
        self::assertInstanceOf(\mysqli_result::class, $result);
        $names = [];
        foreach ($result->fetch_all(MYSQLI_NUM) as [$name, $count]) {
            $names[(string) $name] = (int) $count;
        }
        ksort($names, SORT_STRING);

        return $names;
    }

    /** @return array<int, string> */
    private static function storedIds(string $table, string $idColumn, string $nameColumn): array
    {
        $result = self::db()->query(
            "SELECT $idColumn, $nameColumn FROM $table WHERE user_id = " . self::USER . " ORDER BY $idColumn"
        );
        self::assertInstanceOf(\mysqli_result::class, $result);
        $ids = [];
        foreach ($result->fetch_all(MYSQLI_NUM) as [$id, $name]) {
            $ids[(int) $id] = (string) $name;
        }

        return $ids;
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

    private static function db(): \mysqli
    {
        self::assertNotNull(self::$db);

        return self::$db;
    }

    private static function q(string $sql): void
    {
        self::assertTrue(self::db()->query($sql), self::db()->error . ' in ' . $sql);
    }

    private static function value(string $sql): ?string
    {
        $result = self::db()->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_row();

        return $row === null || $row[0] === null ? null : (string) $row[0];
    }
}
