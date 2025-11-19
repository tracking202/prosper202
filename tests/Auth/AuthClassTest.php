<?php
declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use AUTH;

/**
 * Tests for the AUTH class in functions-auth.php
 */
final class AuthClassTest extends TestCase
{
    private array $originalSession = [];
    private array $originalServer = [];
    private array $originalCookie = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Store original globals
        $this->originalSession = $_SESSION ?? [];
        $this->originalServer = $_SERVER ?? [];
        $this->originalCookie = $_COOKIE ?? [];

        // Set default server variables
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test Agent';
        $_SERVER['HTTP_HOST'] = 'test.prosper202.com';
        $_SERVER['HTTPS'] = 'on';

        // Include the auth functions
        require_once __DIR__ . '/../../202-config/functions-auth.php';
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->originalSession;
        $_SERVER = $this->originalServer;
        $_COOKIE = $this->originalCookie;

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
            'session_fingerprint' => md5('session_fingerprint' . 'PHPUnit Test Agent' . session_id()),
            'session_time' => time(),
        ];

        $result = AUTH::logged_in();

        $this->assertFalse($result);
    }

    public function testLoggedInReturnsFalseWhenUserIdMissing(): void
    {
        $_SESSION = [
            'user_name' => 'testuser',
            'session_fingerprint' => md5('session_fingerprint' . 'PHPUnit Test Agent' . session_id()),
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
            'session_fingerprint' => md5('session_fingerprint' . 'PHPUnit Test Agent' . session_id()),
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
            'session_fingerprint' => md5('session_fingerprint' . 'PHPUnit Test Agent' . session_id()),
            'session_time' => time(),
        ];

        $result = AUTH::logged_in();

        $this->assertTrue($result);
    }

    public function testLoggedInUpdatesSessionTime(): void
    {
        $oldTime = time() - 100;
        $_SESSION = [
            'user_name' => 'testuser',
            'user_id' => 1,
            'session_fingerprint' => md5('session_fingerprint' . 'PHPUnit Test Agent' . session_id()),
            'session_time' => $oldTime,
        ];

        AUTH::logged_in();

        $this->assertGreaterThan($oldTime, $_SESSION['session_time']);
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
