<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRequest;

/**
 * The click's request values, read one way for the four click scripts. Each
 * had its own copy of the keyword chain: a list was stored as "Array", '0'
 * was no keyword on the landing pages, an empty OVKEY won over t202kw on
 * dl.php, and only record_simple.php made the keyword valid UTF-8.
 */
final class ClickRequestTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function texts(): iterable
    {
        yield 'a value' => ['hello', 'hello'];
        yield 'empty' => ['', ''];
        yield 'zero' => ['0', '0'];
        yield 'absent' => [null, ''];
        yield 'a list' => [['a', 'b'], ''];
        yield 'valid UTF-8' => ['café 😀', 'café 😀'];
        yield 'a byte that is no UTF-8' => ["caf\xE9", 'caf?'];
        yield 'a surrogate' => ["\xED\xA0\x80x", '???x'];
    }

    /** @dataProvider texts */
    public function testTextIsAStringTheClickCanStore(mixed $sent, string $expected): void
    {
        self::assertSame($expected, ClickRequest::text($sent === null ? [] : ['c1' => $sent], 'c1'));
    }

    /**
     * A URL is read as sent: text()'s ? for a byte that is no UTF-8 stood
     * where the query began, and the referring search's keyword was lost.
     */
    public function testAUrlIsReadAsSentSoItsQueryStaysItsQuery(): void
    {
        $referer = "http://ex.com/caf\xE9/page?q=shoes";
        $get = ['referer' => $referer, 'lpip' => ['1']];

        self::assertSame($referer, ClickRequest::string($get, 'referer'));
        self::assertSame('', ClickRequest::string($get, 'lpip'), 'a list is no value');
        self::assertSame('', ClickRequest::string($get, 'absent'));
        parse_str((string) parse_url(ClickRequest::string($get, 'referer'), PHP_URL_QUERY), $query);
        self::assertSame('shoes', ClickRequest::keyword([], $query, 'searched'));
        parse_str((string) parse_url(ClickRequest::text($get, 'referer'), PHP_URL_QUERY), $scrubbed);
        self::assertSame('', ClickRequest::keyword([], $scrubbed, 'searched'), 'what text() would have lost');
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, ?string, string}> */
    public static function keywords(): iterable
    {
        yield 'bidded: OVKEY first' => [['OVKEY' => 'y', 't202kw' => 'kw'], [], 'bidded', 'y'];
        yield 'bidded: an empty OVKEY is none' => [['OVKEY' => '', 't202kw' => 'kw'], [], 'bidded', 'kw'];
        yield 'bidded: a list is none' => [['OVKEY' => ['y'], 't202kw' => 'kw'], [], 'bidded', 'kw'];
        yield 'bidded: zero is a keyword' => [['t202kw' => '0', 'keyword' => 'z'], [], 'bidded', '0'];
        yield 'bidded: then target_passthrough' => [
            ['target_passthrough' => 'tp', 'keyword' => 'z'], [], 'bidded', 'tp',
        ];
        yield 'bidded: the referer is not read' => [['keyword' => 'z'], ['q' => 'searched'], 'bidded', 'z'];
        yield 'searched: the referer\'s q first' => [
            ['OVRAW' => 'y', 't202kw' => 'kw'], ['q' => 'shoes'], 'searched', 'shoes',
        ];
        yield 'searched: a referer\'s q[] is none' => [['OVRAW' => 'y'], ['q' => ['shoes']], 'searched', 'y'];
        yield 'searched: the last source' => [['words' => 'w', 't202kw' => 'kw'], [], 'searched', 'w'];
        yield 'searched: then t202kw' => [['t202kw' => 'kw'], [], 'searched', 'kw'];
        yield 'searched: OVKEY is not a source' => [['OVKEY' => 'y'], [], 'searched', ''];
        yield 'no preference reads as the default, searched' => [['t202kw' => 'kw'], ['q' => 'shoes'], null, 'shoes'];
        yield 'none' => [[], [], 'bidded', ''];
        yield '%20 is a space' => [['t202kw' => 'red%20shoes'], [], 'bidded', 'red shoes'];
        yield 'a byte that is no UTF-8' => [['t202kw' => "caf\xE9"], [], 'bidded', 'caf?'];
        yield 't202var_ names a parameter' => [['t202kw' => 't202var_kw2', 'kw2' => 'blue'], [], 'bidded', 'blue'];
        yield 't202var_ naming nothing is no keyword' => [['t202kw' => 't202var_kw2'], [], 'bidded', ''];
        yield 't202var_ naming a list is no keyword' => [
            ['t202kw' => 't202var_kw2', 'kw2' => ['blue']], [], 'bidded', '',
        ];
        yield 't202var_ from the referer' => [['kw2' => 'blue'], ['q' => 't202var_kw2'], 'searched', 'blue'];
        yield 'the parameter\'s %20 is a space' => [['t202kw' => 't202var_kw2', 'kw2' => 'a%20b'], [], 'bidded', 'a b'];
    }

    /** Where each preference reads, in order: the specification, so the reader's lists are held to it. */
    private const BIDDED = ['OVKEY', 't202kw', 'target_passthrough', 'keyword'];
    private const SEARCHED = [
        'OVRAW', 'target_passthrough', 'keyword', 'search_word', 'query', 'encquery', 'terms', 'rdata',
        'qs', 'wd', 'text', 'szukaj', 'qt', 'k', 'words', 't202kw',
    ];

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, string, string}> */
    public static function adjacentSources(): iterable
    {
        foreach (['bidded' => self::BIDDED, 'searched' => self::SEARCHED] as $preference => $sources) {
            foreach ($sources as $i => $source) {
                // Each source wins over the one after it, and is read when it is alone.
                $next = $sources[$i + 1] ?? null;
                $get = [$source => "from $source"] + ($next === null ? [] : [$next => "from $next"]);
                yield "$preference: $source" . ($next === null ? ' alone' : " before $next") => [
                    $get, [], $preference, "from $source",
                ];
            }
        }
        yield 'searched: the referer\'s q before OVRAW' => [['OVRAW' => 'y'], ['q' => 'from q'], 'searched', 'from q'];
    }

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $refererQuery
     * @dataProvider keywords
     * @dataProvider adjacentSources
     */
    public function testTheKeywordIsTheFirstSourceThatNamesOne(
        array $get,
        array $refererQuery,
        ?string $preference,
        string $expected
    ): void {
        self::assertSame($expected, ClickRequest::keyword($get, $refererQuery, $preference));
    }
}
