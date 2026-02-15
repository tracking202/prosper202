<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Auth;
use Api\V3\AuthException;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    public function testFromRequestWithValidBearerTokenCreatesAuth(): void
    {
        $db = $this->createMysqliMock([
            '202_api_keys' => ['user_id' => 7],
            '202_user_role' => [['role_name' => 'Admin']],
        ]);

        $auth = Auth::fromRequest(['Authorization' => 'Bearer abc123validkey'], $db);
        $this->assertSame(7, $auth->userId());
    }

    public function testFromRequestWithMissingAuthHeaderThrowsAuthException(): void
    {
        $db = $this->createMysqliMock();
        $this->expectException(AuthException::class);
        $this->expectExceptionCode(401);
        Auth::fromRequest([], $db);
    }

    public function testFromRequestWithEmptyAuthHeaderThrowsAuthException(): void
    {
        $db = $this->createMysqliMock();
        $this->expectException(AuthException::class);
        $this->expectExceptionCode(401);
        Auth::fromRequest(['Authorization' => ''], $db);
    }

    public function testFromRequestWithInvalidTokenThrowsAuthException(): void
    {
        $db = $this->createMysqliMock();
        $this->expectException(AuthException::class);
        $this->expectExceptionCode(401);
        Auth::fromRequest(['Authorization' => 'Bearer invalidtoken'], $db);
    }

    public function testFromRequestWithLowercaseAuthorizationHeader(): void
    {
        $db = $this->createMysqliMock([
            '202_api_keys' => ['user_id' => 3],
            '202_user_role' => [['role_name' => 'user']],
        ]);

        $auth = Auth::fromRequest(['authorization' => 'Bearer mykey123'], $db);
        $this->assertSame(3, $auth->userId());
    }

    public function testFromRequestWithNonBearerPrefixThrows(): void
    {
        $db = $this->createMysqliMock();
        $this->expectException(AuthException::class);
        $this->expectExceptionCode(401);
        Auth::fromRequest(['Authorization' => 'Basic abc123'], $db);
    }

    public function testFromRequestWithBearerButEmptyKeyThrows(): void
    {
        $db = $this->createMysqliMock();
        $this->expectException(AuthException::class);
        $this->expectExceptionCode(401);
        Auth::fromRequest(['Authorization' => 'Bearer    '], $db);
    }

    public function testFromRequestWithArrayHeaderValue(): void
    {
        $db = $this->createMysqliMock([
            '202_api_keys' => ['user_id' => 5],
            '202_user_role' => [],
        ]);

        $auth = Auth::fromRequest(['Authorization' => ['Bearer validkey']], $db);
        $this->assertSame(5, $auth->userId());
    }

    public function testUserIdReturnsCorrectUserId(): void
    {
        $db = $this->createMysqliMock([
            '202_api_keys' => ['user_id' => 42],
            '202_user_role' => [],
        ]);

        $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
        $this->assertSame(42, $auth->userId());
    }

    public function testRolesReturnsLowercaseRoleNames(): void
    {
        $db = $this->createMysqliMock([
            '202_api_keys' => ['user_id' => 1],
            '202_user_role' => [
                ['role_name' => 'Admin'],
                ['role_name' => 'EDITOR'],
                ['role_name' => 'User'],
            ],
        ]);

        $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
        $this->assertSame(['admin', 'editor', 'user'], $auth->roles());
    }

    public function testRolesReturnsEmptyArrayWhenNoRoles(): void
    {
        $db = $this->createMysqliMock([
            '202_api_keys' => ['user_id' => 1],
        ]);

        $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
        $this->assertSame([], $auth->roles());
    }

    public function testIsAdminTrueWhenUserHasAdminRole(): void
    {
        $db = $this->createMysqliMock([
            '202_api_keys' => ['user_id' => 1],
            '202_user_role' => [['role_name' => 'admin']],
        ]);

        $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
        $this->assertTrue($auth->isAdmin());
    }

    public function testIsAdminTrueWhenUserHasAdministratorRole(): void
    {
        $db = $this->createMysqliMock([
            '202_api_keys' => ['user_id' => 1],
            '202_user_role' => [['role_name' => 'Administrator']],
        ]);

        $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
        $this->assertTrue($auth->isAdmin());
    }

    public function testIsAdminFalseWhenUserHasNoAdminRole(): void
    {
        $db = $this->createMysqliMock([
            '202_api_keys' => ['user_id' => 1],
            '202_user_role' => [
                ['role_name' => 'user'],
                ['role_name' => 'editor'],
            ],
        ]);

        $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
        $this->assertFalse($auth->isAdmin());
    }

    public function testRequireAdminThrows403WhenNotAdmin(): void
    {
        $db = $this->createMysqliMock([
            '202_api_keys' => ['user_id' => 1],
            '202_user_role' => [['role_name' => 'user']],
        ]);

        $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
        $this->expectException(AuthException::class);
        $this->expectExceptionCode(403);
        $auth->requireAdmin();
    }

    public function testRequireAdminPassesForAdmin(): void
    {
        $db = $this->createMysqliMock([
            '202_api_keys' => ['user_id' => 1],
            '202_user_role' => [['role_name' => 'admin']],
        ]);

        $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
        $auth->requireAdmin();
        $this->assertTrue(true);
    }

    public function testRequireSelfOrAdminPassesWhenTargetingSelf(): void
    {
        $db = $this->createMysqliMock([
            '202_api_keys' => ['user_id' => 5],
            '202_user_role' => [['role_name' => 'user']],
        ]);

        $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
        $auth->requireSelfOrAdmin(5);
        $this->assertTrue(true);
    }

    public function testRequireSelfOrAdminPassesWhenAdminTargetingOther(): void
    {
        $db = $this->createMysqliMock([
            '202_api_keys' => ['user_id' => 1],
            '202_user_role' => [['role_name' => 'admin']],
        ]);

        $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
        $auth->requireSelfOrAdmin(99);
        $this->assertTrue(true);
    }

    public function testRequireSelfOrAdminThrows403WhenNonAdminTargetingOther(): void
    {
        $db = $this->createMysqliMock([
            '202_api_keys' => ['user_id' => 5],
            '202_user_role' => [['role_name' => 'user']],
        ]);

        $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
        $this->expectException(AuthException::class);
        $this->expectExceptionCode(403);
        $auth->requireSelfOrAdmin(99);
    }
}
