<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Setup\DniNetworks;

/**
 * Account › API integrations shows the signed-in account's own Direct Network
 * Integrations, and no network's key (CLAUDE.md #27, #5).
 *
 * The page read `WHERE user_id = '1'`: every admin of an install saw account
 * 1's networks, never their own, with each key in a Reveal attribute and the
 * importing ones' keys in the progress poller's inline JSON.
 *
 * A (USER) holds access_to_api_integrations and one network; B (OTHER) holds
 * another, and account 1 a third. Each page runs as A in a process of its
 * own (fixtures/page-runner.php), with the hosted DNI service unreachable (a
 * proxy on a closed port), so nothing leaves the machine: the page then
 * offers no form to add a network, and the progress endpoint answers 502
 * once it has built its request. DniNetworksReadOnlyTheAccountsTest is the
 * structural half; tests/live/dni-own-account.sh drives the forms over HTTP.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class ApiIntegrationsAccountScopeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990081;
    private const OTHER = 990082;
    private const ROLE = 990081;
    private const MY_KEY = 'my-own-dni-key-0001-wxyz';
    private const THEIR_KEY = 'their-dni-key-0002-abcd';
    /** A network of account 1's, the one the page used to list: a row only, user 1 need not exist. */
    private const OWNERS_NETWORK = 990083;
    private const OWNERS_KEY = 'owner-dni-key-0003-efgh';
    private const PAGE = '202-account/api-integrations.php';
    private const NOT_FOUND = 'That network was not found; nothing was changed.';
    private const NOT_YOURS = 'That DNI network is not one of yours, or it was removed.';

    private static string $sessions = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    /** @var list<int> the permission rows this class added */
    private static array $addedPermissions = [];

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any page ran.'
                . ' tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once $root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        self::seed();
        self::$sessions = sys_get_temp_dir() . '/p202-page-runner-dni-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            if (self::$versions !== null) {
                self::q('DELETE FROM 202_version');
                foreach (self::$versions as $version) {
                    self::row('202_version', ['version' => $version]);
                }
            }
        }
        if (self::$sessions !== '' && is_dir(self::$sessions)) {
            array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
            rmdir(self::$sessions);
        }
    }

    private static function cleanUp(): void
    {
        $tables = ['202_user_role', '202_users_pref', '202_users', '202_dni_networks', '202_aff_networks'];
        foreach ([self::USER, self::OTHER] as $user) {
            foreach ($tables as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
        self::q('DELETE FROM 202_dni_networks WHERE id = ' . self::OWNERS_NETWORK);
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
        foreach (self::$addedPermissions as $permission) {
            self::q('DELETE FROM 202_permissions WHERE permission_id = ' . $permission);
        }
        self::$addedPermissions = [];
    }

    private static function seed(): void
    {
        foreach ([self::USER, self::OTHER] as $user) {
            self::user($user);
            self::row('202_users_pref', ['user_id' => $user]);
        }
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner'"
            . ' WHERE user_id IN (' . self::USER . ', ' . self::OTHER . ')');
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'integrations test']);
        $name = 'access_to_api_integrations';
        $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE permission_description = '$name'");
        $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
        if ($permission === null) {
            $permission = self::insert("INSERT INTO 202_permissions SET permission_description = '$name'");
            self::$addedPermissions[] = $permission;
        }
        self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => self::ROLE]);

        // All still importing, so all would be in the poller's data.
        self::network(self::OWNERS_NETWORK, 1, 'owner', 'Owner DNI', self::OWNERS_KEY);
        self::network(self::OTHER, self::OTHER, 'their', 'Their DNI', self::THEIR_KEY);
        self::network(self::USER, self::USER, 'mine', 'My DNI', self::MY_KEY);
    }

    private static function network(int $id, int $user, string $networkId, string $name, string $key): void
    {
        self::row('202_dni_networks', [
            'id' => $id, 'user_id' => $user, 'networkId' => $networkId, 'shortDescription' => $name, 'favIcon' => '',
            'apiKey' => $key, 'name' => $name, 'type' => 'HasOffers', 'time' => time(), 'processed' => 0,
        ]);
    }

    /**
     * What a page printed, run as $user, with the hosted service unreachable.
     *
     * @param array<string, string> $get
     * @param array<string, string> $post a POST's fields, when it is one
     */
    private static function page(string $page, array $get = [], array $post = [], int $user = self::USER): string
    {
        self::requireScratch();
        $closed = 'http://127.0.0.1:9';
        $env = [
            'P202_TEST_REPORT_USER' => (string) $user,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => (string) json_encode((object) $get),
            'P202_TEST_POST' => (string) json_encode((object) $post),
            'https_proxy' => $closed, 'HTTPS_PROXY' => $closed, 'http_proxy' => $closed, 'HTTP_PROXY' => $closed,
            'all_proxy' => '', 'ALL_PROXY' => '', 'no_proxy' => '', 'NO_PROXY' => '',
        ] + getenv();
        $runner = __DIR__ . '/fixtures/page-runner.php';
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', $runner, $page],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        self::assertNotSame(2, $code, "the runner could not set up: $err");
        self::assertStringNotContainsString('Fatal error', $out . $err, "$page: $err");
        self::assertStringNotContainsStringIgnoringCase('database error', $out, "$page: $err");

        return $out;
    }

    private static function assertNoKey(string $out, string $page): void
    {
        self::assertStringNotContainsString(self::OWNERS_KEY, $out, "$page put account 1's DNI key into the page");
        self::assertStringNotContainsString(self::THEIR_KEY, $out, "$page put another account's DNI key into the page");
        self::assertStringNotContainsString(self::MY_KEY, $out, "$page put the account's own DNI key into the page");
        self::assertStringNotContainsString('data-p202-value', $out, "$page has a key behind Reveal");
        self::assertStringNotContainsString('var processing', $out, 'the poller is handed no network data');
    }

    /** @return array<string, string> a network's row as stored */
    private static function stored(int $id): array
    {
        $found = self::$db->query("SELECT networkId, name, apiKey, processed FROM 202_dni_networks WHERE id = $id");
        self::assertInstanceOf(\mysqli_result::class, $found, (string) self::$db->error);
        $row = $found->fetch_assoc();
        self::assertIsArray($row);

        return array_map('strval', $row);
    }

    public function testThePageListsTheAccountsOwnNetworksAndNoKey(): void
    {
        $out = self::page(self::PAGE);
        self::assertStringContainsString('My DNI (HasOffers)', $out, 'the account\'s own network is listed');
        self::assertStringNotContainsString('Their DNI', $out, 'another account\'s network is not');
        self::assertStringNotContainsString('Owner DNI', $out, 'nor account 1\'s, which the page listed to everyone');
        self::assertStringContainsString('Saved, ends in wxyz', $out, 'the key is shown as saved, by its last four');
        $progress = 'data-dni-progress="';
        self::assertStringContainsString($progress . self::USER . '"', $out, 'its import progress is shown');
        self::assertStringNotContainsString($progress . self::OTHER . '"', $out);
        self::assertStringNotContainsString($progress . self::OWNERS_NETWORK . '"', $out);
        self::assertStringContainsString('1 network connected', $out);
        self::assertNoKey($out, 'API integrations');
    }

    public function testAnotherAccountsNetworkOpensNoEdit(): void
    {
        $out = self::page(self::PAGE, ['edit_dni_network' => (string) self::OTHER]);
        self::assertStringContainsString(self::NOT_YOURS, $out);
        self::assertStringNotContainsString('Their DNI', $out);
        self::assertStringNotContainsString('name="editing_dni_network_id"', $out);
        self::assertNoKey($out, 'the edit of another account\'s network');

        $own = self::page(self::PAGE, ['edit_dni_network' => (string) self::USER]);
        self::assertStringNotContainsString('not one of yours', $own, 'the account\'s own opens');
        self::assertNoKey($own, 'the edit of the account\'s own network');

        $typo = self::page(self::PAGE, ['edit_dni_network' => self::USER . 'abc']);
        self::assertStringContainsString(self::NOT_YOURS, $typo, 'an id read exactly: `12abc` is not network 12');
    }

    /** @return array<string, string> an edit's fields, the key left empty to keep the saved one */
    private static function edit(int $id, string $network): array
    {
        return [
            'token' => 'SESSION', 'dni_network' => $network, 'dni_network_type' => 'HasOffers',
            'dni_network_name' => 'Renamed (HasOffers)', 'dni_network_api_key' => '',
            'dni_network_affiliate_id' => 'null',
            'editing_dni_network' => '1', 'editing_dni_network_id' => (string) $id,
        ];
    }

    public function testAnEditThatKeepsTheKeyKeepsOnlyTheAccountsOwn(): void
    {
        $theirs = self::stored(self::OTHER);
        $out = self::page(self::PAGE, [], self::edit(self::OTHER, 'their'));
        self::assertStringContainsString(self::NOT_FOUND, $out, 'another account\'s key is not kept for this one');
        self::assertSame($theirs, self::stored(self::OTHER));
        self::assertNoKey($out, 'the refused edit');

        $mine = self::stored(self::USER);
        $out = self::page(self::PAGE, [], self::edit(self::USER, 'their'));
        $different = 'A different network needs its own API key: paste it to save the change.';
        self::assertStringContainsString($different, $out, 'a saved key is not sent to another network');
        self::assertSame($mine, self::stored(self::USER));
        self::assertNoKey($out, 'the refused edit');
    }

    public function testRemovingAnotherAccountsNetworkRemovesNothing(): void
    {
        self::page(self::PAGE, [], ['token' => 'SESSION', 'delete_dni_network' => (string) self::OTHER]);
        self::assertSame('Their DNI', self::stored(self::OTHER)['name']);
    }

    public function testTheReadsAreTheAccountsAndTheProgressRequestCarriesOnlyItsKeys(): void
    {
        $db = self::requireScratch();
        $listed = DniNetworks::listFor($db, self::USER);
        self::assertSame([self::USER], array_column($listed, 'id'));
        self::assertArrayNotHasKey('apiKey', $listed[0]);
        self::assertNotContains(self::MY_KEY, $listed[0]);
        self::assertNull(DniNetworks::oneFor($db, self::USER, self::OTHER));
        self::assertSame(['', self::NOT_FOUND], DniNetworks::keptKey($db, self::USER, self::OTHER, 'their'));
        self::assertSame(['', self::NOT_FOUND], DniNetworks::keptKey($db, self::USER, self::OWNERS_NETWORK, 'owner'));
        self::assertSame([self::MY_KEY, null], DniNetworks::keptKey($db, self::USER, self::USER, 'mine'));
        $elsewhere = DniNetworks::keptKey($db, self::USER, self::USER, 'their');
        self::assertSame('', $elsewhere[0], 'a key is kept only for the network it was saved for');

        self::q("UPDATE 202_users SET install_hash = 'their-hash' WHERE user_id = " . self::OTHER);
        self::q("UPDATE 202_users SET install_hash = 'my-hash' WHERE user_id = " . self::USER);
        $request = DniNetworks::progressRequest($db, self::USER, 'host');
        self::assertNull(DniNetworks::progressRequest($db, 990089, 'host'), 'an account with no row asks nothing');
        self::assertSame(['host' => 'host', 'install_hash' => 'my-hash', 'networks' => [
            ['id' => self::USER, 'networkId' => 'mine', 'api_key' => self::MY_KEY, 'type' => 'HasOffers'],
        ]], $request);
    }

    public function testTheProgressEndpointAsksAboutTheAccountsOwnNetworks(): void
    {
        $endpoint = '202-account/ajax/dni.php';
        $out = self::page($endpoint, ['getProgress' => 'true']);
        $sent = 'it built a request for the account\'s importing network and sent it';
        self::assertSame('The DNI service could not be reached.', trim($out), $sent);

        self::q('UPDATE 202_dni_networks SET processed = 1 WHERE id = ' . self::USER);
        try {
            $none = self::page($endpoint, ['getProgress' => 'true']);
        } finally {
            self::q('UPDATE 202_dni_networks SET processed = 0 WHERE id = ' . self::USER);
        }
        self::assertSame('{"data":[]}', trim($none), 'another account\'s importing network is not asked about');

        $refused = self::page($endpoint, ['getProgress' => 'true'], [], self::OTHER);
        $permission = "does not have the 'access_to_api_integrations' permission";
        self::assertStringContainsString($permission, $refused, 'the endpoint asks for the page\'s permission');
    }
}
