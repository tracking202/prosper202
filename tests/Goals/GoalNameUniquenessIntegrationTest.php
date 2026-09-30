<?php

declare(strict_types=1);

namespace Tests\Goals;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\GoalEngineException;
use Prosper202\Goals\GoalScope;
use Prosper202\Goals\MysqlGoalRepository;
use Prosper202\Goals\PlainGoals;

/**
 * "Goal names are unique per owner" holds under concurrency, not only for
 * one request at a time.
 *
 * nameTaken() is a plain read, so two transactions that both read before
 * either commits both see the name free. Each test here drives that exact
 * interleaving over two real connections: connection B opens its
 * transaction and reads first, so its REPEATABLE READ snapshot predates A's
 * commit and every read B makes afterwards still says "free". Only the
 * `live_name` UNIQUE key can refuse B's write, and the repository has to
 * report that refusal as a CONFLICT rather than a database error.
 *
 * @group integration
 */
final class GoalNameUniquenessIntegrationTest extends TestCase
{
    use GoalDatabase;

    private ?\mysqli $other = null;

    protected function tearDown(): void
    {
        if ($this->other !== null) {
            @$this->other->rollback();
            $this->other->close();
            $this->other = null;
        }
    }

    /** A second, independent session on the same database. */
    private function secondConnection(): Connection
    {
        mysqli_report(MYSQLI_REPORT_STRICT);
        $this->other = mysqli_connect(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        $this->other->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        $this->other->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        return new Connection($this->other);
    }

    private static function plain(string $name, string $event): GoalDefinition
    {
        return GoalDefinition::parse(['name' => $name, 'trigger' => ['event' => $event]]);
    }

    private static function liveNamed(string $name): int
    {
        $row = self::$db->query(
            "SELECT COUNT(*) FROM 202_goals WHERE user_id = 1 AND scope = 'campaign' AND scope_id = 5 AND archived_at IS NULL AND name = '"
            . self::$db->real_escape_string($name) . "'"
        )->fetch_row();

        return (int) $row[0];
    }

    public function testTwoCreatesThatBothSawTheNameFreeCannotBothCommit(): void
    {
        $this->campaign(5);
        $b = new MysqlGoalRepository($this->secondConnection());

        $this->other->begin_transaction();
        self::$db->begin_transaction();
        $this->assertFalse($b->nameTaken(1, GoalScope::CAMPAIGN, 5, 'Purchase', null), 'B reads first: its snapshot predates A');
        $this->assertFalse($this->goals->nameTaken(1, GoalScope::CAMPAIGN, 5, 'Purchase', null));
        $first = $this->goals->create(1, GoalScope::CAMPAIGN, 5, self::plain('Purchase', 'purchase'), $this->clock);
        self::$db->commit();
        $this->assertFalse(
            $b->nameTaken(1, GoalScope::CAMPAIGN, 5, 'Purchase', null),
            'the check-then-act window this test is about: B still reads the name as free after A committed it'
        );

        try {
            $b->create(1, GoalScope::CAMPAIGN, 5, self::plain('Purchase', 'purchase'), $this->clock);
            $this->other->commit();
            $this->fail('B created a second live goal named Purchase');
        } catch (GoalEngineException $e) {
            $this->assertSame(GoalEngineException::CONFLICT, $e->reason);
            $this->assertStringContainsString('A goal named "Purchase" already exists for this campaign', $e->getMessage());
            $this->other->rollback();
        }

        $this->assertSame(1, self::liveNamed('Purchase'));
        $this->assertSame($first, (int) self::$db->query("SELECT goal_id FROM 202_goals WHERE name = 'Purchase'")->fetch_row()[0]);
    }

    public function testTheNameIsComparedAsNameTakenComparesIt(): void
    {
        $this->campaign(5);
        $b = new MysqlGoalRepository($this->secondConnection());

        $this->other->begin_transaction();
        $this->assertFalse($b->nameTaken(1, GoalScope::CAMPAIGN, 5, 'purchase', null));
        $this->goals->create(1, GoalScope::CAMPAIGN, 5, self::plain('Purchase', 'purchase'), $this->clock);

        // nameTaken() compares under the table's case-insensitive collation,
        // so the key must too: otherwise the read refuses 'purchase' one
        // request at a time and the race admits it.
        $this->assertTrue($this->goals->nameTaken(1, GoalScope::CAMPAIGN, 5, 'purchase', null));
        try {
            $b->create(1, GoalScope::CAMPAIGN, 5, self::plain('purchase', 'purchase'), $this->clock);
            $this->other->commit();
            $this->fail('B created "purchase" beside a live "Purchase"');
        } catch (GoalEngineException $e) {
            $this->assertSame(GoalEngineException::CONFLICT, $e->reason);
            $this->other->rollback();
        }
    }

    public function testTwoRenamesOntoOneFreeNameCannotBothCommit(): void
    {
        $this->campaign(5);
        $one = $this->goals->create(1, GoalScope::CAMPAIGN, 5, self::plain('One', 'one'), $this->clock);
        $two = $this->goals->create(1, GoalScope::CAMPAIGN, 5, self::plain('Two', 'two'), $this->clock);
        $b = new MysqlGoalRepository($this->secondConnection());

        $this->other->begin_transaction();
        self::$db->begin_transaction();
        $this->assertFalse($b->nameTaken(1, GoalScope::CAMPAIGN, 5, 'Renamed', $two));
        $this->assertFalse($this->goals->nameTaken(1, GoalScope::CAMPAIGN, 5, 'Renamed', $one));
        $this->goals->addVersion(1, $one, self::plain('Renamed', 'one'), $this->clock);
        self::$db->commit();

        try {
            $b->addVersion(1, $two, self::plain('Renamed', 'two'), $this->clock);
            $this->other->commit();
            $this->fail('B renamed a second live goal to Renamed');
        } catch (GoalEngineException $e) {
            $this->assertSame(GoalEngineException::CONFLICT, $e->reason);
            $this->other->rollback();
        }
        $this->assertSame(1, self::liveNamed('Renamed'));
        $this->assertSame(1, self::liveNamed('Two'), 'the refused rename left goal two as it was');
    }

    public function testTwoConcurrentPlainGoalLookupsForOneEventMintOneGoal(): void
    {
        $this->campaign(5);
        $bConn = $this->secondConnection();
        $b = new PlainGoals($bConn);
        $a = new PlainGoals($this->conn);

        // B opens a snapshot before A's goal exists, as a double-submitted
        // form's second request does when both arrive together.
        $this->other->begin_transaction();
        $this->assertFalse((new MysqlGoalRepository($bConn))->nameTaken(1, GoalScope::CAMPAIGN, 5, 'purchase', null));
        $goalId = $a->forEvent(1, GoalScope::CAMPAIGN, 5, 'purchase', $this->clock);

        try {
            $b->forEvent(1, GoalScope::CAMPAIGN, 5, 'purchase', $this->clock);
            $this->other->commit();
            $this->fail('B minted a second plain goal for one event');
        } catch (GoalEngineException $e) {
            // Not a fall-through to "purchase (event)": that would be a
            // second plain goal for the same event under another name.
            $this->assertSame(GoalEngineException::CONFLICT, $e->reason);
            $this->other->rollback();
        }
        $this->assertSame(1, (int) self::$db->query('SELECT COUNT(*) FROM 202_goals')->fetch_row()[0]);

        // Retried, B finds A's goal: the double submit converges on one goal.
        $this->assertSame($goalId, $b->forEvent(1, GoalScope::CAMPAIGN, 5, 'purchase', $this->clock));
    }

    public function testAnOperatorGoalNamedInstallDoesNotBlockTheBuiltInInstallGoal(): void
    {
        self::fixture("INSERT INTO 202_app_registrations SET registration_id=7, user_id=1, platform='android', app_key='com.example.seven',
            app_name='Seven', app_token='" . str_repeat('7', 64) . "', created_at=1, updated_at=1");
        // An operator goal that already holds the name the built-in goal is
        // given. The built-in goal's identity is `builtin`: its insert must
        // meet only scope_builtin, never this goal's live name, or the
        // ON DUPLICATE KEY swallows it and every install of the
        // registration fails its read-back.
        $operator = $this->goals->create(1, GoalScope::REGISTRATION, 7, self::plain(
            (string) MysqlGoalRepository::BUILTIN_INSTALL_DEFINITION['name'],
            'opened'
        ), $this->clock);

        $builtin = $this->goals->ensureBuiltinInstallGoal(1, 7, $this->clock);

        $this->assertNotSame($operator, $builtin);
        $this->assertSame(
            MysqlGoalRepository::BUILTIN_INSTALL,
            self::$db->query('SELECT builtin FROM 202_goals WHERE goal_id = ' . $builtin)->fetch_row()[0]
        );
        $this->assertSame($builtin, $this->goals->ensureBuiltinInstallGoal(1, 7, $this->clock), 'and it stays the one built-in goal');
    }

    public function testAnArchivedGoalFreesItsName(): void
    {
        $this->campaign(5);
        $old = $this->goals->create(1, GoalScope::CAMPAIGN, 5, self::plain('Purchase', 'purchase'), $this->clock);
        $this->goals->archive(1, $old, $this->clock);
        $new = $this->goals->create(1, GoalScope::CAMPAIGN, 5, self::plain('Purchase', 'purchase'), $this->clock);
        $this->goals->archive(1, $new, $this->clock);
        $third = $this->goals->create(1, GoalScope::CAMPAIGN, 5, self::plain('Purchase', 'purchase'), $this->clock);

        $this->assertNotSame($old, $new);
        $this->assertSame(1, self::liveNamed('Purchase'));
        $this->assertSame(3, (int) self::$db->query("SELECT COUNT(*) FROM 202_goals WHERE name = 'Purchase'")->fetch_row()[0], 'two archived and one live');

        // Another owner's goal of the same name is not a clash.
        $this->campaign(6);
        $this->goals->create(1, GoalScope::CAMPAIGN, 6, self::plain('Purchase', 'purchase'), $this->clock);
        $this->assertGreaterThan($third, (int) self::$db->query('SELECT MAX(goal_id) FROM 202_goals')->fetch_row()[0]);
    }
}
