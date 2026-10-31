<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookRefused;
use Prosper202\Attribution\WebhookUnresolved;

/**
 * What a sync profile's URL may name (WebhookGuard::checkSync(), and
 * syncRefusal() at a job's save): every address class the export webhooks
 * refuse, an https:// URL with a path and no query, user info or fragment,
 * http:// only to an address the operator's allowlist names, link-local and
 * metadata never, and the sentence that says when the allowlist would lift
 * a refusal.
 */
final class SyncUrlGuardTest extends TestCase
{
    private const PUBLIC = '93.184.216.34';
    private const ALLOWLIST = ' (or its network) to P202_WEBHOOK_ALLOW_NETWORKS in 202-config.php';

    /** @param array<string, list<string>> $dns */
    private static function guard(array $dns = [], string $allow = ''): WebhookGuard
    {
        return new WebhookGuard(static fn (string $host): array => $dns[$host] ?? [], $allow);
    }

    /** A guard whose resolver fails the test: a save must not ask one. */
    private static function noResolver(string $allow = ''): WebhookGuard
    {
        return new WebhookGuard(static function (string $host): array {
            self::fail('a save asked the resolver for ' . $host);
        }, $allow);
    }

    /** @param list<string> $operatorHosts */
    private static function refused(WebhookGuard $guard, string $url, array $operatorHosts = []): string
    {
        try {
            $guard->checkSync($url, $operatorHosts);
        } catch (WebhookRefused $e) {
            return $e->getMessage();
        }
        self::fail($url . ' was accepted');
    }

    /** @dataProvider \Tests\Attribution\WebhookGuardTest::refusedLiterals */
    public function testEveryAddressAWebhookIsRefusedIsRefusedToASync(string $url, string $why): void
    {
        foreach ([$url, (string) preg_replace('~^https~', 'http', $url)] as $each) {
            $message = self::refused(self::guard(), $each);
            self::assertStringContainsString($why, $message, $each);
            self::assertStringContainsString('Syncs are only sent to public addresses', $message, $each);
            self::assertSame($message, self::noResolver()->syncRefusal($each), 'a save refuses it too: ' . $each);
        }
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function notASyncUrl(): iterable
    {
        yield 'ftp' => ['ftp://sync.example.com/', 'must start with https://'];
        yield 'file' => ['file:///etc/passwd', 'must start with https://'];
        yield 'gopher' => ['gopher://sync.example.com:70/', 'must start with https://'];
        yield 'no scheme' => ['sync.example.com', 'must start with https://'];
        yield 'user and password' => ['https://user:pw@sync.example.com/', 'user name or password'];
        yield 'user info hiding the host' => ['https://sync.example.com@169.254.169.254/', 'user name or password'];
        yield 'query' => ['https://sync.example.com/api/v3?key=x', 'not in the form'];
        yield 'fragment' => ['https://sync.example.com/#@10.0.0.1', 'not in the form'];
        yield 'backslash' => ['https://sync.example.com\\@10.0.0.1/', 'user name or password'];
        yield 'space' => ['https://sync.example.com/a b', 'not in the form'];
        yield 'newline' => ["https://sync.example.com/\nHost: x", 'not in the form'];
        yield 'non-ascii host' => ['https://sÿnc.example.com/', 'not in the form'];
        yield 'non-ascii path' => ['https://sync.example.com/trä', 'not in the form'];
        yield 'port 0' => ['https://sync.example.com:0/', 'not a port'];
        yield 'port too big' => ['https://sync.example.com:65536/', 'not a port'];
        yield 'single label' => ['https://intranet/', 'single-label'];
        yield 'dot internal' => ['https://metadata.google.internal/', 'local name'];
        yield 'dot local' => ['https://nas.local/', 'local name'];
        yield 'another spelling of loopback' => ['https://0x7f.1/', 'neither a host name'];
        yield 'decimal loopback' => ['https://2130706433/', 'dotted form'];
        yield 'too long' => ['https://sync.example.com/' . str_repeat('a', 500), 'longer than'];
    }

    /** @dataProvider notASyncUrl */
    public function testWhatIsNotASyncUrlIsRefusedByName(string $url, string $why): void
    {
        $guard = self::guard(['sync.example.com' => [self::PUBLIC]]);
        self::assertStringContainsString($why, self::refused($guard, $url));
        self::assertStringContainsString($why, (string) self::noResolver()->syncRefusal($url));
    }

    public function testAPublicHttpsInstallIsCalledPinned(): void
    {
        $guard = self::guard(['sync.example.com' => [self::PUBLIC]]);
        $target = $guard->checkSync('https://sync.example.com/api/v3');
        self::assertSame('sync.example.com:443:' . self::PUBLIC, $target->resolveEntry());
        $sub = $guard->checkSync('https://sync.example.com:8443/tracker/api/v3');
        self::assertSame('sync.example.com:8443:' . self::PUBLIC, $sub->resolveEntry(), 'a port and a directory');
        self::assertSame(self::PUBLIC, $guard->checkSync('https://' . self::PUBLIC . '/api/v3')->pinned());
        self::assertNull(self::noResolver()->syncRefusal('https://sync.example.com/api/v3'));
    }

    public function testLocalhostIsNamedWithTheAddressToUseInstead(): void
    {
        foreach (['http://localhost:8001/api/v3', 'https://localhost/', 'https://p202.localhost/'] as $url) {
            $message = self::refused(self::guard([], '127.0.0.0/8'), $url);
            $instead = 'names this server: write its address instead (http://127.0.0.1:port)';
            self::assertStringContainsString($instead, $message, $url);
            self::assertStringContainsString('P202_WEBHOOK_ALLOW_NETWORKS', $message, $url);
        }
    }

    /**
     * Two installs on one machine or one private network: refused, saying
     * which setting admits the address, and admitted once it does, over
     * http too.
     */
    public function testAnInstallOnTheOperatorsOwnNetworkIsAdmittedOnlyByTheAllowlist(): void
    {
        $dns = ['staging.corp.example' => ['10.20.0.5']];
        $urls = [
            'https://127.0.0.1:8001/api/v3' => '127.0.0.1',
            'http://127.0.0.1:8001/api/v3' => '127.0.0.1',
            'https://staging.corp.example/api/v3' => '10.20.0.5',
            'http://[::1]:8001/' => '::1',
        ];
        foreach ($urls as $url => $address) {
            $message = self::refused(self::guard($dns), $url);
            self::assertStringContainsString('add ' . $address . self::ALLOWLIST, $message, $url);
        }

        $allowed = self::guard($dns, '127.0.0.1/32, 10.20.0.0/16, ::1/128');
        $literal = $allowed->checkSync('https://127.0.0.1:8001/api/v3');
        self::assertNull($literal->resolveEntry(), 'a literal is connected to as written');
        $http = $allowed->checkSync('http://127.0.0.1:8001/api/v3');
        self::assertSame(['127.0.0.1', 8001], [$http->pinned(), $http->port]);
        $named = $allowed->checkSync('http://staging.corp.example/api/v3');
        self::assertSame('staging.corp.example:80:10.20.0.5', $named->resolveEntry());
        self::assertSame('::1', $allowed->checkSync('http://[::1]:8001/')->pinned());
        $other = self::refused($allowed, 'http://127.0.0.2:8001/');
        self::assertStringContainsString('loopback', $other, 'only the networks it names');
    }

    public function testLinkLocalAndMetadataAreNeverAdmittedAndNoSettingIsOffered(): void
    {
        $dns = ['meta.example.com' => ['169.254.169.254'], 'azure.example.com' => [self::PUBLIC, '168.63.129.16']];
        $urls = [
            'http://169.254.169.254/latest/meta-data/' => 'link-local',
            'https://[fe80::1]/' => 'link-local',
            'https://[::ffff:169.254.169.254]/' => 'link-local',
            'https://100.100.100.200/' => 'metadata',
            'https://[fd00:ec2::254]/' => 'metadata',
            'https://meta.example.com/' => 'link-local',
            'https://azure.example.com/' => 'metadata',
        ];
        foreach ($urls as $url => $why) {
            foreach (['', '0.0.0.0/0, ::/0'] as $allow) {
                $message = self::refused(self::guard($dns, $allow), $url);
                self::assertStringContainsString($why, $message, $url);
                self::assertStringNotContainsString('NETWORKS', $message, $url . ': nothing admits it');
            }
        }
    }

    public function testPlainHttpGoesOnlyToAnAddressTheAllowlistNames(): void
    {
        $dns = ['sync.example.com' => [self::PUBLIC], 'mixed.example.com' => ['10.20.0.5', self::PUBLIC]];
        $message = self::refused(self::guard($dns), 'http://sync.example.com/api/v3');
        self::assertStringContainsString('http:// would send the profile\'s API key in plain text', $message);
        $notNamed = ', which it does not name';
        self::assertStringContainsString('sync.example.com resolves to ' . self::PUBLIC . $notNamed, $message);
        self::assertStringContainsString($notNamed, self::refused(self::guard(), 'http://8.8.8.8/'));
        $allowed = self::guard($dns, '10.20.0.0/16');
        $mixed = self::refused($allowed, 'http://mixed.example.com/');
        self::assertStringContainsString(self::PUBLIC . $notNamed, $mixed);
        $https = $allowed->checkSync('https://mixed.example.com/');
        self::assertSame(['10.20.0.5', self::PUBLIC], $https->addresses, 'https takes it');

        // A save cannot resolve, so a name over http is the send's to judge,
        // unless no allowlist is set at all.
        $unset = (string) self::noResolver()->syncRefusal('http://sync.example.com/');
        self::assertStringContainsString('P202_WEBHOOK_ALLOW_NETWORKS is not set', $unset);
        self::assertNull(self::noResolver('10.20.0.0/16')->syncRefusal('http://sync.example.com/'));
        $public = (string) self::noResolver('10.20.0.0/16')->syncRefusal('http://8.8.8.8/');
        self::assertStringContainsString($notNamed, $public);
    }

    public function testAHostThatDoesNotResolveIsNotAVerdict(): void
    {
        $this->expectException(WebhookUnresolved::class);
        self::guard()->checkSync('https://gone.example.com/api/v3');
    }

    /**
     * P202_URL_MAP sends a URL to a Docker service name the operator chose:
     * its one label is the operator's spelling, and its address is held to
     * the same rules.
     */
    public function testANameTheOperatorsUrlMapSendsToIsCheckedByItsAddress(): void
    {
        $dns = ['web2' => ['172.18.0.3'], 'meta' => ['169.254.169.254']];
        $unmapped = self::refused(self::guard($dns, '172.16.0.0/12'), 'http://web2:80/api/v3');
        self::assertStringContainsString('single-label', $unmapped, 'not mapped');
        $refused = self::refused(self::guard($dns), 'http://web2:80/api/v3', ['web2']);
        self::assertStringContainsString('web2 resolves to 172.18.0.3, which is a private address', $refused);
        self::assertStringContainsString('add 172.18.0.3' . self::ALLOWLIST, $refused);
        $target = self::guard($dns, '172.16.0.0/12')->checkSync('http://web2:80/api/v3', ['web2']);
        self::assertSame('web2:80:172.18.0.3', $target->resolveEntry());
        $meta = self::refused(self::guard($dns, '0.0.0.0/0'), 'http://meta/', ['meta']);
        self::assertStringContainsString('link-local', $meta);
        self::assertNull(self::noResolver()->syncRefusal('https://web2/api/v3', ['web2']));
    }
}
