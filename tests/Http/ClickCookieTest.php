<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\ClickCookie;
use Tests\Support\SourceScan;

/**
 * A click cookie is read as itself or, failing that, its -legacy twin — the
 * only one a browser keeps for a tracker on plain HTTP — and every reader in
 * the served tree reads it that way.
 */
final class ClickCookieTest extends TestCase
{
    /** The cookies the click path sets twice, by name or name prefix. */
    private const NAMES = ['tracking202subid', 'tracking202pci', 'tracking202outbound', 'tracking202rlp_'];

    /**
     * Reads by a name the scan cannot see, each read and found not to be a
     * click cookie.
     *
     * @var array<string, list<string>>
     */
    private const COMPUTED_ELSEWHERE = [
        // $cookies[self::VISITOR_COOKIE]: p202vid, the visitor identity cookie.
        '202-config/Identity/RequestSignals.php' => ['67: ?'],
    ];

    /** @return iterable<string, array{array<string, mixed>, mixed}> */
    public static function jars(): iterable
    {
        yield 'the cookie' => [['tracking202subid' => '9'], '9'];
        yield 'its twin when it is missing' => [['tracking202subid-legacy' => '7'], '7'];
        yield 'the cookie before its twin' => [['tracking202subid' => '9', 'tracking202subid-legacy' => '7'], '9'];
        yield 'its twin when it is empty' => [['tracking202subid' => '', 'tracking202subid-legacy' => '7'], '7'];
        yield 'neither' => [['tracking202subid_a_2' => '5'], null];
        yield 'both empty' => [['tracking202subid' => '', 'tracking202subid-legacy' => ''], null];
        yield 'a value that is not a string, for the caller to refuse' => [['tracking202subid' => ['1']], ['1']];
        yield 'another name is not a twin' => [
            ['tracking202subid_legacy' => '7', 'tracking202subid-legacy2' => '6'],
            null,
        ];
    }

    /**
     * @dataProvider jars
     * @param array<string, mixed> $cookies
     */
    public function testValue(array $cookies, mixed $expected): void
    {
        self::assertSame($expected, ClickCookie::value($cookies, 'tracking202subid'));
    }

    /**
     * Every index into an array by a click cookie's name, in the served tree:
     * `$_COOKIE['tracking202subid']`, `$cookies['tracking202subid_a_' . $c]`,
     * inside isset() or not. Array literals and strings (the landing-page
     * snippets, which read the landing page's own cookies) are not indexes.
     * A name the scan cannot read — `$_COOKIE` or a `$cookies` jar indexed by
     * anything but a quoted string — is reported too, as `?`: it may be a
     * click cookie. A cookie array handed to a function whole is not
     * followed.
     *
     * @return list<string> "line: name" for each
     */
    private static function reads(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $reads = [];
        foreach ($tokens as $i => $token) {
            if ($token !== '[' || !isset($tokens[$i + 1]) || !is_array($tokens[$i + 1])) {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            $isIndex = (is_array($before) && $before[0] === T_VARIABLE) || $before === ']';
            if (!$isIndex) {
                continue;
            }
            if ($tokens[$i + 1][0] !== T_CONSTANT_ENCAPSED_STRING) {
                if (is_array($before) && in_array($before[1], ['$_COOKIE', '$cookies'], true)) {
                    $reads[] = $tokens[$i + 1][2] . ': ?';
                }
                continue;
            }
            $key = substr($tokens[$i + 1][1], 1, -1);
            foreach (self::NAMES as $name) {
                if (str_starts_with($key, $name)) {
                    $reads[] = $tokens[$i + 1][2] . ': ' . $key;
                    break;
                }
            }
        }

        return $reads;
    }

    public function testEveryClickCookieIsReadWithItsTwin(): void
    {
        $direct = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if ($path === '202-config/Http/ClickCookie.php') {
                continue;
            }
            foreach (self::reads($source) as $read) {
                if (!in_array($read, self::COMPUTED_ELSEWHERE[$path] ?? [], true)) {
                    $direct[] = "$path:$read";
                }
            }
        }
        self::assertSame([], $direct, 'read a click cookie with getCookie202() or ClickCookie::value(): '
            . 'on plain HTTP only its -legacy twin exists');

        // The readers, through the helper: without a floor a tree whose
        // readers all vanished would pass.
        $through = 0;
        foreach (SourceScan::phpFiles() as $path => $source) {
            $through += preg_match_all("/(?:getCookie202|ClickCookie::value)\\([^)]*'tracking202/", $source);
        }
        self::assertGreaterThanOrEqual(12, $through);
    }

    /** @return iterable<string, array{string, int}> */
    public static function shapes(): iterable
    {
        yield 'a superglobal' => ['<?php $x = $_COOKIE[\'tracking202subid\'];', 1];
        yield 'inside isset' => ['<?php if (isset($_COOKIE["tracking202pci"])) {}', 1];
        yield 'a campaign cookie by concatenation' => ['<?php $x = $_COOKIE[\'tracking202subid_a_\' . $c] ?? \'\';', 1];
        yield 'any cookie array' => ['<?php $x = $cookies[\'tracking202subid\'] ?? null;', 1];
        yield 'with a comment in the way' => ['<?php $x = $_COOKIE /* c */ [ \'tracking202outbound\' ];', 1];
        yield 'an array literal is not a read' => ['<?php $p[] = [\'tracking202subid\', $v];', 0];
        yield 'a setter is not a read' => ['<?php setcookie(\'tracking202subid\', $v);', 0];
        yield 'a snippet in a string is not a read' => ['<?php $s = \'$_COOKIE[\\\'tracking202outbound\\\']\';', 0];
        yield 'another cookie is not a click cookie' => ['<?php $x = $_COOKIE[\'p202vid\'];', 0];
        yield 'a computed name is reported' => ['<?php $x = $_COOKIE[$name] ?? null;', 1];
        yield 'a computed name in a jar is reported' => ['<?php $x = $cookies[\'tracking\' . $n];', 0];
        yield 'a computed name in a jar by variable' => ['<?php $x = $cookies[$campaignCookie] ?? null;', 1];
        yield 'another array by a variable is not' => ['<?php $x = $rows[$i];', 0];
    }

    /** @dataProvider shapes */
    public function testTheShapesAReadTakes(string $source, int $expected): void
    {
        self::assertCount($expected, self::reads($source));
    }
}
