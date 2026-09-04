<?php

declare(strict_types=1);

namespace Tests\Validation;

use PHPUnit\Framework\TestCase;
use Prosper202\Validation\OutboundUrlGuard;
use RuntimeException;

/**
 * The guard's return value is load-bearing: both webhook crons feed it to
 * curlResolveEntry() and pin the connection with CURLOPT_RESOLVE. A pin that
 * curl silently drops is worse than no pin at all, because the call site still
 * reads as protected.
 */
final class OutboundUrlGuardTest extends TestCase
{
    public function testResolveEntryPrefersIpv4(): void
    {
        self::assertSame(
            'example.com:443:203.0.113.10',
            OutboundUrlGuard::curlResolveEntry('https://example.com/hook', ['2001:db8::1', '203.0.113.10'])
        );
    }

    public function testResolveEntryBracketsIpv6WhenThereIsNoIpv4(): void
    {
        // Unbracketed, "example.com:443:2001:db8::1" has more colons than curl's
        // HOST:PORT:ADDRESS grammar allows; curl rejects the entry and resolves
        // the host itself, quietly reopening the DNS-rebinding hole.
        self::assertSame(
            'example.com:443:[2001:db8::1]',
            OutboundUrlGuard::curlResolveEntry('https://example.com/hook', ['2001:db8::1'])
        );
    }

    public function testResolveEntryHonoursAnExplicitPort(): void
    {
        self::assertSame(
            'example.com:8443:203.0.113.10',
            OutboundUrlGuard::curlResolveEntry('https://example.com:8443/hook', ['203.0.113.10'])
        );
    }

    public function testResolveEntryIsNullWhenThereIsNothingToPin(): void
    {
        self::assertNull(OutboundUrlGuard::curlResolveEntry('https://example.com/hook', []));
        self::assertNull(OutboundUrlGuard::curlResolveEntry('not a url', ['203.0.113.10']));
    }

    public function testLiteralHostIsReturnedForPinning(): void
    {
        self::assertSame(
            ['203.0.113.10'],
            OutboundUrlGuard::assertAllowed('https://203.0.113.10/hook')
        );
    }

    /**
     * @dataProvider rejectedUrls
     */
    public function testRejectedUrls(string $url, string $expectedFragment): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedFragment, '/') . '/');
        OutboundUrlGuard::assertAllowed($url, 'webhook_url');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function rejectedUrls(): array
    {
        return [
            'cleartext'        => ['http://example.com/hook', 'valid https:// URL'],
            'no host'          => ['https:///hook', 'valid https:// URL'],
            'disallowed port'  => ['https://example.com:9000/hook', 'port must be one of'],
            'loopback literal' => ['https://127.0.0.1/hook', 'private or reserved'],
            'link local'       => ['https://169.254.169.254/hook', 'private or reserved'],
            'private literal'  => ['https://10.0.0.5/hook', 'private or reserved'],
            'cgnat literal'    => ['https://100.64.0.1/hook', '100.64.0.0/10'],
            'benchmark range'  => ['https://198.18.0.1/hook', '198.18.0.0/15'],
            'multicast'        => ['https://224.0.0.1/hook', '224.0.0.0/4'],
        ];
    }
}
