<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\User\CampaignCurrencyNotice;

/**
 * The campaigns the 1.9.80 upgrade found paid in another currency, as
 * Personal settings lists them and the owner answers, against a real
 * database. Skips unless a scratch database is configured (P202_TEST_DB_*);
 * writes users 5101 and 5102.
 *
 * @group integration
 */
final class CampaignCurrencyNoticeIntegrationTest extends TestCase
{
    private const USER = 5101;
    private const OTHER = 5102;

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
        self::$db->query('DELETE FROM 202_aff_campaigns WHERE user_id IN (' . self::USER . ', ' . self::OTHER . ')');
        // [id, user, name, currency, payout, foreign payout, deleted, marked]
        foreach ([
            [9201, self::USER, 'Store A', 'USD', '9.20', '10.00', 0, 1],
            [9202, self::USER, 'Store B', 'GBP', '11.50', '10.00', 0, 1],
            [9203, self::USER, 'Own EUR', 'EUR', '5.00', '0.00', 0, 0],
            [9204, self::USER, 'Gone', 'USD', '9.20', '10.00', 1, 1],
            [9205, self::USER, 'Since back', 'EUR', '7.00', '0.00', 0, 1],
            [9206, self::OTHER, 'Theirs', 'USD', '9.20', '10.00', 0, 1],
            // Back in the account's currency with its old foreign payout left: already the account's.
            [9207, self::USER, 'Back home', 'EUR', '10.00', '10.00', 0, 1],
        ] as [$id, $user, $name, $currency, $payout, $foreign, $deleted, $marked]) {
            self::$db->query("INSERT INTO 202_aff_campaigns SET aff_campaign_id = $id, user_id = $user, aff_network_id = 1,"
                . " aff_campaign_name = '$name', aff_campaign_url = 'https://shop.example', aff_campaign_payout = '$payout',"
                . " aff_campaign_currency = '$currency', aff_campaign_foreign_payout = '$foreign', aff_campaign_deleted = $deleted,"
                . " aff_campaign_time = 0, aff_campaign_currency_notice = $marked");
        }
    }

    /** @return array<int, string> id => "currency payout/foreign marked" */
    private static function campaigns(): array
    {
        $rows = self::$db->query('SELECT aff_campaign_id, aff_campaign_currency, aff_campaign_payout, aff_campaign_foreign_payout,'
            . ' aff_campaign_currency_notice FROM 202_aff_campaigns WHERE user_id IN (' . self::USER . ', ' . self::OTHER . ') ORDER BY aff_campaign_id');
        $out = [];
        foreach ($rows->fetch_all(MYSQLI_NUM) as [$id, $currency, $payout, $foreign, $marked]) {
            $out[(int) $id] = "$currency $payout/$foreign $marked";
        }

        return $out;
    }

    public function testTheMarkedLiveCampaignsInAnotherCurrencyAreListed(): void
    {
        $listed = CampaignCurrencyNotice::listed(new Connection(self::$db), self::USER, 'eur ');

        self::assertSame(
            ['campaigns' => [['id' => 9201, 'name' => 'Store A', 'currency' => 'USD'], ['id' => 9202, 'name' => 'Store B', 'currency' => 'GBP']], 'more' => 0],
            $listed,
            'not one in the account\'s currency (9203, 9207), a deleted one, one switched since, or another account\'s'
        );
    }

    public function testSwitchingMakesThemTheAccountsKeepingTheirPayoutAndTheNoticeGoes(): void
    {
        $conn = new Connection(self::$db);

        self::assertSame(2, CampaignCurrencyNotice::switchToAccount($conn, self::USER, 'EUR'));

        self::assertSame([
            9201 => 'EUR 9.20/0.00 0', 9202 => 'EUR 11.50/0.00 0', 9203 => 'EUR 5.00/0.00 0',
            9204 => 'USD 9.20/10.00 0', 9205 => 'EUR 7.00/0.00 0', 9206 => 'USD 9.20/10.00 1', 9207 => 'EUR 10.00/10.00 0',
        ], self::campaigns(), 'a deleted campaign keeps its currency; another account is untouched');
        self::assertSame([], CampaignCurrencyNotice::listed($conn, self::USER, 'EUR')['campaigns']);
    }

    public function testKeepingChangesNoCampaignAndTheNoticeGoes(): void
    {
        $conn = new Connection(self::$db);

        CampaignCurrencyNotice::keep($conn, self::USER);

        self::assertSame([
            9201 => 'USD 9.20/10.00 0', 9202 => 'GBP 11.50/10.00 0', 9203 => 'EUR 5.00/0.00 0',
            9204 => 'USD 9.20/10.00 0', 9205 => 'EUR 7.00/0.00 0', 9206 => 'USD 9.20/10.00 1', 9207 => 'EUR 10.00/10.00 0',
        ], self::campaigns());
        self::assertSame([], CampaignCurrencyNotice::listed($conn, self::USER, 'EUR')['campaigns']);
        self::assertCount(1, CampaignCurrencyNotice::listed($conn, self::OTHER, 'EUR')['campaigns'], 'another account\'s notice stands');
    }
}
