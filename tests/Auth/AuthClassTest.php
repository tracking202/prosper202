<?php
declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use AUTH;
use Prosper202\User\UserStanding;

/**
 * Tests for the AUTH class in functions-auth.php
 */
final class AuthClassTest extends TestCase
{
    private array $originalSession = [];
    private array $originalServer = [];
    private array $originalCookie = [];
    private array $originalPost = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Store original globals
        $this->originalSession = $_SESSION ?? [];
        $this->originalServer = $_SERVER ?? [];
        $this->originalCookie = $_COOKIE ?? [];
        $this->originalPost = $_POST ?? [];

        // Set default server variables
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test Agent';
        $_SERVER['HTTP_HOST'] = 'test.prosper202.com';
        $_SERVER['HTTPS'] = 'on';

        // Include the auth functions
        require_once __DIR__ . '/../../202-config/functions-auth.php';

        // Reset the per-request session heartbeat guard so each test behaves
        // like a fresh request. logged_in() refreshes session_time at most once
        // per request via this private static flag; without resetting it, a test
        // that calls logged_in() leaves the flag set and suppresses the refresh
        // in later tests within the same process.
        $reflection = new \ReflectionProperty(AUTH::class, 'sessionHeartbeatRefreshed');
        $reflection->setValue(null, false);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->originalSession;
        $_SERVER = $this->originalServer;
        $_COOKIE = $this->originalCookie;
        $_POST = $this->originalPost;
        unset($GLOBALS['userObj']);

        parent::tearDown();
    }

    public function testLoggedInReturnsFalseWhenSessionEmpty(): void
    {
        $_SESSION = [];

        $result = AUTH::logged_in();

        $this->assertFalse($result);
    }

    public function testLoggedInReturnsFalseWhenUserNameMissing(): void
    {
        $_SESSION = [
            'user_id' => 1,
            'session_fingerprint' => AUTH::session_fingerprint(),
            'session_time' => time(),
        ];

        $result = AUTH::logged_in();

        $this->assertFalse($result);
    }

    public function testLoggedInReturnsFalseWhenUserIdMissing(): void
    {
        $_SESSION = [
            'user_name' => 'testuser',
            'session_fingerprint' => AUTH::session_fingerprint(),
            'session_time' => time(),
        ];

        $result = AUTH::logged_in();

        $this->assertFalse($result);
    }

    public function testLoggedInReturnsFalseWhenFingerprintMissing(): void
    {
        $_SESSION = [
            'user_name' => 'testuser',
            'user_id' => 1,
            'session_time' => time(),
        ];

        $result = AUTH::logged_in();

        $this->assertFalse($result);
    }

    public function testLoggedInReturnsFalseWhenFingerprintInvalid(): void
    {
        $_SESSION = [
            'user_name' => 'testuser',
            'user_id' => 1,
            'session_fingerprint' => 'invalid_fingerprint',
            'session_time' => time(),
        ];

        $result = AUTH::logged_in();

        $this->assertFalse($result);
    }

    public function testLoggedInReturnsFalseWhenSessionExpired(): void
    {
        $_SESSION = [
            'user_name' => 'testuser',
            'user_id' => 1,
            'session_fingerprint' => AUTH::session_fingerprint(),
            'session_time' => time() - 60000, // More than 50000 seconds ago
        ];

        $result = AUTH::logged_in();

        $this->assertFalse($result);
    }

    public function testLoggedInReturnsTrueWithValidSession(): void
    {
        $_SESSION = [
            'user_name' => 'testuser',
            'user_id' => 1,
            'user_own_id' => 1,
            'session_fingerprint' => AUTH::session_fingerprint(),
            'session_time' => time(),
        ];
        $GLOBALS['userObj'] = self::userRead(1, UserStanding::Active);

        $result = AUTH::logged_in();

        $this->assertTrue($result);
    }

    public function testLoggedInUpdatesSessionTime(): void
    {
        $oldTime = time() - 100;
        $_SESSION = [
            'user_name' => 'testuser',
            'user_id' => 1,
            'user_own_id' => 1,
            'session_fingerprint' => AUTH::session_fingerprint(),
            'session_time' => $oldTime,
        ];
        $GLOBALS['userObj'] = self::userRead(1, UserStanding::Active);

        AUTH::logged_in();

        $this->assertGreaterThan($oldTime, $_SESSION['session_time']);
    }

    /** begin_user_session() sets both ids to one user; a session where they differ names nobody. */
    public function testLoggedInRefusesASessionWhoseTwoIdsDiffer(): void
    {
        $_SESSION = [
            'user_name' => 'testuser',
            'user_id' => 7,
            'user_own_id' => 8,
            'session_fingerprint' => AUTH::session_fingerprint(),
            'session_time' => time(),
        ];
        $GLOBALS['userObj'] = self::userRead(7, UserStanding::Active);

        $this->assertFalse(AUTH::logged_in());
        $this->assertSame(['token'], array_keys($_SESSION));
    }

    /** The User connect.php would have built for $userId, read as $standing, without a database. */
    private static function userRead(int $userId, UserStanding $standing): \User
    {
        require_once __DIR__ . '/../../202-config/User.class.php';
        $user = (new \ReflectionClass(\User::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\User::class, 'userId'))->setValue($user, $userId);
        (new \ReflectionProperty(\User::class, 'standing'))->setValue($user, $standing);

        return $user;
    }

    public function testGenerateRandomStringReturnsCorrectLength(): void
    {
        $lengths = [8, 16, 32, 48, 64, 128];

        foreach ($lengths as $length) {
            $result = AUTH::generate_random_string($length);

            $this->assertSame($length, strlen($result), "Failed for length $length");
        }
    }

    public function testGenerateRandomStringContainsOnlyAllowedCharacters(): void
    {
        $allowed = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

        $result = AUTH::generate_random_string(100);

        for ($i = 0; $i < strlen($result); $i++) {
            $char = $result[$i];
            $this->assertTrue(
                str_contains($allowed, $char),
                "Character '$char' is not allowed"
            );
        }
    }

    public function testGenerateRandomStringIsRandom(): void
    {
        $strings = [];
        for ($i = 0; $i < 10; $i++) {
            $strings[] = AUTH::generate_random_string(48);
        }

        // All strings should be unique
        $this->assertSame(count($strings), count(array_unique($strings)));
    }

    public function testDevUrandReturnsIntegerInRange(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $result = AUTH::dev_urand(10, 20);

            $this->assertGreaterThanOrEqual(10, $result);
            $this->assertLessThanOrEqual(20, $result);
        }
    }

    public function testDevUrandWithDefaultRange(): void
    {
        $result = AUTH::dev_urand();

        $this->assertGreaterThanOrEqual(0, $result);
        $this->assertLessThanOrEqual(0x7FFFFFFF, $result);
    }

    public function testDevUrandThrowsExceptionForBadRange(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Bad range');

        AUTH::dev_urand(100, 50); // max < min
    }

    public function testBeginUserSessionSetsRequiredSessionVariables(): void
    {
        $userRow = [
            'user_id' => 42,
            'user_name' => 'testuser',
            'user_api_key' => 'test_api_key',
            'user_stats202_app_key' => 'test_app_key',
            'user_timezone' => 'America/New_York',
            'user_mods_lb' => 1,
            'install_hash' => '',
            'p202_customer_api_key' => 'customer_key',
        ];

        AUTH::begin_user_session($userRow);

        $this->assertSame('testuser', $_SESSION['user_name']);
        $this->assertSame(42, $_SESSION['user_id']);
        $this->assertSame(42, $_SESSION['user_own_id']);
        $this->assertSame('test_api_key', $_SESSION['user_api_key']);
        $this->assertSame('test_app_key', $_SESSION['user_stats202_app_key']);
        $this->assertSame('America/New_York', $_SESSION['user_timezone']);
        $this->assertSame(1, $_SESSION['user_mods_lb']);
        $this->assertArrayHasKey('session_fingerprint', $_SESSION);
        $this->assertArrayHasKey('session_time', $_SESSION);
        $this->assertArrayHasKey('account_owner_id', $_SESSION);
    }

    public function testBeginUserSessionHandlesMissingOptionalFields(): void
    {
        $userRow = [
            'user_id' => 1,
            'user_name' => 'testuser',
        ];

        AUTH::begin_user_session($userRow);

        $this->assertSame('testuser', $_SESSION['user_name']);
        $this->assertSame(1, $_SESSION['user_id']);
        $this->assertNull($_SESSION['user_api_key']);
        $this->assertSame('UTC', $_SESSION['user_timezone']);
    }

    /**
     * A sign-in placed in the EU/UK whose location cannot be stored still
     * signs in, and its session holds analytics; an unknown location writes
     * nothing; a non-EU result that cannot be stored holds nothing.
     *
     * @dataProvider signInLocations
     */
    public function testTheSignInLocationIsStoredOrHeldNeverFatal(?bool $isEu, bool $held): void
    {
        $hadDb = array_key_exists('db', $GLOBALS);
        $oldDb = $GLOBALS['db'] ?? null;
        $prepared = [];
        $db = $this->createMock(\mysqli::class);
        $db->method('prepare')->willReturnCallback(static function (string $sql) use (&$prepared) {
            $prepared[] = $sql;
            return false;
        });
        $GLOBALS['db'] = $db;
        $_SESSION = [];
        try {
            AUTH::begin_user_session(['user_id' => 42, 'user_name' => 'testuser'], static fn (): ?bool => $isEu);

            self::assertSame(42, $_SESSION['user_id']);
            self::assertSame($held, $_SESSION[\ConsentPolicy::SESSION_GEO_HELD]);
            if ($held) {
                self::assertFalse(\ConsentPolicy::captureFence($db, 42)['allowed'] ?? true, 'the session holds analytics');
            }
            $writes = preg_grep('/^\s*(UPDATE|INSERT IGNORE INTO) `202_users_pref`/', $prepared);
            if ($isEu === null) {
                self::assertSame([], $writes, 'an unknown location writes nothing');
            } else {
                self::assertNotSame([], $writes, 'a definitive location is written');
            }
        } finally {
            if ($hadDb) { $GLOBALS['db'] = $oldDb; } else { unset($GLOBALS['db']); }
        }
    }

    /** @return array<string, array{?bool, bool}> */
    public static function signInLocations(): array
    {
        return [
            'EU/UK, not stored' => [true, true],
            'elsewhere, not stored' => [false, false],
            'unknown' => [null, false],
        ];
    }

    /**
     * The sign-in location comes from REMOTE_ADDR alone: a request carrying
     * any forwarding header is unknown, which holds analytics.
     *
     * @dataProvider signInRequests
     * @param array<string, string> $server
     */
    public function testTheSignInLocationIsTheConnectingPeersNeverAForwardedOne(array $server, ?bool $expected): void
    {
        $countries = [
            '192.0.2.10' => ['DE', true],
            '198.51.100.7' => ['US', false],
            '203.0.113.5' => ['GB', false],
            '2001:db8::10' => ['FR', true],
            '2001:db8::20' => ['US', false],
        ];
        $country = static fn (string $ip): ?array => $countries[$ip] ?? null;

        self::assertSame($expected, AUTH::sign_in_is_eu($server, $country));
    }

    /** @return array<string, array{array<string, string>, ?bool}> */
    public static function signInRequests(): array
    {
        return [
            'an EU address' => [['REMOTE_ADDR' => '192.0.2.10'], true],
            'a UK address' => [['REMOTE_ADDR' => '203.0.113.5'], true],
            'an address elsewhere' => [['REMOTE_ADDR' => '198.51.100.7'], false],
            'an address the database cannot place' => [['REMOTE_ADDR' => '192.0.2.99'], null],
            'a forged X-Forwarded-For from the EU' => [
                ['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], null,
            ],
            'a forged X-Real-IP' => [['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_REAL_IP' => '198.51.100.7'], null],
            'a forged CF-Connecting-IP' => [['REMOTE_ADDR' => '192.0.2.10', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'], null],
            'a forged Forwarded' => [['REMOTE_ADDR' => '192.0.2.10', 'HTTP_FORWARDED' => 'for=198.51.100.7'], null],
            'no address' => [[], null],
            // Behind Cloudflare: an edge server's CF-Connecting-IP is the visitor.
            'Cloudflare, a visitor elsewhere' => [['REMOTE_ADDR' => '172.64.0.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'], false],
            'Cloudflare, a visitor in the EU' => [['REMOTE_ADDR' => '104.16.0.9', 'HTTP_CF_CONNECTING_IP' => '192.0.2.10'], true],
            'Cloudflare: X-Forwarded-For ignored' => [
                ['REMOTE_ADDR' => '162.158.1.1', 'HTTP_X_FORWARDED_FOR' => '192.0.2.10', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'], false,
            ],
            'Cloudflare, no CF-Connecting-IP' => [['REMOTE_ADDR' => '172.64.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], null],
            'Cloudflare, a malformed CF-Connecting-IP' => [['REMOTE_ADDR' => '172.64.0.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7, 192.0.2.10'], null],
            'Cloudflare, a private CF-Connecting-IP' => [['REMOTE_ADDR' => '172.64.0.1', 'HTTP_CF_CONNECTING_IP' => '10.0.0.5'], null],
            'Cloudflare, a loopback CF-Connecting-IP' => [['REMOTE_ADDR' => '172.64.0.1', 'HTTP_CF_CONNECTING_IP' => '127.0.0.1'], null],
            'the last address of a Cloudflare range' => [['REMOTE_ADDR' => '172.71.255.255', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'], false],
            'the first address past a Cloudflare range' => [['REMOTE_ADDR' => '172.72.0.0', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'], null],
            'Cloudflare over IPv6, a visitor in the EU over IPv6' => [
                ['REMOTE_ADDR' => '2606:4700::6810:84e5', 'HTTP_CF_CONNECTING_IP' => '2001:DB8:0::10'], true,
            ],
            'Cloudflare over IPv6, the end of a /29' => [['REMOTE_ADDR' => '2a06:98c7:ffff::1', 'HTTP_CF_CONNECTING_IP' => '2001:db8::20'], false],
            'past a Cloudflare /29' => [['REMOTE_ADDR' => '2a06:98c8::1', 'HTTP_CF_CONNECTING_IP' => '2001:db8::20'], null],
            'Cloudflare, a unique-local IPv6 CF-Connecting-IP' => [['REMOTE_ADDR' => '2400:cb00::1', 'HTTP_CF_CONNECTING_IP' => 'fd00::1'], null],
            'Cloudflare, a link-local IPv6 CF-Connecting-IP' => [['REMOTE_ADDR' => '2400:cb00::1', 'HTTP_CF_CONNECTING_IP' => 'fe80::1'], null],
            'a Cloudflare address written IPv4-mapped is not Cloudflare' => [
                ['REMOTE_ADDR' => '::ffff:172.64.0.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'], null,
            ],
        ];
    }

    /** Every published range holds its own first and last address and not the ones beside it. */
    public function testCloudflareRangesMatchByPrefixLengthNotByText(): void
    {
        foreach (\Prosper202\Http\CloudflareRanges::CIDRS as $cidr) {
            [$network, $bits] = explode('/', $cidr);
            $base = inet_pton($network);
            self::assertNotFalse($base, $cidr);
            $hostBits = strlen($base) * 8 - (int) $bits;
            $first = self::addToPacked($base, 0);
            $last = self::addToPacked($base, 1, $hostBits);
            self::assertTrue(\Prosper202\Http\CloudflareRanges::inCidr(inet_ntop($first), $cidr), "first of $cidr");
            self::assertTrue(\Prosper202\Http\CloudflareRanges::inCidr(inet_ntop($last), $cidr), "last of $cidr");
            // Some ranges adjoin another (104.16.0.0/13, 104.24.0.0/14), so each is checked on its own.
            self::assertFalse(\Prosper202\Http\CloudflareRanges::inCidr(inet_ntop(self::addToPacked($last, 1)), $cidr), "after $cidr");
            self::assertFalse(\Prosper202\Http\CloudflareRanges::inCidr(inet_ntop(self::addToPacked($first, -1)), $cidr), "before $cidr");
        }
        self::assertFalse(\Prosper202\Http\CloudflareRanges::inCidr('2606:4700::1', '173.245.48.0/20'), 'families never match');
        self::assertFalse(\Prosper202\Http\CloudflareRanges::contains('104.16.0'), 'not an address');
    }

    /** $packed plus $delta (or, with $hostBits, with its low $hostBits set). */
    private static function addToPacked(string $packed, int $delta, ?int $hostBits = null): string
    {
        $bytes = array_values(unpack('C*', $packed));
        if ($hostBits !== null) {
            for ($i = count($bytes) - 1; $hostBits > 0; $i--, $hostBits -= 8) {
                $bytes[$i] |= $hostBits >= 8 ? 0xFF : (1 << $hostBits) - 1;
            }
            return pack('C*', ...$bytes);
        }
        for ($i = count($bytes) - 1; $i >= 0 && $delta !== 0; $i--) {
            $sum = $bytes[$i] + $delta;
            $bytes[$i] = ($sum % 256 + 256) % 256;
            $delta = intdiv($sum - $bytes[$i], 256);
        }
        return pack('C*', ...$bytes);
    }

    public function testSetTimezoneWithValidTimezone(): void
    {
        AUTH::set_timezone('America/Los_Angeles');

        $this->assertSame('America/Los_Angeles', date_default_timezone_get());
    }

    public function testSetTimezoneUsesSessionTimezoneIfSet(): void
    {
        $_SESSION['user_timezone'] = 'Europe/London';

        AUTH::set_timezone('America/New_York');

        $this->assertSame('Europe/London', date_default_timezone_get());
    }

    public function testLogoutDaysConstant(): void
    {
        $this->assertSame(14, AUTH::LOGOUT_DAYS);
    }

    public function testSessionFingerprintBindsToUserAgent(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Agent A';
        $fingerprintA = AUTH::session_fingerprint();

        $_SERVER['HTTP_USER_AGENT'] = 'Agent B';
        $fingerprintB = AUTH::session_fingerprint();

        $this->assertNotSame($fingerprintA, $fingerprintB, 'Fingerprint must change with the User-Agent');
        // It must no longer be the trivially derivable md5 of just the session id.
        $this->assertNotSame(md5('session_fingerprint' . session_id()), $fingerprintA);
    }

    public function testCheckCsrfTokenMatchesSessionToken(): void
    {
        $_SESSION['token'] = 'a-valid-token';

        $_POST['token'] = 'a-valid-token';
        $this->assertTrue(AUTH::check_csrf_token());

        $_POST['token'] = 'forged';
        $this->assertFalse(AUTH::check_csrf_token());

        unset($_POST['token']);
        $this->assertFalse(AUTH::check_csrf_token());
    }

    public function testCheckCsrfTokenFailsClosedWhenSessionTokenMissing(): void
    {
        // Both sides empty must NOT pass — hash_equals('', '') is true, so the
        // helper has to reject empty tokens explicitly (fail closed).
        unset($_SESSION['token']);
        $_POST['token'] = '';
        $this->assertFalse(AUTH::check_csrf_token());

        $_SESSION['token'] = '';
        $_POST['token'] = '';
        $this->assertFalse(AUTH::check_csrf_token());
    }

    /**
     * The comparison every token guard goes through, with the submitted value
     * handed in (a Setup delete link's `?token=`). A blanked session token
     * with nothing submitted is the request the inline copies let through:
     * `hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_GET['token'] ?? ''))`
     * is hash_equals('', ''), which is true.
     */
    public function testCsrfTokenMatchesFailsClosedOnEveryEmptyOrNonStringSide(): void
    {
        $_SESSION['token'] = 'a-valid-token';
        $this->assertTrue(AUTH::csrf_token_matches('a-valid-token'));
        $this->assertFalse(AUTH::csrf_token_matches('forged'));
        $this->assertFalse(AUTH::csrf_token_matches('a-valid-toke'), 'a prefix is not the token');
        $this->assertFalse(AUTH::csrf_token_matches(''));
        $this->assertFalse(AUTH::csrf_token_matches(null));
        // ?token[]=… arrives as an array; (string) of it is 'Array' and a warning.
        $this->assertFalse(AUTH::csrf_token_matches(['a-valid-token']));

        foreach (['blanked' => '', 'false' => false, 'null' => null, 'an int' => 0, 'an array' => ['x']] as $what => $stored) {
            $_SESSION['token'] = $stored;
            $this->assertFalse(AUTH::csrf_token_matches(''), "a session token that is $what, nothing submitted");
            $this->assertFalse(AUTH::csrf_token_matches(null), "a session token that is $what, no field at all");
            $this->assertFalse(AUTH::csrf_token_matches('0'), "a session token that is $what, '0' submitted");
        }
        unset($_SESSION['token']);
        $this->assertFalse(AUTH::csrf_token_matches(''), 'no session token, nothing submitted');

        // check_csrf_token() is this comparison on $_POST['token'].
        $_SESSION['token'] = 'a-valid-token';
        $_POST['token'] = ['a-valid-token'];
        $this->assertFalse(AUTH::check_csrf_token(), 'token[] posted');
        $_SESSION['token'] = '';
        unset($_POST['token']);
        $this->assertFalse(AUTH::check_csrf_token(), 'a blanked session token and no posted token');
    }

    public function testClientIpReturnsValidForwardedIp(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.23';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        $this->assertSame('198.51.100.23', AUTH::client_ip());
    }

    public function testClientIpFallsBackWhenForwardedValueIsNotAnIp(): void
    {
        // Attacker-supplied garbage / overlong value must not be used.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = str_repeat('A', 4000);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';

        $this->assertSame('203.0.113.5', AUTH::client_ip());
    }

    public function testClientIpReturnsPlaceholderWhenNothingValid(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
        $_SERVER['REMOTE_ADDR'] = 'also-bad';

        $this->assertSame('0.0.0.0', AUTH::client_ip());
    }

    public function testIsRateLimitedTrueWhenFailuresExceedThreshold(): void
    {
        $mockDb = $this->createMockDb([
            '202_users_log' => ['failures' => AUTH::RATE_LIMIT_MAX_PER_IP + 1],
        ]);

        $this->assertTrue(AUTH::is_rate_limited($mockDb, 'someone', '203.0.113.9'));
    }

    public function testIsRateLimitedFalseWhenNoRecentFailures(): void
    {
        $mockDb = $this->createMockDb(); // fetch_assoc() returns null -> 0 failures

        $this->assertFalse(AUTH::is_rate_limited($mockDb, 'someone', '203.0.113.9'));
    }

    /**
     * Both counts ask for failures alone (login_success 0): a sign-in whose
     * password was right but which could not be completed (2) is not a
     * guess. SignInLogIntegrationTest counts real rows.
     */
    public function testIsRateLimitedCountsOnlyFailedAttempts(): void
    {
        $prepared = [];
        $mockDb = $this->createMockDb();
        $stmt = $mockDb->prepare('');
        $counting = $this->createMock(\mysqli::class);
        $counting->method('prepare')->willReturnCallback(static function (string $sql) use (&$prepared, $stmt) {
            $prepared[] = $sql;
            return $stmt;
        });

        $this->assertFalse(AUTH::is_rate_limited($counting, 'someone', '203.0.113.9'));
        $this->assertCount(2, $prepared, 'the per-IP count, then the per-user count');
        foreach ($prepared as $sql) {
            $this->assertMatchesRegularExpression(
                '/\bWHERE login_success = 0 AND (ip_address|user_name) = \? AND login_time >= \?$/',
                $sql
            );
        }
    }

    public function testLoginAuditSnapshotKeepsSafeFieldsOnly(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/202-login.php';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test Agent';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $snapshot = unserialize(AUTH::login_audit_snapshot());

        $this->assertSame('POST', $snapshot['REQUEST_METHOD']);
        $this->assertSame('/202-login.php', $snapshot['REQUEST_URI']);
        $this->assertSame('PHPUnit Test Agent', $snapshot['HTTP_USER_AGENT']);
        $this->assertSame('203.0.113.7', $snapshot['REMOTE_ADDR']);
    }

    public function testLoginAuditSnapshotDropsSecrets(): void
    {
        $_SERVER['HTTP_COOKIE'] = 'PHPSESSID=abc123; remember_me=42-secretkey-deadbeef';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer supersecret';
        $_SERVER['PHP_AUTH_PW'] = 'hunter2';

        $snapshot = unserialize(AUTH::login_audit_snapshot());

        $this->assertArrayNotHasKey('HTTP_COOKIE', $snapshot);
        $this->assertArrayNotHasKey('HTTP_AUTHORIZATION', $snapshot);
        $this->assertArrayNotHasKey('PHP_AUTH_PW', $snapshot);
        $serialized = AUTH::login_audit_snapshot();
        $this->assertStringNotContainsString('remember_me', $serialized);
        $this->assertStringNotContainsString('supersecret', $serialized);
    }

    public function testAuthenticateWithEmptyUsername(): void
    {
        $mockDb = $this->createMockDb();

        $result = AUTH::authenticate('', 'password', $mockDb);

        $this->assertFalse($result['success']);
        $this->assertSame('missing_credentials', $result['error']);
    }

    public function testAuthenticateWithEmptyPassword(): void
    {
        $mockDb = $this->createMockDb();

        $result = AUTH::authenticate('username', '', $mockDb);

        $this->assertFalse($result['success']);
        $this->assertSame('missing_credentials', $result['error']);
    }

    public function testAuthenticateTrimsUsername(): void
    {
        $mockDb = $this->createMockDb([
            '202_users' => [
                'user_id' => 1,
                'user_name' => 'testuser',
                'user_pass' => password_hash('password', PASSWORD_DEFAULT),
                'user_api_key' => null,
                'user_stats202_app_key' => null,
                'user_timezone' => 'UTC',
                'user_mods_lb' => 0,
                'install_hash' => '',
                'p202_customer_api_key' => '',
                'pref_user_id' => 1,
            ],
        ]);

        // Test with whitespace - this tests that the function trims properly
        // The actual authentication depends on database lookup
        $result = AUTH::authenticate('   ', 'password', $mockDb);

        $this->assertFalse($result['success']);
        $this->assertSame('missing_credentials', $result['error']);
    }

    private function createMockDb(array $queryResults = []): \mysqli
    {
        // Create a mock that mimics mysqli behavior
        $mock = $this->createMock(\mysqli::class);

        $stmt = $this->createMock(\mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('close')->willReturn(true);

        if (!empty($queryResults)) {
            $result = $this->createMock(\mysqli_result::class);
            $result->method('fetch_assoc')->willReturn(array_values($queryResults)[0] ?? null);
            $stmt->method('get_result')->willReturn($result);
        } else {
            // Return a mock result that returns null from fetch_assoc
            $emptyResult = $this->createMock(\mysqli_result::class);
            $emptyResult->method('fetch_assoc')->willReturn(null);
            $stmt->method('get_result')->willReturn($emptyResult);
        }

        $mock->method('prepare')->willReturn($stmt);

        return $mock;
    }
}
