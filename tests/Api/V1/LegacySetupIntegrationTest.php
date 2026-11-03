<?php

declare(strict_types=1);

namespace Tests\Api\V1;

use Api\V1\LegacySetup;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;
use Prosper202\Database\SchemaInstaller;

/**
 * Paid 1.9.56's setup reads, GET /api/v1/{resource}/?apikey=…, answered
 * from the v3 controllers (Api\V1\LegacySetup): the old wrapper keys and
 * string values, the key checked as v3 checks one, and only the key's own
 * account's records, where the old queries read every account's.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST, _PORT,
 * _USER, _PASS, _NAME). Installs the schema there and removes the rows it
 * wrote (users 9331-9335 and their records) afterwards.
 *
 * @group integration
 */
final class LegacySetupIntegrationTest extends TestCase
{
    private const OWNER = 9331;
    private const OTHER = 9332;
    private const VIEWER = 9333;
    private const OFF = 9334;
    private const EMPTY = 9335;
    private const USERS = [self::OWNER, self::OTHER, self::VIEWER, self::OFF, self::EMPTY];

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        // Api\V1 has no PSR-4 entry of its own; Api\ would look in api/V1/.
        require_once dirname(__DIR__, 3) . '/api/v1/LegacySetup.php';
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        // The app's query helper and p202UncaughtLogLine(), unless another suite declared the helper.
        if (!function_exists('_mysqli_query')) {
            require_once dirname(__DIR__, 3) . '/202-config/mysql-error-args.php';
            require_once dirname(__DIR__, 3) . '/202-config/functions-shared.php';
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
        $seeder = new DataSeeder($db);
        $seeder->seedRoles();
        $seeder->seedPermissions();
        $seeder->seedRolePermissions();
        $seeder->seedPixelTypes();
        self::$db = $db;
        self::cleanUp();
        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
    }

    private static function q(string $sql): void
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);
    }

    /** @param array<string, int|float|string> $row */
    private static function insert(string $table, array $row): void
    {
        $sets = [];
        foreach ($row as $column => $value) {
            $sets[] = "`$column` = " . (is_string($value) ? "'" . self::$db->real_escape_string($value) . "'" : $value);
        }
        self::q("INSERT INTO $table SET " . implode(', ', $sets));
    }

    private static function cleanUp(): void
    {
        $in = 'IN (' . implode(',', self::USERS) . ')';
        $rotators = "SELECT id FROM 202_rotators WHERE user_id $in";
        $rules = "SELECT id FROM 202_rotator_rules WHERE rotator_id IN ($rotators)";
        self::q("DELETE FROM 202_rotator_rules_criteria WHERE rotator_id IN ($rotators)");
        self::q("DELETE FROM 202_rotator_rules_redirects WHERE rule_id IN ($rules)");
        self::q("DELETE FROM 202_rotator_rules WHERE rotator_id IN ($rotators)");
        self::q("DELETE FROM 202_ppc_account_pixels WHERE ppc_account_id IN "
            . "(SELECT ppc_account_id FROM 202_ppc_accounts WHERE user_id $in)");
        $tables = [
            '202_rotators', '202_trackers', '202_text_ads', '202_landing_pages', '202_aff_campaigns',
            '202_aff_networks', '202_ppc_accounts', '202_ppc_networks', '202_api_keys', '202_user_role', '202_users',
        ];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE user_id $in");
        }
    }

    /** OWNER and OTHER each get one of every record, named after themselves. */
    private static function seed(): void
    {
        $roles = [self::OWNER => 2, self::OTHER => 2, self::VIEWER => 4, self::OFF => 2, self::EMPTY => 2];
        foreach ($roles as $user => $role) {
            self::insert('202_users', [
                'user_id' => $user, 'user_name' => "legacy-$user", 'user_pass' => '',
                'user_email' => "legacy-$user@example.com", 'user_dash_email' => '', 'user_timezone' => 'UTC',
                'user_time_register' => 1, 'install_hash' => '', 'user_hash' => '',
                'user_active' => $user === self::OFF ? 0 : 1, 'user_deleted' => 0,
            ]);
            self::insert('202_user_role', ['user_id' => $user, 'role_id' => $role]);
            self::insert('202_api_keys', ['user_id' => $user, 'api_key' => "legacy-key-$user", 'created_at' => 1]);
        }
        foreach (['legacy-key-read' => 'read', 'legacy-key-campaigns' => 'campaigns:read'] as $key => $scope) {
            $row = ['user_id' => self::OWNER, 'api_key' => $key, 'created_at' => 1, 'scope' => $scope];
            self::insert('202_api_keys', $row);
        }

        foreach ([self::OWNER => 1, self::OTHER => 2] as $user => $n) {
            $id = 933000 + $n;
            $name = "acct$n";
            $shop = "https://shop.example.com/$name";
            self::insert('202_aff_networks', [
                'aff_network_id' => $id, 'user_id' => $user, 'aff_network_name' => "$name category",
                'aff_network_deleted' => 0, 'aff_network_time' => 1,
            ]);
            self::insert('202_aff_campaigns', [
                'aff_campaign_id' => $id, 'user_id' => $user, 'aff_network_id' => $id,
                'aff_campaign_name' => "$name campaign", 'aff_campaign_url' => $shop, 'aff_campaign_payout' => 1.50,
                'aff_campaign_deleted' => 0, 'aff_campaign_id_public' => $id, 'aff_campaign_time' => 1,
            ]);
            self::insert('202_ppc_networks', [
                'ppc_network_id' => $id, 'user_id' => $user, 'ppc_network_name' => "$name source",
                'ppc_network_deleted' => 0, 'ppc_network_time' => 1,
            ]);
            self::insert('202_ppc_accounts', [
                'ppc_account_id' => $id, 'user_id' => $user, 'ppc_network_id' => $id,
                'ppc_account_name' => "$name account", 'ppc_account_deleted' => 0, 'ppc_account_time' => 1,
            ]);
            self::insert('202_ppc_account_pixels', [
                'pixel_id' => $id, 'ppc_account_id' => $id, 'pixel_type_id' => 1,
                'pixel_code' => "https://ads.example.com/$name.gif",
            ]);
            self::insert('202_landing_pages', [
                'landing_page_id' => $id, 'user_id' => $user, 'aff_campaign_id' => $id,
                'landing_page_nickname' => "$name page", 'landing_page_url' => "$shop-lp", 'landing_page_type' => 0,
                'landing_page_deleted' => 0, 'landing_page_id_public' => $id, 'landing_page_time' => 1,
            ]);
            self::insert('202_text_ads', [
                'text_ad_id' => $id, 'user_id' => $user, 'aff_campaign_id' => $id, 'text_ad_name' => "$name ad",
                'text_ad_headline' => 'h', 'text_ad_description' => 'd', 'text_ad_display_url' => 'shop.example.com',
                'text_ad_deleted' => 0, 'text_ad_time' => 1,
            ]);
            self::insert('202_trackers', [
                'tracker_id' => $id, 'user_id' => $user, 'aff_campaign_id' => $id, 'ppc_account_id' => $id,
                'tracker_id_public' => $id, 'click_cpc' => 0.25, 'tracker_time' => 1,
            ]);
            self::insert('202_rotators', [
                'id' => $id, 'user_id' => $user, 'public_id' => $id, 'name' => "$name redirector",
                'default_url' => "$shop-default",
            ]);
            self::insert('202_rotator_rules', [
                'id' => $id, 'rotator_id' => $id, 'rule_name' => "$name rule", 'splittest' => 0, 'status' => 1,
            ]);
            self::insert('202_rotator_rules_criteria', [
                'id' => $id, 'rotator_id' => $id, 'rule_id' => $id, 'type' => 'country', 'statement' => 'is',
                'value' => 'US',
            ]);
            self::insert('202_rotator_rules_redirects', [
                'id' => $id, 'rule_id' => $id, 'redirect_url' => "$shop-us", 'weight' => '0',
            ]);
        }
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function call(string $resource, ?string $key, string $method = 'GET'): array
    {
        return LegacySetup::answer(self::$db, $resource, $method, $key === null ? [] : ['apikey' => $key]);
    }

    private static function key(int $user): string
    {
        return 'legacy-key-' . $user;
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> [wrapper, name column, OWNER's name] */
    public static function resources(): array
    {
        return [
            'ads'            => ['ads', 'text_ad_name', 'acct1 ad'],
            'landingPages'   => ['landing_pages', 'landing_page_nickname', 'acct1 page'],
            'trafficSources' => ['traffic_sources', 'ppc_network_name', 'acct1 source'],
            'redirectors'    => ['smart_redirectors', 'name', 'acct1 redirector'],
            'trackers'       => ['trackers', 'tracker_id_public', '933001'],
            'campaigns'      => ['campaigns', 'aff_campaign_name', 'acct1 campaign'],
            'categories'     => ['categories', 'aff_network_name', 'acct1 category'],
        ];
    }

    /** @dataProvider resources */
    public function testAnswersOnlyTheKeysOwnRecordsUnderTheOldWrapper(
        string $wrapper,
        string $column,
        string $own
    ): void {
        $resource = (string) $this->dataName();
        [$status, $body] = self::call($resource, self::key(self::OWNER));

        self::assertSame(200, $status, json_encode($body));
        self::assertSame([$wrapper], array_keys($body));
        self::assertIsArray($body[$wrapper]);
        self::assertSame([$own], array_column($body[$wrapper], $column), 'only the key\'s own account\'s record');
        array_walk_recursive($body[$wrapper], static function (mixed $value, string|int $field) use ($resource): void {
            self::assertIsString($value, "$resource.$field is a string, as mysqli gave the old endpoint");
        });
        self::assertArrayNotHasKey('etag', $body[$wrapper][0]);
    }

    /** @dataProvider resources */
    public function testRefusesAMissingOrUnknownKeyUnderTheWrapper(string $wrapper): void
    {
        $resource = (string) $this->dataName();
        foreach ([null, '', 'not-a-key'] as $key) {
            [$status, $body] = self::call($resource, $key);
            self::assertSame(401, $status);
            self::assertSame(true, $body[$wrapper]['error']);
            self::assertSame(401, $body[$wrapper]['status']);
        }
    }

    /** @dataProvider resources */
    public function testRefusesADeactivatedUsersKey(string $wrapper): void
    {
        [$status, $body] = self::call((string) $this->dataName(), self::key(self::OFF));
        self::assertSame(401, $status);
        self::assertStringContainsString('deactivated', $body[$wrapper]['msg']);
    }

    /** @dataProvider resources */
    public function testRefusesARoleWithoutSetupAccess(string $wrapper): void
    {
        [$status, $body] = self::call((string) $this->dataName(), self::key(self::VIEWER));
        self::assertSame(403, $status);
        self::assertStringContainsString('access_to_setup_section', $body[$wrapper]['msg']);
    }

    /** @dataProvider resources */
    public function testIsReadOnly(string $wrapper): void
    {
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            [$status, $body] = self::call((string) $this->dataName(), self::key(self::OWNER), $method);
            self::assertSame(405, $status);
            self::assertSame(405, $body[$wrapper]['status']);
        }
    }

    /** @dataProvider resources */
    public function testAReadScopedKeyReadsEverything(string $wrapper): void
    {
        [$status, $body] = self::call((string) $this->dataName(), 'legacy-key-read');
        self::assertSame(200, $status, json_encode($body));
        self::assertCount(1, $body[$wrapper]);
    }

    /** @dataProvider resources */
    public function testAnAccountWithNoRecordsIsAnsweredNullAsBefore(string $wrapper): void
    {
        [$status, $body] = self::call((string) $this->dataName(), self::key(self::EMPTY));
        self::assertSame(200, $status);
        self::assertSame([$wrapper => null], $body);
    }

    public function testAnAreaScopedKeyReadsOnlyItsArea(): void
    {
        self::assertSame(200, self::call('campaigns', 'legacy-key-campaigns')[0]);
        [$status, $body] = self::call('ads', 'legacy-key-campaigns');
        self::assertSame(403, $status);
        self::assertStringContainsString('text-ads:read', $body['ads']['msg']);
    }

    public function testTrafficSourcesNestAccountsAndPixelsAsBefore(): void
    {
        $source = self::call('trafficSources', self::key(self::OWNER))[1]['traffic_sources'][0];

        self::assertSame('933001', $source['ppc_network_id']);
        self::assertSame(['acct1 account'], array_column($source['ppc_accounts'], 'ppc_account_name'));
        $pixels = $source['ppc_accounts'][0]['traffic_source_account_pixels'];
        self::assertSame(['https://ads.example.com/acct1.gif'], array_column($pixels, 'pixel_code'));
        self::assertSame('1', $pixels[0]['pixel_type_id']);
    }

    public function testRedirectorsNestRulesCriteriaAndRedirectsAsBefore(): void
    {
        $redirector = self::call('redirectors', self::key(self::OWNER))[1]['smart_redirectors'][0];

        self::assertSame('933001', $redirector['id']);
        self::assertArrayNotHasKey('rules', $redirector);
        $rule = $redirector['redirector_rules'][0];
        self::assertSame('acct1 rule', $rule['rule_name']);
        self::assertSame(['US'], array_column($rule['redirector_criteria'], 'value'));
        $urls = array_column($rule['redirector_redirects'], 'redirect_url');
        self::assertSame(['https://shop.example.com/acct1-us'], $urls);
        self::assertArrayNotHasKey('criteria', $rule);
    }

    public function testLandingPagesKeepTheBot202Field(): void
    {
        $page = self::call('landingPages', self::key(self::OWNER))[1]['landing_pages'][0];
        self::assertSame([], $page['bot202_fb_pixel_assistant']);
        self::assertSame('933001', $page['landing_page_id_public']);
    }

    public function testCampaignMoneyIsTheNumberTheOldStringHeld(): void
    {
        $campaign = self::call('campaigns', self::key(self::OWNER))[1]['campaigns'][0];
        self::assertSame(1.5, (float) $campaign['aff_campaign_payout']);
        // v3 leaves the flag out; the old SELECT * answered it, always 0 for a listed record.
        self::assertSame('0', $campaign['aff_campaign_deleted']);
    }

    public function testVersionNeedsAKeyAndAnswersTheInstallsVersion(): void
    {
        require_once dirname(__DIR__, 3) . '/202-config/version.php';
        self::assertSame([200, ['version' => \PROSPER202_VERSION]], self::call('version', self::key(self::OWNER)));
        // Any live key: version reads no records.
        self::assertSame(200, self::call('version', self::key(self::VIEWER))[0]);
        self::assertSame(401, self::call('version', null)[0]);
        self::assertSame(401, self::call('version', self::key(self::OFF))[0]);
    }

    public function testNoDatabaseIsA503(): void
    {
        [$status, $body] = LegacySetup::answer(null, 'campaigns', 'GET', ['apikey' => self::key(self::OWNER)]);
        self::assertSame(503, $status);
        self::assertSame(503, $body['campaigns']['status']);
    }
}
