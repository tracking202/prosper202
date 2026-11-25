<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * /api/v3 signed in by the pages' session cookie, served by `php -S` over
 * the scratch database: a browser signs in through 202-login.php as a person
 * does, then reads and writes through the API with no key. A write without
 * the session's token in X-P202-CSRF is refused and changes nothing;
 * switching the user off, or a password change in another browser, ends the
 * access on the next call; a Bearer key is answered as before, whatever
 * cookie rides along.
 *
 * Needs a 202-config.php naming the scratch database (the server reads it)
 * and P202_TEST_DB_*.
 *
 * @group integration
 */
final class SessionSignInServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 4201;
    private const NAME = 'sessapi4201';
    private const PASSWORD = 'session-api-pw-1';
    private const LICENCE_KEY = 'session-api-licence';
    private const UA = 'Mozilla/5.0 (session sign-in test)';

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $dir = '';
    private static string $key = '';
    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);
        if (!is_file($root . '/202-config.php') || !self::connectScratch()) {
            self::$db = null;

            return;
        }
        require_once $root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        self::$versions = array_column($found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [], 'version');
        // The pages send every request to the upgrade while the schema names another version.
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        $seeder = new DataSeeder(self::requireScratch());
        $seeder->seedRoles();
        $seeder->seedPermissions();
        $seeder->seedRolePermissions();

        self::$dir = sys_get_temp_dir() . '/p202-session-api-' . bin2hex(random_bytes(6));
        foreach ([self::$dir, self::$dir . '/sessions', self::$dir . '/state'] as $made) {
            if (!mkdir($made, 0700)) {
                throw new \RuntimeException('could not create ' . $made);
            }
        }
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        $env = getenv();
        $env['P202_SERVER_STATE_DIR'] = self::$dir . '/state';
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . self::$dir . '/server.log',
                '-d', 'session.save_path=' . self::$dir . '/sessions', '-S', "127.0.0.1:$port", '-t', $root],
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
        if (self::$dir !== '') {
            exec('rm -rf ' . escapeshellarg(self::$dir));
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

    protected function setUp(): void
    {
        if (self::$db === null || !is_resource(self::$server)) {
            self::markTestSkipped('Needs a 202-config.php naming the scratch database, and P202_TEST_DB_*.');
        }
        self::cleanUp();
        self::q('INSERT INTO 202_users SET user_id = ' . self::USER . ', user_name = ' . self::str(self::NAME) . ','
            . " user_email = 'sessapi@example.com', user_dash_email = '',"
            . ' user_pass = ' . self::str(password_hash(self::PASSWORD, PASSWORD_DEFAULT)) . ','
            . " user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = '',"
            . " secret_key = REPEAT('k', 48), p202_customer_api_key = " . self::str(self::LICENCE_KEY));
        self::row('202_users_pref', ['user_id' => self::USER]);
        // Campaign manager: Setup, so the write below is one the role may make.
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => 3]);
        // The licence was confirmed a minute ago; the sign-in gets no answer here and is within its grace period.
        self::q('INSERT INTO 202_licence_answers (key_sha256, valid_yes, valid_at)'
            . ' VALUES (SHA2(' . self::str(self::LICENCE_KEY) . ', 256), 1, UNIX_TIMESTAMP() - 60)');
        self::$key = bin2hex(random_bytes(32));
        self::q('INSERT INTO 202_api_keys (user_id, api_key, scope, label, created_at) VALUES ('
            . self::USER . ', ' . self::str(self::$key) . ', NULL, NULL, ' . time() . ')');
    }

    private static function cleanUp(): void
    {
        foreach (['202_aff_networks', '202_api_keys', '202_auth_keys', '202_user_role', '202_users_pref', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
        self::q('DELETE FROM 202_users_log WHERE user_name = ' . self::str(self::NAME));
        self::q('DELETE FROM 202_licence_answers WHERE key_sha256 = SHA2(' . self::str(self::LICENCE_KEY) . ', 256)');
    }

    /**
     * One request with the cookie jar $jar ('' for none).
     *
     * @param list<string> $headers
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    private static function call(string $method, string $path, string $jar, array $headers = [], ?string $body = null, ?array $form = null): array
    {
        $ch = curl_init(self::$base . $path);
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERAGENT => self::UA, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'];
        if ($jar !== '') {
            $options[CURLOPT_COOKIEFILE] = $jar;
            $options[CURLOPT_COOKIEJAR] = $jar;
        }
        if ($form !== null) {
            $options[CURLOPT_POSTFIELDS] = http_build_query($form);
        } elseif ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        self::assertIsString($raw, 'the server answered nothing: ' . curl_error($ch));
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode($raw, true);

        return [$status, is_array($decoded) ? $decoded : null, $raw];
    }

    /** A cookie jar signed in through 202-login.php, as a browser signs in. */
    private static function signIn(): string
    {
        $jar = self::$dir . '/jar-' . bin2hex(random_bytes(4));
        [, , $page] = self::call('GET', '/202-login.php', $jar);
        self::assertSame(1, preg_match('/name="token" value="([^"]+)"/', $page, $m), 'the sign-in form carries a token');
        [$status] = self::call('POST', '/202-login.php', $jar, [], null,
            ['token' => $m[1], 'user_name' => self::NAME, 'user_pass' => self::PASSWORD]);
        self::assertSame(302, $status, 'the sign-in redirects into the app');
        [$me, $body, $raw] = self::call('GET', '/api/v3/me', $jar);
        self::assertSame(200, $me, $raw);
        self::assertSame('session', $body['data']['auth']['method'] ?? null);

        return $jar;
    }

    /**
     * The pages' licence gate (AUTH::require_valid_api_key()) as it stands in a
     * browser whose key the licence service confirmed: the session remembers
     * it. Without this the gate asks my.tracking202.com about the test's key,
     * which refuses it wherever the service is reachable (CI) and is waved
     * through on the seeded grace answer where it is not.
     */
    private static function licenceConfirmed(string $jar): void
    {
        $id = null;
        foreach (file($jar, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $fields = explode("\t", $line);
            if (count($fields) === 7 && $fields[5] === 'PHPSESSID') {
                $id = $fields[6];
            }
        }
        self::assertIsString($id, 'the jar holds the session cookie');
        self::assertSame(1, preg_match('/^[A-Za-z0-9,-]+$/', $id), 'a session id names a file');
        $file = self::$dir . '/sessions/sess_' . $id;
        self::assertFileExists($file);
        // The php serializer writes name|value; entries one after another.
        self::assertNotFalse(file_put_contents($file, 'valid_key|b:1;', FILE_APPEND));
    }

    private static function csrf(string $jar): string
    {
        [, $body] = self::call('GET', '/api/v3/me', $jar);
        $token = $body['data']['auth']['csrf_token'] ?? null;
        self::assertIsString($token);
        self::assertNotSame('', $token);

        return $token;
    }

    private static function networks(): int
    {
        $result = self::$db->query('SELECT COUNT(*) FROM 202_aff_networks WHERE user_id = ' . self::USER . ' AND aff_network_deleted = 0');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (int) $result->fetch_row()[0];
    }

    public function testASignedInBrowserReadsAndWritesWithTheSessionsToken(): void
    {
        $jar = self::signIn();
        [, $me] = self::call('GET', '/api/v3/me', $jar);
        self::assertSame(self::USER, $me['data']['user']['user_id']);
        self::assertTrue($me['data']['flags']['can_edit_campaigns']);
        $token = self::csrf($jar);

        foreach ([[], ['X-P202-CSRF: not-the-token'], ['X-P202-CSRF:'], ['X-P202-CSRF: ' . strtoupper($token)]] as $headers) {
            [$status, $body] = self::call('POST', '/api/v3/aff-networks', $jar, $headers, '{"aff_network_name":"Session net"}');
            self::assertSame(403, $status, 'a write without the session\'s token: ' . json_encode($headers));
            self::assertStringContainsString('X-P202-CSRF', (string) ($body['message'] ?? ''));
        }
        self::assertSame(0, self::networks(), 'a refused write changed nothing');

        [$status, $created, $raw] = self::call('POST', '/api/v3/aff-networks', $jar, ['X-P202-CSRF: ' . $token], '{"aff_network_name":"Session net"}');
        self::assertSame(201, $status, $raw);
        self::assertSame(self::USER, $created['data']['user_id']);
        $id = (int) $created['data']['aff_network_id'];

        [$status] = self::call('PUT', "/api/v3/aff-networks/$id", $jar, [], '{"aff_network_name":"Renamed"}');
        self::assertSame(403, $status, 'PUT asks for the token too');
        [$status] = self::call('DELETE', "/api/v3/aff-networks/$id", $jar);
        self::assertSame(403, $status, 'DELETE asks for the token too');
        self::assertSame(1, self::networks());
        [$status, $updated, $raw] = self::call('PUT', "/api/v3/aff-networks/$id", $jar, ['X-P202-CSRF: ' . $token], '{"aff_network_name":"Renamed"}');
        self::assertSame(200, $status, $raw);
        self::assertSame('Renamed', $updated['data']['aff_network_name']);
        // The token answers only for the session: the role's own gates still hold
        // (a Campaign manager holds no remove_campaign_category).
        [$status, $refused] = self::call('DELETE', "/api/v3/aff-networks/$id", $jar, ['X-P202-CSRF: ' . $token]);
        self::assertSame(403, $status);
        self::assertStringContainsString('remove_campaign_category', (string) ($refused['message'] ?? ''));
        self::assertSame(1, self::networks());
    }

    public function testSwitchingTheUserOffEndsTheAccessOnTheNextCall(): void
    {
        $jar = self::signIn();
        self::q('UPDATE 202_users SET user_active = 0 WHERE user_id = ' . self::USER);
        [$status] = self::call('GET', '/api/v3/me', $jar);
        self::assertSame(401, $status);

        // Ended, not paused: the same cookie is nobody's when the user is back on.
        self::q('UPDATE 202_users SET user_active = 1 WHERE user_id = ' . self::USER);
        [$status] = self::call('GET', '/api/v3/me', $jar);
        self::assertSame(401, $status);
    }

    public function testAPasswordChangedInAnotherBrowserEndsThisOnesAccess(): void
    {
        $dashboard = self::signIn();
        $settings = self::signIn();
        self::licenceConfirmed($settings);
        [$status, , $page] = self::call('GET', '/202-account/account.php', $settings);
        self::assertSame(200, $status, 'Personal settings is served: ' . substr($page, 0, 300));
        self::assertSame(1, preg_match('/name="token" value="([^"]+)"/', $page, $m), 'Personal settings carries a token');
        [$status] = self::call('POST', '/202-account/account.php', $settings, [], null, [
            'token' => $m[1], 'change_user_pass' => '1', 'user_pass' => self::PASSWORD,
            'new_user_pass' => 'session-api-pw-2', 'retype_new_user_pass' => 'session-api-pw-2',
        ]);
        self::assertSame(303, $status);
        self::assertTrue(password_verify('session-api-pw-2', self::passwordHash()), 'the password changed');

        [$status] = self::call('GET', '/api/v3/me', $dashboard);
        self::assertSame(401, $status, 'the other browser\'s session ended');
        [$status] = self::call('GET', '/api/v3/me', $settings);
        self::assertSame(200, $status, 'the browser that changed it keeps its own');
    }

    public function testABearerKeyIsAnsweredAsBeforeWhateverCookieRidesAlong(): void
    {
        $jar = self::signIn();
        [$status, $body, $raw] = self::call('POST', '/api/v3/aff-networks', $jar, ['Authorization: Bearer ' . self::$key], '{"aff_network_name":"Key net"}');
        self::assertSame(201, $status, 'a key\'s write carries no token: ' . $raw);
        [, $me] = self::call('GET', '/api/v3/me', $jar, ['Authorization: Bearer ' . self::$key]);
        self::assertSame(['method' => 'api_key', 'csrf_token' => null], $me['data']['auth']);

        // A wrong key is refused, not stood in for by the session.
        [$status] = self::call('GET', '/api/v3/me', $jar, ['Authorization: Bearer ' . str_repeat('0', 64)]);
        self::assertSame(401, $status);
        [$status] = self::call('GET', '/api/v3/me', $jar, ['Authorization: Basic eDp5']);
        self::assertSame(401, $status);
    }

    public function testASessionBearsNoKeyToRevoke(): void
    {
        $jar = self::signIn();
        [$status, $body] = self::call('POST', '/api/v3/connect/revoke', $jar, ['X-P202-CSRF: ' . self::csrf($jar)]);
        self::assertSame(400, $status);
        self::assertStringContainsString('no key to revoke', (string) $body['message']);
    }

    public function testNoCookieAndNoKeyIsRefused(): void
    {
        [$status] = self::call('GET', '/api/v3/me', '');
        self::assertSame(401, $status);
        [$status] = self::call('GET', '/api/v3/me', '', ['Cookie: PHPSESSID=neverissued0123456789abcdef']);
        self::assertSame(401, $status, 'an id the server never issued names nobody');
    }

    private static function passwordHash(): string
    {
        $result = self::$db->query('SELECT user_pass FROM 202_users WHERE user_id = ' . self::USER);
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (string) $result->fetch_row()[0];
    }
}
