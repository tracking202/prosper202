<?php

declare(strict_types=1);

namespace Tests\Connect;

use Api\V3\Controllers\UsersController;
use Api\V3\Support\AiConnect;
use Api\V3\Support\ServerStateStore;
use PHPUnit\Framework\TestCase;
use Prosper202\License\CliAccessCache;
use Tests\Support\FakeMysqliConnection;

/**
 * The exchange past the verifier: each refusal is answered before a key is
 * written, and a good exchange writes the scope the approval stored. The
 * licence verdict is seeded in the CLI licence cache, which cliAccess()
 * reads before it would ask the licence service.
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
        CliAccessCache::invalidate($this->licenceKey);
        putenv('P202_SERVER_STATE_DIR');
        exec('rm -rf ' . escapeshellarg($this->stateDir));
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: FakeMysqliConnection} */
    private function exchange(string $storedScope, bool $userActive, ?bool $paid): array
    {
        if ($paid !== null) {
            CliAccessCache::write($this->licenceKey, $paid);
        }
        $verifier = str_repeat('v', 50);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = AiConnect::approve(new ServerStateStore($this->stateDir), 7, $storedScope, $challenge, 'Claude');

        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'label'", [['Field' => 'label']]);
        $user = $userActive ? [['user_name' => 'ann']] : [];
        $db->whenQueryContainsReturnRows('SELECT user_name FROM 202_users', $user);
        $key = [['p202_customer_api_key' => $this->licenceKey]];
        $db->whenQueryContainsReturnRows('SELECT p202_customer_api_key FROM 202_users', $key);
        $db->whenQueryContainsInsertId('INSERT INTO 202_api_keys', 41);
        $payload = ['code' => $code, 'code_verifier' => $verifier];
        [$status, $body] = AiConnect::exchange($db, new ServerStateStore($this->stateDir), $payload);
        return [$status, $body, $db];
    }

    public function testAGoodExchangeWritesTheStoredScopeAndAnswersItsAccess(): void
    {
        foreach (AiConnect::ACCESSES as $access) {
            $scope = (string) AiConnect::scopeFor($access);
            [$status, $body, $db] = $this->exchange($scope, true, true);
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
            [$status, $body, $db] = $this->exchange($global, true, true);
            self::assertSame([500, 'server_error'], [$status, $body['code']], $global);
            self::assertStringContainsString('without a known access', $body['message']);
            self::assertSame([], $db->statementsContaining('INSERT INTO 202_api_keys'), $global);
        }
    }

    public function testAUserWhoCanNoLongerSignInGetsNoKey(): void
    {
        [$status, $body, $db] = $this->exchange((string) AiConnect::scopeFor('read'), false, true);
        self::assertSame([400, 'invalid_grant'], [$status, $body['code']]);
        self::assertStringContainsString('can no longer sign in', $body['message']);
        self::assertSame([], $db->statementsContaining('INSERT INTO 202_api_keys'));
    }

    public function testAnUnlicensedInstallGetsNoKey(): void
    {
        [$status, $body, $db] = $this->exchange((string) AiConnect::scopeFor('write'), true, false);
        self::assertSame([402, 'licence'], [$status, $body['code']]);
        self::assertSame([], $db->statementsContaining('INSERT INTO 202_api_keys'));
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
