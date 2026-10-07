<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\CampaignsController;
use Api\V3\Controllers\LandingPagesController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * Public ids and list filters, against a real database.
 *
 * A landing page made through the API had no public id, so no landing-page
 * code could track it (static/landing.php and go.php resolve lpip=…); a
 * campaign's was a random 8-digit number, which go.php resolves across every
 * account. Both now get the setup pages' rand-id-rand, and an id-less page
 * made before is given one the next time the API reads it. A list filter the
 * controller cannot apply is a 422 rather than an unfiltered answer.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes user 5101's rows.
 *
 * @group integration
 */
final class PublicIdsAndListFiltersIntegrationTest extends TestCase
{
    private const USER = 5101;

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
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        foreach (['202_landing_pages', '202_aff_campaigns'] as $table) {
            self::$db->query("DELETE FROM $table WHERE user_id = " . self::USER);
        }
    }

    private static function assertRandIdRand(int $id, mixed $publicId): void
    {
        self::assertIsInt($publicId);
        self::assertMatchesRegularExpression('/^[1-9]' . $id . '[1-9]$/', (string) $publicId, 'rand-id-rand, as the setup pages make it');
    }

    private function campaign(): int
    {
        $row = (new CampaignsController(self::$db, self::USER))->create([
            'aff_campaign_name' => 'pub', 'aff_campaign_url' => 'https://o.example', 'aff_campaign_payout' => 1, 'aff_network_id' => 1,
        ])['data'];
        self::assertRandIdRand((int) $row['aff_campaign_id'], $row['aff_campaign_id_public']);

        return (int) $row['aff_campaign_id'];
    }

    public function testCreatedLandingPagesAndCampaignsCarryAPublicId(): void
    {
        $campaign = $this->campaign();
        $row = (new LandingPagesController(self::$db, self::USER))->create([
            'aff_campaign_id' => $campaign, 'landing_page_nickname' => 'lp', 'landing_page_url' => 'https://lp.example',
        ])['data'];
        self::assertRandIdRand((int) $row['landing_page_id'], $row['landing_page_id_public']);
        $stored = self::$db->query('SELECT landing_page_id_public FROM 202_landing_pages WHERE landing_page_id = ' . (int) $row['landing_page_id'])->fetch_row()[0];
        self::assertSame((string) $row['landing_page_id_public'], (string) $stored, 'what the API answers is what go.php will resolve');
    }

    public function testAnIdlessLandingPageIsGivenOneWhenTheApiReadsIt(): void
    {
        $campaign = $this->campaign();
        self::$db->query('INSERT INTO 202_landing_pages SET user_id = ' . self::USER . ", aff_campaign_id = $campaign, landing_page_nickname = 'old',"
            . " landing_page_url = 'https://old.example', landing_page_type = 0, landing_page_time = 0, landing_page_id_public = NULL");
        $id = (int) self::$db->insert_id;
        // Another account's id-less page is not this account's to repair.
        self::$db->query("INSERT INTO 202_landing_pages SET user_id = 5102, aff_campaign_id = $campaign, landing_page_nickname = 'other',"
            . " landing_page_url = 'https://other.example', landing_page_type = 0, landing_page_time = 0, landing_page_id_public = NULL");
        $other = (int) self::$db->insert_id;

        $rows = (new LandingPagesController(self::$db, self::USER))->list([])['data'];
        self::assertCount(1, $rows);
        self::assertRandIdRand($id, $rows[0]['landing_page_id_public']);
        self::assertNull(self::$db->query("SELECT landing_page_id_public FROM 202_landing_pages WHERE landing_page_id = $other")->fetch_row()[0]);

        $before = $rows[0]['landing_page_id_public'];
        $again = (new LandingPagesController(self::$db, self::USER))->get($id)['data'];
        self::assertSame($before, $again['landing_page_id_public'], 'an id, once given, is never replaced');
        self::$db->query("DELETE FROM 202_landing_pages WHERE landing_page_id = $other");
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusedFilters(): iterable
    {
        yield 'a misspelled field' => [['aff_campaing_id' => '3'], 'filter[aff_campaing_id]'];
        yield 'a whole number given text' => [['aff_campaign_id' => 'abc'], 'filter[aff_campaign_id]'];
        yield 'a number given text' => [['aff_campaign_payout' => 'ten'], 'filter[aff_campaign_payout]'];
        yield 'a list' => [['aff_network_id' => ['1', '2']], 'filter[aff_network_id]'];
        yield 'not a map' => [[], 'filter'];
    }

    /** @dataProvider refusedFilters */
    public function testAFilterTheListCannotApplyIsRefusedNotDropped(array $filter, string $field): void
    {
        $this->campaign();
        $params = $field === 'filter' ? ['filter' => 'aff_network_id'] : ['filter' => $filter];
        try {
            (new CampaignsController(self::$db, self::USER))->list($params);
            self::fail('the list answered without applying ' . $field);
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->getFieldErrors());
        }
    }

    public function testFiltersOnTheKeyAndOnReadOnlyFieldsAreApplied(): void
    {
        $a = $this->campaign();
        $b = $this->campaign();
        $campaigns = new CampaignsController(self::$db, self::USER);
        self::assertSame([$b], array_map('intval', array_column($campaigns->list(['filter' => ['aff_campaign_id' => (string) $b]])['data'], 'aff_campaign_id')));
        $public = (int) $campaigns->get($a)['data']['aff_campaign_id_public'];
        self::assertSame([$a], array_map('intval', array_column($campaigns->list(['filter' => ['aff_campaign_id_public' => (string) $public]])['data'], 'aff_campaign_id')));
    }
}
