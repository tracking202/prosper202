<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Http\EgressProxy;
use Prosper202\Notifications\PostbackResult;
use Prosper202\Notifications\PostbackSender;

/**
 * The egress proxy (P202_EGRESS_PROXY): what reads as one and what is
 * refused naming the setting, the route each checked target gets, and,
 * against a proxy on this machine driven by real curl, that the tunnel goes
 * to the address the guard approved and never to the name, that a postback
 * is delivered through it, and that the senders read the setting.
 */
final class EgressProxyTest extends TestCase
{
    private const PUBLIC = '93.184.216.34';

    /**
     * A proxy: prints its port, logs each request head; a CONNECT to port 80
     * is answered 200 and the request inside it 200 "ok" (it stands in for
     * the destination), any other CONNECT 403.
     */
    private const PROXY = <<<'PHP'
<?php
[, $log] = $argv;
$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    echo "ERR $errstr\n";
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1), "\n";
$head = static function ($conn): string {
    $request = '';
    while (!str_contains($request, "\r\n\r\n") && !feof($conn)) {
        $request .= (string) fread($conn, 1);
    }
    return $request;
};
$until = time() + 30;
while (time() < $until) {
    $conn = @stream_socket_accept($server, 1);
    if ($conn === false) {
        continue;
    }
    $request = $head($conn);
    file_put_contents($log, $request, FILE_APPEND);
    if (preg_match('/^CONNECT \S+:80 /', $request) === 1) {
        fwrite($conn, "HTTP/1.1 200 Connection established\r\n\r\n");
        file_put_contents($log, $head($conn), FILE_APPEND);
        fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
    } else {
        fwrite($conn, "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    }
    fclose($conn);
}
PHP;

    /** @var list<resource> */
    private array $processes = [];
    private string $dir = '';

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        $this->processes = [];
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
    }

    /** @return array{0: int, 1: string} port, log */
    private function proxy(): array
    {
        $this->dir = sys_get_temp_dir() . '/p202-egress-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        file_put_contents($this->dir . '/proxy.php', self::PROXY);
        $log = $this->dir . '/proxy.log';
        touch($log);
        $process = proc_open([PHP_BINARY, $this->dir . '/proxy.php', $log], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $this->processes[] = $process;
        $line = trim((string) fgets($pipes[1]));
        if (!ctype_digit($line)) {
            self::markTestSkipped('no proxy listener here: ' . $line);
        }

        return [(int) $line, $log];
    }

    private static function guard(string $address = self::PUBLIC, string $allow = ''): WebhookGuard
    {
        return new WebhookGuard(static fn (string $host): array => [$address], $allow);
    }

    public function testTheSettingReadsAsAProxyUrl(): void
    {
        self::assertNull(EgressProxy::parse(''), 'empty is no proxy');

        $proxy = EgressProxy::parse('HTTP://Proxy.Corp.Example:3128/');
        self::assertNotNull($proxy);
        self::assertSame('http://proxy.corp.example:3128', $proxy->label());
        self::assertNull($proxy->address());

        $v6 = EgressProxy::parse('https://[2001:DB8::0:1]:8443');
        self::assertNotNull($v6);
        self::assertSame('https://[2001:db8::1]:8443', $v6->label());
        self::assertSame('2001:db8::1', $v6->address());

        $withPassword = EgressProxy::parse('http://svc%40corp:p%3Ass@10.0.0.5:8080');
        self::assertNotNull($withPassword);
        self::assertSame('http://10.0.0.5:8080', $withPassword->label(), 'the label never carries the credentials');
        $options = EgressProxy::routeOptions(self::guard()->check('https://hooks.example.com/x'), $withPassword);
        self::assertSame('svc@corp', $options[CURLOPT_PROXYUSERNAME]);
        self::assertSame('p:ss', $options[CURLOPT_PROXYPASSWORD]);
        self::assertSame('http://10.0.0.5:8080', $options[CURLOPT_PROXY]);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function unreadable(): iterable
    {
        yield 'no scheme' => ['proxy.example:3128', 'not of the form'];
        yield 'no port' => ['http://proxy.example', 'names no port'];
        yield 'port 0' => ['http://proxy.example:0', 'not a port'];
        yield 'leading zero' => ['http://proxy.example:03128', 'not a port'];
        yield 'too large' => ['http://proxy.example:65536', 'not a port'];
        yield 'socks' => ['socks5://proxy.example:1080', 'only an http:// or https:// proxy'];
        yield 'a path' => ['http://proxy.example:3128/x', 'not of the form'];
        yield 'whitespace' => [' http://proxy.example:3128', 'not of the form'];
        yield 'two proxies' => ['http://a.example:1,http://b.example:2', 'not of the form'];
        yield 'octal address' => ['http://010.0.0.1:3128', 'not an IPv4 address'];
        yield 'short address' => ['http://10.1:3128', 'not an IPv4 address'];
        yield 'bad v6' => ['http://[2001:db8::g]:3128', 'not of the form'];
        yield 'bad label' => ['http://-proxy.example:3128', 'not a host name'];
        yield 'user, no password' => ['http://user@proxy.example:3128', 'not of the form'];
    }

    /** @dataProvider unreadable */
    public function testAValueThatDoesNotReadRefusesNamingTheSetting(string $value, string $why): void
    {
        try {
            EgressProxy::parse($value);
            self::fail("$value was read as a proxy");
        } catch (\UnexpectedValueException $e) {
            self::assertStringStartsWith('P202_EGRESS_PROXY in 202-config.php is ', $e->getMessage());
            self::assertStringContainsString($why, $e->getMessage());
            self::assertStringContainsString('nothing that goes through the outbound guard is sent until it is fixed', $e->getMessage());
        }
    }

    public function testARefusalNeverRepeatsAPassword(): void
    {
        try {
            EgressProxy::parse('http://user:SECRET@proxy.example');
            self::fail('a proxy with no port was read');
        } catch (\UnexpectedValueException $e) {
            self::assertStringNotContainsString('SECRET', $e->getMessage());
            self::assertStringContainsString('names no port', $e->getMessage());
        }
    }

    public function testDirectTheTargetIsPinnedWithEveryProxyOff(): void
    {
        $target = self::guard()->check('https://hooks.example.com/x');
        self::assertSame(
            [CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_RESOLVE => ['hooks.example.com:443:' . self::PUBLIC]],
            EgressProxy::routeOptions($target, null)
        );
        self::assertSame(self::PUBLIC, EgressProxy::expectedPeer($target, null));
        self::assertFalse(EgressProxy::tunnels($target, null));
    }

    public function testThroughTheProxyTheTunnelGoesToTheCheckedAddress(): void
    {
        $proxy = EgressProxy::parse('http://192.0.2.10:3128');
        self::assertNotNull($proxy);
        $target = self::guard()->check('https://hooks.example.com/x');
        $options = EgressProxy::routeOptions($target, $proxy);
        self::assertSame('http://192.0.2.10:3128', $options[CURLOPT_PROXY]);
        self::assertSame(CURLPROXY_HTTP, $options[CURLOPT_PROXYTYPE]);
        self::assertSame('', $options[CURLOPT_NOPROXY], "an environment's no_proxy does not send it past the proxy");
        self::assertTrue($options[CURLOPT_HTTPPROXYTUNNEL]);
        self::assertSame(['::' . self::PUBLIC . ':443'], $options[CURLOPT_CONNECT_TO]);
        self::assertArrayNotHasKey(CURLOPT_RESOLVE, $options);
        self::assertTrue(EgressProxy::tunnels($target, $proxy));
        self::assertSame('192.0.2.10', EgressProxy::expectedPeer($target, $proxy), 'the connection is to the proxy');

        $v6 = (new WebhookGuard(static fn (): array => ['2606:4700::1111'], ''))->checkPostback('http://pb.example.com:8080/p');
        self::assertSame(['::[2606:4700::1111]:8080'], EgressProxy::routeOptions($v6, $proxy)[CURLOPT_CONNECT_TO], 'an IPv6 address bracketed');

        $tls = EgressProxy::parse('https://proxy.example:443');
        self::assertNotNull($tls);
        $options = EgressProxy::routeOptions($target, $tls);
        self::assertSame(CURLPROXY_HTTPS, $options[CURLOPT_PROXYTYPE]);
        self::assertTrue($options[CURLOPT_PROXY_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_PROXY_SSL_VERIFYHOST]);
        self::assertNull(EgressProxy::expectedPeer($target, $tls), 'a proxy named by host name: its address is not known');
    }

    public function testTheOperatorsOwnNetworkIsReachedDirect(): void
    {
        $proxy = EgressProxy::parse('http://192.0.2.10:3128');
        self::assertNotNull($proxy);
        $own = self::guard('10.20.0.7', '10.20.0.0/16')->check('https://receiver.corp.example/x');
        self::assertFalse(EgressProxy::tunnels($own, $proxy));
        self::assertSame(['receiver.corp.example:443:10.20.0.7'], EgressProxy::routeOptions($own, $proxy)[CURLOPT_RESOLVE]);
        self::assertSame('10.20.0.7', EgressProxy::expectedPeer($own, $proxy));
    }

    public function testEverySenderLaysTheRouteOverItsOptions(): void
    {
        $proxy = EgressProxy::parse('http://192.0.2.10:3128');
        self::assertNotNull($proxy);
        $target = self::guard()->check('https://hooks.example.com/x');
        $route = EgressProxy::routeOptions($target, $proxy);
        foreach ([
            'WebhookSender' => WebhookSender::curlOptions($target, 'x', [], WebhookSender::TIMEOUT, $proxy),
            'PostbackSender' => PostbackSender::curlOptions($target, PostbackSender::TIMEOUT, $proxy),
            'LandingPageCheck' => \Prosper202\Setup\LandingPageCheck::curlOptions($target, 5, $proxy),
            'RemoteApiClient' => \Api\V3\Support\RemoteApiClient::curlOptions($target, 'GET', $target->url, [], null, 5, $proxy),
        ] as $sender => $options) {
            self::assertSame($route, array_intersect_key($options, $route), $sender);
            self::assertArrayNotHasKey(CURLOPT_RESOLVE, $options, $sender);
        }
    }

    public function testRealCurlTunnelsToTheCheckedAddressNotTheName(): void
    {
        [$port, $log] = $this->proxy();
        $proxy = EgressProxy::parse('http://127.0.0.1:' . $port);
        self::assertNotNull($proxy);
        // The name resolves elsewhere for everyone but the guard: only the tunnel's target says where it went.
        $target = self::guard()->check('https://hooks.example.com/x');
        $answer = WebhookSender::curlTransport(WebhookSender::curlOptions($target, 'x', [], 5, $proxy));

        self::assertSame(0, $answer['status']);
        self::assertStringContainsString('403', (string) $answer['error'], 'the proxy refused the tunnel');
        self::assertSame('127.0.0.1', $answer['primary_ip'], 'curl connected to the proxy');
        self::assertStringStartsWith('CONNECT ' . self::PUBLIC . ':443 HTTP/1.1', (string) file_get_contents($log));
        self::assertStringNotContainsString('hooks.example.com', (string) file_get_contents($log), 'the proxy is never told the name');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAPostbackIsDeliveredThroughTheConfiguredProxy(): void
    {
        [$port, $log] = $this->proxy();
        define('P202_EGRESS_PROXY', 'http://127.0.0.1:' . $port);

        $result = (new PostbackSender(self::guard()))->send('http://pb.network.example/pb?subid=7');

        $seen = (string) file_get_contents($log);
        self::assertSame(PostbackResult::DELIVERED, $result->outcome, $result->reason . "\n" . $seen);
        self::assertStringStartsWith('CONNECT ' . self::PUBLIC . ':80 HTTP/1.1', $seen);
        self::assertStringContainsString("\r\n\r\nGET /pb?subid=7 HTTP/1.1\r\nHost: pb.network.example\r\n", $seen, 'the request inside names the host');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAMalformedSettingSendsNothing(): void
    {
        define('P202_EGRESS_PROXY', 'proxy.corp.example:3128');
        $calls = 0;
        $transport = static function () use (&$calls): array {
            $calls++;

            return ['status' => 200, 'error' => null, 'primary_ip' => self::PUBLIC, 'redirect_url' => null, 'location' => null, 'body' => ''];
        };

        $postback = (new PostbackSender(self::guard(), $transport))->send('http://pb.network.example/pb');
        self::assertSame(PostbackResult::FAILED, $postback->outcome, "kept to retry");
        self::assertStringContainsString('not sent: P202_EGRESS_PROXY in 202-config.php is "proxy.corp.example:3128"', $postback->reason);

        $webhook = (new WebhookSender(self::guard(), $transport))->post('https://hooks.example.com/x', 'x', []);
        self::assertStringStartsWith('P202_EGRESS_PROXY in 202-config.php', (string) $webhook['refused']);
        self::assertTrue($webhook['retryable'], 'the operator can fix it; the delivery is kept');
        self::assertSame(0, $calls, 'nothing was handed to curl, direct or otherwise');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testASettingThatIsNotAStringRefuses(): void
    {
        define('P202_EGRESS_PROXY', true);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('P202_EGRESS_PROXY in 202-config.php is a bool');
        EgressProxy::configured();
    }
}
