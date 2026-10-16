<?php

declare(strict_types=1);

namespace Tests\Connect;

use Api\V3\Auth;
use Api\V3\Support\AiConnect;
use Tests\TestCase;

/**
 * What a key made for an AI connection can reach, read through the same
 * Auth the API answers with: the areas the hosted tools use, and nothing of
 * users, API keys or the install's settings, whatever the access.
 */
final class AiConnectScopeTest extends TestCase
{
    private function keyFor(string $access): Auth
    {
        $db = $this->createMysqliMock([
            "SHOW COLUMNS FROM 202_api_keys LIKE 'scope'" => ['Field' => 'scope'],
            '202_api_keys' => ['user_id' => 5, 'user_active' => 1, 'scope' => (string) AiConnect::scopeFor($access)],
            '202_user_role' => [['role_name' => 'admin']],
        ]);
        return Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
    }

    public function testTheAreasAreKnownAndLeaveOutUsersSystemAndSync(): void
    {
        foreach (array_merge(AiConnect::READ_AREAS, AiConnect::WRITE_AREAS) as $area) {
            self::assertContains($area, Auth::KNOWN_SCOPE_AREAS, $area);
        }
        self::assertSame([], array_values(array_diff(AiConnect::WRITE_AREAS, AiConnect::READ_AREAS)));
        foreach (['users', 'system', 'sync'] as $area) {
            self::assertNotContains($area, AiConnect::READ_AREAS, $area);
        }
        foreach (AiConnect::ACCESSES as $access) {
            $tokens = explode(',', (string) AiConnect::scopeFor($access));
            self::assertSame($tokens, array_values(array_unique($tokens)), $access);
            foreach ($tokens as $token) {
                self::assertTrue(Auth::isValidScopeToken($token), "$access: $token");
            }
        }
    }

    public function testEachAccessReachesItsAreasAndNoOthers(): void
    {
        foreach (AiConnect::ACCESSES as $access) {
            $key = $this->keyFor($access);
            foreach (Auth::KNOWN_SCOPE_AREAS as $area) {
                $reads = in_array($area, AiConnect::READ_AREAS, true);
                $changes = in_array($area, AiConnect::WRITE_AREAS, true);
                self::assertSame($reads, $key->hasScope("$area:read"), "$access $area:read");
                self::assertSame(
                    $changes && $access !== 'read',
                    $key->hasScope("$area:stage"),
                    "$access $area:stage"
                );
                self::assertSame($changes && $access === 'write', $key->hasScope("$area:write"), "$access $area:write");
            }
            // It cannot hand out more than it holds: no global key, so no key
            // that reaches users, API keys or settings.
            foreach (['*', 'read', 'stage', 'write'] as $global) {
                self::assertFalse($key->coversScopeToken($global), "$access mints $global");
            }
        }
    }

    public function testAConnectRouteRefusesAFlagItCannotHonour(): void
    {
        self::assertSame('dry_run', AiConnect::withheldFlag(['dry_run' => '1']));
        self::assertSame('staged', AiConnect::withheldFlag(['staged' => '0']));
        self::assertSame('staged', AiConnect::withheldFlag(['staged' => '']));
        self::assertNull(AiConnect::withheldFlag([]));
        self::assertNull(AiConnect::withheldFlag(['other' => '1']));
    }
}
