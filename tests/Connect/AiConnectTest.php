<?php

declare(strict_types=1);

namespace Tests\Connect;

use Api\V3\Controllers\ConnectController;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\AiConnect;
use Api\V3\Support\ServerStateStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;

/**
 * The install's side of connecting an AI app: what the approval page takes,
 * and the one-time code it gives Tracking202's MCP server. The parts that
 * write and remove a key run end to end against an instance from the MCP
 * server's repository (workers/mcp/test/e2e-connect.py in p202-edge); their
 * SQL is prepared against the schema by tests/Schema/StaticSqlSchemaTest.
 */
final class AiConnectTest extends TestCase
{
    private string $stateDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/p202-ai-connect-' . bin2hex(random_bytes(6));
        mkdir($this->stateDir, 0700);
        putenv('P202_SERVER_STATE_DIR=' . $this->stateDir);
    }

    protected function tearDown(): void
    {
        putenv('P202_SERVER_STATE_DIR');
        exec('rm -rf ' . escapeshellarg($this->stateDir));
    }

    /** @return array<string, string> */
    private static function request(array $override = []): array
    {
        return $override + [
            'state' => str_repeat('a', 43) . '.' . str_repeat('B', 43),
            'code_challenge' => str_repeat('c', 43),
            'code_challenge_method' => 'S256',
            'client_name' => 'Claude',
            'client_domain' => 'claude.ai',
            'redirect_host' => 'claude.ai',
            'install' => 'https://track.example.com/',
            'redirect_uri' => 'https://mcp.tracking202.com/callback',
        ];
    }

    /**
     * The page where a person consents says what the hosted connector records.
     * The relay that records it is elsewhere; this holds the words here, so a
     * change to them is made on purpose (and the relay checked against them).
     */
    public function testTheApprovalPageSaysWhatTheConnectorRecords(): void
    {
        $page = preg_replace('/\s+/', ' ', (string) file_get_contents(dirname(__DIR__, 2) . '/202-account/connect-ai.php'));
        foreach ([
            "To improve the connector, Tracking202's MCP server records which tools the app calls, the names (not the values) of what it passes them, whether they work, with the error when one fails, and the app's one-line description of what each request is for, which can repeat what you asked.",
            'Email addresses, web addresses and long numbers are removed first.',
            "The records are kept with this install's ClickServer licence, so Tracking202 sees them for your account.",
            'It does not record what the tools return.',
        ] as $sentence) {
            self::assertStringContainsString($sentence, (string) $page);
        }
    }

    public function testAnApprovalRequestFromTheMcpServerIsRead(): void
    {
        $read = AiConnect::readRequest(self::request());
        self::assertIsArray($read);
        self::assertSame(['Claude', 'claude.ai', 'claude.ai', 'https://track.example.com/'], [
            $read['client_name'], $read['client_domain'], $read['redirect_host'], $read['install'],
        ]);
        foreach (['[::1]', 'com.example.app:/cb', 'Claude.AI', 'my_host.example'] as $host) {
            self::assertIsArray(AiConnect::readRequest(self::request(['redirect_host' => $host])), $host);
        }
        $unverified = AiConnect::readRequest(self::request(['client_domain' => '']));
        self::assertIsArray($unverified);
        self::assertSame('', $unverified['client_domain']);
    }

    public function testTheApprovalGoesOnlyToTheMcpServer(): void
    {
        $elsewhere = [
            'https://evil.example/callback',
            'https://mcp.tracking202.com.evil.example/callback',
            'https://mcp.tracking202.com/callback/',
            'http://mcp.tracking202.com/callback',
            '',
        ];
        foreach ($elsewhere as $uri) {
            self::assertIsString(AiConnect::readRequest(self::request(['redirect_uri' => $uri])), $uri);
        }
        $asList = ['redirect_uri' => ['https://mcp.tracking202.com/callback']] + self::request();
        self::assertIsString(AiConnect::readRequest($asList));
    }

    public function testEveryOtherParameterIsChecked(): void
    {
        foreach (
            [
            ['state' => 'short.state'],
            ['state' => str_repeat('a', 43)],
            ['state' => str_repeat('a', 43) . '.' . str_repeat('b', 43) . '.x'],
            ['code_challenge' => str_repeat('c', 42)],
            ['code_challenge' => str_repeat('c', 42) . '='],
            ['code_challenge_method' => 'plain'],
            ['client_name' => ''],
            ['client_name' => str_repeat('x', 101)],
            ['client_name' => "Claude\r\nX"],
            ['client_domain' => 'claude.ai/evil'],
            ['redirect_host' => ''],
            ['redirect_host' => "evil.example\nx"],
            ['redirect_host' => str_repeat('a', 256)],
            ['install' => ''],
            ['install' => 'track.example.com'],
            ['install' => 'https://t.example.com/ Your account is suspended'],
            ['install' => 'https://t.example.com/a?b=1'],
            ] as $bad
        ) {
            self::assertIsString(AiConnect::readRequest(self::request($bad)), json_encode($bad));
        }
    }

    /**
     * Another install cannot send a person here with its own approval link:
     * the page holds the install the MCP server was told to its own address.
     */
    public function testAnInstallIsItselfAndNoOther(): void
    {
        foreach (
            [
            ['https://track.example.com/', 'https://track.example.com/'],
            ['https://Track.Example.com/', 'http://track.example.com/'],
            ['https://track.example.com:443/p202/', 'https://track.example.com/p202'],
            ] as [$a, $b]
        ) {
            self::assertTrue(AiConnect::sameInstall($a, $b), "$a = $b");
        }
        foreach (
            [
            ['https://attacker.example/', 'https://track.example.com/'],
            ['https://track.example.com/', 'https://track.example.com/other/'],
            ['https://track.example.com:8443/', 'https://track.example.com/'],
            ['https://track.example.com.attacker.example/', 'https://track.example.com/'],
            ['', 'https://track.example.com/'],
            ] as [$a, $b]
        ) {
            self::assertFalse(AiConnect::sameInstall($a, $b), "$a != $b");
        }
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/202-account/connect-ai.php');
        self::assertStringContainsString("!AiConnect::sameInstall(\$request['install'], \$self)", $page);
        self::assertStringContainsString("'iss' => \$self", $page);
    }

    public function testOnlyTheThreeAccessesMintAScope(): void
    {
        foreach (AiConnect::ACCESSES as $access) {
            self::assertSame($access, AiConnect::accessFor((string) AiConnect::scopeFor($access)), $access);
        }
        foreach (['', '*', 'full', 'READ', 'read,stage', 'write '] as $other) {
            self::assertNull(AiConnect::scopeFor($other), $other);
        }
        // The global scopes an earlier draft minted are no access now.
        foreach (['read', 'read,stage', 'write', '*'] as $global) {
            self::assertNull(AiConnect::accessFor($global), $global);
        }
        self::assertSame('read', AiConnect::DEFAULT_ACCESS);
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/202-account/connect-ai.php');
        self::assertStringContainsString("\$access === AiConnect::DEFAULT_ACCESS ? ' checked'", $page);
    }

    public function testTheKeyIsLabelledWithTheApp(): void
    {
        self::assertSame('AI: Claude via mcp.tracking202.com', AiConnect::label('Claude'));
        self::assertSame(100, mb_strlen(AiConnect::label(str_repeat('é', 120))));
    }

    /**
     * The licence service finds a key by its hash as it finds a key: case and
     * surrounding spaces aside. A key typed into the install in another case
     * than the portal shows must hash the same, or a payer is refused.
     */
    public function testTheLicenceKeyHashIgnoresCaseAndSurroundingSpace(): void
    {
        $portal = 'Ab3dEf9GhIjKlMnOpQrStUvWxYz0123456789aBcDeFgHiJkLmNoPqRsTuVw';
        $expected = hash('sha256', strtolower($portal));
        $typings = [$portal, strtolower($portal), strtoupper($portal), "  $portal\n", "\t" . strtoupper($portal) . ' '];
        foreach ($typings as $typed) {
            self::assertSame($expected, AiConnect::licenceKeyHash($typed), var_export($typed, true));
        }
        self::assertNotSame($expected, AiConnect::licenceKeyHash(substr($portal, 1)));
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function exchange(array $payload): array
    {
        // Every case here is refused before the database is asked anything
        // but whether 202_api_keys has its label column.
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'label'", [['Field' => 'label']]);
        return AiConnect::exchange($db, new ServerStateStore(), $payload);
    }

    /** A database that cannot say whether it has the label column refuses
     * with the code still good: nothing was used up. */
    public function testADatabaseThatCannotAnswerLeavesTheCodeUnspent(): void
    {
        $verifier = str_repeat('v', 50);
        $code = AiConnect::approve(new ServerStateStore(), 7, self::readScope(), self::challenge($verifier), 'Claude');
        $broken = new FakeMysqliConnection();
        $broken->whenQueryContainsResultFails("SHOW COLUMNS FROM 202_api_keys LIKE 'label'");
        $payload = ['code' => $code, 'code_verifier' => $verifier];
        [$status, $body] = AiConnect::exchange($broken, new ServerStateStore(), $payload);
        self::assertSame([500, 'server_error'], [$status, $body['code']]);

        // The code was not consumed: a wrong verifier now reaches the check.
        [$status, $body] = self::exchange(['code' => $code, 'code_verifier' => str_repeat('w', 50)]);
        self::assertStringContainsString('verifier does not match', $body['message']);
    }

    private static function readScope(): string
    {
        return (string) AiConnect::scopeFor('read');
    }

    private static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function testACodeWorksOnceAndOnlyWithItsVerifier(): void
    {
        $verifier = str_repeat('v', 50);
        $code = AiConnect::approve(new ServerStateStore(), 7, self::readScope(), self::challenge($verifier), 'Claude');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $code);

        [$status, $body] = self::exchange(['code' => $code, 'code_verifier' => str_repeat('w', 50)]);
        self::assertSame([400, 'invalid_grant'], [$status, $body['code']]);
        self::assertStringContainsString('verifier does not match', $body['message']);

        // The wrong verifier spent the code: the right one is too late now.
        [$status, $body] = self::exchange(['code' => $code, 'code_verifier' => $verifier]);
        self::assertSame([400, 'invalid_grant'], [$status, $body['code']]);
        self::assertStringContainsString('used already', $body['message']);
    }

    public function testTheExchangeRefusesAFieldItDoesNotRead(): void
    {
        $controller = new ConnectController(mysqli_init(), new ServerStateStore());
        $body = ['code' => str_repeat('a', 43), 'code_verifier' => str_repeat('v', 50), 'scope' => 'write'];
        try {
            $controller->token($body);
            self::fail('an unknown field was accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('scope', $e->getFieldErrors());
        }
    }

    public function testTheCodeItselfIsNeverStored(): void
    {
        $verifier = str_repeat('v', 50);
        $code = AiConnect::approve(new ServerStateStore(), 7, self::readScope(), self::challenge($verifier), 'Claude');
        $stored = (string) shell_exec('cat ' . escapeshellarg($this->stateDir) . '/tokens/connect.json');
        self::assertStringContainsString(hash('sha256', $code), $stored);
        self::assertStringNotContainsString($code, $stored);
    }

    public function testAnExpiredApprovalIsRefusedAndSwept(): void
    {
        $store = new ServerStateStore();
        $grant = ['user_id' => 1, 'scope' => 'read', 'code_challenge' => 'x', 'client_name' => 'A'];
        $store->issueConnectCode(hash('sha256', 'old'), $grant, -1);
        self::assertNull($store->consumeConnectCode(hash('sha256', 'old')));
        $store->issueConnectCode(hash('sha256', 'stale'), ['user_id' => 1], -1);
        $store->issueConnectCode(hash('sha256', 'new'), ['user_id' => 1], 300);
        $stored = (string) shell_exec('cat ' . escapeshellarg($this->stateDir) . '/tokens/connect.json');
        self::assertStringNotContainsString(hash('sha256', 'stale'), $stored);
        self::assertNotNull($store->consumeConnectCode(hash('sha256', 'new')));
    }

    public function testAMalformedExchangeIsRefusedNamingTheField(): void
    {
        foreach (
            [
            [[], 'code must be'],
            [['code' => str_repeat('a', 43)], 'code_verifier must be'],
            [['code' => 12, 'code_verifier' => str_repeat('v', 50)], 'code must be'],
            [['code' => str_repeat('a', 43), 'code_verifier' => 'short'], 'code_verifier must be'],
            ] as [$payload, $want]
        ) {
            [$status, $body] = self::exchange($payload);
            self::assertSame(400, $status, json_encode($payload));
            self::assertStringContainsString($want, $body['message']);
        }
        [$status, $body] = self::exchange(['code' => str_repeat('a', 43), 'code_verifier' => str_repeat('v', 50)]);
        self::assertSame([400, 'invalid_grant'], [$status, $body['code']]);
    }
}
