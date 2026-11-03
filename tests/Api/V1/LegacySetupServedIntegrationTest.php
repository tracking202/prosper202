<?php

declare(strict_types=1);

namespace Tests\Api\V1;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * The restored paid 1.9.56 addresses through the real files: each
 * api/v1/{resource}/index.php and setup-endpoint.php's boot, status and
 * headers, and the retired Google feed's 410, served by `php -S` over the
 * 202-config.php the integration job writes (tests/run-integration-suites.sh).
 * LegacySetupIntegrationTest covers the answers' rules; this covers the wiring.
 *
 * @group integration
 */
final class LegacySetupServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 9341;
    private const KEY = 'legacy-served-key';

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $root = dirname(__DIR__, 3);
        if (!is_file($root . '/202-config.php')) {
            self::$db = null; // no config for the served tree: skipped, as without a database

            return;
        }
        $seeder = new DataSeeder(self::$db);
        $seeder->seedRoles();
        $seeder->seedPermissions();
        $seeder->seedRolePermissions();
        require_once $root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        self::seed();

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        self::$log = (string) tempnam(sys_get_temp_dir(), 'p202srv');
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . self::$log,
                '-S', "127.0.0.1:$port", '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        self::$base = "http://127.0.0.1:$port";
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);
                break;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (self::$log !== '') {
            @unlink(self::$log);
        }
        if (self::$db !== null) {
            self::cleanUp();
            if (self::$versions !== null) {
                self::q('DELETE FROM 202_version');
                foreach (self::$versions as $version) {
                    self::row('202_version', ['version' => $version]);
                }
            }
            self::$db->close();
            self::$db = null;
        }
    }

    private static function cleanUp(): void
    {
        foreach (['202_aff_campaigns', '202_aff_networks', '202_api_keys', '202_user_role', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
    }

    private static function seed(): void
    {
        self::user(self::USER);
        self::q('UPDATE 202_users SET user_active = 1, user_deleted = 0 WHERE user_id = ' . self::USER);
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => 2]);
        self::row('202_api_keys', ['user_id' => self::USER, 'api_key' => self::KEY, 'created_at' => 1]);
        self::row('202_aff_networks', [
            'aff_network_id' => 934101, 'user_id' => self::USER, 'aff_network_name' => 'Store category',
            'aff_network_deleted' => 0, 'aff_network_time' => 1,
        ]);
        self::row('202_aff_campaigns', [
            'aff_campaign_id' => 934101, 'user_id' => self::USER, 'aff_network_id' => 934101,
            'aff_campaign_name' => 'Spring sale', 'aff_campaign_url' => 'https://shop.example.com/',
            'aff_campaign_payout' => 12.5, 'aff_campaign_foreign_payout' => 0,
            'aff_campaign_deleted' => 0, 'aff_campaign_id_public' => 934101,
            'aff_campaign_time' => 1,
        ]);
    }

    protected function setUp(): void
    {
        self::requireScratch();
        if (self::$base === '') {
            self::fail('the endpoints could not be served');
        }
    }

    /** @return array{int, string, string} status, headers, body */
    private static function request(string $path, string $method = 'GET', ?string $basicAuth = null): array
    {
        $ch = curl_init(self::$base . $path);
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method];
        if ($basicAuth !== null) {
            $options[CURLOPT_USERPWD] = $basicAuth;
        }
        curl_setopt_array($ch, $options);
        $out = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        return [$status, substr($out, 0, $size), substr($out, $size)];
    }

    private function serverLog(): string
    {
        return "\nserver log:\n" . (string) @file_get_contents(self::$log);
    }

    /** @return array<string, array{0: string, 1: string}> resource => [wrapper, what this account has] */
    public static function resources(): array
    {
        return [
            'ads'            => ['ads', 'none'],
            'landingPages'   => ['landing_pages', 'none'],
            'trafficSources' => ['traffic_sources', 'none'],
            'redirectors'    => ['smart_redirectors', 'none'],
            'trackers'       => ['trackers', 'none'],
            'campaigns'      => ['campaigns', 'some'],
            'categories'     => ['categories', 'some'],
        ];
    }

    /** @dataProvider resources */
    public function testEachAddressIsServedAsJsonUnderItsWrapper(string $wrapper, string $has): void
    {
        $resource = (string) $this->dataName();
        [$status, $headers, $body] = self::request("/api/v1/$resource/?apikey=" . self::KEY);

        self::assertSame(200, $status, $body . $this->serverLog());
        self::assertMatchesRegularExpression('~^Content-Type: application/json~mi', $headers);
        self::assertMatchesRegularExpression('~^Cache-Control:[^\r\n]*\bno-store\b~mi', $headers);
        $json = json_decode($body, true);
        self::assertIsArray($json, $body);
        self::assertSame([$wrapper], array_keys($json));
        if ($has === 'none') {
            self::assertNull($json[$wrapper]);
        } else {
            self::assertCount(1, $json[$wrapper]);
        }
    }

    public function testACampaignIsAnsweredWithTheOldStrings(): void
    {
        $json = json_decode(self::request('/api/v1/campaigns/?apikey=' . self::KEY)[2], true);
        self::assertSame('Spring sale', $json['campaigns'][0]['aff_campaign_name']);
        self::assertSame('934101', $json['campaigns'][0]['aff_campaign_id']);
    }

    public function testAWrongKeyIsA401WithTheOldErrorObject(): void
    {
        [$status, , $body] = self::request('/api/v1/campaigns/?apikey=not-a-key');
        self::assertSame(401, $status, $body . $this->serverLog());
        $json = json_decode($body, true);
        self::assertSame(true, $json['campaigns']['error']);
        self::assertSame(401, $json['campaigns']['status']);
    }

    public function testAWriteIsA405NamingGet(): void
    {
        [$status, $headers] = self::request('/api/v1/campaigns/?apikey=' . self::KEY, 'POST');
        self::assertSame(405, $status);
        self::assertMatchesRegularExpression('~^Allow: GET\r?$~mi', $headers);
    }

    public function testVersionAnswersWithAKeyAndNotWithout(): void
    {
        [$status, , $body] = self::request('/api/v1/version/?apikey=' . self::KEY);
        self::assertSame(200, $status, $body . $this->serverLog());
        self::assertSame(['version' => PROSPER202_VERSION], json_decode($body, true));
        self::assertSame(401, self::request('/api/v1/version/')[0]);
    }

    public function testTheOldGoogleFeedIsGoneAndSaysWhereItsReplacementIs(): void
    {
        [$status, $headers, $body] = self::request(
            '/tracking202/static/feeds/google/offlineconversions/?cn=Purchase&ac=7',
            'GET',
            'someone:secret'
        );
        self::assertSame(410, $status, $body . $this->serverLog());
        self::assertMatchesRegularExpression('~^Content-Type: text/plain~mi', $headers);
        self::assertStringContainsString('Setup > Offline Conversions (/tracking202/setup/', $body);
    }
}
