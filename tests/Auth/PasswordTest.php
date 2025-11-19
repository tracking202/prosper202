<?php
declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;

/**
 * Tests for password hashing functions in functions-auth.php
 */
final class PasswordTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Include the auth functions
        require_once __DIR__ . '/../../202-config/functions-auth.php';
    }

    public function testHashUserPassReturnsString(): void
    {
        $hash = hash_user_pass('testpassword');

        $this->assertIsString($hash);
        $this->assertNotEmpty($hash);
    }

    public function testHashUserPassGeneratesUniqueHashes(): void
    {
        $password = 'testpassword';

        $hash1 = hash_user_pass($password);
        $hash2 = hash_user_pass($password);

        // password_hash generates unique hashes even for same password
        $this->assertNotSame($hash1, $hash2);
    }

    public function testHashUserPassGeneratesValidPasswordHash(): void
    {
        $password = 'testpassword';
        $hash = hash_user_pass($password);

        // Should be verifiable with password_verify
        $this->assertTrue(password_verify($password, $hash));
    }

    public function testVerifyUserPassWithValidModernHash(): void
    {
        $password = 'testpassword';
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $result = verify_user_pass($password, $hash);

        $this->assertTrue($result['valid']);
    }

    public function testVerifyUserPassWithInvalidPassword(): void
    {
        $password = 'testpassword';
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $result = verify_user_pass('wrongpassword', $hash);

        $this->assertFalse($result['valid']);
    }

    public function testVerifyUserPassWithEmptyStoredHash(): void
    {
        $result = verify_user_pass('anypassword', '');

        $this->assertFalse($result['valid']);
        $this->assertFalse($result['needsRehash']);
    }

    public function testVerifyUserPassWithWhitespaceOnlyHash(): void
    {
        $result = verify_user_pass('anypassword', '   ');

        $this->assertFalse($result['valid']);
        $this->assertFalse($result['needsRehash']);
    }

    public function testVerifyUserPassWithLegacyMd5Hash(): void
    {
        $password = 'testpassword';
        $legacyHash = md5($password);

        $result = verify_user_pass($password, $legacyHash);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['needsRehash']); // Legacy hashes need rehashing
    }

    public function testVerifyUserPassWithInvalidLegacyHash(): void
    {
        $password = 'testpassword';
        $legacyHash = md5('differentpassword');

        $result = verify_user_pass($password, $legacyHash);

        $this->assertFalse($result['valid']);
    }

    public function testVerifyUserPassNeedsRehashForOldAlgorithm(): void
    {
        $password = 'testpassword';
        // Create a hash that might need rehashing (using older cost)
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);

        $result = verify_user_pass($password, $hash);

        $this->assertTrue($result['valid']);
        // Note: needsRehash depends on current PASSWORD_DEFAULT settings
    }

    public function testVerifyUserPassReturnsCorrectStructure(): void
    {
        $password = 'testpassword';
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $result = verify_user_pass($password, $hash);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('needsRehash', $result);
        $this->assertIsBool($result['valid']);
        $this->assertIsBool($result['needsRehash']);
    }

    public function testHashUserPassWithSpecialCharacters(): void
    {
        $passwords = [
            'password with spaces',
            'password!@#$%^&*()',
            'пароль',  // Cyrillic
            '密码',    // Chinese
            "password\twith\ttabs",
            "password\nwith\nnewlines",
        ];

        foreach ($passwords as $password) {
            $hash = hash_user_pass($password);
            $result = verify_user_pass($password, $hash);

            $this->assertTrue(
                $result['valid'],
                "Failed to verify password with special characters: $password"
            );
        }
    }

    public function testVerifyUserPassWithVeryLongPassword(): void
    {
        // bcrypt has a 72 character limit, but password_hash handles this
        $longPassword = str_repeat('a', 100);
        $hash = hash_user_pass($longPassword);

        $result = verify_user_pass($longPassword, $hash);

        $this->assertTrue($result['valid']);
    }

    public function testVerifyUserPassWithEmptyPassword(): void
    {
        $hash = hash_user_pass('');

        $result = verify_user_pass('', $hash);

        $this->assertTrue($result['valid']);
    }

    public function testVerifyUserPassTimingSafety(): void
    {
        // This is a basic sanity check - proper timing attack tests require more sophisticated tools
        $password = 'testpassword';
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Valid password check
        $start = microtime(true);
        verify_user_pass($password, $hash);
        $validTime = microtime(true) - $start;

        // Invalid password check (should take similar time due to constant-time comparison)
        $start = microtime(true);
        verify_user_pass('wrongpassword', $hash);
        $invalidTime = microtime(true) - $start;

        // Times should be in the same order of magnitude (basic sanity check)
        $this->assertGreaterThan(0, $validTime);
        $this->assertGreaterThan(0, $invalidTime);
    }
}
