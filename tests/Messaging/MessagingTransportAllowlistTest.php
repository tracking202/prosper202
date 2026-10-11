<?php

declare(strict_types=1);

namespace Tests\Messaging;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * MessagingClient makes one transport decision, transportProtocols(): whether
 * a configured MESSAGING_API_URL (or CHAT_PASS_URL) is accepted at all and, if
 * so, which curl protocols may carry requests to it. Those used to be two
 * functions that had to agree and did not (the allowlist was pinned to HTTPS
 * while acceptance admitted loopback http://, so a local stub was accepted and
 * then failed every request). With one function there is nothing to keep
 * aligned -- this test pins the table itself, and that the constructor stores
 * the URL trimmed, since the decision trims and a padded stored URL failed at
 * curl anyway.
 *
 * transportOptions() is the decision as curl options, and both portal
 * clients (MessagingClient, ChatPassClient) make their calls with it: a
 * loopback URL never goes through the environment's proxy, which libcurl
 * otherwise uses for an http:// request whatever its host -- the cleartext
 * carve-out is justified only because the request stays on the host.
 * Measured with libcurl 8.5.0 and pinned below by running it.
 */
final class MessagingTransportAllowlistTest extends TestCase
{
    private ReflectionMethod $decide;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../202-config/Messaging/MessagingClient.class.php';
        $this->decide = new ReflectionMethod(\MessagingClient::class, 'transportProtocols');
        $this->decide->setAccessible(true);
    }

    /**
     * @dataProvider decisions
     */
    public function testTheTransportDecision(string $url, ?int $expected): void
    {
        self::assertSame($expected, $this->decide->invoke(null, $url), $url);
    }

    /** @return array<string, array{0: string, 1: ?int}> */
    public static function decisions(): array
    {
        $httpsOnly = CURLPROTO_HTTPS;
        $loopback = CURLPROTO_HTTPS | CURLPROTO_HTTP;

        return [
            'central https'    => ['https://my.tracking202.com/api/v3/messaging', $httpsOnly],
            'https any host'   => ['https://10.0.0.9/messaging', $httpsOnly],
            'local stub'       => ['http://127.0.0.1:8787/messaging', $loopback],
            'loopback name'    => ['http://localhost:8787/messaging', $loopback],
            'loopback v6'      => ['http://[::1]:8787/messaging', $loopback],
            'loopback 127.x'   => ['http://127.5.5.5:8787/messaging', $loopback],
            'uppercase scheme' => ['HTTP://127.0.0.1:8787/messaging', $loopback],
            'padded'           => ["  http://127.0.0.1:8787/messaging  ", $loopback],
            'private lan'      => ['http://10.0.0.9/messaging', null],
            'public cleartext' => ['http://my.tracking202.com/api/v3/messaging', null],
            'no scheme'        => ['my.tracking202.com/api/v3/messaging', null],
            'empty'            => ['', null],
            'http no host'     => ['http:///messaging', null],
        ];
    }

    public function testCleartextIsNeverGrantedToARefusedOrHttpsUrl(): void
    {
        // The security property, stated independently of the table above: HTTP
        // appears in the mask only for an accepted loopback URL.
        foreach (self::decisions() as $name => [$url, $expected]) {
            $mask = $this->decide->invoke(null, $url);
            if ($mask === null) {
                continue;
            }
            self::assertNotSame(0, $mask & CURLPROTO_HTTPS, "$name: HTTPS must always be permitted");
            $grantsHttp = ($mask & CURLPROTO_HTTP) !== 0;
            $isLoopbackCleartext = str_starts_with(strtolower(trim($url)), 'http://');
            self::assertSame($isLoopbackCleartext, $grantsHttp, "$name: cleartext must be granted exactly to accepted http:// (loopback) URLs");
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheConstructorStoresTheTrimmedUrlAndTheMatchingProtocols(): void
    {
        define('MESSAGING_API_URL', "  http://127.0.0.1:8787/messaging  ");
        require_once __DIR__ . '/../../202-config/Messaging/MessagingClient.class.php';

        $client = new \MessagingClient();

        $baseUrl = new ReflectionProperty(\MessagingClient::class, 'baseUrl');
        $baseUrl->setAccessible(true);
        self::assertSame('http://127.0.0.1:8787/messaging', $baseUrl->getValue($client), 'a padded URL must not reach curl padded');

        $protocols = new ReflectionProperty(\MessagingClient::class, 'curlProtocols');
        $protocols->setAccessible(true);
        self::assertSame(CURLPROTO_HTTPS | CURLPROTO_HTTP, $protocols->getValue($client));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheConstructorRefusesACleartextUrlThatIsNotLoopback(): void
    {
        define('MESSAGING_API_URL', 'http://my.tracking202.com/api/v3/messaging');
        require_once __DIR__ . '/../../202-config/Messaging/MessagingClient.class.php';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('refusing to send credentials in cleartext');
        new \MessagingClient();
    }
    /**
     * @dataProvider optionCases
     * @param array<int, mixed>|null $expected
     */
    public function testTheTransportOptions(string $url, ?array $expected): void
    {
        self::assertSame($expected, \MessagingClient::transportOptions($url), $url);
    }

    /** @return array<string, array{0: string, 1: array<int, mixed>|null}> */
    public static function optionCases(): array
    {
        $bypass = [CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'];

        return [
            'portal https takes the environment\'s proxy' => ['https://my.tracking202.com/api/v3/chat/pass', [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]],
            'loopback http never goes through one' => ['http://127.0.0.1:8787/api/v3/chat/pass', [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP] + $bypass],
            'loopback name' => ['http://localhost:8787/x', [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP] + $bypass],
            'loopback https' => ['https://127.0.0.1:8787/x', [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS] + $bypass],
            'refused cleartext' => ['http://10.0.0.9/x', null],
            'refused scheme' => ['ftp://127.0.0.1/x', null],
        ];
    }

    public function testTheChatPassClientMakesTheSameDecision(): void
    {
        require_once __DIR__ . '/../../202-config/Messaging/ChatPassClient.class.php';
        $transport = new ReflectionProperty(\ChatPassClient::class, 'transport');
        $transport->setAccessible(true);
        foreach (self::optionCases() as $name => [$url, $expected]) {
            if ($expected === null) {
                try {
                    new \ChatPassClient($url);
                    self::fail("$name: ChatPassClient accepted $url");
                } catch (\RuntimeException $e) {
                    self::assertStringContainsString('refusing to send credentials in cleartext', $e->getMessage(), $name);
                }
                continue;
            }
            self::assertSame($expected, $transport->getValue(new \ChatPassClient($url)), $name);
        }
    }

    /**
     * Both portal clients, run against a stub portal on loopback while the
     * environment names a proxy: every request reaches the stub directly and
     * the proxy sees none of them. The control call, made without the
     * options, does reach the proxy, so the proxy is one curl would use.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testALoopbackCallNeverGoesThroughTheEnvironmentsProxy(): void
    {
        $proxy = LocalHttpRecorder::start();
        $portal = LocalHttpRecorder::start();
        try {
            foreach (['http_proxy', 'HTTP_PROXY'] as $name) {
                putenv($name . '=' . $proxy->url());
            }
            foreach (['no_proxy', 'NO_PROXY'] as $name) {
                putenv($name . '=');
            }

            $control = curl_init($portal->url() . '/control');
            self::assertNotFalse($control);
            curl_setopt($control, CURLOPT_RETURNTRANSFER, true);
            curl_exec($control);
            curl_close($control);
            self::assertSame([$portal->url() . '/control'], array_column($proxy->requests(), 'target'), 'the control call went through the proxy');

            define('MESSAGING_API_URL', $portal->url() . '/api/v3/messaging');
            require_once __DIR__ . '/../../202-config/Messaging/ChatPassClient.class.php';
            self::assertSame(['ok' => true], (new \MessagingClient())->track(['install_hash' => 'h'], [], []));
            $identity = ['api_key' => 'k', 'install_hash' => 'h', 'user_id' => 3, 'user_email' => 'dana@acme.test', 'user_name' => 'Dana Lee'];
            $pass = (new \ChatPassClient($portal->url() . '/api/v3/chat/pass'))->request($identity, 'https://track.acme.test/');
            self::assertSame(LocalHttpRecorder::PASS, $pass['pass']);
            self::assertSame('https://chat.example.test', $pass['chat_url']);

            self::assertSame(
                ['/api/v3/messaging/track', '/api/v3/chat/pass'],
                array_column($portal->requests(), 'target'),
                'both clients reached the stub portal directly'
            );
            self::assertSame([$portal->url() . '/control'], array_column($proxy->requests(), 'target'), 'neither client went through the proxy');
            self::assertSame(
                ['identity' => $identity, 'install_url' => 'https://track.acme.test/'],
                json_decode($portal->requests()[1]['body'], true),
                'the pass request carries the identity block and the install URL (chat PROTOCOL 12.1)'
            );
        } finally {
            $proxy->stop();
            $portal->stop();
        }
    }
}
