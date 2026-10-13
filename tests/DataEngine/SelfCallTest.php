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
        // The operator's address: redirects followed as they always were.
        self::assertSame(
            self::common() + [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5],
            SelfCall::curlOptions('track.example.com', $server)
        );
    }

    /** What every call is made with, pinned or not. @return array<int, mixed> */
    private static function common(): array
    {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => SelfCall::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => SelfCall::TIMEOUT,
        ];
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
        // The whole set, so nothing in it can go missing unseen: a proxy
        // resolves the name itself and ignores the pin, and a redirect to
        // another name or port (an http-to-https rule built from the claimed
        // Host) is resolved by DNS and leaves the machine.
        self::assertSame(
            self::common() + [
                CURLOPT_RESOLVE => ['attacker.example:8080:10.1.2.3'],
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
            ],
            SelfCall::curlOptions('', $server)
        );
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

    /**
     * The server's name as the request leaves it: under nginx's catch-all,
     * connect.php puts the Host header there, port and all. The call takes
     * the listener's own port either way (it was built as `name:8136:8136`,
     * which curl cannot connect to, so every rebuild on a non-default port
     * failed); a name that cannot be read, or a port that is missing, is
     * still pinned rather than handed to the unpinned fallback.
     *
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function names(): iterable
    {
        $at = ['SERVER_PORT' => '8136', 'SERVER_ADDR' => '10.1.2.3', 'DOCUMENT_ROOT' => self::ROOT];
        yield 'a name with the claimed port' => [['SERVER_NAME' => 'attacker.example:8136'] + $at, 'http://attacker.example:8136/', 'attacker.example:8136:10.1.2.3'];
        yield 'a name with another port' => [['SERVER_NAME' => 'attacker.example:9000'] + $at, 'http://attacker.example:8136/', 'attacker.example:8136:10.1.2.3'];
        yield 'a name with an empty port' => [['SERVER_NAME' => 'attacker.example:'] + $at, 'http://attacker.example:8136/', 'attacker.example:8136:10.1.2.3'];
        yield 'an IPv6 literal with a port' => [['SERVER_NAME' => '[2001:db8::7]:8136'] + $at, 'http://[2001:db8::7]:8136/', '[2001:db8::7]:8136:10.1.2.3'];
        yield 'a bare IPv6 address' => [['SERVER_NAME' => '2001:db8::7'] + $at, 'http://[2001:db8::7]:8136/', '[2001:db8::7]:8136:10.1.2.3'];
        yield 'a name that is not a host' => [['SERVER_NAME' => 'a:b:c'] + $at, 'http://10.1.2.3:8136/', '10.1.2.3:8136:10.1.2.3'];
        yield 'an unclosed bracket' => [['SERVER_NAME' => '[2001:db8::7'] + $at, 'http://10.1.2.3:8136/', '10.1.2.3:8136:10.1.2.3'];
        yield 'no name' => [['REQUEST_METHOD' => 'GET'] + $at, 'http://10.1.2.3:8136/', '10.1.2.3:8136:10.1.2.3'];
        yield 'neither name nor port' => [
            ['REQUEST_METHOD' => 'GET', 'SERVER_ADDR' => '10.1.2.3', 'DOCUMENT_ROOT' => self::ROOT],
            'http://10.1.2.3:80/', '10.1.2.3:80:10.1.2.3',
        ];
        yield 'no port, over TLS' => [
            ['SERVER_NAME' => 'site.example', 'HTTPS' => 'on', 'SERVER_ADDR' => '10.1.2.3', 'DOCUMENT_ROOT' => self::ROOT],
            'https://site.example:443/', 'site.example:443:10.1.2.3',
        ];
    }

    /**
     * @dataProvider names
     * @param array<string, string> $server
     */
    public function testTheNameIsReadWithoutItsPortAndAlwaysPinned(array $server, string $base, string $resolve): void
    {
        self::assertSame($base, SelfCall::base('', $server, self::ROOT));
        $options = SelfCall::curlOptions('', $server);
        self::assertSame([$resolve], $options[CURLOPT_RESOLVE]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertFalse(SelfCall::followsRedirects($options));
        self::assertTrue(SelfCall::followsRedirects(SelfCall::curlOptions('track.example.com', $server)));
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

    /**
     * The rebuild's URL is public, and every signed-in user sets their own
     * tracking domain on Personal Settings. Read for the session's user, it
     * let any account choose the host the server fetched — and a call on a
     * stored domain follows redirects. The cron reads the install's (user
     * 1's), and before it claims the window, so a read that throws cannot
     * leave the window claimed with nothing to release it.
     */
    public function testTheRebuildReadsTheInstallsDomainBeforeItClaimsTheWindow(): void
    {
        $path = dirname(__DIR__, 2) . '/202-cronjobs/process_dataengine_job.php';
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($path)),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $reads = [];
        $claim = null;
        foreach ($tokens as $i => $t) {
            if (is_array($t) && $t[0] === T_STRING && $t[1] === 'p202StoredTrackingDomain') {
                $call = '';
                for ($j = $i + 1; $j < count($tokens) && $tokens[$j] !== ')'; $j++) {
                    $call .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                }
                $reads[] = [$call . ')', $t[2]];
            }
            if ($claim === null && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && str_contains($t[1], "SET processing = '1'")) {
                $claim = $t[2];
            }
        }
        self::assertCount(1, $reads, 'the rebuild reads the stored domain once');
        self::assertSame('(1)', $reads[0][0], 'the install\'s domain, named; never the session\'s');
        self::assertNotNull($claim, 'the window claim is not where this test looks');
        self::assertLessThan($claim, $reads[0][1], 'the domain is read before the window is claimed');
    }

    public function testARunWithNoListenerBuildsTheBaseAsBeforeAndPinsNothing(): void
    {
        // The PHP CLI: no SERVER_NAME, no SERVER_PORT.
        self::assertSame(
            self::common() + [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5],
            SelfCall::curlOptions('', ['DOCUMENT_ROOT' => self::ROOT])
        );
        self::assertSame(
            \Prosper202\Click\TrackingBaseUrl::build('', ['DOCUMENT_ROOT' => self::ROOT], self::ROOT),
            SelfCall::base('', ['DOCUMENT_ROOT' => self::ROOT], self::ROOT)
        );
    }
}
