<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\EgressProxy;
use Prosper202\Http\FixedHostRoute;
use Prosper202\Http\StoredEgressProxy;

/**
 * The fixed-host calls' route (FixedHostRoute): capped always; with an
 * egress proxy, tunnelled to the address this server looked up, never to
 * the name; a loopback override direct; nothing sent when the proxy setting
 * cannot be read or the host does not resolve here.
 */
final class FixedHostRouteTest extends TestCase
{
    protected function tearDown(): void
    {
        EgressProxy::readStoredFrom(null);
        FixedHostRoute::resolveWith(null);
    }

    private static function saved(?string $url): void
    {
        EgressProxy::readStoredFrom(static fn (): ?StoredEgressProxy => $url === null ? null : new StoredEgressProxy($url, '', '', null));
    }

    public function testWithoutAProxyOnlyTheCapIsAdded(): void
    {
        self::saved(null);
        $options = FixedHostRoute::options('https://my.tracking202.com/api/v2/hostings', 100);
        self::assertIsArray($options);
        self::assertSame([CURLOPT_NOPROGRESS, CURLOPT_XFERINFOFUNCTION], array_keys($options));
        self::assertFalse($options[CURLOPT_NOPROGRESS]);
        self::assertSame(0, ($options[CURLOPT_XFERINFOFUNCTION])(null, 0, 100));
        self::assertSame(1, ($options[CURLOPT_XFERINFOFUNCTION])(null, 0, 101), 'past the cap the transfer is stopped');
    }

    public function testThroughTheProxyTheTunnelGoesToTheAddressLookedUpHere(): void
    {
        self::saved('http://192.0.2.10:3128');
        $asked = [];
        FixedHostRoute::resolveWith(static function (string $host) use (&$asked): array {
            $asked[] = $host;

            return ['93.184.216.34', '2606:2800::1'];
        });
        $options = FixedHostRoute::options('https://my.tracking202.com/api/v2/hostings?x=1');
        self::assertIsArray($options);
        self::assertSame(['my.tracking202.com'], $asked);
        self::assertSame('http://192.0.2.10:3128', $options[CURLOPT_PROXY]);
        self::assertTrue($options[CURLOPT_HTTPPROXYTUNNEL]);
        self::assertSame(['::93.184.216.34:443'], $options[CURLOPT_CONNECT_TO]);
        self::assertSame('', $options[CURLOPT_NOPROXY]);

        $plain = FixedHostRoute::options('http://feeds.example.com:8080/x');
        self::assertIsArray($plain);
        self::assertSame(['::93.184.216.34:8080'], $plain[CURLOPT_CONNECT_TO]);
    }

    public function testALoopbackOverrideIsReachedDirectly(): void
    {
        self::saved('http://192.0.2.10:3128');
        FixedHostRoute::resolveWith(static fn (): array => self::fail('an address is not looked up'));
        $options = FixedHostRoute::options('http://127.0.0.1:8099/v1/rates');
        self::assertIsArray($options);
        self::assertSame('', $options[CURLOPT_PROXY]);
        self::assertSame('*', $options[CURLOPT_NOPROXY]);
        self::assertArrayNotHasKey(CURLOPT_CONNECT_TO, $options);
    }

    public function testNothingIsSentWhenTheRouteCannotBeKnown(): void
    {
        self::saved('http://192.0.2.10:3128');
        FixedHostRoute::resolveWith(static fn (): array => []);
        $why = FixedHostRoute::options('https://my.tracking202.com/x');
        self::assertIsString($why);
        self::assertStringContainsString('my.tracking202.com does not resolve from this server', $why);

        self::saved('proxy.example:3128');
        $why = FixedHostRoute::options('https://my.tracking202.com/x');
        self::assertIsString($why);
        self::assertStringStartsWith('The egress proxy saved on Account › Settings is "proxy.example:3128"', $why);

        EgressProxy::readStoredFrom(static fn (): never => throw new \RuntimeException('MySQL server has gone away'));
        $why = FixedHostRoute::options('https://my.tracking202.com/x');
        self::assertIsString($why);
        self::assertStringContainsString('could not be read (RuntimeException: MySQL server has gone away)', $why);

        $log = (string) tempnam(sys_get_temp_dir(), 'p202-fixed-');
        $previous = ini_set('error_log', $log);
        try {
            $ch = curl_init('https://my.tracking202.com/x');
            self::assertInstanceOf(\CurlHandle::class, $ch);
            self::assertFalse(FixedHostRoute::exec($ch), 'nothing is sent');
            self::assertStringContainsString('FixedHostRoute: not sent: The egress proxy saved', (string) file_get_contents($log));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($log);
        }
    }

    public function testExecReadsTheUrlTheRequestWasGiven(): void
    {
        self::saved('http://192.0.2.10:3128');
        $asked = [];
        FixedHostRoute::resolveWith(static function (string $host) use (&$asked): array {
            $asked[] = $host;

            return [];
        });
        $ch = curl_init();
        self::assertInstanceOf(\CurlHandle::class, $ch);
        curl_setopt($ch, CURLOPT_URL, 'https://www.ipqualityscore.com/api/json/ip/KEY/1.2.3.4');
        $log = (string) tempnam(sys_get_temp_dir(), 'p202-fixed-');
        $previous = ini_set('error_log', $log);
        try {
            self::assertFalse(FixedHostRoute::exec($ch));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
        self::assertSame(['www.ipqualityscore.com'], $asked, 'the host of CURLOPT_URL as set');
        self::assertStringNotContainsString('KEY', (string) file_get_contents($log), 'a logged URL leaves its path out');
        @unlink($log);
    }

    public function testTheSavedProxyIsOverriddenByTheConstantAndAnUnreadableOneRefuses(): void
    {
        self::saved('https://Proxy.Corp.Example:8443');
        $proxy = EgressProxy::configured();
        self::assertNotNull($proxy);
        self::assertSame(EgressProxy::FROM_SETTINGS, $proxy->source);
        self::assertSame('https://proxy.corp.example:8443', $proxy->label());

        EgressProxy::readStoredFrom(static fn (): StoredEgressProxy => new StoredEgressProxy('http://10.0.0.5:8080', 'svc', 'p:w', null));
        $withCredentials = EgressProxy::configured();
        self::assertNotNull($withCredentials);
        self::assertTrue($withCredentials->hasCredentials());
        $route = EgressProxy::routeOptions(
            (new \Prosper202\Attribution\WebhookGuard(static fn (): array => ['93.184.216.34'], ''))->check('https://h.example.com/'),
            $withCredentials
        );
        self::assertSame(['svc', 'p:w'], [$route[CURLOPT_PROXYUSERNAME], $route[CURLOPT_PROXYPASSWORD]]);

        EgressProxy::readStoredFrom(static fn (): StoredEgressProxy => new StoredEgressProxy('http://u:p@10.0.0.5:8080', '', '', null));
        try {
            EgressProxy::configured();
            self::fail('a saved URL with credentials in it was used');
        } catch (\UnexpectedValueException $e) {
            self::assertStringNotContainsString('u:p', $e->getMessage());
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheConstantOverridesWhatIsSaved(): void
    {
        define('P202_EGRESS_PROXY', 'http://192.0.2.99:3128');
        EgressProxy::readStoredFrom(static fn (): never => throw new \LogicException('the saved one is not read'));
        $proxy = EgressProxy::configured();
        self::assertNotNull($proxy);
        self::assertSame(EgressProxy::FROM_CONSTANT, $proxy->source);
        self::assertSame('http://192.0.2.99:3128', $proxy->label());
    }
}
