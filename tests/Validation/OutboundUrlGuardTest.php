<?php

declare(strict_types=1);

namespace Tests\Validation;

use PHPUnit\Framework\TestCase;
use Prosper202\Validation\OutboundUrlException;
use Prosper202\Validation\OutboundUrlGuard;

/**
 * The guard has two entry points for two moments -- assertWellFormed() at a
 * write boundary (no DNS) and assertAllowed() at dispatch (resolves, returns
 * the addresses) -- and its return value is load-bearing: both webhook crons
 * feed it to curlOptions() and pin the connection with CURLOPT_RESOLVE. A pin
 * that curl silently drops is worse than no pin, because the call site still
 * reads as protected.
 *
 * Only IP-literal hosts appear here so nothing depends on live DNS.
 */
final class OutboundUrlGuardTest extends TestCase
{
    // ---- write boundary --------------------------------------------------

    /**
     * @dataProvider rejectedUrls
     */
    public function testWellFormedRejectsWhatItCanSeeWithoutDns(string $url, string $expectedFragment): void
    {
        $this->expectException(OutboundUrlException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedFragment, '/') . '/');
        OutboundUrlGuard::assertWellFormed($url, 'webhook_url');
    }

    public function testWellFormedAcceptsAPublicLiteralAndAHostnameWithoutResolving(): void
    {
        OutboundUrlGuard::assertWellFormed('https://203.0.113.10/hook');
        // A hostname is not resolved at the write boundary: the point is that a
        // resolver stall cannot block the request, and the cron re-checks anyway.
        OutboundUrlGuard::assertWellFormed('https://this-host-must-not-be-looked-up.invalid/hook');
        $this->addToAssertionCount(2);
    }

    public function testExceptionIsARuntimeExceptionForExistingCatchSites(): void
    {
        self::assertInstanceOf(\RuntimeException::class, new OutboundUrlException('x'));
    }

    // ---- dispatch --------------------------------------------------------

    /**
     * @dataProvider rejectedUrls
     */
    public function testAllowedRejectsTheSameUrls(string $url, string $expectedFragment): void
    {
        $this->expectException(OutboundUrlException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedFragment, '/') . '/');
        OutboundUrlGuard::assertAllowed($url, 'webhook_url');
    }

    public function testLiteralHostIsReturnedForPinning(): void
    {
        self::assertSame(['203.0.113.10'], OutboundUrlGuard::assertAllowed('https://203.0.113.10/hook'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function rejectedUrls(): array
    {
        return [
            'cleartext'        => ['http://203.0.113.10/hook', 'valid https:// URL'],
            'no host'          => ['https:///hook', 'valid https:// URL'],
            'not a url'        => ['not a url', 'valid https:// URL'],
            'disallowed port'  => ['https://203.0.113.10:9000/hook', 'port must be one of'],
            'loopback literal' => ['https://127.0.0.1/hook', 'private or reserved'],
            'link local'       => ['https://169.254.169.254/hook', 'private or reserved'],
            'private literal'  => ['https://10.0.0.5/hook', 'private or reserved'],
            'cgnat literal'    => ['https://100.64.0.1/hook', '100.64.0.0/10'],
            'benchmark range'  => ['https://198.18.0.1/hook', '198.18.0.0/15'],
            'multicast'        => ['https://224.0.0.1/hook', '224.0.0.0/4'],
        ];
    }

    // ---- pinning ---------------------------------------------------------

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

    public function testNothingToPinIsAnErrorNotAnUnpinnedSend(): void
    {
        // The old contract returned null here and both crons then sent
        // unpinned -- a fail-open shape with the pinning code still present.
        $this->expectException(OutboundUrlException::class);
        $this->expectExceptionMessage('Refusing to send unpinned');
        OutboundUrlGuard::curlResolveEntry('https://example.com/hook', []);
    }

    public function testCurlOptionsCarryEveryHardeningSetting(): void
    {
        $opts = OutboundUrlGuard::curlOptions('https://example.com/hook', ['203.0.113.10']);

        self::assertSame(['example.com:443:203.0.113.10'], $opts[CURLOPT_RESOLVE]);
        self::assertFalse($opts[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $opts[CURLOPT_MAXREDIRS]);
        self::assertSame(CURLPROTO_HTTPS, $opts[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_HTTPS, $opts[CURLOPT_REDIR_PROTOCOLS]);
        self::assertTrue($opts[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $opts[CURLOPT_SSL_VERIFYHOST]);
        self::assertGreaterThan(0, $opts[CURLOPT_CONNECTTIMEOUT]);
        self::assertGreaterThan(0, $opts[CURLOPT_TIMEOUT]);
    }

    public function testEveryDispatcherUsesTheSharedCurlOptions(): void
    {
        // The hardening set exists once so no dispatcher can drop an entry. A
        // new cron that posts to a user-supplied URL must go through it too.
        foreach (\Tests\Support\SourceScan::phpFiles() as $path => $source) {
            if (!str_contains($source, 'CURLOPT_RESOLVE') || $path === '202-config/Validation/OutboundUrlGuard.php') {
                continue;
            }
            self::fail("$path sets CURLOPT_RESOLVE itself; use OutboundUrlGuard::curlOptions() so the pin and the rest of the hardening cannot diverge.");
        }
        foreach (['202-cronjobs/attribution-export.php', '202-cronjobs/ltv_webhooks.php'] as $cron) {
            self::assertStringContainsString(
                'OutboundUrlGuard::curlOptions(',
                \Tests\Support\SourceScan::phpFiles()[$cron],
                "$cron must apply OutboundUrlGuard::curlOptions()"
            );
        }
    }
}
