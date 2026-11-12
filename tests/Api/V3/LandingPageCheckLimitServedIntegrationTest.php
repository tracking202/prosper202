<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\LandingPageCheckLimit;
use PHPUnit\Framework\TestCase;

/**
 * POST /landing-pages/{id}/check through api/v3/index.php itself, served by
 * `php -S` over the scratch database, with a read-only key: a repeat inside
 * the cooldown is answered from the last check, and past the key's limit
 * the answer is a 429 with Retry-After, while another key of the same
 * account is counted apart. The pages' URLs are documentation addresses the
 * fetch guard refuses, so nothing leaves the machine; a refused fetch is an
 * answer (`status: error`) and is counted like any other.
 *
 * @group integration
 */
final class LandingPageCheckLimitServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990561;
    private const ROLE = 990561;

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';
    private static string $state = '';
    /** @var array<string, string> */
    private static array $keys = [];
    /** @var list<int> */
    private static array $pages = [];

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
        self::cleanUp();
        self::user(self::USER);
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::USER
            . ", user_tracking_domain = 'https://track.example.com'");
        // Setup's permission, as Get LP Code asks; not an admin.
        self::q('INSERT INTO 202_roles SET role_id = ' . self::ROLE . ", role_name = 'lp check limit test'");
        $found = self::$db->query('SELECT permission_id FROM 202_permissions'
            . " WHERE permission_description = 'access_to_setup_section'");
        $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
        if ($permission === null) {
            (new \Prosper202\Database\DataSeeder(self::$db))->seedPermissions();
        }
        self::q('INSERT INTO 202_role_permission (role_id, permission_id) SELECT ' . self::ROLE . ', permission_id'
            . " FROM 202_permissions WHERE permission_description = 'access_to_setup_section'");
        self::q('INSERT INTO 202_user_role (user_id, role_id) VALUES (' . self::USER . ', ' . self::ROLE . ')');
        foreach (['first', 'second'] as $name) {
            $key = bin2hex(random_bytes(32));
            self::q('INSERT INTO 202_api_keys (user_id, api_key, scope, created_at) VALUES (' . self::USER . ', '
                . self::str($key) . ", 'landing-pages:read', " . time() . ')');
            self::$keys[$name] = $key;
        }
        for ($i = 0; $i <= LandingPageCheckLimit::PER_KEY + 1; $i++) {
            self::$pages[] = self::insert('INSERT INTO 202_landing_pages SET user_id = ' . self::USER
                . ", aff_campaign_id = 0, landing_page_nickname = 'Spring sale $i',"
                . " landing_page_url = 'https://198.51.100." . ($i + 10) . "/spring', leave_behind_page_url = '',"
                . ' landing_page_time = 0, landing_page_type = 0, landing_page_id_public = ' . (77100 + $i));
        }

        self::$state = sys_get_temp_dir() . '/p202-lp-check-served-' . bin2hex(random_bytes(6));
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
        $tables = ['202_landing_page_checks', '202_landing_pages', '202_api_keys', '202_user_role', '202_users_pref',
            '202_users'];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string, 3: string} status, body, raw body, Retry-After */
    private static function check(int $page, string $key): array
    {
        $retryAfter = '';
        $ch = curl_init(self::$base . '/api/v3/landing-pages/' . $page . '/check');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}', CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (stripos($line, 'Retry-After:') === 0) {
                    $retryAfter = trim(substr($line, 12));
                }

                return strlen($line);
            }]);
        $raw = (string) curl_exec($ch);
        $decoded = json_decode($raw, true);

        $context = $raw . "\nserver log:\n" . (string) @file_get_contents(self::$log);

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$status, is_array($decoded) ? $decoded : [], $context, $retryAfter];
    }

    public function testARepeatIsAnsweredFromTheLastCheckAndAKeyPastItsLimitIsTold429(): void
    {
        self::requireScratch();
        [$status, $body, $raw] = self::check(self::$pages[0], self::$keys['first']);
        self::assertSame(200, $status, $raw);
        self::assertSame(['error', true], [$body['data']['status'] ?? null, $body['data']['fetched'] ?? null], $raw);
        [$status, $body, $raw] = self::check(self::$pages[0], self::$keys['first']);
        self::assertSame([200, false], [$status, $body['data']['fetched'] ?? null], $raw);
        self::assertIsInt($body['data']['fetch_again_in_seconds'] ?? null, $raw);
        self::assertStringContainsString('that check is the answer', $body['data']['note'] ?? '', $raw);

        $perKey = LandingPageCheckLimit::PER_KEY;
        for ($i = 1; $i < $perKey; $i++) {
            [$status, $body, $raw] = self::check(self::$pages[$i], self::$keys['first']);
            self::assertSame([200, true], [$status, $body['data']['fetched'] ?? null], $raw);
        }
        [$status, $body, $raw, $retryAfter] = self::check(self::$pages[$perKey], self::$keys['first']);
        self::assertSame(429, $status, $raw);
        self::assertMatchesRegularExpression('/^[1-9]\d*$/D', $retryAfter, $raw);
        self::assertLessThanOrEqual(LandingPageCheckLimit::WINDOW, (int) $retryAfter);
        self::assertSame((int) $retryAfter, $body['retry_after_seconds'] ?? null, $raw);
        self::assertSame(LandingPageCheckLimit::PER_KEY, $body['rate_limit']['limit'] ?? null, $raw);
        self::assertStringContainsString('Try again in ' . $retryAfter . ' seconds', $body['message'] ?? '', $raw);
        // A fixed window: "this minute", never "the last 60 seconds".
        self::assertStringContainsString('fetches it may have this minute', $body['message'] ?? '', $raw);
        $unchecked = self::$db->query('SELECT 1 FROM 202_landing_page_checks WHERE landing_page_id = '
            . self::$pages[$perKey]);
        $rows = $unchecked instanceof \mysqli_result ? $unchecked->num_rows : -1;
        self::assertSame(0, $rows, 'the refused page was not checked');

        [$status, $body, $raw] = self::check(self::$pages[0], self::$keys['first']);
        $answered = 'past the limit, a page just checked is still answered: ';
        self::assertSame([200, false], [$status, $body['data']['fetched'] ?? null], $answered . $raw);
        [$status, $body, $raw] = self::check(self::$pages[$perKey + 1], self::$keys['second']);
        $apart = 'another key is counted apart: ';
        self::assertSame([200, true], [$status, $body['data']['fetched'] ?? null], $apart . $raw);
    }
}
