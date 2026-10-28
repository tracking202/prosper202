<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Auth;
use Api\V3\AuthException;
use Api\V3\InsufficientScopeException;
use Api\V3\Support\AiConnect;
use Api\V3\Support\ScopeRefusal;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * A key keeps the scope it was made with, so every AI connection approved
 * before a release added to what connections may do is refused there: the
 * answer has to name the scope and say what grants it, and that differs for
 * a connection (approve it again) and any other key (a new key).
 * ScopeRefusalServedIntegrationTest holds the dispatcher to answering it.
 */
final class ScopeRefusalTest extends TestCase
{
    private const OLD_SENTENCE = "Insufficient API key scope for this operation: requires '%s' (key has: %s).";

    private function authWithScope(string $scope): Auth
    {
        $db = $this->createMysqliMock([
            "SHOW COLUMNS FROM 202_api_keys LIKE 'scope'" => ['Field' => 'scope'],
            '202_api_keys' => ['user_id' => 5, 'user_active' => 1, 'scope' => $scope],
            '202_user_role' => [['role_name' => 'admin']],
        ]);

        return Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
    }

    private function refusal(Auth $auth, string $scope): InsufficientScopeException
    {
        try {
            $auth->requireScope($scope);
        } catch (InsufficientScopeException $e) {
            return $e;
        }
        self::fail("requireScope('$scope') passed");
    }

    /** A connection approved with "Read and change" before ppc-networks could be changed. */
    private static function oldWriteConnectionScope(): string
    {
        $scope = (string) AiConnect::scopeFor('write');
        self::assertStringContainsString('ppc-networks:write', $scope);

        return str_replace('ppc-networks:write', 'ppc-networks:read', $scope);
    }

    public function testRequireScopeNamesTheScopeAndTheKeysOwn(): void
    {
        $e = $this->refusal($this->authWithScope('read,campaigns:stage'), ' Campaigns:Write ');
        self::assertSame(403, $e->getCode());
        self::assertSame('campaigns:write', $e->requiredScope);
        self::assertSame(['read', 'campaigns:stage'], $e->keyScopes);
        self::assertSame(sprintf(self::OLD_SENTENCE, 'campaigns:write', 'read,campaigns:stage'), $e->getMessage());
    }

    public function testARoleRefusalIsNotAScopeRefusal(): void
    {
        $db = $this->createMysqliMock([
            '202_api_keys' => ['user_id' => 5, 'user_active' => 1],
            '202_user_role' => [['role_name' => 'user']],
        ]);
        $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
        try {
            $auth->requireAdmin();
            self::fail('a user passed requireAdmin()');
        } catch (AuthException $e) {
            self::assertNotInstanceOf(InsufficientScopeException::class, $e);
            self::assertSame(403, $e->getCode());
        }
    }

    public function testAPlainKeyIsToldToMakeAKeyWithTheScope(): void
    {
        $e = $this->refusal($this->authWithScope('campaigns:read'), 'ppc-networks:write');
        [$message, $fields] = ScopeRefusal::answer($e, ScopeRefusal::API_KEY);

        $sentence = sprintf(self::OLD_SENTENCE, 'ppc-networks:write', 'campaigns:read');
        self::assertStringStartsWith($sentence . ' ', $message);
        self::assertSame([
            'code' => 'insufficient_scope',
            'required_scope' => 'ppc-networks:write',
            'key_scope' => 'campaigns:read',
            'key_source' => 'api_key',
            'remedy' => $fields['remedy'],
        ], $fields);
        self::assertStringEndsWith($fields['remedy'], $message);
        self::assertStringContainsString("make a key whose scope includes 'ppc-networks:write'", $fields['remedy']);
        self::assertStringContainsString('Personal Settings › API keys', $fields['remedy']);
        self::assertStringNotContainsString('Connect the AI app', $fields['remedy']);
    }

    public function testAConnectionApprovedEarlierIsToldToConnectAgain(): void
    {
        $e = $this->refusal($this->authWithScope(self::oldWriteConnectionScope()), 'ppc-networks:write');
        [$message, $fields] = ScopeRefusal::answer($e, ScopeRefusal::AI_CONNECTION);

        self::assertSame('ai_connection', $fields['key_source']);
        self::assertSame('ppc-networks:write', $fields['required_scope']);
        self::assertSame('write', $fields['connect_access']);
        self::assertStringContainsString('Connect the AI app again and choose "Read and change"', $fields['remedy']);
        self::assertStringNotContainsString('make a key', $fields['remedy']);
        self::assertStringEndsWith($fields['remedy'], $message);
    }

    public function testAConnectionIsNeverToldToChooseLessThanItHas(): void
    {
        // A "Read and change" connection staging a write it may not make yet,
        // and reading an area: the least access covering each is less than
        // what it holds, and choosing that would take its writes away.
        $writer = $this->authWithScope(self::oldWriteConnectionScope());
        foreach (['ppc-networks:stage', 'ppc-networks:read'] as $scope) {
            $e = new InsufficientScopeException($scope, $writer->scopes(), 'refused');
            [, $fields] = ScopeRefusal::answer($e, ScopeRefusal::AI_CONNECTION);
            self::assertSame('write', $fields['connect_access'], $scope);
        }
        $proposer = explode(',', (string) AiConnect::scopeFor('propose'));
        $e = new InsufficientScopeException('ppc-networks:read', $proposer, 'refused');
        self::assertSame('propose', ScopeRefusal::answer($e, ScopeRefusal::AI_CONNECTION)[1]['connect_access']);
        $e = new InsufficientScopeException('ppc-networks:stage', ['reports:read'], 'refused');
        [, $fields] = ScopeRefusal::answer($e, ScopeRefusal::AI_CONNECTION);
        self::assertSame('propose', $fields['connect_access']);
        self::assertStringContainsString('choose "Read, and propose changes"', $fields['remedy']);
    }

    public function testAScopeNoConnectionCanHoldIsNotSentToConnectAgain(): void
    {
        $scope = (string) AiConnect::scopeFor('write');
        foreach (['users:write', 'system:read', 'sync:write', 'write'] as $needed) {
            $e = new InsufficientScopeException($needed, explode(',', $scope), 'refused');
            [, $fields] = ScopeRefusal::answer($e, ScopeRefusal::AI_CONNECTION);
            self::assertArrayHasKey('connect_access', $fields);
            self::assertNull($fields['connect_access'], $needed);
            self::assertStringContainsString("No AI connection's key can hold '$needed'", $fields['remedy']);
            self::assertStringNotContainsString('choose "', $fields['remedy']);
        }
    }

    public function testAKeyOfUnknownSourceIsGivenBothRemedies(): void
    {
        $e = $this->refusal($this->authWithScope(self::oldWriteConnectionScope()), 'ppc-networks:write');
        [, $fields] = ScopeRefusal::answer($e, ScopeRefusal::UNKNOWN);

        self::assertSame('unknown', $fields['key_source']);
        self::assertSame('write', $fields['connect_access']);
        self::assertStringContainsString('choose "Read and change"', $fields['remedy']);
        self::assertStringContainsString("make a key whose scope includes 'ppc-networks:write'", $fields['remedy']);
    }

    public function testEveryAccessTheRemedyNamesIsOnTheApprovalPage(): void
    {
        self::assertSame(AiConnect::ACCESSES, array_keys(AiConnect::ACCESS_LABELS));
        $page = (string) file_get_contents(dirname(__DIR__, 3) . '/202-account/connect-ai.php');
        foreach (AiConnect::ACCESSES as $access) {
            self::assertStringContainsString("AiConnect::ACCESS_LABELS['$access']", $page, $access);
        }
    }

    public function testAccessCoveringFollowsWhatEachAccessMints(): void
    {
        foreach (AiConnect::READ_AREAS as $area) {
            self::assertSame('read', AiConnect::accessCovering("$area:read"), $area);
            $changes = in_array($area, AiConnect::WRITE_AREAS, true);
            self::assertSame($changes ? 'write' : null, AiConnect::accessCovering("$area:write"), $area);
            self::assertSame($changes ? 'propose' : null, AiConnect::accessCovering("$area:stage"), $area);
        }
        foreach (['users:read', 'system:write', 'sync:read', 'read', 'write', 'stage', '*'] as $scope) {
            self::assertNull(AiConnect::accessCovering($scope), $scope);
        }
    }

    public function testAConnectionsLabelIsKnownForOne(): void
    {
        self::assertTrue(AiConnect::isConnectionLabel(AiConnect::label('Claude')));
        // label() cuts at 100 characters; the prefix survives any name.
        self::assertTrue(AiConnect::isConnectionLabel(AiConnect::label(str_repeat('x', 100))));
        foreach ([null, '', 'ai: lower case', 'My key', ' AI: leading space'] as $label) {
            self::assertFalse(AiConnect::isConnectionLabel($label), var_export($label, true));
        }
    }

    /** @return array<string, mixed> */
    private static function labelRow(?string $label): array
    {
        return ['label' => $label];
    }

    public function testKeySourceReadsTheKeysLabel(): void
    {
        $cases = [
            'a connection' => [self::labelRow(AiConnect::label('Claude')), ScopeRefusal::AI_CONNECTION],
            'no label' => [self::labelRow(null), ScopeRefusal::API_KEY],
            'another label' => [self::labelRow('CI deploy'), ScopeRefusal::API_KEY],
        ];
        foreach ($cases as $name => [$row, $want]) {
            $db = $this->createMysqliMock([
                "SHOW COLUMNS FROM 202_api_keys LIKE 'label'" => ['Field' => 'label'],
                'SELECT label FROM 202_api_keys' => $row,
            ]);
            self::assertSame($want, ScopeRefusal::keySource($db, 'key', 5), $name);
        }
    }

    public function testKeySourceBeforeTheLabelColumnIsAPlainKey(): void
    {
        $db = $this->createMysqliMock(["SHOW COLUMNS FROM 202_api_keys LIKE 'label'" => []]);
        self::assertSame(ScopeRefusal::API_KEY, ScopeRefusal::keySource($db, 'key', 5));
    }

    public function testKeySourceThatCannotBeReadIsUnknownNotAPlainKey(): void
    {
        $removed = $this->createMysqliMock([
            "SHOW COLUMNS FROM 202_api_keys LIKE 'label'" => ['Field' => 'label'],
            'SELECT label FROM 202_api_keys' => [],
        ]);
        self::assertSame(ScopeRefusal::UNKNOWN, ScopeRefusal::keySource($removed, 'key', 5));

        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $failing */
        $failing = $this->getMockBuilder(\mysqli::class)->disableOriginalConstructor()->getMock();
        $failing->method('prepare')->willReturn(false);
        $log = ini_set('error_log', '/dev/null');
        try {
            self::assertSame(ScopeRefusal::UNKNOWN, ScopeRefusal::keySource($failing, 'key', 5));
        } finally {
            ini_set('error_log', (string) $log);
        }
    }

    /**
     * keySource() reads "AI: " as a connection's label because AiConnect is
     * the only writer of a key's label. A second writer (a key named by its
     * owner) would make that label a claim; this fails until the reading is
     * decided again.
     */
    public function testOnlyTheAiConnectionWritesAKeysLabel(): void
    {
        $write = '/(?:INSERT\s+INTO|UPDATE|REPLACE\s+INTO)\s+`?202_api_keys`?[^;]*?\blabel\b/is';
        $writers = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (preg_match($write, $source) === 1) {
                $writers[] = $path;
            }
        }
        self::assertSame(['api/v3/Support/AiConnect.php'], $writers);
    }
}
