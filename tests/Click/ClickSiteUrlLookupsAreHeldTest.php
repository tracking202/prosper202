<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Tests\Support\HeldLookupScan;
use Tests\Support\SourceScan;

/**
 * findOrCreateClickSiteUrl() finds its URL's domain through the cache
 * (CachedLocationRepository). A domain row inserted inside a transaction
 * that rolls back would leave that cache entry naming a row that is gone, so
 * every call in the served tree either runs inside the click's held cache
 * work (p202WithHeldCacheWrites(), which drops what it held on a rollback) or
 * is listed below with why it runs in autocommit. A call added anywhere else
 * fails until it is held or listed.
 */
final class ClickSiteUrlLookupsAreHeldTest extends TestCase
{
    private const METHOD = 'findOrCreateClickSiteUrl';

    /**
     * Calls outside the hold, in order per file, by argument as the tokens
     * spell it, each with why no transaction is open when it runs.
     */
    private const AUTOCOMMIT = [
        '202-config/functions-shared.php' => [
            ['\\Prosper202\\Click\\TrackingBaseUrl :: requestUrl ( $_SERVER )',
                'the error recorder rolls back and turns autocommit on before it writes'],
        ],
        'tracking202/redirect/dl.php' => [
            ["\$_GET [ 't202ref' ]", 'before the click is written'],
            ["\$referer_query [ 'url' ]", 'before the click is written'],
            ["\$_SERVER [ 'HTTP_REFERER' ] ?? ''", 'before the click is written'],
            ["\$referer_query [ 'url' ]", 'before the click is written'],
            ["\$_SERVER [ 'HTTP_REFERER' ] ?? ''", 'before the click is written'],
            ['$outbound_site_url', 'before the click is written'],
            ['$cloaking_site_url', 'before the click is written'],
            ['$redirect_site_url', 'before the click is written'],
        ],
        'tracking202/redirect/off.php' => [
            ['$outbound_site_url', 'before the exit is written'],
        ],
        'tracking202/redirect/offrtr.php' => [
            ['$outbound_site_url', 'before the exit is written'],
            ['$outbound_site_url', 'before the exit is written'],
        ],
        'tracking202/redirect/rtr.php' => [
            ["\$referer_query [ 'url' ]", 'before the click is written'],
            ["(string) ( \$_SERVER [ 'HTTP_REFERER' ] ?? '' )", 'before the click is written'],
            ['$outbound_site_url', 'before the click is written'],
            ['$cloaking_site_url', 'before the click is written'],
        ],
        'tracking202/static/record_adv.php' => [
            ['$_referer_site_url', 'before the click is written'],
            ['$landing_site_url', 'before the click is written'],
        ],
        'tracking202/static/record_simple.php' => [
            ['$_referer_site_url', 'before the click is written'],
            ['$landing_site_url', 'before the click is written'],
            ['$outbound_site_url', 'before the click is written'],
            ['$cloaking_site_url', 'before the click is written'],
            ['$redirect_site_url', 'before the click is written'],
        ],
    ];

    /** The calls that resolve a redirect inside the write, which the hold must cover. */
    private const HELD = [
        'tracking202/redirect/off.php' => 1,
        'tracking202/redirect/offrtr.php' => 2,
        'tracking202/redirect/rtr.php' => 1,
    ];

    public function testEveryCallIsHeldOrListedAsAutocommit(): void
    {
        $outside = [];
        $held = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            if (!str_contains($source, self::METHOD) || str_contains($file, '/Repository/')) {
                continue;
            }
            // Case-folded: a macOS checkout merges tracking202/Redirect into redirect.
            $key = strtolower($file);
            foreach ((new HeldLookupScan($source))->calls(self::METHOD) as [$line, $argument, $isHeld]) {
                if ($isHeld) {
                    $held[$key] = ($held[$key] ?? 0) + 1;
                } else {
                    $outside[$key][] = $argument;
                }
            }
        }
        ksort($outside);
        ksort($held);

        $listed = array_map(static fn (array $calls): array => array_column($calls, 0), self::AUTOCOMMIT);
        ksort($listed);
        self::assertSame(
            $listed,
            $outside,
            'a findOrCreateClickSiteUrl() call outside the held cache work: hold it, or list it in AUTOCOMMIT with why '
                . 'no transaction is open when it runs'
        );
        self::assertSame(self::HELD, $held, 'the redirect lookups inside the write stay held');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function shapes(): iterable
    {
        yield 'at top level' => ['<?php $r->' . self::METHOD . '($u);', false];
        yield 'inside the hold' => ['<?php p202WithHeldCacheWrites(fn () => $r->' . self::METHOD . '($u));', true];
        yield 'a closure assigned and used inside the hold' => [
            '<?php $f = function () use ($r) { return $r->' . self::METHOD . '($u); };'
                . ' p202WithHeldCacheWrites(fn () => $w->write($f));',
            true,
        ];
        yield 'a closure assigned and used outside it' => [
            '<?php $f = function () use ($r) { return $r->' . self::METHOD . '($u); }; $f();'
                . ' p202WithHeldCacheWrites(fn () => $w->write());',
            false,
        ];
        yield 'a closure passed to a callable that holds' => [
            '<?php $exit = function ($resolve) { p202WithHeldCacheWrites(fn () => $w->write($resolve)); };'
                . ' $exit(1, function () use ($r) { return $r->' . self::METHOD . '($u); });',
            true,
        ];
        yield 'inside a closure that holds, before the hold' => [
            '<?php $go = function () use ($r) { $r->' . self::METHOD . '($u);'
                . ' p202WithHeldCacheWrites(fn () => $w->write()); }; $go();',
            false,
        ];
    }

    /** @dataProvider shapes */
    public function testTheScanTellsHeldFromOutside(string $source, bool $held): void
    {
        $calls = (new HeldLookupScan($source))->calls(self::METHOD);
        self::assertCount(1, $calls);
        self::assertSame($held, $calls[0][2]);
    }
}
