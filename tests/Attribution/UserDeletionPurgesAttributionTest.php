<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionWorker;
use Prosper202\Attribution\DefaultModel;
use Prosper202\Attribution\ExportFiles;
use Prosper202\Database\Tables\AttributionTables;
use Prosper202\User\UserDataPurge;
use Tests\Attribution\Support\AttributionDatabase;

/**
 * Deleting a user removes the MTA engine's state for them (plan §7.2): every
 * AttributionTables table, the outbox rows of their conversions, and their
 * export files on disk — in UserDataPurge's one transaction, with the files
 * removed only once it commits — and nothing of another account's. After the
 * delete, the worker does not rebuild what the purge removed.
 *
 * The owner's MTA state is built by the real ledger and worker, so the rows
 * purged are the rows the engine writes, not hand-made lookalikes.
 *
 * @group integration
 */
final class UserDeletionPurgesAttributionTest extends TestCase
{
    use AttributionDatabase;

    private const OTHER = 2;

    private string $exportDir = '';

    protected function tearDown(): void
    {
        if ($this->exportDir !== '' && is_dir($this->exportDir)) {
            foreach (glob($this->exportDir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            rmdir($this->exportDir);
        }
    }

    /**
     * Rows of each MTA table that belong to a user. Credits and journeys have
     * no user column; they belong to the user whose conversion they describe.
     *
     * @return array<string, string> table => COUNT(*) query with one %d for the user id
     */
    private static function ownership(): array
    {
        return [
            '202_attribution_models' => 'SELECT COUNT(*) FROM 202_attribution_models WHERE user_id = %d',
            '202_attribution_journeys' => 'SELECT COUNT(*) FROM 202_attribution_journeys j JOIN 202_conversion_logs c ON c.conv_id = j.conv_id WHERE c.user_id = %d',
            '202_attribution_journey_meta' => 'SELECT COUNT(*) FROM 202_attribution_journey_meta WHERE user_id = %d',
            '202_attribution_credits' => 'SELECT COUNT(*) FROM 202_attribution_credits cr JOIN 202_conversion_logs c ON c.conv_id = cr.conv_id WHERE c.user_id = %d',
            '202_attribution_audit' => 'SELECT COUNT(*) FROM 202_attribution_audit WHERE user_id = %d',
            '202_attribution_exports' => 'SELECT COUNT(*) FROM 202_attribution_exports WHERE user_id = %d',
            '202_attribution_pending' => 'SELECT COUNT(*) FROM 202_attribution_pending p JOIN 202_conversion_logs c ON c.conv_id = p.conv_id WHERE c.user_id = %d',
            '202_attribution_rollup' => 'SELECT COUNT(*) FROM 202_attribution_rollup WHERE user_id = %d',
            '202_attribution_rollup_state' => 'SELECT COUNT(*) FROM 202_attribution_rollup_state WHERE user_id = %d',
            '202_attribution_rollup_overrides' => 'SELECT COUNT(*) FROM 202_attribution_rollup_overrides WHERE user_id = %d',
            '202_attribution_rollup_dirty' => 'SELECT COUNT(*) FROM 202_attribution_rollup_dirty WHERE user_id = %d',
            '202_attribution_rollup_dirty_clicks' => 'SELECT COUNT(*) FROM 202_attribution_rollup_dirty_clicks WHERE user_id = %d',
        ];
    }

    /** @return array<string, int> table => rows of that user */
    private static function counts(int $userId): array
    {
        $out = [];
        foreach (self::ownership() as $table => $sql) {
            $out[$table] = (int) self::scalar(sprintf($sql, $userId));
        }

        return $out;
    }

    private function files(): ExportFiles
    {
        if ($this->exportDir === '') {
            $this->exportDir = sys_get_temp_dir() . '/p202-purge-exports-' . bin2hex(random_bytes(6));
        }

        return new ExportFiles($this->exportDir);
    }

    private function export(int $userId, int $modelId, int $exportId): string
    {
        $name = $this->files()->write($userId, $exportId, "group,conversions\nx,1\n");
        self::fixture("INSERT INTO 202_attribution_exports SET export_id=$exportId, user_id=$userId, model_id=$modelId, group_by='campaign',
            range_start=1, range_end=2, status='completed', file_path='$name', rows_exported=1, queued_at=1, created_at=1, updated_at=1");

        return $name;
    }

    /**
     * The owner (user 1): a two-touch journey built and credited by the
     * worker, a second conversion still in the outbox, an export with its
     * file, and an audit row. Another account (user 2): one of each, made
     * directly, which the purge must leave alone.
     *
     * @return array{owner: string, other: string} export file names
     */
    private function seed(): array
    {
        $this->campaign(10);
        $this->click(101, 10, 1_000);
        $this->click(102, 10, 2_000);
        $this->visit(101, 1_000, self::cookie('a'));
        $this->visit(102, 2_000, self::cookie('a'));
        $this->convert(102, '10.00', 'T-1', 3_000);
        $this->work();
        $this->convert(101, '4.00', 'T-2', 3_500); // left in the outbox
        // The worker's run summed the report rollup (its rows and state);
        // the marks and overrides it keeps are made directly.
        self::fixture('INSERT INTO 202_attribution_rollup_overrides SET user_id=1, campaign_id=10, model_id=' . $this->defaultModelId());
        self::fixture('INSERT INTO 202_attribution_rollup_dirty SET user_id=1, hour_from=0, hour_to=0');
        self::fixture('INSERT INTO 202_attribution_rollup_dirty_clicks SET user_id=1, click_id=101');
        self::fixture("INSERT INTO 202_attribution_audit SET user_id=1, model_id=NULL, action='test', metadata='{}', created_at=1");
        $ownerFile = $this->export(1, $this->defaultModelId(), 1);

        self::fixture("INSERT INTO 202_users SET user_id=" . self::OTHER . ", user_name='other', user_email='x@example.test', user_time_register=1, user_deleted=0, user_active=1");
        $otherModel = DefaultModel::ensureFor($this->conn, self::OTHER);
        self::fixture("INSERT INTO 202_conversion_logs SET conv_id=9001, click_id=9001, campaign_id=99, click_payout=5, user_id=" . self::OTHER . ",
            click_time=1, conv_time=2, time_difference='', pixel_type=0, user_agent='', dedupe_key='tx:O-1'");
        self::fixture("INSERT INTO 202_attribution_pending SET conv_id=9001, enqueued_at=1, reason='recorded'");
        self::fixture("INSERT INTO 202_attribution_journeys SET conv_id=9001, position=0, click_id=9001, click_time=1");
        self::fixture("INSERT INTO 202_attribution_journey_meta SET conv_id=9001, user_id=" . self::OTHER . ", conv_time=2, touches=1, built_lookback_days=30, built_at=2");
        self::fixture("INSERT INTO 202_attribution_credits SET conv_id=9001, model_id=$otherModel, click_id=9001, position=0, credit=1, revenue=5, conv_time=2");
        self::fixture("INSERT INTO 202_attribution_audit SET user_id=" . self::OTHER . ", model_id=NULL, action='test', metadata='{}', created_at=1");
        self::fixture('INSERT INTO 202_attribution_rollup SET user_id=' . self::OTHER . ", part=4, dim=0, model_id=$otherModel, grain=0, bucket=0, key_null=0, dim_key=0, n=1, credit=1, revenue=5, cost=0");
        self::fixture('INSERT INTO 202_attribution_rollup_state SET user_id=' . self::OTHER . ", built_through_hour=1, default_model_id=$otherModel, updated_at=1");
        self::fixture('INSERT INTO 202_attribution_rollup_overrides SET user_id=' . self::OTHER . ", campaign_id=99, model_id=$otherModel");
        self::fixture('INSERT INTO 202_attribution_rollup_dirty SET user_id=' . self::OTHER . ', hour_from=0, hour_to=0');
        self::fixture('INSERT INTO 202_attribution_rollup_dirty_clicks SET user_id=' . self::OTHER . ', click_id=9001');
        $otherFile = $this->export(self::OTHER, $otherModel, 2);

        return ['owner' => $ownerFile, 'other' => $otherFile];
    }

    public function testTheCascadeNamesEveryAttributionTable(): void
    {
        $tables = array_map(static fn ($d): string => $d->tableName, AttributionTables::getDefinitions());
        $tables[] = '202_attribution_pending';
        sort($tables);
        $checked = array_keys(self::ownership());
        sort($checked);
        $this->assertSame($tables, $checked, 'this test checks every MTA table; a new one needs a line in ownership() and a purge statement');
    }

    public function testDeletingAUserPurgesEveryAttributionTableAndTheirExportFiles(): void
    {
        $files = $this->seed();
        $owner = self::counts(1);
        $other = self::counts(self::OTHER);
        foreach ($owner as $table => $n) {
            $this->assertGreaterThan(0, $n, "$table: the owner has rows before the delete, or the check below proves nothing");
            $this->assertGreaterThan(0, $other[$table], "$table: so does the other account");
        }
        $this->assertFileExists($this->exportDir . '/' . $files['owner']);
        $ledgerRows = (int) self::scalar('SELECT COUNT(*) FROM 202_conversion_logs WHERE user_id = 1');

        (new UserDataPurge(self::$db, $this->files()))->deleteUser(1);

        foreach (self::counts(1) as $table => $n) {
            $this->assertSame(0, $n, "$table: the deleted user's rows are gone");
        }
        $this->assertSame($other, self::counts(self::OTHER), 'another account keeps every row');
        $this->assertFileDoesNotExist($this->exportDir . '/' . $files['owner'], 'the deleted user\'s export file is removed');
        $this->assertFileExists($this->exportDir . '/' . $files['other'], 'another account\'s export file stays');
        $this->assertSame($ledgerRows, (int) self::scalar('SELECT COUNT(*) FROM 202_conversion_logs WHERE user_id = 1'), 'the ledger rows are kept, as the clicks are');
        $this->assertSame('1', (string) self::scalar('SELECT user_deleted FROM 202_users WHERE user_id = 1'));
    }

    public function testTheWorkerDoesNotRebuildADeletedUsersAttribution(): void
    {
        $this->seed();
        (new UserDataPurge(self::$db, $this->files()))->deleteUser(1);

        // A conversion on the deleted user's click, after the delete: the
        // ledger records it and queues it, as for any click (and re-queues
        // the click's earlier conversion, which the new one supersedes).
        $this->convert(101, '2.00', 'T-late', 4_000);
        $queued = self::counts(1)['202_attribution_pending'];
        $this->assertGreaterThan(0, $queued);

        $report = (new AttributionWorker($this->conn))->run(30, 200);

        $this->assertSame($queued, $report->outcomes['user_deleted'] ?? 0, 'the worker refused every one: ' . $report->summary());
        $this->assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_models WHERE user_id = 1'), 'no default model is created for a deleted account');
        foreach (self::counts(1) as $table => $n) {
            $this->assertSame(0, $n, "$table: nothing rebuilt for the deleted user");
        }
        $this->assertSame(0, (int) self::scalar(DefaultModel::MISSING_SQL), 'a deleted account without a default is not a missing default');
    }

    /** A second connection to the test database, as a second process would have. */
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

    /**
     * A purge in progress holds the user row (UserDataPurge::LOCK_USER, its
     * first statement): the worker's read of user_deleted waits for it
     * rather than reading the row as it was before the purge and writing a
     * journey the purge will never come back for. Once the purge commits,
     * the worker reads the user as deleted and writes nothing.
     */
    public function testTheWorkerWaitsForAPurgeInProgressAndThenReadsTheUserDeleted(): void
    {
        $this->seed();
        $purge = self::connect();
        $purge->begin_transaction();
        $lock = $purge->prepare(UserDataPurge::LOCK_USER);
        $userId = 1;
        $lock->bind_param('i', $userId);
        $this->assertTrue($lock->execute());
        $lock->close();

        self::$db->query('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            (new AttributionWorker($this->conn))->run(30, 200);
            $this->fail('the worker read the user without waiting for the purge that holds it');
        } catch (\Prosper202\Attribution\WorkerHalted $e) {
            $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
        } finally {
            self::$db->query('SET SESSION innodb_lock_wait_timeout = 50');
        }

        // The purge finishes as deleteUser() would.
        $purge->query('UPDATE 202_users SET user_deleted = 1 WHERE user_id = 1');
        $purge->commit();
        $purge->close();

        $queued = self::counts(1)['202_attribution_pending'];
        $this->assertGreaterThan(0, $queued);
        $report = (new AttributionWorker($this->conn))->run(30, 200);
        $this->assertSame($queued, $report->outcomes['user_deleted'] ?? 0, 'it then reads the user deleted: ' . $report->summary());
    }

    /**
     * The other order: a worker is writing a conversion's journey (holding
     * the user row shared) when the delete starts. The purge's first
     * statement waits for the worker's commit, so its DELETEs then see and
     * remove what the worker wrote. Locked last instead, the purge ran its
     * DELETEs first and the worker's rows outlived the user.
     */
    public function testAPurgeThatStartsWhileTheWorkerWritesDeletesWhatTheWorkerWrote(): void
    {
        $this->seed();
        $this->files();
        $pending = (int) self::scalar('SELECT p.conv_id FROM 202_attribution_pending p JOIN 202_conversion_logs c ON c.conv_id = p.conv_id WHERE c.user_id = 1 ORDER BY p.conv_id LIMIT 1');
        $this->assertGreaterThan(0, $pending, 'a conversion of the user is waiting for the worker');

        $worker = self::connect();
        $workerConn = new \Prosper202\Database\Connection($worker);
        $worker->begin_transaction();
        $held = $worker->prepare(AttributionWorker::USER_LOCK_SQL);
        $userId = 1;
        $held->bind_param('i', $userId);
        $this->assertTrue($held->execute());
        $held->get_result();
        $held->close();

        $child = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/delete-user.php', '1', $this->exportDir],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($child);
        // Long enough for the purge to reach its first statement and, locked
        // last, to run every DELETE before it blocked.
        usleep(1_500_000);

        // The worker writes the journey and credits and commits.
        $this->assertSame('credited', (new AttributionWorker($workerConn))->processConversion($pending, 'recorded'));
        $this->assertGreaterThan(0, (int) $worker->query("SELECT COUNT(*) FROM 202_attribution_journey_meta WHERE conv_id = $pending")->fetch_row()[0]);
        $worker->commit();
        $worker->close();

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($child), 'the delete: ' . $out . $err);
        $this->assertSame("deleted\n", $out);

        foreach (self::counts(1) as $table => $n) {
            $this->assertSame(0, $n, "$table: what the worker wrote while the purge waited is gone with the user");
        }
    }

    public function testExportFilesStayWhenTheDeleteRollsBack(): void
    {
        $files = $this->seed();
        $before = self::counts(1);

        // A statement after the MTA ones fails: its table is missing.
        self::$db->query('RENAME TABLE 202_goals TO 202_goals_away');
        try {
            (new UserDataPurge(self::$db, $this->files()))->deleteUser(1);
            $this->fail('a purge with a failing statement reported success');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('was not deleted', $e->getMessage());
        } finally {
            self::$db->query('RENAME TABLE 202_goals_away TO 202_goals');
        }

        $this->assertSame($before, self::counts(1), 'the MTA deletes that ran were rolled back');
        $this->assertFileExists($this->exportDir . '/' . $files['owner'], 'the file of an export row that still exists is not removed');
        $this->assertSame('0', (string) self::scalar('SELECT user_deleted FROM 202_users WHERE user_id = 1'));
    }
}
