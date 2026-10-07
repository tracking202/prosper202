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
        // Through a proxy the proxy resolves the host and the pin is ignored.
        self::assertSame('', $opts[CURLOPT_PROXY]);
        self::assertSame('*', $opts[CURLOPT_NOPROXY]);
        self::assertFalse($opts[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $opts[CURLOPT_MAXREDIRS]);
        self::assertSame(CURLPROTO_HTTPS, $opts[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_HTTPS, $opts[CURLOPT_REDIR_PROTOCOLS]);
        self::assertTrue($opts[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $opts[CURLOPT_SSL_VERIFYHOST]);
        self::assertGreaterThan(0, $opts[CURLOPT_CONNECTTIMEOUT]);
        self::assertGreaterThan(0, $opts[CURLOPT_TIMEOUT]);
    }

    /**
     * Two implementations pin an outbound webhook: this guard's curlOptions()
     * (LTV webhooks) and Attribution\WebhookSender (MTA exports, checked by
     * its own, stricter WebhookGuard). They arrived separately; until they are
     * one, what keeps them from drifting is this: no third file pins curl
     * itself, and each of the two names every option in the hardening set.
     * Presence only -- this guard's values are asserted above, the sender's in
     * tests/Attribution/WebhookSenderTest. The proxy pair is in the set
     * because it was the drift: the sender had it and this guard did not, and
     * without it a proxy in the environment resolves the host and the pin is
     * ignored.
     *
     * DataEngine\SelfCall is the one pin of another kind: the report rebuild's
     * call to this server's own listener, which no SSRF guard would approve
     * and which may be plain http. It names the same set (its values are in
     * tests/DataEngine/SelfCallTest), and its caller sets nothing but the
     * URL: the cron's own defaults were merged in front of the pin and turned
     * redirects on, and a redirect to another name or port leaves the pin.
     */
    public function testEveryPinnedDispatcherCarriesTheWholeHardeningSet(): void
    {
        $sanctioned = [
            '202-config/Validation/OutboundUrlGuard.php',
            '202-config/Attribution/WebhookSender.php',
            '202-config/DataEngine/SelfCall.php',
        ];
        $files = \Tests\Support\SourceScan::phpFiles();

        // Matched as a code token, not text: a docblock that names an option
        // (WebhookTarget's does) sets nothing.
        $usesInCode = static function (string $source, string $option): bool {
            foreach (token_get_all($source) as $t) {
                if (is_array($t) && $t[0] === T_STRING && $t[1] === $option) {
                    return true;
                }
            }
            return false;
        };

        foreach ($files as $path => $source) {
            if (str_contains($source, 'CURLOPT_RESOLVE') && $usesInCode($source, 'CURLOPT_RESOLVE') && !in_array($path, $sanctioned, true)) {
                self::fail("$path sets CURLOPT_RESOLVE itself; send through OutboundUrlGuard::curlOptions() (or WebhookSender) so the pin and the rest of the hardening cannot diverge.");
            }
        }

        foreach ($sanctioned as $path) {
            self::assertArrayHasKey($path, $files, "$path is gone; update this test");
            foreach (['CURLOPT_RESOLVE', 'CURLOPT_PROXY', 'CURLOPT_NOPROXY', 'CURLOPT_PROTOCOLS', 'CURLOPT_REDIR_PROTOCOLS',
                'CURLOPT_FOLLOWLOCATION', 'CURLOPT_MAXREDIRS', 'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST',
                'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_TIMEOUT'] as $option) {
                self::assertTrue($usesInCode($files[$path], $option), "$path does not set $option");
            }
        }

        self::assertStringContainsString(
            'OutboundUrlGuard::curlOptions(',
            $files['202-cronjobs/ltv_webhooks.php'],
            '202-cronjobs/ltv_webhooks.php must apply OutboundUrlGuard::curlOptions()'
        );
        $cron = '202-cronjobs/process_dataengine_job.php';
        self::assertArrayHasKey($cron, $files, "$cron is gone; update this test");
        self::assertStringContainsString('SelfCall::curlOptions(', $files[$cron], "$cron must call with SelfCall::curlOptions()");
        $set = [];
        foreach (token_get_all($files[$cron]) as $t) {
            if (is_array($t) && $t[0] === T_STRING && str_starts_with($t[1], 'CURLOPT_')) {
                $set[$t[1]] = true;
            }
        }
        self::assertSame(['CURLOPT_URL'], array_keys($set), "$cron sets curl options beside SelfCall's; they can override the pin");
    }
}
