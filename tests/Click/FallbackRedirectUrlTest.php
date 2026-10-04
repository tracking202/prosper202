<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\FallbackRedirectUrl;

/**
 * The URL dl.php, lp.php and off.php redirect to while MySQL is down must be
 * the link's current offer URL, not the one it had at its first click.
 */
final class FallbackRedirectUrlTest extends TestCase
{
    /** @var array<string, string> */
    private array $store = [];
    private int $writes = 0;

    public function testTheFirstClickStoresTheUrl(): void
    {
        self::assertTrue($this->refresh('k', 'https://offer.example/a'));
        self::assertSame(['k' => 'https://offer.example/a'], $this->store);
    }

    public function testAnUnchangedUrlIsNotRewritten(): void
    {
        $this->refresh('k', 'https://offer.example/a');

        self::assertFalse($this->refresh('k', 'https://offer.example/a'));
        self::assertFalse($this->refresh('k', 'https://offer.example/a'));
        self::assertSame(1, $this->writes, 'the hot path writes only when the URL changed');
    }

    public function testAChangedUrlReplacesTheStoredOne(): void
    {
        $this->refresh('k', 'https://retired.example/old');

        self::assertTrue($this->refresh('k', 'https://offer.example/new'));
        self::assertSame(
            'https://offer.example/new',
            $this->store['k'],
            'an outage now would still send visitors to the retired URL'
        );
        self::assertSame(2, $this->writes);
    }

    public function testACampaignLeftWithoutAUrlStopsTheFallback(): void
    {
        $this->refresh('k', 'https://retired.example/old');

        $this->refresh('k', '');

        // The readers redirect only on a truthy value, so an outage answers
        // 503, as the live path answers 502, instead of the retired URL.
        self::assertSame('', $this->store['k']);
    }

    public function testKeysAreIndependent(): void
    {
        $this->refresh('a', 'https://offer.example/a');
        $this->refresh('b', 'https://offer.example/b');

        self::assertFalse($this->refresh('a', 'https://offer.example/a'));
        self::assertSame(['a' => 'https://offer.example/a', 'b' => 'https://offer.example/b'], $this->store);
    }

    /** @return array<string, array{string, string}> [script, key family] */
    public static function writers(): array
    {
        return [
            'dl.php' => ['tracking202/redirect/dl.php', 'url_'],
            'lp.php' => ['tracking202/redirect/lp.php', 'lp_'],
            'off.php' => ['tracking202/redirect/off.php', 'ac_'],
        ];
    }

    /**
     * Each script refreshes the key it reads during an outage, and no longer
     * writes it any other way (the old write-once `getKey === false` block).
     *
     * @dataProvider writers
     */
    public function testTheScriptRefreshesTheKeyItFallsBackTo(string $script, string $family): void
    {
        $src = self::scriptSource($script);
        $quoted = preg_quote($family, '/');

        $readerPattern = "/->get\(md5\('$quoted'\s*\.\s*(\\$\w+)\s*\.\s*systemHash\(\)\)\)/";
        self::assertSame(1, preg_match($readerPattern, $src, $reader), "$script: no outage reader for $family");
        $id = preg_quote($reader[1], '/');
        self::assertMatchesRegularExpression(
            "/FallbackRedirectUrl::refresh\(\s*md5\('$quoted'\s*\.\s*$id\s*\.\s*systemHash\(\)\),/",
            $src,
            "$script: the $family fallback the outage reads is not the one refreshed"
        );
        self::assertDoesNotMatchRegularExpression(
            "/setCache\(\s*md5\('$quoted'/",
            $src,
            "$script: writes $family around the refresh"
        );
    }

    public function testNoOtherFileWritesTheseKeys(): void
    {
        $root = dirname(__DIR__, 2);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        foreach ($it as $file) {
            $path = substr((string) $file->getPathname(), strlen($root) + 1);
            $skipped = preg_match('#^(vendor|tests|node_modules|\.git|\.claude|sdk|go-cli)/#', $path) === 1;
            if (!str_ends_with($path, '.php') || $skipped) {
                continue;
            }
            $code = (string) file_get_contents((string) $file->getPathname());
            if (preg_match("/setCache\(\s*md5\(\s*['\"](?:url_|lp_|ac_)['\"]/", $code) === 1) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, 'a fallback URL is written without FallbackRedirectUrl::refresh()');
    }

    private function refresh(string $key, string $url): bool
    {
        return FallbackRedirectUrl::refresh(
            $key,
            $url,
            fn(string $k): mixed => $this->store[$k] ?? false,
            function (string $k, string $v): bool {
                $this->store[$k] = $v;
                ++$this->writes;

                return true;
            },
        );
    }

    private static function scriptSource(string $script): string
    {
        $root = dirname(__DIR__, 2);
        foreach ([$script, str_replace('/redirect/', '/Redirect/', $script)] as $candidate) {
            if (is_file($root . '/' . $candidate)) {
                return (string) file_get_contents($root . '/' . $candidate);
            }
        }
        self::fail("$script not found");
    }
}
