<?php

declare(strict_types=1);

namespace Tests\Messaging;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * MessagingClient makes the same decision twice: isSafeTransport() decides
 * whether a configured MESSAGING_API_URL may be used at all, and
 * allowedCurlProtocols() decides which schemes curl will actually speak. Those
 * two answers must agree for every URL.
 *
 * They did not. isSafeTransport() admitted http:// for loopback so the repo's
 * own documented mock-server setup would work, while the curl allowlist was
 * pinned to CURLPROTO_HTTPS — so the constructor accepted the URL and then
 * every request died with CURLE_UNSUPPORTED_PROTOCOL. Drift in the other
 * direction is worse: an allowlist wider than the transport rule would carry
 * the install's customer API key over cleartext.
 */
final class MessagingTransportAllowlistTest extends TestCase
{
    private ReflectionMethod $isSafeTransport;
    private ReflectionMethod $allowedProtocols;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../202-config/Messaging/MessagingClient.class.php';

        $this->isSafeTransport = new ReflectionMethod(\MessagingClient::class, 'isSafeTransport');
        $this->isSafeTransport->setAccessible(true);
        $this->allowedProtocols = new ReflectionMethod(\MessagingClient::class, 'allowedCurlProtocols');
        $this->allowedProtocols->setAccessible(true);
    }

    /**
     * @dataProvider urls
     */
    public function testTheAllowlistAgreesWithTheTransportRule(string $url): void
    {
        $accepted = (bool) $this->isSafeTransport->invoke(null, $url);
        $protocols = (int) $this->allowedProtocols->invoke(null, $url);
        $allowsCleartext = ($protocols & CURLPROTO_HTTP) !== 0;

        self::assertNotSame(
            0,
            $protocols & CURLPROTO_HTTPS,
            'HTTPS must always be permitted'
        );
        self::assertSame(
            0,
            $protocols & ~(CURLPROTO_HTTPS | CURLPROTO_HTTP),
            'The allowlist must never widen beyond http/https'
        );

        if (!$accepted) {
            // A rejected URL never reaches curl, so the allowlist is moot; it
            // must still not be the permissive variant, or a future refactor
            // that relaxes the constructor silently inherits cleartext.
            self::assertFalse($allowsCleartext, "Rejected URL must not enable cleartext: $url");
            return;
        }

        $isCleartextUrl = str_starts_with(strtolower(trim($url)), 'http://');
        self::assertSame(
            $isCleartextUrl,
            $allowsCleartext,
            "curl's protocol allowlist disagrees with isSafeTransport() for: $url"
        );
    }

    /** @return array<string, array{0: string}> */
    public static function urls(): array
    {
        return [
            'central https'      => ['https://my.tracking202.com/api/v3/messaging'],
            'documented mock'    => ['http://127.0.0.1:8787/messaging'],
            'loopback name'      => ['http://localhost:8787/messaging'],
            'loopback v6'        => ['http://[::1]:8787/messaging'],
            'loopback 127.x'     => ['http://127.5.5.5:8787/messaging'],
            'uppercase scheme'   => ['HTTP://127.0.0.1:8787/messaging'],
            'padded'            => ["  http://127.0.0.1:8787/messaging  "],
            'private lan'        => ['http://10.0.0.9/messaging'],
            'public cleartext'   => ['http://my.tracking202.com/api/v3/messaging'],
            'no scheme'          => ['my.tracking202.com/api/v3/messaging'],
            'empty'              => [''],
        ];
    }
}
