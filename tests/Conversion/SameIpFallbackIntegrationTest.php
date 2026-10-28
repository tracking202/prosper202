<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\SameIpFallback;
use Prosper202\Database\Connection;
use Prosper202\Repository\Mysql\MysqlLocationRepository;

/**
 * The pixels' same-IP fallback against a real server: the account's setting
 * (SameIpFallback::enabledFor()), whose pixel a request is
 * (pixelAccount(), ofCampaign()), and the guess itself
 * (p202SameIpFallbackClick(), with connect2.php's privacy functions as they
 * are, extracted from its source, which a test cannot include).
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST and the
 * rest); it installs the schema there and writes users 5801-5804, their
 * campaigns and clicks 980000-980004, and puts user 1's preferences row
 * back as it found it.
 *
 * @group integration
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SameIpFallbackIntegrationTest extends TestCase
{
    private const ON = 5801;
    private const OFF = 5802;
    private const GARBAGE = 5803;
    private const NO_ROW = 5804;
    private const USERS = [self::ON, self::OFF, self::GARBAGE, self::NO_ROW];
    private const CLICKS = [980000, 980001, 980002, 980003, 980004];
    private const CAMPAIGN = 980100;
    private const CAPTURE_OFF = 980101;
    private const OTHER_CAMPAIGN = 980102;
    private const VISITOR = '203.0.113.9';

    private const FUNCTIONS = [
        'p202StoredVisitorIp', 'p202ApplyOwnerPrivacy', 'p202PrivacyInForce', 'trackingEnabled',
        'p202MayRecogniseVisitor', 'p202VisitorMayBeInEu',
    ];

    private static ?\mysqli $db = null;
    private static ?Connection $conn = null;
    /** @var array<string, mixed>|null user 1's preferences row as found */
    private static ?array $installRow = null;

    private string $log = '';

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) '
                . '{ return $sql === null ? null : $dbOrSql->query($sql); }');
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
        (new \Prosper202\Database\SchemaInstaller($db))->install();
        self::$db = $db;
        self::$conn = new Connection($db);
        $found = $db->query('SELECT * FROM 202_users_pref WHERE user_id = 1');
        self::$installRow = $found instanceof \mysqli_result ? $found->fetch_assoc() : null;
        self::cleanUp();

        foreach (self::USERS as $user) {
            self::q(sprintf("INSERT INTO 202_users SET user_id = %d, user_name = 'sameip%d', user_email = 'sameip%d@example.com',"
                . " user_pass = '', user_timezone = 'UTC', user_time_register = 0", $user, $user, $user));
        }
        self::q('INSERT INTO 202_users_pref (user_id, user_pref_privacy, user_pref_same_ip_fallback) VALUES'
            . sprintf(" (%d, 'disabled', 1), (%d, 'disabled', 0), (%d, 'disabled', 7)", self::ON, self::OFF, self::GARBAGE));
        // The install's own setting, which every visitor is held to as well.
        self::q("INSERT INTO 202_users_pref (user_id, user_pref_privacy) VALUES (1, 'disabled')"
            . " ON DUPLICATE KEY UPDATE user_pref_privacy = 'disabled'");
        foreach ([[self::CAMPAIGN, self::ON, 1], [self::CAPTURE_OFF, self::ON, 0], [self::OTHER_CAMPAIGN, self::OFF, 1]] as [$id, $user, $capture]) {
            self::q(sprintf("INSERT INTO 202_aff_campaigns SET aff_campaign_id = %d, user_id = %d, aff_campaign_name = 'sameip',"
                . " aff_campaign_url = 'https://offer.example/', identity_signals = %d, aff_campaign_time = 0", $id, $user, $capture));
        }

        $now = time();
        $locations = new MysqlLocationRepository(self::$conn);
        $visitor = $locations->findOrCreateIp(self::VISITOR);
        $elsewhere = $locations->findOrCreateIp('198.51.100.7');
        // As a visitor held back is stored: the one click from the /24.
        $masked = $locations->findOrCreateIp('203.0.113.0');
        // [click, user, ip_id, seconds ago]: the other account's click from
        // the address is the latest of all, and must never be ON's guess.
        foreach ([[980000, self::ON, $visitor, 3600], [980001, self::ON, $elsewhere, 60], [980002, self::OFF, $visitor, 30],
            [980003, self::GARBAGE, $visitor, 20], [980004, self::ON, $masked, 1800]] as [$click, $user, $ipId, $ago]) {
            self::q(sprintf('INSERT INTO 202_clicks SET click_id = %d, user_id = %d, click_time = %d, ppc_account_id = 7',
                $click, $user, $now - $ago));
            self::q(sprintf('INSERT INTO 202_clicks_advance SET click_id = %d, ip_id = %d, keyword_id = 11', $click, $ipId));
            self::q(sprintf("INSERT INTO 202_clicks_record SET click_id = %d, click_id_public = '%d'", $click, $click + 1000000));
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::cleanUp();
        self::$db->query('DELETE FROM 202_users_pref WHERE user_id = 1');
        if (self::$installRow !== null) {
            $columns = array_map(static fn (string $c): string => '`' . $c . '`', array_keys(self::$installRow));
            $values = array_map(
                static fn (mixed $v): string => $v === null ? 'NULL' : "'" . self::$db->real_escape_string((string) $v) . "'",
                array_values(self::$installRow)
            );
            self::q('INSERT INTO 202_users_pref (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')');
        }
        self::$db->close();
        self::$db = null;
        self::$conn = null;
    }

    protected function setUp(): void
    {
        if (self::$conn === null) {
            self::markTestSkipped('No scratch database configured (P202_TEST_DB_HOST).');
        }
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-same-ip-');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
    }

    private function logged(): string
    {
        return (string) file_get_contents($this->log);
    }

    public function testTheSettingIsReadAsStoredAndAnythingElseIsOff(): void
    {
        self::assertTrue(SameIpFallback::enabledFor(self::$conn, self::ON));
        self::assertFalse(SameIpFallback::enabledFor(self::$conn, self::OFF));
        self::assertFalse(SameIpFallback::enabledFor(self::$conn, self::GARBAGE), 'a value that is not 0 or 1');
        self::assertStringContainsString('user 5803\'s user_pref_same_ip_fallback is 7, not 0 or 1', $this->logged());
        self::assertFalse(SameIpFallback::enabledFor(self::$conn, self::NO_ROW), 'no row: the column\'s default');
    }

    public function testANewAccountsRowStartsOff(): void
    {
        self::q(sprintf('INSERT INTO 202_users_pref (user_id) VALUES (%d)', self::NO_ROW));
        try {
            self::assertFalse(SameIpFallback::enabledFor(self::$conn, self::NO_ROW));
        } finally {
            self::q(sprintf('DELETE FROM 202_users_pref WHERE user_id = %d', self::NO_ROW));
        }
    }

    /** Before the 1.9.79 upgrade has added the column, every account is one it will turn on. */
    public function testBeforeTheUpgradeAddsTheColumnTheAccountKeepsTheFallback(): void
    {
        self::q('ALTER TABLE 202_users_pref DROP COLUMN user_pref_same_ip_fallback');
        try {
            self::assertTrue(SameIpFallback::enabledFor(self::$conn, self::OFF));
            self::assertStringContainsString('the 1.9.79 upgrade has not added user_pref_same_ip_fallback yet', $this->logged());
        } finally {
            self::q("ALTER TABLE 202_users_pref ADD COLUMN `user_pref_same_ip_fallback` tinyint(1) NOT NULL DEFAULT '0'"
                . ' AFTER `user_pref_dynamic_bid`');
            self::q(sprintf('UPDATE 202_users_pref SET user_pref_same_ip_fallback = 1 WHERE user_id = %d', self::ON));
            self::q(sprintf('UPDATE 202_users_pref SET user_pref_same_ip_fallback = 7 WHERE user_id = %d', self::GARBAGE));
        }
    }

    public function testAReadThatFailsIsOff(): void
    {
        $db = $this->getMockBuilder(\mysqli::class)->disableOriginalConstructor()->getMock();
        $db->method('prepare')->willThrowException(new \mysqli_sql_exception('gone away', 2006));
        self::assertFalse(SameIpFallback::enabledFor(new Connection($db), self::ON));
        self::assertStringContainsString('user 5801\'s user_pref_same_ip_fallback could not be read (gone away)', $this->logged());
    }

    public function testAPixelNamingACampaignIsThatCampaignsAccounts(): void
    {
        self::assertSame(['user_id' => self::ON, 'campaign_allows' => true], SameIpFallback::pixelAccount(self::$conn, (string) self::CAMPAIGN, 't'));
        self::assertSame(['user_id' => self::ON, 'campaign_allows' => false], SameIpFallback::pixelAccount(self::$conn, (string) self::CAPTURE_OFF, 't'));
        self::assertSame(['user_id' => self::OFF, 'campaign_allows' => true], SameIpFallback::pixelAccount(self::$conn, (string) self::OTHER_CAMPAIGN, 't'));
        foreach (['980199', '12abc', '0', '-1', '1.5', ['1']] as $cid) {
            self::assertNull(SameIpFallback::pixelAccount(self::$conn, $cid, 't'), 'cid ' . var_export($cid, true) . ' names no campaign');
        }
        self::assertStringContainsString('t: cid 980199 names no campaign', $this->logged());
        self::assertStringContainsString("t: cid '12abc' is not a campaign id", $this->logged());
    }

    /** No cid: the install's one account with campaigns, never the first account by default. */
    public function testAPixelNamingNoCampaignIsTheOnlyAccountWithCampaigns(): void
    {
        self::$db->begin_transaction();
        try {
            // Only this class's campaigns, for the length of the transaction.
            self::q('DELETE FROM 202_aff_campaigns WHERE aff_campaign_id NOT IN (' . self::CAMPAIGN . ', ' . self::CAPTURE_OFF . ', ' . self::OTHER_CAMPAIGN . ')');
            self::assertNull(SameIpFallback::pixelAccount(self::$conn, null, 't'), 'two accounts have campaigns: nobody\'s to choose');
            self::assertStringContainsString('more than one account has campaigns', $this->logged());
            self::q('UPDATE 202_aff_campaigns SET aff_campaign_deleted = 1 WHERE aff_campaign_id = ' . self::OTHER_CAMPAIGN);
            self::assertSame(['user_id' => self::ON, 'campaign_allows' => true], SameIpFallback::pixelAccount(self::$conn, null, 't'));
            self::assertSame(['user_id' => self::ON, 'campaign_allows' => true], SameIpFallback::pixelAccount(self::$conn, '', 't'), 'an empty cid, as the universal pixel sends it');
            self::q('UPDATE 202_aff_campaigns SET aff_campaign_deleted = 0 WHERE aff_campaign_id = ' . self::OTHER_CAMPAIGN);
            self::q('UPDATE 202_users SET user_deleted = 1 WHERE user_id = ' . self::OFF);
            self::assertSame(self::ON, SameIpFallback::pixelAccount(self::$conn, null, 't')['user_id'] ?? null, 'a deleted account does not count');
            self::q('DELETE FROM 202_aff_campaigns');
            self::assertNull(SameIpFallback::pixelAccount(self::$conn, null, 't'), 'no account has campaigns');
        } finally {
            self::$db->rollback();
        }
    }

    public function testACampaignRowNamesItsAccountAndCapture(): void
    {
        self::assertSame(['user_id' => 3, 'campaign_allows' => true], SameIpFallback::ofCampaign(['user_id' => '3', 'identity_signals' => '1'], 't'));
        self::assertSame(['user_id' => 3, 'campaign_allows' => false], SameIpFallback::ofCampaign(['user_id' => 3, 'identity_signals' => '0'], 't'));
        self::assertSame(['user_id' => 3, 'campaign_allows' => false], SameIpFallback::ofCampaign(['user_id' => 3], 't'), 'a setting not read is off');
        self::assertNull(SameIpFallback::ofCampaign(['user_id' => '0', 'identity_signals' => '1'], 't'));
        self::assertNull(SameIpFallback::ofCampaign(['identity_signals' => '1'], 't'));
    }

    private function loadTheGuess(string $installPrivacy = 'disabled'): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/connect2.php');
        foreach (self::FUNCTIONS as $name) {
            self::assertSame(1, preg_match('/^function ' . $name . '\(.*?^\}\n/ms', $source, $m), $name);
            eval($m[0]);
        }
        require_once dirname(__DIR__, 2) . '/202-config/static-endpoint-helpers.php';
        $GLOBALS['db'] = self::$db;
        $_SESSION = ['privacy' => $installPrivacy];
        $_SERVER = ['REMOTE_ADDR' => self::VISITOR, 'SCRIPT_NAME' => '/tracking202/static/gpx.php'];
    }

    /** @return array{user_id: int, campaign_allows: bool} */
    private static function pixelOf(int $user, bool $capture = true): array
    {
        return ['user_id' => $user, 'campaign_allows' => $capture];
    }

    public function testAnAccountWithTheFallbackOnGetsItsOwnLatestClickFromTheAddress(): void
    {
        $this->loadTheGuess();
        $click = p202SameIpFallbackClick(self::$db, self::pixelOf(self::ON), []);
        self::assertSame(980000, $click['click_id'] ?? null, 'its own click from the address, not the later one of another account');
        self::assertTrue(trackingEnabled(), 'the account\'s privacy setting is in force');
    }

    public function testAnAccountWithTheFallbackOffGetsNone(): void
    {
        $this->loadTheGuess();
        self::assertNull(p202SameIpFallbackClick(self::$db, self::pixelOf(self::OFF), []));
        self::assertNull(p202SameIpFallbackClick(self::$db, self::pixelOf(self::GARBAGE), []));
        self::assertNull(p202SameIpFallbackClick(self::$db, self::pixelOf(self::NO_ROW), []));
    }

    public function testNothingNamingThePixelsAccountGetsNone(): void
    {
        $this->loadTheGuess();
        self::assertNull(p202SameIpFallbackClick(self::$db, null, []));
    }

    /**
     * Each with the click the lookup alone would credit: under privacy the
     * one click from the masked /24 (the old rule credited a unique one),
     * otherwise the latest from the address. Only the decision refuses it.
     *
     * @return iterable<string, array{string, string, array<string, string>, bool, int}>
     */
    public static function heldBack(): iterable
    {
        yield 'the account holds every visitor back' => ['disabled', 'all', [], true, 980004];
        yield 'the install holds every visitor back' => ['all', 'disabled', [], true, 980004];
        // 203.0.113.9 is a documentation address GeoIP cannot place: 'eu' holds it back.
        yield 'an unplaceable visitor under eu' => ['disabled', 'eu', [], true, 980004];
        yield 'consent withheld' => ['disabled', 'disabled', ['p202_consent' => '0'], true, 980000];
        yield 'consent withheld as false' => ['disabled', 'disabled', ['p202_consent' => 'false'], true, 980000];
        yield 'the campaign captures no identity' => ['disabled', 'disabled', [], false, 980000];
    }

    /**
     * A visitor held back from the p202vid cookie is not matched by address
     * either: the same decision (p202MayRecogniseVisitor()).
     *
     * @dataProvider heldBack
     * @param array<string, string> $get
     */
    public function testAVisitorHeldBackFromTheVisitorCookieIsNotMatchedByAddress(string $install, string $account, array $get, bool $capture, int $lookupAlone): void
    {
        $this->loadTheGuess($install);
        self::q(sprintf("UPDATE 202_users_pref SET user_pref_privacy = '%s' WHERE user_id = %d", $account, self::ON));
        try {
            self::assertNull(p202SameIpFallbackClick(self::$db, self::pixelOf(self::ON, $capture), $get));
            self::assertFalse(p202MayRecogniseVisitor($get, $capture), 'and the p202vid cookie is withheld');
            $found = \Prosper202\Click\LastClickFromAddress::find(self::$conn, p202StoredVisitorIp(), self::ON, 0, !trackingEnabled());
            self::assertSame($lookupAlone, $found['click_id'] ?? null, 'the lookup alone would have credited it');
        } finally {
            self::q(sprintf("UPDATE 202_users_pref SET user_pref_privacy = 'disabled' WHERE user_id = %d", self::ON));
        }
    }

    private static function cleanUp(): void
    {
        $clicks = implode(',', self::CLICKS);
        foreach (['202_clicks', '202_clicks_advance', '202_clicks_record'] as $table) {
            self::$db->query("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        $users = implode(',', self::USERS);
        self::$db->query("DELETE FROM 202_aff_campaigns WHERE user_id IN ($users)");
        self::$db->query("DELETE FROM 202_users_pref WHERE user_id IN ($users)");
        self::$db->query("DELETE FROM 202_users WHERE user_id IN ($users)");
    }

    private static function q(string $sql): void
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);
    }
}
