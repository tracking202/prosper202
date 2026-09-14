<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionIntegrationService;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use Prosper202\Database\Exceptions\QueryException;
use mysqli;

/**
 * AttributionIntegrationService ran prepare/bind/execute by hand and checked
 * none of the three. The consequence was not a crash but a wrong answer:
 * getCampaignsUsingModel() turned a failed statement into an empty array, and
 * safeDeleteModel() reads an empty array as permission to delete the model —
 * so a database fault could delete a model out from under the campaigns still
 * pointing at it. That is CLAUDE.md error pattern #1 in its worst form: false
 * indistinguishable from a legitimate empty answer.
 *
 * The seam being fixed IS the database wiring, so a fake statement would prove
 * nothing about it (error pattern #9): a stub that returns false on demand
 * tests the stub. These run the real service, against a real MySQL, and make
 * the statement fail the way MySQL actually fails it.
 *
 * There are two distinct ways for it to fail, and the difference matters
 * because the original defect was on the SECOND one:
 *
 *   - Dropping the table fails the PREPARE. MySQL resolves table names at
 *     prepare time, so nothing downstream of it ever runs. This is the cheap
 *     case and it is what breakTheCampaignsTable() does — worth having, but on
 *     its own it never exercises the code the bug lived in.
 *   - A view over a function that SIGNALs fails the EXECUTE, with the prepare
 *     succeeding. That is the real shape: execute returns false, get_result()
 *     is then false too, and the unchecked code read that as "no campaigns use
 *     this model". breakTheCampaignsTableAtExecuteTime() reproduces it.
 *
 * @group integration
 */
final class AttributionIntegrationServiceTest extends TestCase
{
    private ?mysqli $db = null;
    private ?int $reportModeToRestore = null;

    protected function setUp(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            $this->markTestSkipped('Set P202_TEST_DB_HOST to run the attribution integration tests.');
        }

        // No default database name. These tests DROP 202_aff_campaigns and
        // 202_attribution_models and do not put them back — that is the point,
        // it is how a statement is made to fail for real — so defaulting to
        // 'prosper202' would mean anyone who exports P202_TEST_DB_HOST to run
        // the schema suite destroys the campaign and attribution-model tables
        // of whatever install that host is serving. The database has to be
        // named deliberately, and it has to be a scratch one.
        $name = getenv('P202_TEST_DB_NAME');
        if ($name === false || $name === '') {
            $this->markTestSkipped(
                'Set P202_TEST_DB_NAME to a SCRATCH database: these tests drop 202_aff_campaigns '
                . 'and 202_attribution_models and leave them dropped.'
            );
        }

        // Restored in tearDown: the app runs under STRICT-only (connect.php),
        // which is what makes execute() return false instead of throwing, but
        // leaving the mode changed leaks into every later test in the process.
        $this->reportModeToRestore = MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT;
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $this->db = new mysqli(
                (string) $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) $name,
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('Cannot connect to the test database: ' . $e->getMessage());
        }

        $this->db->query('DROP TABLE IF EXISTS `202_aff_campaigns`');
        $this->db->query(
            'CREATE TABLE `202_aff_campaigns` (
                `aff_campaign_id` int unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint unsigned NOT NULL,
                `aff_campaign_name` varchar(255) NOT NULL DEFAULT \'\',
                `attribution_model_id` int unsigned DEFAULT NULL,
                PRIMARY KEY (`aff_campaign_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    protected function tearDown(): void
    {
        if ($this->db instanceof mysqli) {
            // The execute-time break replaces the table with a view over a
            // renamed base and a stored function; all three have to go, or the
            // next test's setUp cannot create its table.
            $this->db->query('DROP VIEW IF EXISTS `202_aff_campaigns`');
            $this->db->query('DROP FUNCTION IF EXISTS `p202_test_signal`');
            $this->db->query('DROP TABLE IF EXISTS `202_aff_campaigns_base`');
            $this->db->query('DROP TABLE IF EXISTS `202_aff_campaigns`');
            $this->db->close();
            $this->db = null;
        }
        if ($this->reportModeToRestore !== null) {
            mysqli_report($this->reportModeToRestore);
            $this->reportModeToRestore = null;
        }
    }

    /**
     * Never assert() for anything with an effect: this sandbox runs
     * zend.assertions=-1, where the expression inside assert() is not
     * evaluated at all. The first draft of this file seeded its rows with
     * assert($stmt->execute()) and three tests failed against correct code
     * because no row was ever inserted.
     */
    private function db(): mysqli
    {
        if (!$this->db instanceof mysqli) {
            $this->fail('no database connection');
        }

        return $this->db;
    }

    private function service(?ModelRepositoryInterface $repo = null): AttributionIntegrationService
    {
        return new AttributionIntegrationService($repo ?? $this->stubRepository(), $this->db());
    }

    private function seedCampaign(int $userId, string $name, ?int $modelId): int
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO `202_aff_campaigns` (user_id, aff_campaign_name, attribution_model_id) VALUES (?, ?, ?)'
        );
        $this->assertNotFalse($stmt, 'could not prepare the seed insert');
        $stmt->bind_param('isi', $userId, $name, $modelId);
        $this->assertTrue($stmt->execute(), 'could not seed a campaign row');
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Breaks every read against the campaigns table at PREPARE time — MySQL
     * resolves table names before the statement is ever executed.
     */
    private function breakTheCampaignsTable(): void
    {
        $this->assertTrue($this->db()->query('DROP TABLE `202_aff_campaigns`'), 'could not drop the table');
    }

    /**
     * Breaks it at EXECUTE time instead, which is the leg the defect lived on:
     * prepare succeeds, execute returns false, and get_result() is false after
     * it. Swaps the table for a view of the same shape whose WHERE clause calls
     * a function that SIGNALs — the name and columns resolve, so the prepare is
     * clean, and the error only happens when a row is actually read.
     */
    private function breakTheCampaignsTableAtExecuteTime(): void
    {
        $db = $this->db();
        $this->assertTrue(
            $db->query('RENAME TABLE `202_aff_campaigns` TO `202_aff_campaigns_base`'),
            'could not rename the table'
        );
        $db->query('DROP FUNCTION IF EXISTS `p202_test_signal`');
        $this->assertTrue(
            $db->query(
                "CREATE FUNCTION `p202_test_signal`() RETURNS INT DETERMINISTIC
                 BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'result set unavailable'; RETURN 1; END"
            ),
            'could not create the signalling function'
        );
        $this->assertTrue(
            $db->query(
                'CREATE VIEW `202_aff_campaigns` AS
                 SELECT aff_campaign_id, user_id, aff_campaign_name, attribution_model_id
                 FROM `202_aff_campaigns_base` WHERE `p202_test_signal`() = 1'
            ),
            'could not create the signalling view'
        );
    }

    // ── the answer that mattered ─────────────────────────────────────

    public function testCampaignsUsingModelReturnsTheRows(): void
    {
        $this->seedCampaign(7, 'Alpha', 42);
        $this->seedCampaign(7, 'Beta', 42);
        $this->seedCampaign(7, 'Gamma', 99);
        $this->seedCampaign(8, 'OtherUser', 42);

        $campaigns = $this->service()->getCampaignsUsingModel(42, 7);

        $this->assertSame(['Alpha', 'Beta'], array_column($campaigns, 'name'));
    }

    public function testCampaignsUsingModelIsEmptyWhenGenuinelyUnused(): void
    {
        $this->seedCampaign(7, 'Alpha', 42);

        $this->assertSame([], $this->service()->getCampaignsUsingModel(1234, 7));
    }

    /**
     * The whole point. Before, this returned [] — the same value as "no
     * campaigns use this model".
     */
    public function testCampaignsUsingModelThrowsWhenTheQueryFails(): void
    {
        $this->breakTheCampaignsTable();

        $this->expectException(QueryException::class);
        $this->service()->getCampaignsUsingModel(42, 7);
    }

    /**
     * The same promise on the leg the defect actually lived on. Dropping the
     * table only ever failed the prepare; here the prepare succeeds and the
     * EXECUTE fails, which is when get_result() returns false and the old code
     * answered [].
     */
    public function testCampaignsUsingModelThrowsWhenTheExecuteFails(): void
    {
        $this->seedCampaign(7, 'Alpha', 42);
        $this->breakTheCampaignsTableAtExecuteTime();

        $this->expectException(QueryException::class);
        $this->service()->getCampaignsUsingModel(42, 7);
    }

    /**
     * And the consequence: an in-use model must survive a failure on that leg.
     * This is the case that reported success:true and deleted the model.
     */
    public function testSafeDeleteRefusesWhenTheInUseCheckFailsAtExecuteTime(): void
    {
        $this->seedCampaign(7, 'Alpha', 42);
        $repo = $this->stubRepository(model: $this->model(42, 7));
        $this->breakTheCampaignsTableAtExecuteTime();

        $result = $this->service($repo)->safeDeleteModel(42, 7);

        $this->assertFalse($result['success']);
        $this->assertSame(
            0,
            $repo->deleteCalls,
            'the model was deleted despite the in-use check failing at execute time'
        );
        $this->assertStringContainsString('Could not check', (string) $result['error']);
    }

    // ── and what that answer is used for ─────────────────────────────

    public function testSafeDeleteRefusesWhenCampaignsUseTheModel(): void
    {
        $this->seedCampaign(7, 'Alpha', 42);
        $repo = $this->stubRepository(model: $this->model(42, 7));

        $result = $this->service($repo)->safeDeleteModel(42, 7);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Alpha', (string) $result['error']);
        $this->assertSame(0, $repo->deleteCalls);
    }

    public function testSafeDeleteProceedsWhenNothingUsesTheModel(): void
    {
        $repo = $this->stubRepository(model: $this->model(42, 7));

        $result = $this->service($repo)->safeDeleteModel(42, 7);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $repo->deleteCalls);
    }

    /**
     * Fail closed. An unreadable answer must not be read as "nothing uses
     * it": the delete has to be refused, and refused visibly.
     */
    public function testSafeDeleteRefusesWhenItCannotTellWhetherTheModelIsInUse(): void
    {
        $repo = $this->stubRepository(model: $this->model(42, 7));
        $this->breakTheCampaignsTable();

        $result = $this->service($repo)->safeDeleteModel(42, 7);

        $this->assertFalse($result['success']);
        $this->assertSame(
            0,
            $repo->deleteCalls,
            'the model was deleted despite the in-use check never having answered'
        );
        $this->assertStringContainsString('Could not check', (string) $result['error']);
    }

    // ── the other two statements ─────────────────────────────────────

    public function testUpdateCampaignAttributionModelWritesTheModel(): void
    {
        $id = $this->seedCampaign(7, 'Alpha', null);
        $repo = $this->stubRepository(model: $this->model(42, 7));

        $this->assertTrue($this->service($repo)->updateCampaignAttributionModel($id, 42, 7));

        $result = $this->db()->query(
            'SELECT attribution_model_id FROM `202_aff_campaigns` WHERE aff_campaign_id = ' . $id
        );
        $this->assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        $this->assertSame(42, (int) ($row['attribution_model_id'] ?? 0));
    }

    public function testUpdateCampaignAttributionModelRefusesACampaignTheUserDoesNotOwn(): void
    {
        $id = $this->seedCampaign(8, 'NotYours', null);
        $repo = $this->stubRepository(model: $this->model(42, 7));

        $this->assertFalse($this->service($repo)->updateCampaignAttributionModel($id, 42, 7));
    }

    public function testUpdateCampaignAttributionModelThrowsWhenTheQueryFails(): void
    {
        $this->breakTheCampaignsTable();

        $this->expectException(QueryException::class);
        $this->service()->updateCampaignAttributionModel(1, 42, 7);
    }

    public function testModelStatsThrowsWhenTheQueryFails(): void
    {
        $this->db()->query('DROP TABLE IF EXISTS `202_attribution_models`');

        $this->expectException(QueryException::class);
        $this->service()->getAttributionModelStats(7);
    }

    // ── stubs for the repository collaborator only ───────────────────
    //
    // The repository is a genuine collaborator behind an interface, not the
    // seam under test; the database wiring above is the seam, and it is real.

    private function model(int $modelId, int $userId): ModelDefinition
    {
        return new ModelDefinition(
            modelId: $modelId,
            userId: $userId,
            name: 'Model ' . $modelId,
            slug: 'model-' . $modelId,
            type: ModelType::LAST_TOUCH,
            weightingConfig: [],
            isActive: true,
            isDefault: false,
            createdAt: 0,
            updatedAt: 0
        );
    }

    private function stubRepository(?ModelDefinition $model = null): ModelRepositoryInterface
    {
        return new class ($model) implements ModelRepositoryInterface {
            public int $deleteCalls = 0;

            public function __construct(private readonly ?ModelDefinition $model)
            {
            }

            public function findById(int $modelId): ?ModelDefinition
            {
                return $this->model;
            }

            public function findDefaultForUser(int $userId): ?ModelDefinition
            {
                return null;
            }

            public function findForUser(int $userId, ?ModelType $type = null, bool $onlyActive = true): array
            {
                return $this->model !== null ? [$this->model] : [];
            }

            public function findBySlug(int $userId, string $slug): ?ModelDefinition
            {
                return $this->model;
            }

            public function save(ModelDefinition $model): ModelDefinition
            {
                return $model;
            }

            public function promoteToDefault(ModelDefinition $model): void
            {
            }

            public function setAsDefault(int $userId, int $modelId): bool
            {
                return true;
            }

            public function delete(int $modelId, int $userId): void
            {
                $this->deleteCalls++;
            }
        };
    }
}
