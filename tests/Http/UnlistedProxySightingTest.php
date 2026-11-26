<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\TrustedProxies;
use Prosper202\Http\UnlistedProxySighting;

/**
 * Forwarding headers from a peer nobody listed: recorded at most once a
 * window, warned about once the same peer is seen in MIN_WINDOWS of them,
 * and no more once the setting lists it.
 */
final class UnlistedProxySightingTest extends TestCase
{
    private string $root = '';

    private const PROXY = ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/p202-sighting-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/202-config/temp', 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root . '/202-config/temp/*') ?: []);
        rmdir($this->root . '/202-config/temp');
        rmdir($this->root . '/202-config');
        rmdir($this->root);
    }

    public function testNothingIsWrittenForAListedPeerOrARequestWithoutHeaders(): void
    {
        $lan = TrustedProxies::parse('10.1.2.0/24');
        UnlistedProxySighting::record($this->root, self::PROXY, $lan);
        UnlistedProxySighting::record($this->root, ['REMOTE_ADDR' => '203.0.113.5'], $lan);
        self::assertFileDoesNotExist(UnlistedProxySighting::path($this->root));
    }

    public function testTheSamePeerInEnoughWindowsIsWarnedAbout(): void
    {
        $none = TrustedProxies::none();
        $t = 1_800_000_000;
        UnlistedProxySighting::record($this->root, self::PROXY, $none, $t);
        $first = UnlistedProxySighting::read($this->root);
        self::assertSame(['peer' => '10.1.2.3', 'masked' => false, 'headers' => ['HTTP_X_FORWARDED_FOR'], 'first_at' => $t, 'last_at' => $t, 'windows' => 1], $first);
        self::assertNull(UnlistedProxySighting::warning($first, $none, $t), 'one window is not yet a proxy');

        // Within the window nothing is written: mtime is the gate.
        touch(UnlistedProxySighting::path($this->root), $t);
        UnlistedProxySighting::record($this->root, ['REMOTE_ADDR' => '172.16.5.9', 'HTTP_X_REAL_IP' => '1.2.3.4'], $none, $t + 60);
        self::assertSame($first, UnlistedProxySighting::read($this->root), 'another peer inside the window is not written');

        foreach ([1, 2] as $n) {
            $at = $t + $n * UnlistedProxySighting::RECORD_EVERY_SECONDS;
            UnlistedProxySighting::record($this->root, self::PROXY, $none, $at);
            touch(UnlistedProxySighting::path($this->root), $at);
        }
        $seen = UnlistedProxySighting::read($this->root);
        self::assertIsArray($seen);
        self::assertSame(3, $seen['windows']);
        $warning = (string) UnlistedProxySighting::warning($seen, $none, $seen['last_at'] + 60);
        self::assertStringContainsString('Requests from 10.1.2.3 carry X-Forwarded-For', $warning);
        self::assertStringContainsString('add it to P202_TRUSTED_PROXIES', $warning);
        self::assertStringNotContainsString('198.51.100.7', $warning, 'a header value is never shown');

        self::assertNull(UnlistedProxySighting::warning($seen, TrustedProxies::parse('10.1.2.3'), $seen['last_at']), 'listed now: no warning');
        self::assertNull(
            UnlistedProxySighting::warning($seen, $none, $seen['last_at'] + UnlistedProxySighting::RECENT_SECONDS + 1),
            'a week later: no warning'
        );
    }

    public function testAnotherPeerStartsTheCountAgain(): void
    {
        $none = TrustedProxies::none();
        $t = 1_800_000_000;
        UnlistedProxySighting::record($this->root, self::PROXY, $none, $t);
        touch(UnlistedProxySighting::path($this->root), $t);
        $later = $t + UnlistedProxySighting::RECORD_EVERY_SECONDS;
        UnlistedProxySighting::record($this->root, ['REMOTE_ADDR' => '172.16.5.9', 'HTTP_FORWARDED' => 'for=x'], $none, $later);
        $seen = UnlistedProxySighting::read($this->root);
        self::assertSame(['peer' => '172.16.5.9', 'masked' => false, 'headers' => ['HTTP_FORWARDED'], 'first_at' => $later, 'last_at' => $later, 'windows' => 1], $seen);
    }

    /** A public peer may be a visitor: kept as its /24 (/48), and named so. */
    public function testAPublicPeerIsKeptMasked(): void
    {
        $none = TrustedProxies::none();
        UnlistedProxySighting::record($this->root, ['REMOTE_ADDR' => '8.8.4.4', 'HTTP_CF_CONNECTING_IP' => '1.1.1.1'], $none);
        $seen = UnlistedProxySighting::read($this->root);
        self::assertIsArray($seen);
        self::assertSame(['8.8.4.0', true], [$seen['peer'], $seen['masked']]);
        self::assertStringNotContainsString('8.8.4.4', (string) file_get_contents(UnlistedProxySighting::path($this->root)));
        $seen['windows'] = UnlistedProxySighting::MIN_WINDOWS;
        $warning = (string) UnlistedProxySighting::warning($seen, $none, $seen['last_at']);
        self::assertStringContainsString('an address in 8.8.4.0/24 (shortened', $warning);
        self::assertStringContainsString('carry CF-Connecting-IP', $warning);

        UnlistedProxySighting::record($this->root, ['REMOTE_ADDR' => '2001:4860:1234::5', 'HTTP_FORWARDED' => 'for=x'], $none, time() + 3600);
        self::assertSame('2001:4860:1234::', UnlistedProxySighting::read($this->root)['peer'] ?? null);
    }

    /** Listing the peer ends the warning, and its next request removes the file. */
    public function testTheSightingGoesOnceThePeerIsListed(): void
    {
        UnlistedProxySighting::record($this->root, self::PROXY, TrustedProxies::none());
        self::assertFileExists(UnlistedProxySighting::path($this->root));
        UnlistedProxySighting::record($this->root, ['REMOTE_ADDR' => '10.1.2.4'] + self::PROXY, TrustedProxies::parse('10.1.2.4'));
        self::assertFileExists(UnlistedProxySighting::path($this->root), 'another listed proxy leaves it');
        UnlistedProxySighting::record($this->root, self::PROXY, TrustedProxies::parse('10.1.2.3'));
        self::assertFileDoesNotExist(UnlistedProxySighting::path($this->root));
    }

    /** @return iterable<string, array{string}> */
    public static function unreadable(): iterable
    {
        yield 'not JSON' => ['nope'];
        yield 'a peer that is not an address' => ['{"peer":"<b>x</b>","headers":[],"first_at":1,"last_at":1,"windows":9}'];
        yield 'a count that is not a number' => ['{"peer":"192.0.2.1","masked":false,"headers":[],"first_at":1,"last_at":1,"windows":"9"}'];
        yield 'no masked flag' => ['{"peer":"192.0.2.1","headers":[],"first_at":1,"last_at":1,"windows":9}'];
        yield 'too long' => [str_repeat(' ', 5000)];
    }

    /** @dataProvider unreadable */
    public function testAFileThatIsNotASightingIsNone(string $body): void
    {
        file_put_contents(UnlistedProxySighting::path($this->root), $body);
        self::assertNull(UnlistedProxySighting::read($this->root));
    }

    /**
     * Both bootstraps record, once the autoloader is up, and both places
     * that show install warnings show this one.
     */
    public function testTheBootstrapsRecordAndSettingsAndHealthWarn(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['202-config/connect.php', '202-config/connect2.php'] as $file) {
            $source = (string) file_get_contents($root . '/' . $file);
            $call = strpos($source, "\n\\Prosper202\\Http\\UnlistedProxySighting::record(ROOT_PATH, \$_SERVER);\n");
            $autoload = strpos($source, 'vendor/autoload.php');
            self::assertIsInt($call, $file . ' records the sighting at the top level');
            self::assertIsInt($autoload);
            self::assertGreaterThan($autoload, $call, $file . ' records after the autoloader is required');
            self::assertSame(1, substr_count($source, 'UnlistedProxySighting::record('), $file);
        }
        foreach (['202-account/administration.php', '202-cronjobs/health.php'] as $file) {
            self::assertStringContainsString(
                'UnlistedProxySighting::warning(',
                (string) file_get_contents($root . '/' . $file),
                $file . ' shows the warning'
            );
        }
    }

    public function testOnlyKnownHeaderNamesAreRead(): void
    {
        file_put_contents(
            UnlistedProxySighting::path($this->root),
            '{"peer":"192.0.2.1","masked":false,"headers":["HTTP_X_FORWARDED_FOR","<script>"],"first_at":1,"last_at":1,"windows":5}'
        );
        self::assertSame(['HTTP_X_FORWARDED_FOR'], UnlistedProxySighting::read($this->root)['headers'] ?? null);
    }
}
