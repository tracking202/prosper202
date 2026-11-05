<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\PurLinkPageview;
use Prosper202\Click\PurLinkProof;
use Prosper202\Click\RecordedClicks;

final class PurLinkPageviewTest extends TestCase
{
    protected function tearDown(): void
    {
        RecordedClicks::reset();
    }

    public function testOptedInCommittedPageviewProducesSeparateScopedProofs(): void
    {
        RecordedClicks::note(22);
        $key = str_repeat('x', 32);
        $output = PurLinkPageview::script(['p202_purlink' => '1'], true, 22, 4, 8, 1_700_000_000, fn () => $key);
        self::assertSame(1, preg_match('/p202PurLinkPageview\((\{.*\})\)/', $output, $matches));
        $proofs = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(22, PurLinkProof::verify($proofs['exitProof'], PurLinkProof::EXIT, 1_700_000_001, $key)['click_id']);
        self::assertSame(4, PurLinkProof::verify($proofs['pixelProof'], PurLinkProof::PIXELS, 1_700_000_001, $key)['landing_page_id']);
        self::assertNull(PurLinkProof::verify($proofs['exitProof'], PurLinkProof::PIXELS, 1_700_000_001, $key));
        self::assertNull(PurLinkProof::verify($proofs['pixelProof'], PurLinkProof::PIXELS, 1_700_086_400, $key));
    }

    /** @dataProvider refusedPageviews */
    public function testRefusedPageviewsDoNotReadTheKey(array $request, bool $trackingAllowed): void
    {
        $output = PurLinkPageview::script($request, $trackingAllowed, 22, 4, 8, 1_700_000_000,
            static function (): ?string { self::fail('a refused pageview read the signing key'); });
        self::assertSame('', $output);
    }

    public static function refusedPageviews(): iterable
    {
        yield 'not opted in' => [[], true];
        yield 'list option' => [['p202_purlink' => ['1']], true];
        yield 'withheld consent' => [['p202_purlink' => '1', 'p202_consent' => '0'], true];
        yield 'invalid consent' => [['p202_purlink' => '1', 'p202_consent' => ['1']], true];
    }

    public function testPrivacyHoldsBackOnlyThePixelProof(): void
    {
        RecordedClicks::note(22);
        $key = str_repeat('x', 32);
        $output = PurLinkPageview::script(['p202_purlink' => '1'], false, 22, 4, 8, 1_700_000_000, fn () => $key);
        self::assertSame(1, preg_match('/p202PurLinkPageview\((\{.*\})\)/', $output, $matches));
        $proofs = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(22, PurLinkProof::verify($proofs['exitProof'], PurLinkProof::EXIT, 1_700_000_001, $key)['click_id']);
        self::assertArrayNotHasKey('pixelProof', $proofs, 'a held-back visitor fires no third-party pixel');
    }

    public function testUnallocatedClicksAndUnavailableKeyDoNotEmitCapabilities(): void
    {
        self::assertSame('', PurLinkPageview::script(['p202_purlink' => '1'], true, 22, 4, 8, 1_700_000_000, fn () => str_repeat('x', 32)));
        RecordedClicks::note(22);
        self::assertSame('', PurLinkPageview::script(['p202_purlink' => '1'], true, 22, 4, 8, 1_700_000_000, fn () => null));
        self::assertSame('', PurLinkPageview::script(['p202_purlink' => '1'], true, 22, 4, 8, 1_700_000_000, static function (): ?string { throw new \RuntimeException('unavailable'); }));
    }

    /** @dataProvider optOutSpellings */
    public function testASnippetTurnsPurLinksOffWithAnyValueButOne(string $query, bool $on): void
    {
        parse_str($query, $get);
        self::assertSame($on, PurLinkPageview::requestedBy($get), $query);
    }

    public static function optOutSpellings(): iterable
    {
        foreach (['', 'lpip=4', 'p202_purlink=1', 'p202_purlink=%201%20', 'p202.purlink=1'] as $on) {
            yield $on => [$on, true];
        }
        foreach ([
            'p202_purlink=0', 'p202_purlink=off', 'p202_purlink=false', 'p202_purlink=no', 'p202_purlink=00',
            'p202_purlink=', 'p202_purlink[]=1', 'p202_purlink[]=', 'p202.purlink=0', 'p202_purlink=1&p202_purlink=0',
        ] as $off) {
            yield $off => [$off, false];
        }
    }

    /**
     * The exit-order fence and the deferred pixel's fire-once rows are keyed
     * by click and outlive one proof: that holds because a click's proofs
     * come only from the pageview request that allocated it, once.
     */
    public function testOnlyTheAllocatingPageviewIssuesAClicksProofs(): void
    {
        $root = dirname(__DIR__, 2);
        $callers = [];
        foreach (new \RecursiveIteratorIterator(\Tests\Support\SourceScan::tree($root)) as $file) {
            $path = substr((string) $file, strlen($root) + 1);
            if (!str_ends_with($path, '.php') || preg_match('#^(vendor|tests|node_modules)/#', $path) === 1) {
                continue;
            }
            $source = (string) file_get_contents((string) $file);
            $calls = ['PurLinkProof::issue(', 'PurLinkPageview::forDatabase(', 'PurLinkPageview::script(',
                'PageUpdate::clickScript(', 'echo p202DcsClickJs('];
            foreach ($calls as $call) {
                if (str_contains($source, $call)) {
                    $callers[$call][] = $path;
                }
            }
        }
        foreach ($callers as &$paths) {
            sort($paths);
        }
        ksort($callers);
        // PageUpdate's update proof goes out from the same pageview, through p202DcsClickJs().
        self::assertSame([
            'PageUpdate::clickScript(' => ['202-config/connect2.php'],
            'PurLinkPageview::forDatabase(' => ['tracking202/static/record_adv.php', 'tracking202/static/record_simple.php'],
            'PurLinkProof::issue(' => ['202-config/Click/PageUpdate.php', '202-config/Click/PurLinkPageview.php'],
            'echo p202DcsClickJs(' => ['tracking202/static/record_adv.php', 'tracking202/static/record_simple.php'],
        ], $callers);
        foreach ($callers['PurLinkPageview::forDatabase('] as $path) {
            $source = (string) file_get_contents($root . '/' . $path);
            self::assertSame(1, substr_count($source, '->allocateClickId()'), "$path allocates one click");
            foreach (['PurLinkPageview::forDatabase(', 'p202DcsClickJs('] as $issuer) {
                self::assertSame(1, substr_count($source, $issuer), "$path calls $issuer once");
                self::assertLessThan(strpos($source, $issuer), strpos($source, '->allocateClickId()'), $path);
            }
        }
    }
}
