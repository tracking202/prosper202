<?php

declare(strict_types=1);

namespace Tests\Messaging;

use PHPUnit\Framework\TestCase;

/**
 * What the install takes from the portal's pass answer
 * and hands the browser: a cht1 pass, a chat service origin the browser may be
 * sent to, an expiry in the future. Anything else is refused by name, so
 * chat-pass.php answers 502 instead of handing the chat box a value it would
 * build a URL or a WebSocket from.
 */
final class ChatPassClientTest extends TestCase
{
    private const PASS = 'cht1.eyJ2IjoxLCJ0eXAiOiJjdXN0b21lciJ9.GmGfBjmw0Cm1A1Y5Xo2e1KApqM6rg8gW7DiwgjBJmvw';

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../202-config/Messaging/ChatPassClient.class.php';
    }

    /** @param array<string, mixed> $changes */
    private static function answer(array $changes = []): string
    {
        $answer = ['ok' => true, 'pass' => self::PASS, 'expires_at_s' => time() + 86400, 'chat_url' => 'https://chat.tracking202.com'];
        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($answer[$key]);
            } else {
                $answer[$key] = $value;
            }
        }

        return (string) json_encode($answer);
    }

    /** A kept pass is for one version: after an upgrade the next pass reports the new one. */
    public function testAKeptPassIsForTheVersionItReports(): void
    {
        $identity = [
            'api_key' => 'k', 'install_hash' => 'h', 'user_id' => 1, 'user_email' => 'a@b.c', 'user_name' => 'u',
        ];
        $url = 'https://t.example.com/';
        $pass = 'https://portal.example.com/pass';
        self::assertSame(
            \ChatPassClient::fingerprint($identity, $url, $pass, '1.9.78'),
            \ChatPassClient::fingerprint($identity, $url, $pass, '1.9.78')
        );
        self::assertNotSame(
            \ChatPassClient::fingerprint($identity, $url, $pass, '1.9.77'),
            \ChatPassClient::fingerprint($identity, $url, $pass, '1.9.78')
        );
        self::assertSame(
            \ChatPassClient::fingerprint($identity, $url, $pass, PROSPER202_VERSION),
            \ChatPassClient::fingerprint($identity, $url, $pass)
        );
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/202-account/ajax/chat-pass.php');
        self::assertStringContainsString(
            'ChatPassClient::fingerprint($p202ChatRequest, $p202ChatInstallUrl, CHAT_PASS_URL)',
            $page
        );
    }

    public function testAGoodAnswerIsTaken(): void
    {
        $expires = time() + 86400;
        self::assertSame(
            ['pass' => self::PASS, 'chat_url' => 'https://chat.tracking202.com', 'expires_at_s' => $expires],
            \ChatPassClient::parse(200, self::answer(['expires_at_s' => $expires]))
        );
    }

    /**
     * @dataProvider refusals
     */
    public function testAnythingElseIsRefusedByName(int $status, string $body, string $reason): void
    {
        try {
            \ChatPassClient::parse($status, $body);
            self::fail('accepted: ' . $body);
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function refusals(): iterable
    {
        yield 'a 503 names the portal\'s error' => [503, '{"ok":false,"error":"chat is not configured"}', 'HTTP 503: chat is not configured'];
        yield 'a 401' => [401, '{"ok":false,"error":"unauthorized"}', 'HTTP 401'];
        yield 'a 200 that is not JSON' => [200, '<html>', 'not a JSON object'];
        yield 'an empty 200' => [200, '', 'not a JSON object'];
        yield 'a list' => [200, '[1,2]', 'not a JSON object'];
        yield 'ok false' => [200, self::answer(['ok' => false]), 'did not answer ok'];
        yield 'ok missing' => [200, self::answer(['ok' => null]), 'did not answer ok'];
        yield 'ok as a string' => [200, self::answer(['ok' => 'true']), 'did not answer ok'];
        yield 'no pass' => [200, self::answer(['pass' => null]), 'not a cht1 pass'];
        yield 'another format' => [200, self::answer(['pass' => 'cht2.eyJ2IjoxfQ.c2ln']), 'not a cht1 pass'];
        yield 'two parts' => [200, self::answer(['pass' => 'cht1.eyJ2IjoxfQ']), 'not a cht1 pass'];
        yield 'padding' => [200, self::answer(['pass' => 'cht1.eyJ2IjoxfQ==.c2ln']), 'not a cht1 pass'];
        yield 'a character a subprotocol cannot carry' => [200, self::answer(['pass' => 'cht1.eyJ2 IjoxfQ.c2ln']), 'not a cht1 pass'];
        yield 'too long' => [200, self::answer(['pass' => 'cht1.' . str_repeat('a', 4100) . '.c2ln']), 'not a cht1 pass'];
        yield 'a number' => [200, self::answer(['pass' => 42]), 'not a cht1 pass'];
        yield 'no expiry' => [200, self::answer(['expires_at_s' => null]), 'expiry'];
        yield 'an expiry in the past' => [200, self::answer(['expires_at_s' => time() - 1]), 'expiry'];
        yield 'an expiry as a string' => [200, self::answer(['expires_at_s' => (string) (time() + 86400)]), 'expiry'];
        yield 'an expiry in milliseconds' => [200, self::answer(['expires_at_s' => (time() + 86400) * 1000]), 'expiry'];
        yield 'an expiry with a fraction' => [200, self::answer(['expires_at_s' => time() + 86400.5]), 'expiry'];
        yield 'an expiry a pass cannot reach' => [200, self::answer(['expires_at_s' => time() + 8 * 86400]), 'expiry'];
        yield 'no chat_url' => [200, self::answer(['chat_url' => null]), 'chat_url'];
        yield 'cleartext chat_url' => [200, self::answer(['chat_url' => 'http://chat.tracking202.com']), 'chat_url'];
        yield 'a script URL' => [200, self::answer(['chat_url' => 'javascript:alert(1)']), 'chat_url'];
    }

    /**
     * @dataProvider chatUrls
     */
    public function testTheChatUrlIsAnOriginTheBrowserMayBeSentTo(mixed $given, ?string $expected): void
    {
        self::assertSame($expected, \ChatPassClient::chatUrl($given), var_export($given, true));
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function chatUrls(): iterable
    {
        yield 'production' => ['https://chat.tracking202.com', 'https://chat.tracking202.com'];
        yield 'a trailing slash' => ['https://chat.tracking202.com/', 'https://chat.tracking202.com'];
        yield 'case' => ['HTTPS://Chat.Tracking202.com', 'https://chat.tracking202.com'];
        yield 'a port' => ['https://chat.tracking202.com:8443', 'https://chat.tracking202.com:8443'];
        yield 'local development' => ['http://127.0.0.1:8787', 'http://127.0.0.1:8787'];
        yield 'local by name' => ['http://localhost:8787', 'http://localhost:8787'];
        yield 'cleartext elsewhere' => ['http://chat.tracking202.com', null];
        yield 'a path' => ['https://chat.tracking202.com/v1', null];
        yield 'a query' => ['https://chat.tracking202.com?x=1', null];
        yield 'a fragment' => ['https://chat.tracking202.com#x', null];
        yield 'credentials' => ['https://user:pw@chat.tracking202.com', null];
        yield 'a backslash' => ['https://chat.tracking202.com\\@evil.test', null];
        yield 'whitespace inside' => ["https://chat.tracking202.com\n.evil.test", null];
        yield 'a websocket URL' => ['wss://chat.tracking202.com', null];
        yield 'no host' => ['https://', null];
        yield 'empty' => ['', null];
        yield 'not a string' => [['https://chat.tracking202.com'], null];
        yield 'null' => [null, null];
    }

    public function testACleartextPassUrlIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('refusing to send credentials in cleartext');
        new \ChatPassClient('http://my.tracking202.com/api/v3/chat/pass');
    }

    public function testAnUnreachablePortalIsAnErrorNotAPass(): void
    {
        // A closed loopback port: nothing listens there.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the portal could not be reached');
        (new \ChatPassClient('http://' . $name . '/api/v3/chat/pass'))->request(
            ['api_key' => 'k', 'install_hash' => 'h', 'user_id' => 1, 'user_email' => '', 'user_name' => ''],
            ''
        );
    }
}
