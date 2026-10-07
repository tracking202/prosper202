<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;
use Prosper202\DataEngine\SelfCall;

/**
 * Where the cron's report rebuild calls this server back, and that the call
 * stays on this machine when the address came from the request (SelfCall).
 */
final class SelfCallTest extends TestCase
{
    private const ROOT = '/srv/p202';

    public function testAStoredDomainIsCalledAsConfiguredWithTimeoutsAndNoPin(): void
    {
        $server = ['SERVER_NAME' => 'attacker.example', 'SERVER_PORT' => '8080', 'SERVER_ADDR' => '10.1.2.3', 'DOCUMENT_ROOT' => self::ROOT];
        self::assertSame('http://track.example.com/', SelfCall::base('track.example.com', $server, self::ROOT));
        self::assertSame(
            [CURLOPT_CONNECTTIMEOUT => SelfCall::CONNECT_TIMEOUT, CURLOPT_TIMEOUT => SelfCall::TIMEOUT],
            SelfCall::curlOptions('track.example.com', $server)
        );
    }

    /**
     * Under nginx's catch-all the server's name is the request's Host header.
     * The call goes to the listener that served the request, on the scheme the
     * connection used (a proxy's X-Forwarded-Proto is the proxy's side), with
     * the claimed name pinned to the listener's address.
     */
    public function testWithNoDomainTheClaimedNameIsPinnedToTheListener(): void
    {
        $server = [
            'SERVER_NAME' => 'attacker.example', 'SERVER_PORT' => '8080', 'SERVER_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https', 'DOCUMENT_ROOT' => self::ROOT,
        ];
        self::assertSame('http://attacker.example:8080/', SelfCall::base('', $server, self::ROOT));
        $options = SelfCall::curlOptions('', $server);
        self::assertSame(['attacker.example:8080:10.1.2.3'], $options[CURLOPT_RESOLVE]);
        self::assertSame('', $options[CURLOPT_PROXY], 'a proxy resolves the name itself and ignores the pin');
        self::assertSame('*', $options[CURLOPT_NOPROXY]);
        self::assertSame(SelfCall::TIMEOUT, $options[CURLOPT_TIMEOUT]);
    }

    public function testATlsListenerAndAnIpv6AddressAreWrittenAsCurlReadsThem(): void
    {
        $server = ['SERVER_NAME' => 'site.example', 'SERVER_PORT' => '443', 'SERVER_ADDR' => '2001:db8::5', 'HTTPS' => 'on', 'DOCUMENT_ROOT' => self::ROOT];
        self::assertSame('https://site.example:443/', SelfCall::base('', $server, self::ROOT));
        // An unbracketed IPv6 address makes the entry unparseable, and curl
        // drops the pin without a word (CLAUDE.md #24).
        self::assertSame(['site.example:443:[2001:db8::5]'], SelfCall::curlOptions('', $server)[CURLOPT_RESOLVE]);

        $literal = ['SERVER_NAME' => '[::1]', 'SERVER_PORT' => '8098', 'SERVER_ADDR' => '::1', 'DOCUMENT_ROOT' => self::ROOT];
        self::assertSame('http://[::1]:8098/', SelfCall::base('', $literal, self::ROOT));
        self::assertSame(['[::1]:8098:[::1]'], SelfCall::curlOptions('', $literal)[CURLOPT_RESOLVE]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function unknownAddresses(): iterable
    {
        yield 'none' => [null];
        yield 'a wildcard' => ['0.0.0.0'];
        yield 'the IPv6 wildcard' => ['::'];
        yield 'not an address' => ['localhost'];
    }

    /** @dataProvider unknownAddresses */
    public function testAnUnknownListeningAddressPinsToLoopback(mixed $address): void
    {
        $server = ['SERVER_NAME' => 'attacker.example', 'SERVER_PORT' => '80', 'DOCUMENT_ROOT' => self::ROOT];
        if ($address !== null) {
            $server['SERVER_ADDR'] = $address;
        }
        self::assertSame(['attacker.example:80:127.0.0.1'], SelfCall::curlOptions('', $server)[CURLOPT_RESOLVE]);
    }

    public function testARunWithNoListenerBuildsTheBaseAsBeforeAndPinsNothing(): void
    {
        // The PHP CLI: no SERVER_NAME, no SERVER_PORT.
        $options = SelfCall::curlOptions('', ['DOCUMENT_ROOT' => self::ROOT]);
        self::assertArrayNotHasKey(CURLOPT_RESOLVE, $options);
        self::assertSame(SelfCall::TIMEOUT, $options[CURLOPT_TIMEOUT]);
        self::assertSame(
            \Prosper202\Click\TrackingBaseUrl::build('', ['DOCUMENT_ROOT' => self::ROOT], self::ROOT),
            SelfCall::base('', ['DOCUMENT_ROOT' => self::ROOT], self::ROOT)
        );
    }
}
