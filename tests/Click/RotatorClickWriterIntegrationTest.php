<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\RotatorClickWriter;
use Prosper202\Database\Connection;

/**
 * A redirector click's rows commit together or not at all.
 *
 * rtr.php wrote them as seven autocommitted REPLACEs, so a failure part way
 * (a lost connection, a full disk, a lock timeout) left a click with some of
 * its rows. RotatorClickWriter writes them in one transaction, with the
 * redirect URL resolved inside it from the rows just written, and a re-click
 * (rtr.php?lpr=) marked for the attribution rollup in the same transaction.
 * Run on the scratch database in the strict mode a MySQL 8 server has.
 *
 * @group integration
 */
final class RotatorClickWriterIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990301;
    private const FIRST_CLICK = 99030101;

    private const TABLES = [
        '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking',
        '202_clicks_rotator', '202_clicks_record', '202_clicks_site',
    ];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    private static function cleanUp(): void
    {
        $range = 'BETWEEN ' . self::FIRST_CLICK . ' AND ' . (self::FIRST_CLICK + 99);
        foreach ([...self::TABLES, '202_attribution_rollup_dirty_clicks'] as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
    }

    private static function click(int $clickId, int $keywordId = 5): ClickRecord
    {
        $click = new ClickRecord();
        $click->clickId = $clickId;
        $click->userId = self::USER;
        $click->affCampaignId = 0;
        $click->landingPageId = 0;
        $click->ppcAccountId = 3;
        $click->clickCpc = '0.25';
        // No campaign: no payout, which rtr.php hands over as '0'.
        $click->clickPayout = '0';
        $click->clickFiltered = 1;
        $click->clickBot = 0;
        $click->clickTime = time();
        $click->keywordId = $keywordId;
        $click->ipId = 11;
        $click->countryId = 12;
        $click->c1Id = 21;
        $click->c4Id = 24;
        $click->clickIdPublic = '';
        $click->clickIn = 1;
        $click->clickOut = 1;
        $click->clickRefererSiteUrlId = 31;
        $click->clickOutboundSiteUrlId = 32;

        return $click;
    }

    /** @return array<string, int> table => rows the click has there */
    private static function rows(int $clickId): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $result = self::$db->query("SELECT COUNT(*) FROM $table WHERE click_id = $clickId");
            self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);
            $counts[$table] = (int) $result->fetch_row()[0];
        }

        return $counts;
    }

    private static function one(string $sql): ?array
    {
        $result = self::$db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error . ' in ' . $sql);

        return $result->fetch_assoc();
    }

    private static function marks(int $clickId): int
    {
        $row = self::one("SELECT COUNT(*) AS n FROM 202_attribution_rollup_dirty_clicks WHERE click_id = $clickId");

        return (int) $row['n'];
    }

    /** @param array<string, string> $expected column => the value the server answers */
    private static function assertColumns(string $table, int $clickId, array $expected): void
    {
        $columns = implode(', ', array_keys($expected));
        self::assertSame($expected, self::one("SELECT $columns FROM $table WHERE click_id = $clickId"), $table);
    }

    private static function writer(): RotatorClickWriter
    {
        return new RotatorClickWriter(new Connection(self::requireScratch()));
    }

    public function testEveryRowIsWrittenAndTheRedirectIsResolvedFromThem(): void
    {
        $clickId = self::FIRST_CLICK;
        $seenInside = null;

        $resolve = function () use ($clickId, &$seenInside): int {
            $seenInside = self::rows($clickId);

            return 33;
        };
        self::writer()->write(self::click($clickId), 7, 8, 9, false, $resolve);

        self::assertSame(array_fill_keys(self::TABLES, 1), self::rows($clickId));
        // The resolver runs after every row but the site row, which takes its answer.
        self::assertSame(array_merge(array_fill_keys(self::TABLES, 1), ['202_clicks_site' => 0]), $seenInside);
        self::assertColumns('202_clicks', $clickId, [
            'user_id' => (string) self::USER, 'aff_campaign_id' => '0', 'ppc_account_id' => '3',
            'click_cpc' => '0.25000', 'click_payout' => '0.00000', 'click_filtered' => '1',
            'rotator_id' => '7', 'rule_id' => '9',
        ]);
        self::assertColumns('202_clicks_rotator', $clickId, [
            'rotator_id' => '7', 'rule_id' => '8', 'rule_redirect_id' => '9',
        ]);
        self::assertColumns('202_clicks_advance', $clickId, [
            'keyword_id' => '5', 'ip_id' => '11', 'country_id' => '12',
        ]);
        self::assertColumns('202_clicks_tracking', $clickId, ['c1_id' => '21', 'c2_id' => '0', 'c4_id' => '24']);
        self::assertColumns('202_clicks_record', $clickId, [
            'click_id_public' => '0', 'click_cloaking' => '0', 'click_in' => '1', 'click_out' => '1',
        ]);
        self::assertColumns('202_clicks_site', $clickId, [
            'click_referer_site_url_id' => '31',
            'click_outbound_site_url_id' => '32',
            'click_redirect_site_url_id' => '33',
        ]);
        self::assertSame(0, self::marks($clickId), 'a new click is not summed yet, so it is not marked');
    }

    /**
     * The public id is a digit, the click id and a digit: on a 32-bit build
     * it passes PHP_INT_MAX at tens of millions of clicks, and (int) clamped
     * it, so cl.php?pci= found no click. Past PHP_INT_MAX here is the same
     * clamp on a 64-bit build.
     */
    public function testACloakedClickKeepsItsPublicIdWhole(): void
    {
        $clickId = self::FIRST_CLICK + 5;
        $click = self::click($clickId);
        $click->clickIdPublic = '18446744073709551615';
        $click->clickCloaking = 1;

        self::writer()->write($click, 7, 8, 9, false, static fn (): int => 33);

        self::assertColumns('202_clicks_record', $clickId, [
            'click_id_public' => '18446744073709551615', 'click_cloaking' => '1',
        ]);
    }

    public function testAFailureWhileResolvingTheRedirectWritesNoRow(): void
    {
        $clickId = self::FIRST_CLICK + 1;

        try {
            self::writer()->write(self::click($clickId), 7, 8, 9, false, static function (): int {
                throw new \RuntimeException('the redirect could not be resolved');
            });
            self::fail('the failure reaches the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('the redirect could not be resolved', $e->getMessage());
        }

        self::assertSame(array_fill_keys(self::TABLES, 0), self::rows($clickId));
    }

    public function testAFailedLastStatementTakesTheOthersWithIt(): void
    {
        $clickId = self::FIRST_CLICK + 2;

        try {
            // Out of range for the unsigned column: the server refuses the site row.
            self::writer()->write(self::click($clickId), 7, 8, 9, false, static fn (): int => -1);
            self::fail('the refused statement reaches the caller');
        } catch (\Prosper202\Database\Exceptions\QueryException $e) {
            self::assertStringContainsString('click_redirect_site_url_id', $e->getMessage());
        }

        self::assertSame(array_fill_keys(self::TABLES, 0), self::rows($clickId));
    }

    public function testAReClickAddsAClicksRowReplacesTheRestAndIsMarked(): void
    {
        $clickId = self::FIRST_CLICK + 3;
        self::writer()->write(self::click($clickId, 5), 7, 8, 9, false, static fn (): int => 33);

        self::writer()->write(self::click($clickId, 6), 7, 8, 9, true, static fn (): int => 34);

        // 202_clicks and 202_clicks_spy have no key on click_id: a second row.
        self::assertSame(
            ['202_clicks' => 2, '202_clicks_spy' => 2] + array_fill_keys(self::TABLES, 1),
            self::rows($clickId)
        );
        self::assertColumns('202_clicks_advance', $clickId, ['keyword_id' => '6']);
        self::assertColumns('202_clicks_site', $clickId, ['click_redirect_site_url_id' => '34']);
        self::assertSame(1, self::marks($clickId));
    }

    public function testAFailedReClickLeavesTheClickAsItWasAndUnmarked(): void
    {
        $clickId = self::FIRST_CLICK + 4;
        self::writer()->write(self::click($clickId, 5), 7, 8, 9, false, static fn (): int => 33);

        try {
            self::writer()->write(self::click($clickId, 6), 7, 8, 9, true, static fn (): int => -1);
            self::fail('the refused statement reaches the caller');
        } catch (\Prosper202\Database\Exceptions\QueryException) {
        }

        self::assertSame(array_fill_keys(self::TABLES, 1), self::rows($clickId));
        self::assertColumns('202_clicks_advance', $clickId, ['keyword_id' => '5']);
        self::assertSame(0, self::marks($clickId));
    }
}
