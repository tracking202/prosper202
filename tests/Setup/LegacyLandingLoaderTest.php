<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\PurLinkPageview;
use Prosper202\Setup\LandingPageCode;

/**
 * Paid 1.9.56 landing pages load tracking202/static/landing.js?lpip=N; the
 * file must ship and make the same landing.php call as the current loader.
 */
final class LegacyLandingLoaderTest extends TestCase
{
    private const LOADER = __DIR__ . '/../../tracking202/static/landing.js';

    public function testLegacyLoaderShips(): void
    {
        self::assertFileExists(self::LOADER);
    }

    public function testLegacyLoaderCallsLandingPhpAsTheCurrentLoaderDoes(): void
    {
        $legacy = (string) file_get_contents(self::LOADER);
        $current = LandingPageCode::loader('//t.example/', '42');

        self::assertStringContainsString('tracking202/static/landing.php?lpip=42&t202id=', $current);
        self::assertStringContainsString("'landing.php?lpip=' + encodeURIComponent(lpip) + '&t202id='", $legacy);
        // The same element id, so a page carrying both snippets loads once.
        self::assertStringContainsString('"upxif"', $current);
        self::assertStringContainsString("s.id = 'upxif'", $legacy);
        self::assertStringContainsString("d.getElementById('upxif')", $legacy);
        // The old snippet's own id, for browsers without currentScript.
        self::assertStringContainsString("d.getElementById('t202js')", $legacy);
        // A 1.9.56 snippet's deferred pixel stays on (landing.php's p202_defpixel).
        self::assertStringContainsString("own.get('defpixel') === '1' ? '&p202_defpixel=1' : ''", $legacy);
        self::assertStringContainsString('&p202_defpixel=1', LandingPageCode::loader('//t.example/', '42', true));
    }

    /**
     * A 1.9.56 page's own opt-out and deferred-pixel flags reach landing.php:
     * the shipped loader runs under node and landing.php's readers are held
     * to what the snippet's own query says.
     */
    public function testTheLoaderPassesLandingPhpsFlagsThrough(): void
    {
        $snippets = [
            'lpip=42&p202_purlink=0', 'lpip=42&p202_purlink=off', 'lpip=42&p202_purlink[]=1', 'lpip=42&p202.purlink=0',
            'lpip=42&p202_purlink=1', 'lpip=42', 'lpip=42&rsid=9', 'lpip=42&p202_defpixel=1', 'lpip=42&defpixel=1',
            'p202_defpixel=1&lpip=42&p202_purlink=0', 'lpip=42&p202_purlink=%E0',
        ];
        $loaded = $this->runLoader($snippets);
        foreach ($snippets as $i => $snippet) {
            parse_str($snippet, $own);
            $url = parse_url($loaded[$i]);
            self::assertSame('/tracking202/static/landing.php', $url['path'] ?? null, $snippet);
            parse_str($url['query'] ?? '', $get);
            self::assertSame('42', $get['lpip'] ?? null, $snippet);
            self::assertSame('7', $get['t202id'] ?? null, $snippet);
            self::assertArrayNotHasKey('rsid', $get, $snippet);
            self::assertSame(PurLinkPageview::requestedBy($own), PurLinkPageview::requestedBy($get), $snippet . ': PurLinks');
            $deferred = static fn (array $q): bool => ($q['p202_defpixel'] ?? $q['defpixel'] ?? null) === '1';
            self::assertSame($deferred($own), $deferred($get), $snippet . ': deferred pixel');
        }
    }

    /** @param list<string> $snippets @return list<string> the landing.php URL each snippet loads */
    private function runLoader(array $snippets): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::assertFalse(getenv('CI') === 'true', 'node is not on PATH, so the loader was not run');
            self::markTestSkipped('node is not on PATH');
        }
        $loader = (string) file_get_contents(self::LOADER);
        $program = 'var snippets = JSON.parse(require("fs").readFileSync(0, "utf8"));'
            . 'var loader = new Function("document", "window", ' . json_encode($loader, JSON_THROW_ON_ERROR) . ');'
            . 'process.stdout.write(JSON.stringify(snippets.map(function (q) {'
            . '  var made = null;'
            . '  var parent = {insertBefore: function (s) { made = s; }};'
            . '  var doc = {currentScript: {src: "https://t.example/tracking202/static/landing.js?" + q},'
            . '    getElementById: function () { return null; },'
            . '    createElement: function () { return {}; },'
            . '    getElementsByTagName: function () { return [{parentNode: parent}]; }};'
            . '  loader(doc, {location: {search: "?t202id=7"}});'
            . '  return made ? made.src : "";'
            . '})));';
        $proc = proc_open([$node, '-e', $program], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($proc, 'node could not be started');
        fwrite($pipes[0], json_encode($snippets, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc), $stderr);
        $loaded = json_decode($stdout, true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($loaded);
        self::assertCount(count($snippets), $loaded, $stdout);

        return $loaded;
    }
}
