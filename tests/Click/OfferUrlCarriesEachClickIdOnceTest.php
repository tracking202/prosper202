<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Tests\Support\SourceScan;

/**
 * A redirect's destination carries each ad click id once.
 *
 * getPrePopVars() passes the gbraid, wbraid and ttclid of a link on, and an
 * offer URL with `gbraid=[[gbraid]]` filled carried it too: the offer got
 * the parameter twice. Run here from connect2.php's own getPrePopVars() and
 * setPrePopVars(), in a child PHP, as dl.php, rtr.php, lp.php, off.php and
 * lpc.php call them: the request's parameters, then the URL with its tokens
 * filled. A cloaked link packs the pass-on into 202vars, which cl.php adds
 * to the page; its share is the live pass's.
 */
final class OfferUrlCarriesEachClickIdOnceTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function clickIds(): iterable
    {
        foreach (array_keys(ClickRecord::AD_CLICK_IDS) as $name) {
            yield $name => [$name];
        }
    }

    /** @dataProvider clickIds */
    public function testAClickIdTheOfferUrlNamesWithItsTokenGoesOnce(string $name): void
    {
        $url = self::redirect(
            ['t202id' => '42', $name => 'ID-' . $name, 'promo' => 'spring'],
            'https://offer.example/go?aff=7&' . $name . '=ID-' . $name
        );

        self::assertSame(1, self::occurrences($url, $name), $url);
        self::assertSame('ID-' . $name, self::query($url)[$name], $url);
        self::assertSame(1, self::occurrences($url, 'promo'), $url);
    }

    /**
     * Without the token, nothing changes: the gbraid, wbraid and ttclid go on
     * (a landing page's Google tag reads them from its URL), the others not.
     *
     * @dataProvider clickIds
     */
    public function testAClickIdTheOfferUrlDoesNotNameIsPassedOnAsBefore(string $name): void
    {
        $url = self::redirect(['t202id' => '42', $name => 'ID-' . $name], 'https://offer.example/go?aff=7');
        $passedOn = in_array($name, ['gbraid', 'wbraid', 'ttclid'], true);

        self::assertSame($passedOn ? 1 : 0, self::occurrences($url, $name), $url);
    }

    public function testEveryIdAtOnceGoesOnceEach(): void
    {
        $request = ['t202id' => '42', 'promo' => 'spring'];
        $offer = 'https://offer.example/go?aff=7';
        foreach (array_keys(ClickRecord::AD_CLICK_IDS) as $name) {
            $request[$name] = 'ID-' . $name;
            $offer .= '&' . $name . '=ID-' . $name;
        }
        $url = self::redirect($request, $offer . '#top');

        foreach (array_keys(ClickRecord::AD_CLICK_IDS) as $name) {
            self::assertSame(1, self::occurrences($url, $name), $name . ' in ' . $url);
        }
    }

    /** off.php's outage fallback sends the cached URL with its tokens unfilled: the link's id still goes. */
    public function testAnUnfilledTokenDoesNotHoldBackTheLinksId(): void
    {
        $url = self::redirect(['t202id' => '42', 'gbraid' => 'G1'], 'https://offer.example/go?gbraid=[[gbraid]]');

        self::assertSame('https://offer.example/go?gbraid=[[gbraid]]&gbraid=G1', $url);
    }

    /** With no click every token is filled empty (lp.php, off.php): the link's id is not lost. */
    public function testAnIdTheNoClickUrlLeavesEmptyKeepsTheLinksId(): void
    {
        foreach (['gbraid=', 'gbraid'] as $empty) {
            $url = self::redirect(['t202id' => '42', 'gbraid' => 'REAL'], 'https://offer.example/go?s=p&' . $empty);

            self::assertSame('https://offer.example/go?s=p&' . $empty . '&gbraid=REAL', $url);
        }
    }

    /** A cloaked link's 202vars carry the pass-on whole: cl.php decides against the page. */
    public function testACloakedLinkPacksThePassOnWhole(): void
    {
        $url = self::redirect(
            ['t202id' => '42', 'gbraid' => 'G1', 'promo' => 'spring'],
            'https://track.example/tracking202/redirect/cl.php?pci=123',
            true
        );
        self::assertSame(1, preg_match('/[?&]202vars=([^&]*)/', $url, $m), $url);
        $packed = base64_decode($m[1]);

        self::assertSame('gbraid=G1&promo=spring', $packed);
    }

    /**
     * @param array<string, string> $request
     */
    private static function redirect(array $request, string $filledUrl, bool $b64 = false): string
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/202-config/connect2.php');
        self::assertSame(1, preg_match('/^function getPrePopVars\(.*?^\}\n/ms', $source, $get));
        self::assertSame(1, preg_match('/^function setPrePopVars\(.*?^\}\n/ms', $source, $set));
        $code = 'require ' . var_export(SourceScan::repoRoot() . '/vendor/autoload.php', true) . ';'
            . $get[0] . $set[0]
            . ' echo setPrePopVars(getPrePopVars(' . var_export($request, true) . '), '
            . var_export($filledUrl, true) . ', ' . var_export($b64, true) . ');';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $out . $err);
        self::assertSame('', $err);

        return $out;
    }

    private static function occurrences(string $url, string $name): int
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);

        return count(array_filter(
            explode('&', $query),
            static fn (string $piece): bool => urldecode(explode('=', $piece, 2)[0]) === $name
        ));
    }

    /** @return array<string, mixed> */
    private static function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
