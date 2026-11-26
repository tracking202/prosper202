<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\AffNetworksController;
use Api\V3\Controllers\CampaignsController;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * Where a Library record is used, through api/v3/index.php itself, served by
 * `php -S` over the scratch database: `?with=usage` on a list,
 * `GET /campaigns/{id}/uses`, a delete's dry run naming `in_use_by`, the
 * Setup permission on the new route, a strict `with`, and another
 * account's record as a 404.
 *
 * @group integration
 */
final class LibraryUsageServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    // An Admin: Setup, and remove_campaign for the delete preview.
    private const MANAGER = 6201;
    private const VIEWER = 6202;
    private const OTHER = 6203;

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';
    private static string $state = '';
    /** @var array<string, string> */
    private static array $keys = [];
    /** @var array<string, int> */
    private static array $ids = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $root = dirname(__DIR__, 3);
        if (!is_file($root . '/202-config.php')) {
            self::$db = null;

            return;
        }
        self::seed();

        self::$state = sys_get_temp_dir() . '/p202-library-usage-' . bin2hex(random_bytes(6));
        mkdir(self::$state, 0700);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        self::$log = (string) tempnam(sys_get_temp_dir(), 'p202srv');
        $env = getenv();
        $env['P202_SERVER_STATE_DIR'] = self::$state;
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . self::$log,
                '-S', "127.0.0.1:$port", '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env
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
        if (self::$state !== '') {
            exec('rm -rf ' . escapeshellarg(self::$state));
        }
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    private static function cleanUp(): void
    {
        $tables = [
            '202_trackers', '202_landing_pages', '202_aff_campaigns', '202_aff_networks',
            '202_api_keys', '202_user_role', '202_users_pref', '202_users',
        ];
        foreach ([self::MANAGER, self::VIEWER, self::OTHER] as $user) {
            foreach ($tables as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
    }

    private static function seed(): void
    {
        self::cleanUp();
        $seeder = new DataSeeder(self::requireScratch());
        $seeder->seedRoles();
        $seeder->seedPermissions();
        $seeder->seedRolePermissions();
        foreach ([self::MANAGER => 2, self::VIEWER => 5, self::OTHER => 2] as $user => $role) {
            self::user($user);
            self::q("INSERT INTO 202_user_role (user_id, role_id) VALUES ($user, $role)");
            $key = bin2hex(random_bytes(32));
            self::q("INSERT INTO 202_api_keys (user_id, api_key, scope, label, created_at) VALUES ($user, "
                . self::str($key) . ', NULL, NULL, ' . time() . ')');
            self::$keys[$user] = $key;
        }
        foreach ([self::MANAGER => 'mine', self::OTHER => 'theirs'] as $user => $name) {
            $net = (int) (new AffNetworksController(self::requireScratch(), $user))
                ->create(['aff_network_name' => 'net'])['data']['aff_network_id'];
            self::$ids[$name] = (int) (new CampaignsController(self::requireScratch(), $user))->create([
                'aff_campaign_name' => $name,
                'aff_campaign_url' => 'https://o.example',
                'aff_campaign_payout' => 1.0,
                'aff_network_id' => $net,
            ])['data']['aff_campaign_id'];
        }
        $offer = self::$ids['mine'];
        self::$ids['lp'] = self::insert('INSERT INTO 202_landing_pages (user_id, aff_campaign_id,'
            . " landing_page_nickname, landing_page_url, landing_page_time) VALUES (" . self::MANAGER
            . ", $offer, 'Spring LP', 'https://lp.example/s', 1)");
        self::$ids['tracker'] = self::insert('INSERT INTO 202_trackers (user_id, tracker_id_public,'
            . ' aff_campaign_id, text_ad_id, ppc_account_id, landing_page_id, click_cloaking, tracker_time)'
            . ' VALUES (' . self::MANAGER . ", 62999, $offer, 0, 0, 0, 0, 1)");
        // Another account's link naming this account's offer: never counted.
        self::q('INSERT INTO 202_trackers (user_id, tracker_id_public, aff_campaign_id, text_ad_id,'
            . ' ppc_account_id, landing_page_id, click_cloaking, tracker_time)'
            . ' VALUES (' . self::OTHER . ", 62998, $offer, 0, 0, 0, 0, 1)");
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} */
    private static function call(string $method, string $path, int $user): array
    {
        $ch = curl_init(self::$base . '/api/v3' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::$keys[$user]]]);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode($raw, true);

        $context = $raw . "\nserver log:\n" . (string) @file_get_contents(self::$log);

        return [$status, is_array($decoded) ? $decoded : [], $context];
    }

    public function testAListCountsTheUsesOfEachOfItsRows(): void
    {
        self::requireScratch();
        [$status, $body, $raw] = self::call('GET', '/campaigns?with=usage', self::MANAGER);
        self::assertSame(200, $status, $raw);
        self::assertCount(1, $body['data'], $raw);
        self::assertSame(
            ['trackers' => 1, 'landing_pages' => 1, 'text_ads' => 0, 'rotators' => 0, 'total' => 2],
            $body['data'][0]['usage']
        );

        [$status, $body, $raw] = self::call('GET', '/campaigns', self::MANAGER);
        self::assertSame(200, $status, $raw);
        self::assertArrayNotHasKey('usage', $body['data'][0], 'opt-in');

        [$status, $body, $raw] = self::call('GET', '/landing-pages?with=health,usage', self::MANAGER);
        self::assertSame(200, $status, $raw);
        self::assertSame('unchecked', $body['data'][0]['health']['status'], $raw);
        self::assertSame(0, $body['data'][0]['usage']['total'], $raw);
    }

    public function testOneRecordsUsesAndItsDeletePreviewNameTheSameRecords(): void
    {
        self::requireScratch();
        $offer = self::$ids['mine'];
        [$status, $body, $raw] = self::call('GET', "/campaigns/$offer/uses", self::MANAGER);
        self::assertSame(200, $status, $raw);
        $tracker = self::$ids['tracker'];
        self::assertSame(2, $body['data']['total'], $raw);
        self::assertSame(
            ['count' => 1, 'records' => [['id' => $tracker, 'name' => null, 'path' => "/trackers/$tracker"]]],
            $body['data']['uses']['trackers']
        );
        $uses = $body['data']['uses'];

        [$status, $body, $raw] = self::call('DELETE', "/campaigns/$offer?dry_run=1", self::MANAGER);
        self::assertSame(200, $status, $raw);
        self::assertTrue($body['data']['dry_run'], $raw);
        self::assertSame($uses, $body['data']['in_use_by'], $raw);

        [$status, $body, $raw] = self::call('GET', "/campaigns/$offer", self::MANAGER);
        self::assertSame(200, $status, 'the preview deleted nothing: ' . $raw);
    }

    public function testTheRoutesRefuseWhatTheyCannotAnswer(): void
    {
        self::requireScratch();
        [$status, $body, $raw] = self::call('GET', '/campaigns?with=usage,clicks', self::MANAGER);
        self::assertSame(422, $status, $raw);
        self::assertSame('A comma list of: usage.', $body['field_errors']['with'] ?? null, $raw);

        [$status, , $raw] = self::call('GET', '/campaigns/' . self::$ids['theirs'] . '/uses', self::MANAGER);
        self::assertSame(404, $status, 'another account\'s offer: ' . $raw);

        [$status, $body, $raw] = self::call('GET', '/campaigns/' . self::$ids['mine'] . '/uses', self::VIEWER);
        self::assertSame(403, $status, 'a role without Setup: ' . $raw);
        self::assertStringContainsString('access_to_setup_section', $raw);

        [$status, , $raw] = self::call('GET', '/campaigns/2e0/uses', self::MANAGER);
        self::assertSame(404, $status, 'a path id is read strictly: ' . $raw);
    }
}
