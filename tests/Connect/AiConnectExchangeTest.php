<?php

declare(strict_types=1);

namespace Tests\Connect;

use Api\V3\Controllers\UsersController;
use Api\V3\Support\AiConnect;
use Api\V3\Support\ServerStateStore;
use PHPUnit\Framework\TestCase;
use Prosper202\License\ShellAccessCache;
use Tests\Support\FakeMysqliConnection;

/**
 * The exchange past the verifier: each refusal is answered before a key is
 * written, and a good exchange writes the scope the approval stored.
 * Connecting is free but needs an active free ClickServer licence: the
 * install's key answered valid by Tracking202 (paid or not), seeded here in
 * the licence cache the gate reads before it would ask the service. The
 * answer carries the key's hash only when the key could be read.
 */
final class AiConnectExchangeTest extends TestCase
{
    private string $stateDir;
    private string $licenceKey;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/p202-ai-exchange-' . bin2hex(random_bytes(6));
        mkdir($this->stateDir, 0700);
        putenv('P202_SERVER_STATE_DIR=' . $this->stateDir);
        $this->licenceKey = 'exchange-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        ShellAccessCache::invalidate($this->licenceKey);
        putenv('P202_SERVER_STATE_DIR');
        exec('rm -rf ' . escapeshellarg($this->stateDir));
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: FakeMysqliConnection} */
    private function exchange(
        string $storedScope,
        bool $userActive,
        ?string $clickServerKey = null,
        ?bool $valid = true
    ): array {
        $clickServerKey ??= $this->licenceKey;
        if ($valid !== null) {
            ShellAccessCache::write($this->licenceKey, $valid);
        }
        $verifier = str_repeat('v', 50);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = AiConnect::approve(new ServerStateStore($this->stateDir), 7, $storedScope, $challenge, 'Claude');

        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'label'", [['Field' => 'label']]);
        $user = $userActive ? [['user_name' => 'ann']] : [];
        $db->whenQueryContainsReturnRows('SELECT user_name FROM 202_users', $user);
        $key = [['p202_customer_api_key' => $clickServerKey]];
        $db->whenQueryContainsReturnRows('SELECT p202_customer_api_key FROM 202_users', $key);
        // Nobody else on the install holds a key either.
        $db->whenQueryContainsReturnRows('SELECT u.install_hash', [['install_hash' => 'h', 'owner_key' => '']]);
        $db->whenQueryContainsInsertId('INSERT INTO 202_api_keys', 41);
        $payload = ['code' => $code, 'code_verifier' => $verifier];
        [$status, $body] = AiConnect::exchange($db, new ServerStateStore($this->stateDir), $payload);
        return [$status, $body, $db];
    }

    public function testAGoodExchangeWritesTheStoredScopeAndAnswersItsAccess(): void
    {
        foreach (AiConnect::ACCESSES as $access) {
            $scope = (string) AiConnect::scopeFor($access);
            [$status, $body, $db] = $this->exchange($scope, true);
            self::assertSame(200, $status, $access . ': ' . json_encode($body));
            self::assertSame([$scope, $access], [$body['data']['scope'], $body['data']['access']]);
            self::assertSame(AiConnect::licenceKeyHash($this->licenceKey), $body['data']['licence_key_sha256']);
            $inserts = $db->statementsContaining('INSERT INTO 202_api_keys');
            self::assertCount(1, $inserts, $access);
            self::assertContains($scope, $inserts[0]->boundValues, $access);
        }
    }

    public function testAnApprovalStoredWithoutAKnownAccessMakesNoKey(): void
    {
        foreach (['read', 'write', 'read,stage', '*'] as $global) {
            [$status, $body, $db] = $this->exchange($global, true);
            self::assertSame([500, 'server_error'], [$status, $body['code']], $global);
            self::assertStringContainsString('without a known access', $body['message']);
            self::assertSame([], $db->statementsContaining('INSERT INTO 202_api_keys'), $global);
        }
    }

    public function testAUserWhoCanNoLongerSignInGetsNoKey(): void
    {
        [$status, $body, $db] = $this->exchange((string) AiConnect::scopeFor('read'), false);
        self::assertSame([400, 'invalid_grant'], [$status, $body['code']]);
        self::assertStringContainsString('can no longer sign in', $body['message']);
        self::assertSame([], $db->statementsContaining('INSERT INTO 202_api_keys'));
    }

    public function testAValidUnpaidKeyConnects(): void
    {
        // What the cache holds is the validity verdict: a valid key whose
        // account has no paid subscription is the free licence.
        [$status, $body] = $this->exchange((string) AiConnect::scopeFor('read'), true, null, true);
        self::assertSame(200, $status, (string) json_encode($body));
        self::assertTrue($body['data']['licensed']);
        self::assertSame(AiConnect::licenceKeyHash($this->licenceKey), $body['data']['licence_key_sha256']);
    }

    public function testAKeyTracking202RefusedGetsNoKeyAndALicenceRefusal(): void
    {
        [$status, $body, $db] = $this->exchange((string) AiConnect::scopeFor('write'), true, null, false);
        self::assertSame([402, 'licence'], [$status, $body['code']]);
        self::assertStringContainsString('Connecting an AI assistant is free', $body['message']);
        self::assertStringContainsString('active free ClickServer licence', $body['message']);
        self::assertStringContainsString('CPM* newsletter', $body['message'], 'how to keep a free licence active');
        self::assertStringContainsString('https://my.tracking202.com/api/customers/subscriptions/support', $body['message']);
        self::assertDoesNotMatchRegularExpression('/\b(Pro|trials?)\b/i', $body['message'], 'no paid tier is offered');
        self::assertSame([], $db->statementsContaining('INSERT INTO 202_api_keys'));
    }

    public function testAnInstallWithNoClickServerKeyGetsALicenceRefusalNotAServerError(): void
    {
        [$status, $body, $db] = $this->exchange((string) AiConnect::scopeFor('write'), true, '', null);
        self::assertSame([402, 'licence'], [$status, $body['code']], (string) json_encode($body));
        self::assertStringContainsString('api-key-required.php', $body['message']);
        self::assertSame([], $db->statementsContaining('INSERT INTO 202_api_keys'));
    }

    public function testUnreachableWithinTheGracePeriodConnects(): void
    {
        // A valid answer 2 days old (stale, inside the 30 days), then an
        // outage: the hold-off is set, the record (fake DB) cannot be read,
        // so the cached answer within the window decides.
        ShellAccessCache::write($this->licenceKey, true);
        $path = (new \ReflectionMethod(ShellAccessCache::class, 'path'))->invoke(null, $this->licenceKey);
        self::assertTrue(touch((string) $path, time() - 2 * 86400));
        clearstatcache();
        ShellAccessCache::markUnknown($this->licenceKey);
        [$status, $body] = $this->exchange((string) AiConnect::scopeFor('read'), true, null, null);
        self::assertSame(200, $status, (string) json_encode($body));
    }

    public function testUnreachableWithNothingInTheGracePeriodSaysItCouldNotBeChecked(): void
    {
        ShellAccessCache::markUnknown($this->licenceKey);
        [$status, $body, $db] = $this->exchange((string) AiConnect::scopeFor('read'), true, null, null);
        self::assertSame([402, 'licence'], [$status, $body['code']]);
        self::assertStringContainsString('could not be checked with Tracking202', $body['message']);
        self::assertSame([], $db->statementsContaining('INSERT INTO 202_api_keys'));
    }

    public function testAKeyThatCannotBeReadStillConnectsWithoutAHash(): void
    {
        $verifier = str_repeat('w', 50);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $scope = (string) AiConnect::scopeFor('read');
        $code = AiConnect::approve(new ServerStateStore($this->stateDir), 7, $scope, $challenge, 'Claude');
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'label'", [['Field' => 'label']]);
        $db->whenQueryContainsReturnRows('SELECT user_name FROM 202_users', [['user_name' => 'ann']]);
        $db->whenQueryContainsResultFails('SELECT p202_customer_api_key FROM 202_users');
        $db->whenQueryContainsInsertId('INSERT INTO 202_api_keys', 41);
        [$status, $body] = AiConnect::exchange(
            $db,
            new ServerStateStore($this->stateDir),
            ['code' => $code, 'code_verifier' => $verifier]
        );
        // The install's own error is not a missing licence: it connects, and
        // the answer carries no hash.
        self::assertSame(200, $status, (string) json_encode($body));
        self::assertArrayNotHasKey('licence_key_sha256', $body['data']);
        self::assertCount(1, $db->statementsContaining('INSERT INTO 202_api_keys'));
    }

    /** label came with 1.9.78; an install whose upgrade has not run yet has no such column. */
    public function testTheKeyListAsksForLabelOnlyWhereTheColumnExists(): void
    {
        foreach ([true, false] as $hasLabel) {
            $db = new FakeMysqliConnection();
            $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'scope'", [['Field' => 'scope']]);
            $label = $hasLabel ? [['Field' => 'label']] : [];
            $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'label'", $label);
            $db->whenQueryContainsReturnRows('FROM 202_api_keys WHERE user_id', []);
            (new UsersController($db))->listApiKeys(7);
            $select = $db->statementsContaining('FROM 202_api_keys WHERE user_id');
            self::assertCount(1, $select);
            self::assertSame($hasLabel, str_contains($select[0]->sql, 'label'), $select[0]->sql);
        }
    }
}
