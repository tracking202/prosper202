<?php

declare(strict_types=1);

namespace Tests\Messaging;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * MessagingClient makes one transport decision, transportProtocols(): whether
 * a configured MESSAGING_API_URL is accepted at all and, if so, which curl
 * protocols may carry requests to it. Those used to be two functions that had
 * to agree and did not (the allowlist was pinned to HTTPS while acceptance
 * admitted loopback http://, so the documented mock-server setup was accepted
 * and then failed every request). With one function there is nothing to keep
 * aligned -- this test pins the table itself, and that the constructor stores
 * the URL trimmed, since the decision trims and a padded stored URL failed at
 * curl anyway.
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
            'documented mock'  => ['http://127.0.0.1:8787/messaging', $loopback],
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
}
