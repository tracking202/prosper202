<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\TrackersController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * A tracker written through the API is stored as Get Links stores it:
 * cloaking left to the campaign unless asked, and one cost type — dl.php
 * charges any tracker with a click_cpa per action, so a tracker holding
 * both costs would be charged twice.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes user 5301's rows.
 *
 * @group integration
 */
final class TrackerWriteRulesIntegrationTest extends TestCase
{
    private const USER = 5301;

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
        self::$db?->query('DELETE FROM 202_trackers WHERE user_id = ' . self::USER);
        self::$db?->query('DELETE FROM 202_aff_campaigns WHERE user_id = ' . self::USER);
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::$db->query('DELETE FROM 202_trackers WHERE user_id = ' . self::USER);
        self::$db->query('DELETE FROM 202_aff_campaigns WHERE user_id = ' . self::USER);
        // A tracker links only to the caller's own campaign.
        self::assertTrue(self::$db->query('INSERT INTO 202_aff_campaigns SET user_id = ' . self::USER . ", aff_network_id = 0, aff_campaign_name = 'rules', aff_campaign_url = 'https://o.example', aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_time = 0"), (string) self::$db->error);
        $this->campaign = (int) self::$db->insert_id;
    }

    private int $campaign = 0;

    private function trackers(): TrackersController
    {
        return new TrackersController(self::$db, self::USER);
    }

    /** @return array{0: ?string, 1: ?string, 2: string} click_cpc, click_cpa, click_cloaking as stored */
    private function stored(int $id): array
    {
        $row = self::$db->query("SELECT click_cpc, click_cpa, click_cloaking FROM 202_trackers WHERE tracker_id = $id")->fetch_row();

        return [$row[0], $row[1], (string) $row[2]];
    }

    public function testCloakingIsLeftToTheCampaignUnlessAsked(): void
    {
        $id = (int) $this->trackers()->create(['aff_campaign_id' => $this->campaign])['data']['tracker_id'];
        self::assertSame('-1', $this->stored($id)[2], "the campaign's setting, as Get Links defaults it");
        $this->trackers()->update($id, ['click_cloaking' => '0']);
        self::assertSame('0', $this->stored($id)[2]);
    }

    public function testSettingOneCostSwitchesTheTrackerToIt(): void
    {
        $id = (int) $this->trackers()->create(['aff_campaign_id' => $this->campaign, 'click_cpc' => '0.25'])['data']['tracker_id'];
        self::assertSame(['0.25000', null], array_slice($this->stored($id), 0, 2));

        $this->trackers()->update($id, ['click_cpa' => '3']);
        self::assertSame([null, '3.00000'], array_slice($this->stored($id), 0, 2), 'now CPA, and no longer charged per click');

        $this->trackers()->update($id, ['click_cpc' => '0.10']);
        self::assertSame(['0.10000', null], array_slice($this->stored($id), 0, 2));

        $this->trackers()->update($id, ['click_cloaking' => '1']);
        self::assertSame(['0.10000', null], array_slice($this->stored($id), 0, 2), 'a write that names no cost leaves the cost alone');
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function refused(): iterable
    {
        yield 'both costs' => [['click_cpc' => '0.25', 'click_cpa' => '3'], 'click_cpa'];
        yield 'a fractional cloaking value' => [['click_cloaking' => '0.5'], 'click_cloaking'];
        yield 'a cloaking value that is not one' => [['click_cloaking' => '2'], 'click_cloaking'];
        yield 'cloaking given as true' => [['click_cloaking' => true], 'click_cloaking'];
        yield 'a negative cost' => [['click_cpc' => '-1'], 'click_cpc'];
        yield 'a cost the column cannot hold' => [['click_cpa' => '100'], 'click_cpa'];
        yield 'a cost that is not a number' => [['click_cpc' => 'ten'], 'click_cpc'];
    }

    /** @dataProvider refused */
    public function testAValueTheTrackerCannotHoldIsRefused(array $payload, string $field): void
    {
        foreach (['create', 'update'] as $write) {
            try {
                if ($write === 'create') {
                    $this->trackers()->create(['aff_campaign_id' => $this->campaign] + $payload);
                } else {
                    $id = (int) $this->trackers()->create(['aff_campaign_id' => $this->campaign])['data']['tracker_id'];
                    $this->trackers()->update($id, $payload);
                }
                self::fail("$write accepted " . json_encode($payload));
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->getFieldErrors(), $write);
            }
        }
        self::assertSame('0', (string) self::$db->query('SELECT COUNT(*) FROM 202_trackers WHERE user_id = ' . self::USER . ' AND (click_cpc IS NOT NULL OR click_cpa IS NOT NULL OR click_cloaking <> -1)')->fetch_row()[0], 'nothing refused was written');
    }
}
