<?php

declare(strict_types=1);

namespace Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookRefused;
use Prosper202\Attribution\WebhookUnresolved;
use Prosper202\Conversion\TrafficSourcePixels;
use Tests\Attribution\WebhookGuardTest;

/**
 * The guard a traffic-source postback is sent through
 * (WebhookGuard::checkPostback()) and the part of it a save asks
 * (postbackRefusal()): the export webhooks' address rules, every one of
 * them, with http:// and any port allowed, and the operator's allowlist.
 */
final class PostbackGuardTest extends TestCase
{
    private const PUBLIC = '93.184.216.34';

    /** @param array<string, list<string>> $dns */
    private static function guard(array $dns = [], string $allow = ''): WebhookGuard
    {
        return new WebhookGuard(static fn (string $host): array => $dns[$host] ?? [], $allow);
    }

    private static function refused(WebhookGuard $guard, string $url, bool $webhook = false): string
    {
        try {
            $webhook ? $guard->check($url) : $guard->checkPostback($url);
        } catch (WebhookRefused $e) {
            return $e->getMessage();
        }
        self::fail($url . ' was accepted');
    }

    private static function pin(WebhookGuard $guard, string $url): ?string
    {
        return $guard->checkPostback($url)->resolveEntry();
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function refusedLiterals(): iterable
    {
        foreach (WebhookGuardTest::refusedLiterals() as $name => [$url, $why]) {
            yield $name => [$url, $why];
            yield $name . ', over http' => ['http://' . substr($url, strlen('https://')), $why];
        }
        yield 'metadata on a port' => ['http://169.254.169.254:8080/latest/meta-data/', 'link-local'];
    }

    /** @dataProvider refusedLiterals */
    public function testEveryAddressAWebhookIsRefusedAPostbackIsRefused(string $url, string $why): void
    {
        $message = self::refused(self::guard(), $url);
        self::assertStringContainsString($why, $message);
        self::assertStringContainsString('Postbacks are only sent to public addresses', $message);
        self::assertSame($message, self::guard()->postbackRefusal($url), 'a save is refused in the sender\'s words');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function malformed(): iterable
    {
        $scheme = 'must start with http:// or https://';
        $form = 'not in the form';
        $credentials = 'user name or password';
        yield 'no scheme' => ['network.example/pb?s=1', $scheme];
        yield 'ftp' => ['ftp://network.example/pb', $scheme];
        yield 'gopher' => ['gopher://network.example:70/x', $scheme];
        yield 'file' => ['file:///etc/passwd', $scheme];
        yield 'scheme-relative' => ['//network.example/pb', $scheme];
        yield 'user and password' => ['http://user:pass@network.example/pb', $credentials];
        yield 'user info hiding the host' => ['http://network.example@127.0.0.1/pb', $credentials];
        yield 'backslash hiding the host' => ['http://network.example\\@127.0.0.1/pb', $credentials];
        yield 'user info with tokens' => ['http://[[c1]]@network.example/pb', $credentials];
        yield 'space' => ['http://network.example/a b', $form];
        yield 'newline' => ["http://network.example/pb\nHost: x", $form];
        yield 'tab' => ["http://network.example/pb?\tx", $form];
        yield 'non-ascii host' => ['http://nëtwork.example/', $form];
        yield 'empty host' => ['http:///pb', $form];
        yield 'port 0' => ['http://network.example:0/', 'not a port'];
        yield 'port too big' => ['http://network.example:65536/', 'not a port'];
        yield 'port leading zero' => ['http://network.example:080/', 'not a port'];
        yield 'two ports' => ['http://network.example:80:81/', $form];
        yield 'single label' => ['http://intranet/pb', 'single-label'];
        yield 'localhost' => ['http://localhost:8080/pb', 'single-label'];
        yield 'dot localhost' => ['http://api.localhost/pb', 'local name'];
        yield 'dot internal' => ['http://metadata.google.internal/computeMetadata/v1/', 'local name'];
        yield 'decimal loopback' => ['http://2130706433/', 'dotted form'];
        yield 'octal loopback' => ['http://0177.0.0.1/', 'dotted form'];
        yield 'short loopback' => ['http://127.1/', 'dotted form'];
        yield 'hex loopback' => ['http://0x7f.0.0.1/', 'neither a host name'];
    }

    /** @dataProvider malformed */
    public function testWhatIsNotPlainlyAWebUrlIsRefused(string $url, string $why): void
    {
        $guard = self::guard(['network.example' => [self::PUBLIC]]);
        self::assertStringContainsString($why, self::refused($guard, $url));
        self::assertStringContainsString($why, (string) self::guard()->postbackRefusal($url), 'and when it is saved');
    }

    public function testHttpAnyPortAndAQueryStraightAfterTheHostAreWhatNetworksSend(): void
    {
        $guard = self::guard(['pb.network.example' => [self::PUBLIC, '2606:2800:220:1:248:1893:25c8:1946']]);

        $plain = $guard->checkPostback('http://pb.network.example/postback?subid=42&payout=1.00&email=a@b.example');
        self::assertSame(80, $plain->port);
        self::assertSame(
            'pb.network.example:80:' . self::PUBLIC,
            $plain->resolveEntry(),
            'an http URL is pinned on port 80, where curl connects'
        );
        $tls = self::pin($guard, 'https://pb.network.example?subid=42');
        self::assertSame('pb.network.example:443:' . self::PUBLIC, $tls);

        $ports = [
            'http://pb.network.example:8080/pb' => 8080,
            'https://pb.network.example:2083/pb' => 2083,
            'HTTP://PB.NETWORK.EXAMPLE/pb#frag' => 80,
            'http://pb.network.example' => 80,
            'http://pb.network.example/pb?x=[[unknown]]&y=%7Bz%7D|\\' => 80,
        ];
        foreach ($ports as $url => $port) {
            self::assertSame('pb.network.example:' . $port . ':' . self::PUBLIC, self::pin($guard, $url), $url);
        }

        $literal = self::guard()->checkPostback('http://8.8.8.8:81/pb');
        self::assertTrue($literal->ipLiteral);
        self::assertSame(['8.8.8.8', 81], [$literal->pinned(), $literal->port]);
        self::assertSame('2606:4700::1111', self::guard()->checkPostback('http://[2606:4700::1111]/pb')->pinned());
    }

    /** @return iterable<string, array{0: list<string>, 1: string}> */
    public static function resolverAnswers(): iterable
    {
        yield 'loopback' => [['127.0.0.1'], 'resolves to 127.0.0.1, which is a loopback'];
        yield 'one private among public' => [[self::PUBLIC, '10.1.2.3'], 'resolves to 10.1.2.3'];
        yield 'metadata' => [['169.254.169.254'], 'link-local'];
        yield 'CGNAT' => [['100.64.1.1'], 'carrier-grade NAT'];
        yield 'IPv6 loopback' => [[self::PUBLIC, '::1'], 'resolves to ::1'];
        yield 'IPv4-mapped metadata' => [['::ffff:169.254.169.254'], 'link-local'];
        yield 'not an address' => [['not-an-ip'], 'which is not an address'];
    }

    /**
     * @dataProvider resolverAnswers
     * @param list<string> $answers
     */
    public function testEveryAddressTheHostResolvesToIsChecked(array $answers, string $why): void
    {
        $guard = self::guard(['pb.network.example' => $answers]);
        self::assertStringContainsString($why, self::refused($guard, 'http://pb.network.example/pb'));
    }

    public function testAHostThatResolvesToNothingIsNotAVerdictOnTheUrl(): void
    {
        $this->expectException(WebhookUnresolved::class);
        self::guard()->checkPostback('http://pb.network.example/pb');
    }

    public function testTheOperatorsAllowlistIsTheWebhooksOne(): void
    {
        $guard = self::guard(['pb.corp.example' => ['10.20.0.5']], '127.0.0.2/32, 10.20.0.0/16');
        self::assertSame('127.0.0.2', $guard->checkPostback('http://127.0.0.2:8121/pb')->pinned());
        self::assertSame(['10.20.0.5'], $guard->checkPostback('http://pb.corp.example/pb')->addresses);
        self::assertNull($guard->postbackRefusal('http://10.20.9.9/pb'), 'a save takes what the sender would send');
        self::assertStringContainsString('loopback', self::refused($guard, 'http://127.0.0.1:8121/pb'));
        self::assertStringContainsString('private', self::refused($guard, 'http://10.21.0.1/pb'));

        $wide = self::guard([], '169.254.0.0/16, 0.0.0.0/0, fe80::/10, fd00::/8');
        $never = [
            'http://169.254.169.254/' => 'link-local',
            'http://100.100.100.200/' => 'metadata',
            'http://[fd00:ec2::254]/' => 'metadata',
            'http://[fe80::1]/' => 'link-local',
            'http://0.0.0.0/' => 'this network',
        ];
        foreach ($never as $url => $why) {
            self::assertStringContainsString($why, self::refused($wide, $url), $url . ': never allowlisted');
        }
    }

    public function testASaveNeverAsksTheResolver(): void
    {
        $guard = new WebhookGuard(static function (string $host): array {
            self::fail('a save looked up ' . $host);
        }, '');
        self::assertNull($guard->postbackRefusal('http://pb.network.example/pb?s=[[subid]]'));
        self::assertNull($guard->postbackRefusal('https://pb.network.example:8443/pb'));
    }

    public function testATemplateWhoseHostIsTokensIsLeftToTheSender(): void
    {
        $guard = self::guard();
        self::assertNull($guard->postbackRefusal('http://[[c1]].network.example/pb?s=[[subid]]', true));
        self::assertNull($guard->postbackRefusal('http://[[c1]]/pb', true));
        self::assertNotNull($guard->postbackRefusal('http://[[c1]]/pb'), 'filled, the same characters are no host');
        $tokens = (string) $guard->postbackRefusal('http://[[c1]]@[[c2]]/pb', true);
        self::assertStringContainsString('user name or password', $tokens);
        $scheme = (string) $guard->postbackRefusal('[[scheme]]://network.example/pb', true);
        self::assertStringContainsString('must start with', $scheme);
        $after = (string) $guard->postbackRefusal('http://127.0.0.1/pb?s=[[subid]]', true);
        self::assertStringContainsString('loopback', $after, 'tokens after the host change nothing');

        // Filled from a visitor's c1, a token in the host is checked at send
        // time like any host. Encoding keeps "@" readable, so a value can
        // put one before the real host, and that is refused by name.
        $fill = static fn (string $c1): string => TrafficSourcePixels::replaceTokens('http://[[c1]]/pb', ['c1' => $c1]);
        self::assertStringContainsString('loopback', self::refused($guard, $fill('127.0.0.1')));
        $at = self::refused($guard, $fill('pb.network.example@127.0.0.1'));
        self::assertStringContainsString('user name or password', $at);
        self::assertStringContainsString('not in the form', self::refused($guard, $fill('pb.network.example/x')));
    }

    public function testTheExportWebhooksRulesAreUnchanged(): void
    {
        $guard = self::guard(['hooks.example.com' => [self::PUBLIC]]);
        $http = self::refused($guard, 'http://hooks.example.com/', true);
        self::assertStringContainsString('must start with https://', $http);
        $loopback = self::refused($guard, 'https://127.0.0.1/', true);
        self::assertStringContainsString('Webhooks are only sent to public addresses', $loopback);
        $pinned = $guard->check('https://hooks.example.com/x')->resolveEntry();
        self::assertSame('hooks.example.com:443:' . self::PUBLIC, $pinned);
        try {
            self::guard()->check('https://nowhere.example/');
            self::fail('an unresolved webhook host was accepted');
        } catch (WebhookUnresolved $e) {
            self::assertInstanceOf(WebhookRefused::class, $e, 'the export sender catches it as before');
        }
    }
}
