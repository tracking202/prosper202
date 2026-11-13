<?php

declare(strict_types=1);

namespace Tests\Connect;

use Api\V3\Auth;
use Api\V3\AuthException;
use Api\V3\Support\AdConnectionKeyAccess;
use Api\V3\Support\AiConnect;
use Tests\TestCase;

/**
 * An AI connection's key reaches ChatGPT Ads connections (reads; a check with write)
 * and their campaigns (pause, activate, template, event settings), never
 * the connection's key: connecting, replacing or deleting it, and setup need
 * full access or global write.
 */
final class AdConnectionKeyAccessTest extends TestCase
{
    private function keyWith(string $scope): Auth
    {
        $db = $this->createMysqliMock([
            "SHOW COLUMNS FROM 202_api_keys LIKE 'scope'" => ['Field' => 'scope'],
            '202_api_keys' => ['user_id' => 5, 'user_active' => 1, 'scope' => $scope],
            '202_user_role' => [['role_name' => 'admin']],
        ]);

        return Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
    }

    public function testAnAiConnectionReadsAndChangesCampaignsButNeverTheKey(): void
    {
        foreach (AiConnect::ACCESSES as $access) {
            $key = $this->keyWith((string) AiConnect::scopeFor($access));
            self::assertTrue($key->hasScope('ad-connections:read'), $access . ' reads connections');
            self::assertSame($access === 'write', $key->hasScope('ad-connections:write'), $access . ' changes campaigns only with write');
            try {
                AdConnectionKeyAccess::require($key);
                self::fail($access . ' reached the key');
            } catch (AuthException $e) {
                self::assertSame(403, $e->getCode());
                self::assertStringContainsString('full access or write scope', $e->getMessage());
            }
        }
    }

    public function testAFullOrGlobalWriteKeyHandlesTheKey(): void
    {
        foreach (['*', 'write', 'read,write'] as $scope) {
            AdConnectionKeyAccess::require($this->keyWith($scope));
            $this->addToAssertionCount(1);
        }
        $this->expectException(AuthException::class);
        AdConnectionKeyAccess::require($this->keyWith('read,ad-connections:write'));
    }
}
