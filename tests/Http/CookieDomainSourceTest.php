<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Every cookie the served tree sets with a Domain takes it from
 * CookieDomain (directly, or through AUTH::cookie_domain(), which is only
 * that call).
 *
 * The click cookies passed $_SERVER['HTTP_HOST'] as the Domain, which carries
 * the port on a non-default one, and a Domain with a port never matches: the
 * browser dropped tracking202subid and every cookie beside it, so lp.php
 * never set click_out and no pixel found its click by cookie on an install
 * served from :8080 or :8443. go.php and ipx.php used SERVER_NAME, which is
 * the server's configured name rather than the host the browser asked for.
 * Each was its own derivation; this test makes a new one name its line.
 *
 * What it reads, by token: every call to setcookie() and setrawcookie() —
 * bare, fully qualified (`\setcookie`), any letter case, with `@` — and the
 * Domain each carries: the `domain` key of an options array written inline
 * (`[...]` or `array(...)`, the key in any case, as PHP reads it), the fifth
 * positional argument of the old signature, or a named `domain:` argument;
 * the same for session_set_cookie_params(), and ini_set() of
 * session.cookie_domain. What it cannot read it refuses, by line: options
 * passed as anything but an inline array (an old-signature expiry of digits
 * and time() is read as one), argument unpacking, a computed option key, a
 * spread inside the options, `header('Set-Cookie: …')`, a header() whose
 * line does not begin with a literal (unless COMPUTED_HEADERS accounts for
 * it), and `setcookie` named as a string (a callable the scan cannot follow).
 */
final class CookieDomainSourceTest extends TestCase
{
    /** The Domain values allowed, as their tokens read with whitespace removed. */
    private const ALLOWED = [
        '\Prosper202\Http\CookieDomain::fromServer($_SERVER)',
        'AUTH::cookie_domain()',
        '\AUTH::cookie_domain()',
    ];

    /** The global functions whose calls the scan reads. */
    private const CALLS_READ = [
        'setcookie', 'setrawcookie', 'header', 'ini_set', 'ini_alter', 'session_set_cookie_params',
    ];

    /** Inside AUTH itself. */
    private const ALLOWED_IN_FILE = [
        '202-config/functions-auth.php' => ['self::cookie_domain()'],
    ];

    /**
     * header() calls whose line is computed, so the scan cannot tell whether
     * they set a cookie: file => how many, and why none of them can.
     */
    private const COMPUTED_HEADERS = [
        // The Android install intake relays its result's 'headers' (Retry-After);
        // InstallIntake and InstallEventsIntake build them, and set no cookie.
        'api/v3/index.php' => 1,
    ];

    public function testEveryCookieDomainComesFromCookieDomain(): void
    {
        $problems = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            $scan = self::scan($source, self::ALLOWED_IN_FILE[$path] ?? []);
            foreach ($scan['problems'] as $problem) {
                $problems[] = $path . ':' . $problem;
            }
            $computed = count($scan['computedHeaders']);
            if ($computed !== (self::COMPUTED_HEADERS[$path] ?? 0)) {
                $problems[] = $path . ':' . implode(',', $scan['computedHeaders'])
                    . '  header() with a computed line (' . $computed . ' here, '
                    . (self::COMPUTED_HEADERS[$path] ?? 0) . ' accounted for): the scan cannot tell'
                    . ' whether it sets a cookie; begin the line with a literal, or account for it in'
                    . ' COMPUTED_HEADERS with the reason it sets none';
            }
        }

        self::assertSame([], $problems, "A cookie's Domain does not come from CookieDomain:\n  "
            . implode("\n  ", $problems)
            . "\nPass 'domain' => \\Prosper202\\Http\\CookieDomain::fromServer(\$_SERVER) in an inline"
            . ' options array: the request host without its port, none for an IP literal (a Domain'
            . ' with a port is dropped by every browser).');
    }

    public function testTheScanReadsTheTreesCookieDomains(): void
    {
        // Without this a scan that read nothing would pass the test above:
        // connect2.php's twelve click cookies, go.php's three, ipx.php's one,
        // and remember_me's three.
        $read = 0;
        foreach (SourceScan::phpFiles() as $path => $source) {
            $read += self::scan($source, self::ALLOWED_IN_FILE[$path] ?? [])['domains'];
        }
        self::assertGreaterThanOrEqual(19, $read);
    }

    public function testAuthCookieDomainIsOnlyCookieDomain(): void
    {
        $source = SourceScan::phpFiles()['202-config/functions-auth.php'] ?? '';
        self::assertSame(
            'return\Prosper202\Http\CookieDomain::fromServer($_SERVER);',
            self::methodBody($source, 'cookie_domain'),
            'AUTH::cookie_domain() is allowed as a Domain only because it is exactly this call'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'HTTP_HOST in an options array' => [
            '<?php setcookie("a", "b", ["path" => "/", "domain" => $_SERVER["HTTP_HOST"]]);',
        ];
        yield 'SERVER_NAME, cast' => ['<?php setcookie("a", "b", ["domain" => (string) $_SERVER["SERVER_NAME"]]);'];
        yield 'a variable' => ['<?php $domain = $_SERVER["HTTP_HOST"]; setcookie("a", "b", ["domain" => $domain]);'];
        yield 'the key in another case' => ['<?php setcookie("a", "b", ["Domain" => $h]);'];
        yield 'long array syntax' => ['<?php setcookie("a", "b", array("expires" => 0, "domain" => $h));'];
        yield 'the old positional signature' => ['<?php setcookie("a", "b", 0, "/", $_SERVER["HTTP_HOST"]);'];
        yield 'a named domain argument' => ['<?php setcookie("a", "b", 0, "/", domain: $h);'];
        yield 'options from a variable' => ['<?php $o = ["domain" => $h]; setcookie("a", "b", $o);'];
        yield 'named options from a variable' => ['<?php setcookie("a", "b", expires_or_options: $o);'];
        yield 'argument unpacking' => ['<?php setcookie(...$args);'];
        yield 'a spread inside the options' => ['<?php setcookie("a", "b", [...$base, "path" => "/"]);'];
        yield 'a computed option key' => ['<?php setcookie("a", "b", [$k => $h]);'];
        yield 'fully qualified, with @' => ['<?php @\setcookie("a", "b", ["domain" => $h]);'];
        yield 'mixed case function name' => ['<?php SetCookie("a", "b", ["domain" => $h]);'];
        yield 'setrawcookie' => ['<?php setrawcookie("a", "b", ["domain" => $h]);'];
        yield 'a raw Set-Cookie header' => ['<?php header("Set-Cookie: a=b; Domain=" . $h);'];
        yield 'a raw Set-Cookie header, interpolated' => ['<?php header("set-cookie: a=b; domain=$h");'];
        yield 'a header line built elsewhere' => ['<?php $line = "Set-Cookie: a=b; Domain=" . $h; header($line);'];
        yield 'a header line from a function' => ['<?php header(sprintf("Set-Cookie: a=b; Domain=%s", $h));'];
        yield 'setcookie as a callable' => ['<?php call_user_func("setcookie", "a", "b", ["domain" => $h]);'];
        yield 'session.cookie_domain' => ['<?php ini_set("session.cookie_domain", $_SERVER["HTTP_HOST"]);'];
        yield 'session_set_cookie_params array' => [
            '<?php session_set_cookie_params(["lifetime" => 0, "domain" => $h]);',
        ];
        yield 'session_set_cookie_params positional' => ['<?php session_set_cookie_params(0, "/", $h);'];
        yield 'self::cookie_domain() outside AUTH' => [
            '<?php setcookie("a", "b", ["domain" => self::cookie_domain()]);',
        ];
        yield 'an unqualified CookieDomain (relative in a namespace)' => [
            '<?php setcookie("a", "b", ["domain" => CookieDomain::fromServer($_SERVER)]);',
        ];
    }

    /** @dataProvider refused */
    public function testEveryRefusedShapeIsReported(string $source): void
    {
        $scan = self::scan($source, []);
        self::assertNotSame([], array_merge($scan['problems'], $scan['computedHeaders']), 'not reported: ' . $source);
    }

    /** @return iterable<string, array{string}> */
    public static function accepted(): iterable
    {
        yield 'the helper' => [
            '<?php setcookie("a", "b", ["path" => "/",'
                . ' "domain" => \Prosper202\Http\CookieDomain::fromServer($_SERVER)]);',
        ];
        yield 'AUTH::cookie_domain()' => [
            '<?php setcookie("remember_me", "", ["expires" => 1, "domain" => AUTH::cookie_domain()]);',
        ];
        yield 'no Domain at all' => [
            '<?php setcookie("p202vid", $v, ["expires" => 1, "path" => "/", "httponly" => true]);',
        ];
        yield 'two arguments' => ['<?php setcookie("a", "");'];
        yield 'a method of the same name' => [
            '<?php $response->setcookie("a", "b", ["domain" => $h]); Foo::setcookie("a", "b", $o);',
        ];
        yield 'a function declaration' => ['<?php namespace X; function setcookie($a, $b, $c) {}'];
        yield 'a header that is not Set-Cookie' => ['<?php header("Content-Type: text/plain");'];
        yield 'a header line that begins with a literal' => [
            '<?php header("Location: $url"); header(\'Location: \' . $u);',
        ];
        yield 'another ini setting' => ['<?php ini_set("session.cookie_samesite", "Lax");'];
    }

    /** @dataProvider accepted */
    public function testWhatIsReadableAndRightIsAccepted(string $source): void
    {
        $scan = self::scan($source, []);
        self::assertSame([], array_merge($scan['problems'], $scan['computedHeaders']));
    }

    /**
     * @param list<string> $allowedHere
     * @return array{problems: list<string>, domains: int, computedHeaders: list<int>}
     */
    private static function scan(string $source, array $allowedHere): array
    {
        $tokens = self::significantTokens($source);
        $allowed = array_merge(self::ALLOWED, $allowedHere);
        $problems = [];
        $domains = 0;
        $computedHeaders = [];
        $check = static function (array $value, int $line) use (&$problems, &$domains, $allowed): void {
            $domains++;
            $text = self::text($value);
            if (!in_array($text, $allowed, true)) {
                $problems[] = $line . '  Domain from ' . $text;
            }
        };

        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $literal = is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING
                ? strtolower(ltrim(substr($t[1], 1, -1), '\\'))
                : '';
            if (in_array($literal, ['setcookie', 'setrawcookie'], true)) {
                $problems[] = $t[2] . '  ' . $t[1] . ' named as a string: a callable this check cannot follow';
                continue;
            }
            $name = self::calledFunction($tokens, $i);
            if ($name === null) {
                continue;
            }
            $line = $t[2];
            $close = self::matching($tokens, $i + 1);
            $args = self::split(array_slice($tokens, $i + 2, $close - $i - 2));

            if ($name === 'header') {
                $head = $args[0][0] ?? null;
                if (!is_array($head) || !in_array($head[0], [T_CONSTANT_ENCAPSED_STRING, T_START_HEREDOC], true)) {
                    if ($head !== '"') {
                        $computedHeaders[] = $line;
                        continue;
                    }
                }
                $first = strtolower(ltrim(self::text($args[0] ?? []), "'\"\t "));
                if (str_starts_with($first, 'set-cookie')) {
                    $problems[] = $line . '  a raw Set-Cookie header: set cookies with setcookie()'
                        . ' so the Domain can be checked';
                }
                continue;
            }
            if ($name === 'ini_set' || $name === 'ini_alter') {
                if (strtolower(trim(self::text($args[0] ?? []), "'\"")) === 'session.cookie_domain') {
                    $check($args[1] ?? [], $line);
                }
                continue;
            }
            // setcookie/setrawcookie: the options are the third argument and
            // the old signature's domain the fifth; session_set_cookie_params
            // takes the options first and the old domain third.
            [$optionsAt, $domainAt] = $name === 'session_set_cookie_params' ? [0, 2] : [2, 4];
            foreach ($args as $k => $arg) {
                if (($arg[0] ?? null) !== null && is_array($arg[0]) && $arg[0][0] === T_ELLIPSIS) {
                    $problems[] = $line . '  argument unpacking: the options cannot be read';
                    continue;
                }
                $named = self::namedArgument($arg);
                if ($named !== null) {
                    [$argName, $value] = $named;
                    if ($argName === 'domain') {
                        $check($value, $line);
                    } elseif (in_array($argName, ['expires_or_options', 'lifetime_or_options'], true)) {
                        self::readOptions($value, $line, $check, $problems);
                    }
                    continue;
                }
                if ($k === $optionsAt) {
                    // An inline array is read. Anything else is the old
                    // signature's expiry only when it plainly is one or when
                    // the call goes on to the domain position; otherwise it
                    // may be an options array held elsewhere, and is refused.
                    if (self::isArrayLiteral($arg) || (count($args) <= $domainAt && !self::isPlainExpiry($arg))) {
                        self::readOptions($arg, $line, $check, $problems);
                    }
                } elseif ($k === $domainAt && !self::isArrayLiteral($args[$optionsAt] ?? [])) {
                    $check($arg, $line);
                }
            }
        }

        return ['problems' => $problems, 'domains' => $domains, 'computedHeaders' => $computedHeaders];
    }

    /**
     * The `domain` entry of an inline options array, or a refusal when the
     * options are not an inline array this can read.
     *
     * @param list<string|array{0:int,1:string,2:int}> $arg
     * @param list<string> $problems
     */
    private static function readOptions(array $arg, int $line, callable $check, array &$problems): void
    {
        if (!self::isArrayLiteral($arg)) {
            $problems[] = $line . '  options passed as ' . self::text($arg)
                . ': write the options array inline so its Domain can be read';
            return;
        }
        $inner = $arg[0] === '[' ? array_slice($arg, 1, -1) : array_slice($arg, 2, -1);
        foreach (self::split($inner) as $element) {
            if ($element === []) {
                continue;
            }
            if (is_array($element[0]) && $element[0][0] === T_ELLIPSIS) {
                $problems[] = $line . '  a spread inside the options: they cannot be read';
                continue;
            }
            $arrow = null;
            $depth = 0;
            foreach ($element as $j => $tok) {
                $depth += in_array($tok, ['(', '[', '{'], true) ? 1 : (in_array($tok, [')', ']', '}'], true) ? -1 : 0);
                if ($depth === 0 && is_array($tok) && $tok[0] === T_DOUBLE_ARROW) {
                    $arrow = $j;
                    break;
                }
            }
            if ($arrow === null) {
                continue;
            }
            $key = array_slice($element, 0, $arrow);
            if (count($key) !== 1 || !is_array($key[0]) || $key[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
                $problems[] = $line . '  a computed option key ' . self::text($key) . ': it cannot be read';
                continue;
            }
            // PHP reads the option names case-insensitively.
            if (strtolower(substr($key[0][1], 1, -1)) === 'domain') {
                $check(array_slice($element, $arrow + 1), $line);
            }
        }
    }

    /** An expiry of the old signature: digits, time(), arithmetic. */
    private static function isPlainExpiry(array $arg): bool
    {
        if ($arg === []) {
            return false;
        }
        foreach ($arg as $tok) {
            if (is_string($tok)) {
                if (!in_array($tok, ['(', ')', '+', '-', '*'], true)) {
                    return false;
                }
            } elseif (!($tok[0] === T_LNUMBER || ($tok[0] === T_STRING && strtolower($tok[1]) === 'time'))) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string|array{0:int,1:string,2:int}> $arg */
    private static function isArrayLiteral(array $arg): bool
    {
        if ($arg === []) {
            return false;
        }
        if ($arg[0] === '[') {
            return self::matchingIn($arg, 0) === count($arg) - 1;
        }

        return is_array($arg[0]) && $arg[0][0] === T_ARRAY && ($arg[1] ?? null) === '('
            && self::matchingIn($arg, 1) === count($arg) - 1;
    }

    /**
     * @param list<string|array{0:int,1:string,2:int}> $arg
     * @return array{string, list<string|array{0:int,1:string,2:int}>}|null
     */
    private static function namedArgument(array $arg): ?array
    {
        if (count($arg) >= 2 && is_array($arg[0]) && $arg[0][0] === T_STRING && $arg[1] === ':') {
            return [strtolower($arg[0][1]), array_slice($arg, 2)];
        }

        return null;
    }

    /** The lower-cased name of a global function called at $i, or null. */
    private static function calledFunction(array $tokens, int $i): ?string
    {
        $t = $tokens[$i];
        $isName = is_array($t) && in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true);
        if (!$isName || ($tokens[$i + 1] ?? null) !== '(') {
            return null;
        }
        $name = strtolower(ltrim($t[1], '\\'));
        if (!in_array($name, self::CALLS_READ, true)) {
            return null;
        }
        $prev = $tokens[$i - 1] ?? null;
        $notACall = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST];
        if (is_array($prev) && in_array($prev[0], $notACall, true)) {
            return null;
        }

        return $name;
    }

    /**
     * Split a token list at its top-level commas.
     *
     * @param list<string|array{0:int,1:string,2:int}> $tokens
     * @return list<list<string|array{0:int,1:string,2:int}>>
     */
    private static function split(array $tokens): array
    {
        $parts = [[]];
        $depth = 0;
        foreach ($tokens as $tok) {
            if (self::opens($tok)) {
                $depth++;
            } elseif (in_array($tok, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($tok === ',' && $depth === 0) {
                $parts[] = [];
                continue;
            }
            $parts[count($parts) - 1][] = $tok;
        }

        return $parts === [[]] ? [] : $parts;
    }

    private static function matching(array $tokens, int $open): int
    {
        $found = self::matchingIn($tokens, $open);
        if ($found === null) {
            throw new \RuntimeException('unbalanced brackets at token ' . $open);
        }

        return $found;
    }

    private static function matchingIn(array $tokens, int $open): ?int
    {
        $depth = 0;
        $n = count($tokens);
        for ($i = $open; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (self::opens($tok)) {
                $depth++;
            } elseif (in_array($tok, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private static function methodBody(string $source, string $method): string
    {
        $tokens = self::significantTokens($source);
        foreach ($tokens as $i => $t) {
            $next = $tokens[$i + 1] ?? null;
            if (is_array($t) && $t[0] === T_FUNCTION && is_array($next) && $next[1] === $method) {
                $j = $i;
                while (($tokens[$j] ?? '{') !== '{') {
                    $j++;
                }
                $close = self::matching($tokens, $j);

                return self::text(array_slice($tokens, $j + 1, $close - $j - 1));
            }
        }

        return '';
    }

    /** An opening bracket, including `{$` and `${` inside a string. */
    private static function opens(string|array $tok): bool
    {
        return in_array($tok, ['(', '[', '{'], true)
            || (is_array($tok) && in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
    }

    /** @param list<string|array{0:int,1:string,2:int}> $tokens */
    private static function text(array $tokens): string
    {
        return implode('', array_map(static fn ($t): string => is_array($t) ? $t[1] : $t, $tokens));
    }

    /** @return list<string|array{0:int,1:string,2:int}> */
    private static function significantTokens(string $source): array
    {
        $out = [];
        foreach (token_get_all($source) as $t) {
            $insignificant = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_CLOSE_TAG];
            if (is_array($t) && in_array($t[0], $insignificant, true)) {
                continue;
            }
            $out[] = $t;
        }

        return $out;
    }
}
