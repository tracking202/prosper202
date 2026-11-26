<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\VendorFetch;

/**
 * getData() and getUrl() reach https://my.tracking202.com and nowhere else:
 * a URL off that host is refused before curl sees it, and a redirect is
 * followed by hand only to that host (4KFZ). They followed any redirect to
 * any address.
 */
final class VendorFetchTest extends TestCase
{
    private const VENDOR = 'https://my.tracking202.com/api/v2/hostings';

    private string $log = '';
    private string|false $previousLog = false;

    protected function setUp(): void
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-vendor-');
        $this->previousLog = ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLog === false ? '' : $this->previousLog);
        @unlink($this->log);
    }

    /**
     * @param list<array{status: int, body: string|null, redirect: string|null, error: string}> $answers
     * @param list<array<int, mixed>> $calls
     */
    private static function hop(array $answers, array &$calls): \Closure
    {
        return static function (array $options) use (&$answers, &$calls): array {
            $calls[] = $options;

            return array_shift($answers) ?? ['status' => 0, 'body' => null, 'redirect' => null, 'error' => 'no more answers'];
        };
    }

    /** @return iterable<string, array{string}> */
    public static function offHost(): iterable
    {
        yield 'another host' => ['https://evil.example/x'];
        yield 'plain http' => ['http://my.tracking202.com/x'];
        yield 'a port' => ['https://my.tracking202.com:8443/x'];
        yield 'user info' => ['https://user@my.tracking202.com/x'];
        yield 'metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'a lookalike' => ['https://my.tracking202.com.evil.example/x'];
    }

    /** @dataProvider offHost */
    public function testAUrlOffTheVendorHostIsNeverHandedToCurl(string $url): void
    {
        $calls = [];
        self::assertNull(VendorFetch::fetch($url, 10, 5, null, [], '', self::hop([], $calls)));
        self::assertSame([], $calls);
        self::assertStringContainsString('only https://my.tracking202.com is fetched this way', (string) file_get_contents($this->log));
    }

    public function testARedirectIsFollowedOnlyOnTheVendorHost(): void
    {
        $calls = [];
        $body = VendorFetch::fetch(self::VENDOR, 10, 5, null, [], '', self::hop([
            ['status' => 301, 'body' => '', 'redirect' => 'https://my.tracking202.com/api/v2/hostings/', 'error' => ''],
            ['status' => 200, 'body' => '[1]', 'redirect' => null, 'error' => ''],
        ], $calls));
        self::assertSame('[1]', $body);
        self::assertCount(2, $calls);
        self::assertSame('https://my.tracking202.com/api/v2/hostings/', $calls[1][CURLOPT_URL]);
        foreach ($calls as $options) {
            self::assertFalse($options[CURLOPT_FOLLOWLOCATION], 'curl follows nothing itself');
            self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
            self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
            self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
            self::assertGreaterThan(0, $options[CURLOPT_CONNECTTIMEOUT]);
            self::assertGreaterThan(0, $options[CURLOPT_TIMEOUT]);
        }

        $calls = [];
        self::assertNull(VendorFetch::fetch(self::VENDOR, 10, 5, null, [], '', self::hop([
            ['status' => 302, 'body' => '', 'redirect' => 'http://10.0.0.1/admin', 'error' => ''],
        ], $calls)));
        self::assertCount(1, $calls, 'the redirect off the host is not fetched');
        self::assertStringContainsString('refused a redirect to http://10.0.0.1/admin', (string) file_get_contents($this->log));
    }

    public function testRedirectsAreBoundedAndAPostsIsNotFollowed(): void
    {
        $loop = ['status' => 302, 'body' => '', 'redirect' => self::VENDOR, 'error' => ''];
        $calls = [];
        self::assertNull(VendorFetch::fetch(self::VENDOR, 10, 5, null, [], '', self::hop(array_fill(0, 10, $loop), $calls)));
        self::assertCount(VendorFetch::MAX_REDIRECTS + 1, $calls);

        $calls = [];
        self::assertNull(VendorFetch::fetch(self::VENDOR, 10, 5, ['a' => '1'], [], '', self::hop([$loop], $calls)));
        self::assertCount(1, $calls);
        self::assertSame('a=1', $calls[0][CURLOPT_POSTFIELDS]);
    }

    public function testAnyAnswersBodyIsReturnedAsCurlReturnedIt(): void
    {
        $calls = [];
        self::assertSame('{"msg":"no"}', VendorFetch::fetch(self::VENDOR, 10, 5, null, [], '', self::hop([
            ['status' => 404, 'body' => '{"msg":"no"}', 'redirect' => null, 'error' => ''],
        ], $calls)));
        self::assertNull(VendorFetch::fetch(self::VENDOR, 10, 5, null, [], '', self::hop([
            ['status' => 0, 'body' => null, 'redirect' => null, 'error' => 'Could not resolve host'],
        ], $calls)));
    }

    public function testTheHelpersGoThroughIt(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-tracking202api.php';
        self::assertSame('', getUrl('http://169.254.169.254/latest/meta-data/'));
        self::assertStringContainsString('VendorFetch: refused a request to http://169.254.169.254', (string) file_get_contents($this->log));
    }
}
