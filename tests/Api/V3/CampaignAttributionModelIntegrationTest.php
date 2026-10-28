<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\CampaignsController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * A campaign's attribution model, set through the API as Setup > Campaigns
 * sets it: one of the caller's own models, or nothing for the account's
 * default. The API had no field for it, so the override could be made only
 * on the page.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 5401 and 5402's rows.
 *
 * @group integration
 */
final class CampaignAttributionModelIntegrationTest extends TestCase
{
    private const USER = 5401;
    private const OTHER = 5402;

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            self::$db->query("DELETE FROM 202_aff_campaigns WHERE user_id = $u");
            self::$db->query("DELETE FROM 202_attribution_models WHERE user_id = $u");
            self::$db->query("DELETE FROM 202_aff_networks WHERE user_id = $u");
        }
    }

    /** The category the campaigns go in: one of the caller's own. */
    private int $network = 0;

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::cleanUp();
        self::assertTrue(self::$db->query('INSERT INTO 202_aff_networks SET user_id = ' . self::USER . ", aff_network_name = 'models', aff_network_time = 0"), (string) self::$db->error);
        $this->network = (int) self::$db->insert_id;
    }

    private static function model(int $user, string $slug): int
    {
        self::$db->query("INSERT INTO 202_attribution_models SET user_id = $user, model_name = '$slug', model_slug = '$slug',"
            . " model_type = 'linear', weighting_config = '{}', created_at = 0, updated_at = 0");

        return (int) self::$db->insert_id;
    }

    private function campaigns(): CampaignsController
    {
        return new CampaignsController(self::$db, self::USER);
    }

    private function stored(int $campaign): ?string
    {
        $row = self::$db->query("SELECT attribution_model_id FROM 202_aff_campaigns WHERE aff_campaign_id = $campaign")->fetch_row();

        return $row[0];
    }

    /** @param array<string, mixed> $extra */
    private function create(array $extra = []): int
    {
        return (int) $this->campaigns()->create([
            'aff_campaign_name' => 'm', 'aff_campaign_url' => 'https://o.example', 'aff_campaign_payout' => 1, 'aff_network_id' => $this->network,
        ] + $extra)['data']['aff_campaign_id'];
    }

    public function testACampaignNamesOneOfTheCallersModelsOrTheDefault(): void
    {
        $linear = self::model(self::USER, 'linear');
        $first = self::model(self::USER, 'first');

        $id = $this->create(['attribution_model_id' => $linear]);
        self::assertSame((string) $linear, $this->stored($id));
        self::assertSame($linear, (int) $this->campaigns()->get($id)['data']['attribution_model_id'], 'and reading it back says so');

        $this->campaigns()->update($id, ['attribution_model_id' => (string) $first]);
        self::assertSame((string) $first, $this->stored($id), 'the model alone');

        $this->campaigns()->update($id, ['attribution_model_id' => $linear, 'aff_campaign_name' => 'renamed']);
        self::assertSame((string) $linear, $this->stored($id), 'with another field, in one write');

        $this->campaigns()->update($id, ['attribution_model_id' => 0]);
        self::assertNull($this->stored($id), '0 returns it to the account default');

        self::assertNull($this->stored($this->create()), 'a campaign that names none uses the default');
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function refused(): iterable
    {
        yield 'another account\'s model' => ['other'];
        yield 'a model that does not exist' => [999999];
        // Each of these casts to the caller's own model id, so only a check
        // of the value as sent refuses it (CLAUDE.md #18).
        yield 'a fraction of an own id' => ['{mine}.5'];
        yield 'an own id in scientific notation' => ['{mine}e0'];
        yield 'an own id with a leading zero' => ['0{mine}'];
        yield 'negative' => [-1];
        yield 'a list' => [[1]];
    }

    /** @dataProvider refused */
    public function testAnythingElseIsRefusedAndWritesNothing(mixed $value): void
    {
        $value = $value === 'other' ? self::model(self::OTHER, 'theirs') : $value;
        $mine = self::model(self::USER, 'mine');
        $value = is_string($value) ? str_replace('{mine}', (string) $mine, $value) : $value;
        $id = $this->create();
        foreach ([
            'create' => fn () => $this->create(['attribution_model_id' => $value]),
            'update' => fn () => $this->campaigns()->update($id, ['attribution_model_id' => $value]),
            'update with another field' => fn () => $this->campaigns()->update($id, ['attribution_model_id' => $value, 'aff_campaign_name' => 'x']),
        ] as $write => $call) {
            try {
                $call();
                self::fail("$write accepted " . json_encode($value));
            } catch (ValidationException $e) {
                self::assertArrayHasKey('attribution_model_id', $e->getFieldErrors(), $write);
            }
        }
        self::assertSame('1', (string) self::$db->query('SELECT COUNT(*) FROM 202_aff_campaigns WHERE user_id = ' . self::USER)->fetch_row()[0], 'no campaign was created');
        self::assertNull($this->stored($id));
        self::assertSame('m', (string) self::$db->query("SELECT aff_campaign_name FROM 202_aff_campaigns WHERE aff_campaign_id = $id")->fetch_row()[0], 'nothing else in the request was written');
    }
}
