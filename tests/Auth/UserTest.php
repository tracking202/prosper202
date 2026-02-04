<?php
declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;

/**
 * Tests for User.class.php
 */
final class UserTest extends TestCase
{
    private array $originalServer = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalServer = $_SERVER ?? [];
        $_SERVER['PHP_SELF'] = '/test.php';

        // Include the Role class first (User depends on it)
        require_once __DIR__ . '/../../202-config/Role.class.php';
        require_once __DIR__ . '/../../202-config/User.class.php';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;

        parent::tearDown();
    }

    public function testUserSkipsRoleLoadingDuringInstallation(): void
    {
        // Skip test because User class requires DB singleton
        $this->markTestSkipped('User class requires DB singleton which is not available in tests');
    }

    public function testUserSkipsRoleLoadingDuringApiKeyRetrieval(): void
    {
        // Skip test because User class requires DB singleton
        $this->markTestSkipped('User class requires DB singleton which is not available in tests');
    }

    public function testHasPermissionReturnsFalseWhenRolesNotSet(): void
    {
        // Skip test because User class requires DB singleton
        $this->markTestSkipped('User class requires DB singleton which is not available in tests');
    }

    public function testUserClassExists(): void
    {
        $this->assertTrue(class_exists('User'));
    }

    public function testUserHasPermissionMethod(): void
    {
        $this->assertTrue(method_exists('User', 'hasPermission'));
    }

    public function testHasPermissionReturnsFalseForInvalidPermission(): void
    {
        // Skip test because User class requires DB singleton
        $this->markTestSkipped('User class requires DB singleton which is not available in tests');
    }
}
