<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\TrackersController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * GET /trackers/{id}/url against a real database: the link is built on
 * user 1's tracking domain, carries the custom variables of the tracker's
 * traffic source only when that source's account is the caller's, and
 * refuses a token it would otherwise leave out.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there, writes users 5201 and 5202's rows, and sets
 * (then restores) user 1's tracking domain.
 *
 * @group integration
 */
final class TrackerUrlIntegrationTest extends TestCase
{
    private const USER = 5201;
    private const OTHER = 5202;

    private static ?\mysqli $db = null;
    private static ?string $savedDomain = null;

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
        $row = $db->query('SELECT user_tracking_domain FROM 202_users_pref WHERE user_id = 1')->fetch_row();
        self::$savedDomain = $row === null ? null : (string) $row[0];
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::$db->query('DELETE FROM 202_users_pref WHERE user_id = ' . self::USER);
            if (self::$savedDomain === null) {
                self::$db->query('DELETE FROM 202_users_pref WHERE user_id = 1');
            } else {
                $stmt = self::$db->prepare('UPDATE 202_users_pref SET user_tracking_domain = ? WHERE user_id = 1');
                $stmt->bind_param('s', self::$savedDomain);
                $stmt->execute();
                $stmt->close();
            }
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            foreach (['202_trackers', '202_ppc_accounts', '202_ppc_networks', '202_landing_pages', '202_aff_campaigns'] as $table) {
                self::$db->query("DELETE FROM $table WHERE user_id = $u");
            }
        }
        self::$db->query('DELETE FROM 202_ppc_network_variables WHERE ppc_network_id IN (9201, 9202)');
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::cleanUp();
        self::setDomain('track.example.com');
        foreach ([[9201, self::USER], [9202, self::OTHER]] as [$network, $user]) {
            self::$db->query("INSERT INTO 202_ppc_networks SET ppc_network_id = $network, user_id = $user, ppc_network_name = 'n$network', ppc_network_deleted = 0, ppc_network_time = 0");
            self::$db->query("INSERT INTO 202_ppc_accounts SET ppc_account_id = $network, user_id = $user, ppc_network_id = $network, ppc_account_name = 'a$network', ppc_account_deleted = 0, ppc_account_time = 0");
        }
        foreach ([
            [9201, 'Ad', 'adid', '{ad_id}', 0],
            [9201, 'Keyword', 't202kw', '{keyword}', 0],
            [9201, 'Gone', 'gone', '{gone}', 1],
            [9202, 'Theirs', 'theirs', '{theirs}', 0],
        ] as [$network, $name, $parameter, $placeholder, $deleted]) {
            self::$db->query("INSERT INTO 202_ppc_network_variables SET ppc_network_id = $network, name = '$name', parameter = '$parameter', placeholder = '$placeholder', deleted = $deleted");
        }
        self::assertTrue(self::$db->query('INSERT INTO 202_aff_campaigns SET user_id = ' . self::USER . ", aff_network_id = 0, aff_campaign_name = 'links', aff_campaign_url = 'https://o.example', aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_time = 0"), (string) self::$db->error);
        $this->campaign = (int) self::$db->insert_id;
    }

    private int $campaign = 0;

    /** The caller's tracking domain; user 1's is another, so a read of the owner's shows. */
    private static function setDomain(string $domain): void
    {
        self::assertTrue(self::$db->query("INSERT INTO 202_users_pref (user_id, user_tracking_domain) VALUES (1, 'owner.example') ON DUPLICATE KEY UPDATE user_tracking_domain = VALUES(user_tracking_domain)"));
        $stmt = self::$db->prepare('INSERT INTO 202_users_pref (user_id, user_tracking_domain) VALUES (' . self::USER . ', ?) ON DUPLICATE KEY UPDATE user_tracking_domain = VALUES(user_tracking_domain)');
        $stmt->bind_param('s', $domain);
        self::assertTrue($stmt->execute());
        $stmt->close();
    }

    private function tracker(int $ppcAccountId, int $landingPageId = 0): int
    {
        return (int) (new TrackersController(self::$db, self::USER))->create([
            'aff_campaign_id' => $this->campaign, 'ppc_account_id' => $ppcAccountId, 'landing_page_id' => $landingPageId,
        ])['data']['tracker_id'];
    }

    /**
     * A tracker that already links to another account's record: the API
     * refuses to write that link now, but rows written before it did are
     * still read, and must lend nothing of the other account's.
     */
    private function legacyTracker(string $column, int $theirs): int
    {
        $id = $this->tracker(0);
        self::assertTrue(self::$db->query("UPDATE 202_trackers SET $column = $theirs WHERE tracker_id = $id"), (string) self::$db->error);

        return $id;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $server what the request adds to or changes in the default server values
     */
    private function url(int $id, array $params = [], array $server = []): array
    {
        $server += [
            'SERVER_NAME' => 'internal', 'SERVER_PORT' => '443', 'HTTPS' => 'on',
            'DOCUMENT_ROOT' => dirname(__DIR__, 3),
        ];

        return (new TrackersController(self::$db, self::USER))->getTrackingUrl($id, $params, $server)['data'];
    }

    /**
     * On the caller's own tracking domain, as Get Links builds it for the
     * signed-in user: the API used user 1's, so an account with a domain of
     * its own got its links on the owner's host here and on its own there.
     */
    public function testTheLinkIsOnTheCallersDomainWithTheSourcesLiveVariables(): void
    {
        $id = $this->tracker(9201);
        $data = $this->url($id);
        $public = $data['tracker_id_public'];
        self::assertSame("https://track.example.com/tracking202/redirect/dl.php?t202id=$public&adid={ad_id}&t202kw={keyword}", $data['direct_url']);
        self::assertSame("?t202id=$public&adid={ad_id}&t202kw={keyword}", $data['tracking_params']);

        $given = $this->url($id, ['t202kw' => '[kw]', 'c1' => 'fb']);
        self::assertStringEndsWith("t202id=$public&adid={ad_id}&c1=fb&t202kw=[kw]", $given['direct_url'], 'a given value replaces the default');
    }

    public function testAnotherAccountsTrafficSourceLendsNoVariables(): void
    {
        $data = $this->url($this->legacyTracker('ppc_account_id', 9202));
        self::assertStringEndsWith('&t202kw=', $data['direct_url']);
        self::assertStringNotContainsString('theirs', $data['direct_url']);
    }

    /**
     * The account is the caller's, but it is filed under another account's
     * traffic source (written before 229df10 checked it): that source's
     * variables are not the caller's to put in a link, as Get Links leaves
     * them out.
     */
    public function testAnAccountUnderAnotherAccountsSourceLendsNoVariables(): void
    {
        self::assertTrue(self::$db->query('INSERT INTO 202_ppc_accounts SET ppc_account_id = 9203, user_id = ' . self::USER . ", ppc_network_id = 9202, ppc_account_name = 'stray', ppc_account_deleted = 0, ppc_account_time = 0"), (string) self::$db->error);
        $data = $this->url($this->legacyTracker('ppc_account_id', 9203));
        self::assertStringEndsWith('&t202kw=', $data['direct_url']);
        self::assertStringNotContainsString('theirs', $data['direct_url']);
    }

    public function testAnEmptyDomainUsesThisServer(): void
    {
        self::setDomain('');
        $data = $this->url($this->tracker(0));
        self::assertStringStartsWith('https://internal/tracking202/redirect/dl.php?t202id=', $data['direct_url']);
    }

    /**
     * The host the request came in on, not the server's own name and port:
     * behind a proxy (or a published container port) internal:8080 is not an
     * address anyone the link is given to can reach. A stored domain still
     * wins.
     */
    public function testAnEmptyDomainUsesTheHostTheRequestCameIn(): void
    {
        self::setDomain('');
        $id = $this->tracker(0);
        $proxied = [
            'SERVER_PORT' => '8080', 'HTTPS' => '',
            'HTTP_HOST' => 'proxy.example:9443', 'HTTP_X_FORWARDED_PROTO' => 'https',
        ];
        self::assertStringStartsWith(
            'https://proxy.example:9443/tracking202/redirect/dl.php?t202id=',
            $this->url($id, [], $proxied)['direct_url']
        );
        self::setDomain('track.example.com');
        $stored = $this->url($id, [], $proxied)['direct_url'];
        self::assertStringStartsWith('https://track.example.com/tracking202/', $stored);
    }

    public function testAnotherAccountsLandingPageIsNotLinked(): void
    {
        self::$db->query("INSERT INTO 202_landing_pages SET user_id = " . self::OTHER . ", aff_campaign_id = 1, landing_page_nickname = 'x',"
            . " landing_page_url = 'https://theirs.example/lp', landing_page_type = 0, landing_page_time = 0");
        $data = $this->url($this->legacyTracker('landing_page_id', (int) self::$db->insert_id));
        self::assertStringStartsWith('https://track.example.com/tracking202/redirect/dl.php?', $data['direct_url']);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusedTokens(): iterable
    {
        yield 'a token the link does not take' => [['c5' => 'x'], 'c5'];
        yield 'a value with an ampersand' => [['c1' => 'a&b'], 'c1'];
        yield 'a list' => [['c2' => ['a']], 'c2'];
    }

    /** @dataProvider refusedTokens */
    public function testATokenTheLinkCannotCarryIsRefused(array $params, string $field): void
    {
        $id = $this->tracker(9201);
        try {
            $this->url($id, $params);
            self::fail('answered a link without ' . $field);
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->getFieldErrors());
        }
    }
}
