<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Auth;
use Api\V3\AuthException;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    private function createResultMock(array $rows): \mysqli_result
    {
        /** @var \mysqli_result&\PHPUnit\Framework\MockObject\MockObject $result */
        $result = $this->getMockBuilder(\mysqli_result::class)
            ->disableOriginalConstructor()
            ->getMock();

        $index = 0;
        $result->method('fetch_assoc')->willReturnCallback(
            function () use (&$index, $rows) {
                return $rows[$index++] ?? null;
            }
        );

        return $result;
    }

    private function createStmtMock(bool $executeResult, \mysqli_result|false $result): \mysqli_stmt
    {
        /** @var \mysqli_stmt&\PHPUnit\Framework\MockObject\MockObject $stmt */
        $stmt = $this->getMockBuilder(\mysqli_stmt::class)
            ->disableOriginalConstructor()
            ->getMock();

        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn($executeResult);
        $stmt->method('get_result')->willReturn($result);
        $stmt->method('close')->willReturn(true);

        return $stmt;
    }

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

    public function testFromRequestThrowsWhenApiKeyLookupExecuteFails(): void
    {
        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        $stmt = $this->createStmtMock(false, $this->createResultMock([]));
        $db->method('prepare')->willReturn($stmt);

        $this->expectException(AuthException::class);
        $this->expectExceptionCode(500);
        Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
    }

    public function testFromRequestThrowsWhenApiKeyLookupResultFails(): void
    {
        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        $stmt = $this->createStmtMock(true, false);
        $db->method('prepare')->willReturn($stmt);

        $this->expectException(AuthException::class);
        $this->expectExceptionCode(500);
        Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
    }

    public function testFromRequestThrowsWhenRoleLookupExecuteFails(): void
    {
        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        $showScopeStmt = $this->createStmtMock(true, $this->createResultMock([]));
        $apiKeyResult = $this->createResultMock([['user_id' => 7]]);
        $apiStmt = $this->createStmtMock(true, $apiKeyResult);
        $roleStmt = $this->createStmtMock(false, $this->createResultMock([]));

        $db->method('prepare')->willReturnOnConsecutiveCalls($showScopeStmt, $apiStmt, $roleStmt);

        $this->expectException(AuthException::class);
        $this->expectExceptionCode(500);
        Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
    }

    public function testFromRequestThrowsWhenRoleLookupResultFails(): void
    {
        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        $showScopeStmt = $this->createStmtMock(true, $this->createResultMock([]));
        $apiKeyResult = $this->createResultMock([['user_id' => 7]]);
        $apiStmt = $this->createStmtMock(true, $apiKeyResult);
        $roleStmt = $this->createStmtMock(true, false);

        $db->method('prepare')->willReturnOnConsecutiveCalls($showScopeStmt, $apiStmt, $roleStmt);

        $this->expectException(AuthException::class);
        $this->expectExceptionCode(500);
        Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
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

    public function testRequireScopePassesWhenScopePresent(): void
    {
        $db = $this->createMysqliMock([
            "SHOW COLUMNS FROM 202_api_keys LIKE 'scope'" => ['Field' => 'scope'],
            '202_api_keys' => ['user_id' => 5, 'scope' => 'sync:read,sync:write'],
            '202_user_role' => [['role_name' => 'user']],
        ]);

        $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
        $auth->requireScope('sync:read');
        $this->assertTrue(true);
    }

    public function testRequireScopeThrowsWhenScopeMissing(): void
    {
        $db = $this->createMysqliMock([
            "SHOW COLUMNS FROM 202_api_keys LIKE 'scope'" => ['Field' => 'scope'],
            '202_api_keys' => ['user_id' => 5, 'scope' => 'sync:read'],
            '202_user_role' => [['role_name' => 'user']],
        ]);

        $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
        $this->expectException(AuthException::class);
        $this->expectExceptionCode(403);
        $auth->requireScope('sync:write');
    }

    private function authWithScope(string $scope, string $role = 'user'): Auth
    {
        $db = $this->createMysqliMock([
            "SHOW COLUMNS FROM 202_api_keys LIKE 'scope'" => ['Field' => 'scope'],
            '202_api_keys' => ['user_id' => 5, 'scope' => $scope],
            '202_user_role' => [['role_name' => $role]],
        ]);

        return Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
    }

    public function testGlobalReadScopeSatisfiesAreaReadsButNoWrites(): void
    {
        $auth = $this->authWithScope('read');
        $this->assertTrue($auth->hasScope('reports:read'));
        $this->assertTrue($auth->hasScope('campaigns:read'));
        $this->assertTrue($auth->hasScope('ltv:read'));
        $this->assertFalse($auth->hasScope('campaigns:write'));
        $this->assertFalse($auth->hasScope('sync:write'));
    }

    public function testGlobalWriteScopeImpliesRead(): void
    {
        $auth = $this->authWithScope('write');
        $this->assertTrue($auth->hasScope('campaigns:write'));
        $this->assertTrue($auth->hasScope('campaigns:read'));
        $this->assertTrue($auth->hasScope('reports:read'));
    }

    public function testAreaWriteImpliesAreaReadOnly(): void
    {
        $auth = $this->authWithScope('ltv:write');
        $this->assertTrue($auth->hasScope('ltv:write'));
        $this->assertTrue($auth->hasScope('ltv:read'));
        $this->assertFalse($auth->hasScope('sync:read'));
        $this->assertFalse($auth->hasScope('reports:read'));
    }

    public function testScopedKeyAttenuatesAdmin(): void
    {
        $auth = $this->authWithScope('reports:read', 'Admin');
        $this->assertTrue($auth->isAdmin());
        $this->assertTrue($auth->hasScope('reports:read'));
        $this->assertFalse($auth->hasScope('campaigns:write'));

        $this->expectException(AuthException::class);
        $this->expectExceptionCode(403);
        $auth->requireScope('campaigns:write');
    }

    public function testUnscopedKeyKeepsFullAccess(): void
    {
        // No scope column at all (pre-1.9.75 schema): the key parses to `*`.
        $db = $this->createMysqliMock([
            '202_api_keys' => ['user_id' => 5],
            '202_user_role' => [['role_name' => 'user']],
        ]);
        $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
        $this->assertTrue($auth->hasFullScope());
        $this->assertTrue($auth->hasScope('campaigns:write'));
        $this->assertTrue($auth->hasScope('sync:write'));
    }

    public function testRequireScopeErrorNamesTheRequiredScope(): void
    {
        $auth = $this->authWithScope('read');
        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches("/requires 'campaigns:write'/");
        $auth->requireScope('campaigns:write');
    }

    public function testSuperUserRoleCountsAsAdmin(): void
    {
        // The installer grants role 1, "Super user" — the install owner must
        // pass the v3 admin gates.
        $db = $this->createMysqliMock([
            '202_api_keys' => ['user_id' => 1],
            '202_user_role' => [['role_name' => 'Super user']],
        ]);
        $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
        $this->assertTrue($auth->isAdmin());
    }

    public function testIsValidScopeTokenGrammar(): void
    {
        $this->assertTrue(Auth::isValidScopeToken('*'));
        $this->assertTrue(Auth::isValidScopeToken('read'));
        $this->assertTrue(Auth::isValidScopeToken('write'));
        $this->assertTrue(Auth::isValidScopeToken('reports:read'));
        $this->assertTrue(Auth::isValidScopeToken('forecast-events:write'));
        $this->assertTrue(Auth::isValidScopeToken(' LTV:READ '));

        $this->assertFalse(Auth::isValidScopeToken(''));
        $this->assertFalse(Auth::isValidScopeToken('reports'));
        $this->assertFalse(Auth::isValidScopeToken('reports:delete'));
        $this->assertFalse(Auth::isValidScopeToken('bogus:read'));
        $this->assertFalse(Auth::isValidScopeToken('a:b:c'));
    }

    public function testStageScopeIsProposeOnly(): void
    {
        // The propose-only agent shape: read everything, stage writes,
        // perform none.
        $auth = $this->authWithScope('read,stage');
        $this->assertTrue($auth->hasScope('campaigns:stage'));
        $this->assertTrue($auth->hasScope('users:stage'));
        $this->assertTrue($auth->hasScope('campaigns:read'));
        $this->assertFalse($auth->hasScope('campaigns:write'));
        $this->assertFalse($auth->hasScope('staged-changes:write'));

        // stage alone grants neither reads nor writes.
        $stageOnly = $this->authWithScope('stage');
        $this->assertTrue($stageOnly->hasScope('campaigns:stage'));
        $this->assertFalse($stageOnly->hasScope('campaigns:read'));
        $this->assertFalse($stageOnly->hasScope('campaigns:write'));
    }

    public function testWriteScopeImpliesStage(): void
    {
        $this->assertTrue($this->authWithScope('write')->hasScope('campaigns:stage'));
        $this->assertTrue($this->authWithScope('campaigns:write')->hasScope('campaigns:stage'));
        $this->assertFalse($this->authWithScope('campaigns:write')->hasScope('rotators:stage'));
    }

    public function testStageScopeTokensAreValid(): void
    {
        $this->assertTrue(Auth::isValidScopeToken('stage'));
        $this->assertTrue(Auth::isValidScopeToken('reports:stage'));
        $this->assertTrue(Auth::isValidScopeToken('staged-changes:read'));
        $this->assertFalse(Auth::isValidScopeToken('stage:read'));
    }

    public function testScopeAreaForPathMapsRouteFamilies(): void
    {
        $this->assertSame('campaigns', Auth::scopeAreaForPath('/campaigns/42'));
        $this->assertSame('sync', Auth::scopeAreaForPath('/changes/campaigns'));
        $this->assertSame('sync', Auth::scopeAreaForPath('/audit/sync-jobs'));
        $this->assertSame('staged-changes', Auth::scopeAreaForPath('/staged-changes/chg_ab/apply'));
        $this->assertNull(Auth::scopeAreaForPath('/capabilities'));
        $this->assertNull(Auth::scopeAreaForPath('/system/health'));
        $this->assertSame('system', Auth::scopeAreaForPath('/system/version'));
        $this->assertNull(Auth::scopeAreaForPath('/'));
    }

    public function testCoversScopeTokenNeverEscalates(): void
    {
        $readOnly = $this->authWithScope('read');
        $this->assertTrue($readOnly->coversScopeToken('read'));
        $this->assertTrue($readOnly->coversScopeToken('reports:read'));
        $this->assertFalse($readOnly->coversScopeToken('write'));
        $this->assertFalse($readOnly->coversScopeToken('reports:write'));
        $this->assertFalse($readOnly->coversScopeToken('*'));

        $writeKey = $this->authWithScope('write');
        $this->assertTrue($writeKey->coversScopeToken('read'));
        $this->assertTrue($writeKey->coversScopeToken('write'));
        $this->assertTrue($writeKey->coversScopeToken('campaigns:write'));
        $this->assertFalse($writeKey->coversScopeToken('*'));
    }
}
