<?php

declare(strict_types=1);

namespace Tests\Report;

use Api\V3\Controllers\ClicksController;
use PHPUnit\Framework\TestCase;
use Prosper202\DataEngine\IpAddressSql;

/**
 * The visitor's address as the Visitors list (click_history.php, its
 * download) and GET /clicks show it. An IPv6 address is a 202_ips row whose
 * ip_address holds its 202_ips_v6 row's id; both showed that id ("1") for
 * every IPv6 visitor.
 *
 * @group integration
 */
final class VisitorIpAddressIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 5751;

    /** @var array<string, int> */
    private static array $ids = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        $i = &self::$ids;
        $i['v4'] = self::insert("INSERT INTO 202_ips SET ip_address = '203.0.113.75'");
        $i['v6row'] = self::insert('INSERT INTO 202_ips_v6 SET ip_address = ' . self::str((string) inet_pton('2001:db8::5751')));
        $i['v6'] = self::insert('INSERT INTO 202_ips SET ip_address = ' . self::str((string) $i['v6row']));
        // An IPv4 address that, compared as a number, reads as that id.
        $i['collide'] = self::insert('INSERT INTO 202_ips SET ip_address = ' . self::str($i['v6row'] . '.0.1.1'));
        // A reference whose v6 row is gone.
        $i['dangling'] = self::insert("INSERT INTO 202_ips SET ip_address = '987654321'");

        self::user(self::USER);
        self::q('INSERT INTO 202_clicks SET click_id = 575101, user_id = ' . self::USER
            . ', aff_campaign_id = 0, ppc_account_id = 0, click_cpc = 0, click_time = UNIX_TIMESTAMP()');
        self::q("INSERT INTO 202_clicks_advance SET click_id = 575101, ip_id = {$i['v6']}, country_id = 0,"
            . ' region_id = 0, city_id = 0, isp_id = 0, platform_id = 0, browser_id = 0, device_id = 0');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    protected function setUp(): void
    {
        self::requireScratch();
    }

    private static function cleanUp(): void
    {
        self::$db->query('DELETE FROM 202_clicks_advance WHERE click_id = 575101');
        self::$db->query('DELETE FROM 202_clicks WHERE user_id = ' . self::USER);
        self::$db->query('DELETE FROM 202_users WHERE user_id = ' . self::USER);
        foreach (['v4', 'v6', 'collide', 'dangling'] as $k) {
            if (isset(self::$ids[$k])) {
                self::$db->query('DELETE FROM 202_ips WHERE ip_id = ' . self::$ids[$k]);
            }
        }
        if (isset(self::$ids['v6row'])) {
            self::$db->query('DELETE FROM 202_ips_v6 WHERE ip_id = ' . self::$ids['v6row']);
        }
        self::$ids = [];
    }

    private function address(int $ipId): ?string
    {
        $result = self::$db->query('SELECT ' . IpAddressSql::address('2i', '2i6') . ' AS ip_address FROM 202_ips AS 2i'
            . IpAddressSql::join('2i', '2i6') . " WHERE 2i.ip_id = $ipId");
        self::assertNotFalse($result, self::$db->error);

        return $result->fetch_assoc()['ip_address'];
    }

    public function testTheVisitorsListShowsEachAddressAsItIs(): void
    {
        $i = self::$ids;
        self::assertSame('203.0.113.75', $this->address($i['v4']));
        self::assertSame('2001:db8::5751', $this->address($i['v6']), 'an IPv6 address, not its row id');
        self::assertSame($i['v6row'] . '.0.1.1', $this->address($i['collide']), 'an IPv4 address that reads as a number is itself');
        self::assertNull($this->address($i['dangling']), 'a reference whose row is gone is no address, not its id');
    }

    public function testGetClicksShowsTheSameAddress(): void
    {
        $row = (new ClicksController(self::$db, self::USER))->get(575101)['data'];
        self::assertSame('2001:db8::5751', $row['ip_address']);
    }
}
