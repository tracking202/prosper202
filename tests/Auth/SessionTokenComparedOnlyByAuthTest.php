<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * The session's anti-CSRF token is compared in two places in the served tree
 * and nowhere else: AUTH::csrf_token_matches() (which AUTH::check_csrf_token()
 * is, on the posted token), and p202_standalone_wizard_token_ok() for the
 * setup wizard, which runs before connect.php can be loaded.
 *
 * Why: two dozen pages compared it inline, as
 * `hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))`.
 * hash_equals('', '') is true, so a session holding an empty token —
 * connect.php reseeds a token that is not set, not one that is set to '' —
 * let a request that carried no token through. Measured live before the fix:
 * with the stored token blanked, a Setup add, a Setup delete link,
 * set_user_prefs, charts and the redirector's rule save all wrote on a
 * token-less request, while the pages that called AUTH::check_csrf_token()
 * refused it (error pattern #5: the inconsistent sibling). Both allowed
 * comparisons fail closed and are executed on token pairs in AuthClassTest
 * and PreLoginPostRequiresTokenTest.
 *
 * What is refused, outside those two functions:
 *
 *  1. A call of hash_equals(), strcmp(), strcasecmp(), strncmp(),
 *     strncasecmp(), substr_compare() or strcoll() — by any spelling of the
 *     name: bare, `\name`, `namespace\name`, `Other\name`, or a method of
 *     that name — whose argument list reads the session token:
 *       a. directly: `$_SESSION['…token…']` (any key containing "token",
 *          either quote style), `$_SESSION` indexed by anything but a string
 *          literal, or `$_SESSION` whole;
 *       b. through what reaches the session without naming the key:
 *          `$GLOBALS`, a variable variable (`$$x`, `${…}`), `"${…}"`;
 *       c. through a variable or property that some statement in the same
 *          scope (the enclosing function's body — closures and a function
 *          that declares `global` share their parent's — or the file's top
 *          level) both names and reads (a)-(d) in; to a fixpoint, and in
 *          any order, so over-inclusive on purpose. A scope that calls
 *          eval(), whose code this cannot read, or that reads the token and
 *          calls extract() or uses a variable variable, writes variables it
 *          never names, so every variable in it counts;
 *       d. through a call of a function or method declared anywhere in the
 *          served tree that returns the token as read in (a), by name
 *          (p202_standalone_wizard_token() is one), or a string naming one,
 *          or a closure or arrow function whose body reads (a)-(d).
 *  2. A comparison operator (==, ===, !=, !==, <>, <=>) one of whose
 *     operands — everything that binds tighter than the comparison,
 *     brackets taken whole — reads the session token as in (a) or (d)
 *     (`$GLOBALS['_SESSION']['…token…']` included), or is a variable
 *     assigned from one by a value-preserving expression (casts, `??`,
 *     trim()): `($_SESSION['token'] ?? '') === ($_POST['token'] ?? '')` is
 *     the same hole by another operator. Outside the two comparisons and
 *     the seed's own emptiness check in p202_standalone_wizard_token().
 *  3. hash_equals named in a string (call_user_func('hash_equals', …), a
 *     callable string) or taken as a first-class callable (hash_equals(...)):
 *     its arguments are not at the call site, so nothing here can read them.
 *
 * What it does not see, stated so nobody reads more into a green run:
 *   - the token handed as an argument to a helper that compares its
 *     parameters — install_csrf_ok() is that shape, fails closed, and is
 *     held to it and executed in PreLoginPostRequiresTokenTest;
 *   - a value carried across files (an include that assigns it), through
 *     a method resolved by __call(), returned by a function that gets it
 *     from another function or from a property (only a body that reads the
 *     session itself is a source; following callers by name reaches every
 *     page that renders the token), or by a method whose name another
 *     source shares (sources are matched by name, so that one is refused
 *     too — the over-inclusive direction);
 *   - an operator comparison through a variable that took the token by
 *     anything but a value-preserving assignment (the wide taint of 1c would
 *     follow every flag computed from a token check, and refuse correct
 *     code), or of a session value by a key this cannot read (form b): the
 *     session helpers compare `$_SESSION[$key]` for their own reasons.
 */
final class SessionTokenComparedOnlyByAuthTest extends TestCase
{
    /** The comparisons that may read the session token: [file, class or null, function]. */
    private const ALLOWED = [
        ['202-config/functions-auth.php', 'AUTH', 'csrf_token_matches'],
        ['202-config/functions-standalone-ui.php', null, 'p202_standalone_wizard_token_ok'],
    ];

    /** Where the seed tests its own token for emptiness with an operator: [file, class or null, function]. */
    private const SEEDS = [
        ['202-config/functions-standalone-ui.php', null, 'p202_standalone_wizard_token'],
    ];

    private const COMPARING_CALLS = ['hash_equals', 'strcmp', 'strcasecmp', 'strncmp', 'strncasecmp', 'substr_compare', 'strcoll'];

    private const SUPERGLOBALS = ['$_SESSION', '$_POST', '$_GET', '$_REQUEST', '$_COOKIE', '$_SERVER', '$_FILES', '$_ENV', '$GLOBALS', '$this'];

    /** Calls that write variables they do not name. */
    private const UNNAMED_WRITERS = ['extract', 'parse_str', 'mb_parse_str'];

    /** Function names a value-preserving assignment may pass the token through. */
    private const VALUE_PRESERVING = ['trim', 'strval', 'rtrim', 'ltrim'];

    public function testTheSessionTokenIsComparedOnlyByAuthAndTheStandaloneHelper(): void
    {
        $files = SourceScan::phpFiles();
        self::assertGreaterThan(500, count($files), 'the walk read the served tree');
        $sources = self::tokenSources(self::treeBodies());

        $refused = [];
        $allowedSeen = [];
        foreach ($files as $path => $source) {
            foreach (self::findings($path, $source, $sources) as $finding) {
                if ($finding['allowed'] !== null) {
                    $allowedSeen[$finding['allowed']][$finding['kind']] = ($allowedSeen[$finding['allowed']][$finding['kind']] ?? 0) + 1;
                    continue;
                }
                $refused[] = "$path:{$finding['line']}: {$finding['what']}";
            }
        }

        self::assertSame([], $refused, "The session token is compared outside AUTH::csrf_token_matches() and the"
            . " standalone helper:\n  " . implode("\n  ", $refused)
            . "\nCall AUTH::check_csrf_token() for a posted `token`, or AUTH::csrf_token_matches(\$value) for one"
            . " that arrives otherwise; both fail closed on an empty session token, which an inline hash_equals() does not.");

        // Each allowance is exercised: the scan sees the comparison it permits
        // (a scan that saw nothing would pass vacuously) — exactly one
        // comparing call in each comparison, and the seed's operator check.
        foreach (self::ALLOWED as [$file, $class, $function]) {
            $key = self::siteKey($file, $class, $function);
            self::assertSame(1, $allowedSeen[$key]['call'] ?? 0, "$key holds exactly one comparing call that reads the session token");
        }
        foreach (self::SEEDS as [$file, $class, $function]) {
            $key = self::siteKey($file, $class, $function);
            self::assertGreaterThan(0, $allowedSeen[$key]['operator'] ?? 0, "$key still tests the token with an operator; remove it from SEEDS if not");
        }
    }

    /**
     * Every spelling the docblock claims, planted into a page that is
     * otherwise clean, is refused — by the check that claims it (a call, an
     * operator, or a call this cannot read), not by something incidental.
     *
     * @dataProvider plants
     */
    public function testEveryClaimedSpellingIsRefused(string $plant, string $kind): void
    {
        $source = "<?php\ndeclare(strict_types=1);\n$plant\n";
        // The page's own declarations are part of the tree it would join.
        $sources = self::tokenSources([...self::treeBodies(), ...self::functionBodies($source)]);
        $refused = array_values(array_filter(
            self::findings('tracking202/ajax/plant.php', $source, $sources),
            static fn (array $f): bool => $f['allowed'] === null
        ));
        self::assertContains($kind, array_column($refused, 'kind'), "not refused as a $kind:\n$plant\nfindings: " . json_encode($refused));
    }

    /** @return array<string, array{0: string, 1: string}> the spelling => [code, the kind of finding that refuses it] */
    public static function plants(): array
    {
        return [
            'the inline guard that shipped' => ["if (!hash_equals((string) (\$_SESSION['token'] ?? ''), (string) (\$_POST['token'] ?? ''))) { die(); }", 'call'],
            'its GET twin' => ["if (!hash_equals((string) (\$_SESSION['token'] ?? ''), (string) (\$_GET['token'] ?? ''))) { die(); }", 'call'],
            'no casts' => ["if (!hash_equals(\$_SESSION['token'], \$_POST['token'])) { die(); }", 'call'],
            'the arguments reversed' => ["if (!hash_equals(\$_POST['token'] ?? '', \$_SESSION['token'] ?? '')) { die(); }", 'call'],
            'double quotes' => ["if (!hash_equals(\$_SESSION[\"token\"], \$_POST[\"token\"])) { die(); }", 'call'],
            'another token key' => ["if (!hash_equals(\$_SESSION['csrf_token'], \$_POST['csrf_token'])) { die(); }", 'call'],
            'a key in capitals' => ["if (!hash_equals(\$_SESSION['CSRF_TOKEN'], \$_POST['t'])) { die(); }", 'call'],
            'a key it cannot read' => ["\$k = 'token'; if (!hash_equals(\$_SESSION[\$k], \$_POST['token'])) { die(); }", 'call'],
            'a constant key' => ["if (!hash_equals(\$_SESSION[TOKEN_KEY], \$_POST['token'])) { die(); }", 'call'],
            'the whole session' => ["if (!hash_equals(implode(\$_SESSION), \$_POST['token'])) { die(); }", 'call'],
            'fully qualified' => ["if (!\\hash_equals(\$_SESSION['token'], \$_POST['token'])) { die(); }", 'call'],
            'namespace-relative' => ["if (!namespace\\hash_equals(\$_SESSION['token'], \$_POST['token'])) { die(); }", 'call'],
            'a method of that name' => ["if (!\$cmp->hash_equals(\$_SESSION['token'], \$_POST['token'])) { die(); }", 'call'],
            'named arguments' => ["if (!hash_equals(user_string: \$_POST['token'], known_string: \$_SESSION['token'])) { die(); }", 'call'],
            'spread' => ["if (!hash_equals(...[\$_SESSION['token'], \$_POST['token']])) { die(); }", 'call'],
            'multi-line, commented' => ["if (!hash_equals(\n  // the session's\n  (string) (\$_SESSION['token'] ?? ''), /* posted */ (string) (\$_POST['token'] ?? '')\n)) { die(); }", 'call'],
            'inside a longer condition' => ["if ((\$_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals((string) (\$_SESSION['token'] ?? ''), (string) (\$_POST['token'] ?? ''))) { die(); }", 'call'],
            'through a variable' => ["\$expected = (string) (\$_SESSION['token'] ?? '');\nif (!hash_equals(\$expected, (string) (\$_POST['token'] ?? ''))) { die(); }", 'call'],
            'through a variable assigned below the call' => ["while (\$retry) { if (!hash_equals(\$expected ?? '', \$_POST['token'])) { die(); } \$expected = \$_SESSION['token']; }", 'call'],
            'through two variables' => ["\$a = \$_SESSION['token'];\n\$b = \$a;\nif (!hash_equals(\$b, \$_POST['token'])) { die(); }", 'call'],
            'through destructuring' => ["[\$a] = [\$_SESSION['token']];\nif (!hash_equals(\$a, \$_POST['token'])) { die(); }", 'call'],
            'through foreach' => ["foreach (\$_SESSION as \$k => \$v) { if (\$k === 'x') { \$t = \$v; } }\nif (!hash_equals(\$t, \$_POST['token'])) { die(); }", 'call'],
            'through a reference' => ["\$s = &\$_SESSION;\nif (!hash_equals(\$s['token'], \$_POST['token'])) { die(); }", 'call'],
            'through interpolation' => ["\$t = \"{\$_SESSION['token']}\";\nif (!hash_equals(\$t, \$_POST['token'])) { die(); }", 'call'],
            'through simple interpolation' => ["\$t = \"\$_SESSION[token]\";\nif (!hash_equals(\$t, \$_POST['token'])) { die(); }", 'call'],
            'through a property' => ["class C { private string \$t; function __construct() { \$this->t = \$_SESSION['token']; } function ok(): bool { return hash_equals(\$this->t, \$_POST['token']); } }", 'call'],
            'through a static property' => ["class C { private static string \$t = ''; static function seed(): void { self::\$t = \$_SESSION['token']; } static function ok(): bool { return hash_equals(self::\$t, \$_POST['token']); } }", 'call'],
            'inside a method' => ["class C { function ok(): bool { return hash_equals(\$_SESSION['token'], \$_POST['token']); } }", 'call'],
            'inside a class named AUTH in another file' => ["class AUTH { static function csrf_token_matches(\$s): bool { return hash_equals(\$_SESSION['token'], \$s); } }", 'call'],
            'inside a closure' => ["\$ok = static function (): bool { return hash_equals(\$_SESSION['token'], \$_POST['token']); };", 'call'],
            'through a closure' => ["\$f = static function () { return \$_SESSION['token']; };\nif (!hash_equals(\$f(), \$_POST['token'])) { die(); }", 'call'],
            'through an arrow function' => ["\$f = fn () => \$_SESSION['token'];\nif (!hash_equals(\$f(), \$_POST['token'])) { die(); }", 'call'],
            'through a closure that uses it' => ["\$t = \$_SESSION['token'];\n\$ok = function () use (\$t) { return hash_equals(\$t, \$_POST['token']); };", 'call'],
            'through a global' => ["\$t = \$_SESSION['token'];\nfunction ok(): bool { global \$t; return hash_equals(\$t, \$_POST['token']); }", 'call'],
            'through \$GLOBALS' => ["if (!hash_equals(\$GLOBALS['_SESSION']['token'], \$_POST['token'])) { die(); }", 'call'],
            'through a variable variable' => ["\$n = '_SESSION';\nif (!hash_equals(\$\$n['token'], \$_POST['token'])) { die(); }", 'call'],
            'through "\${…}" in a string' => ["\$t = \"\${x}\";\nif (!hash_equals(\$t, \$_POST['token'])) { die(); }", 'call'],
            'through \${…}' => ["if (!hash_equals(\${'_SESSION'}['token'], \$_POST['token'])) { die(); }", 'call'],
            'through extract()' => ["extract(\$_SESSION);\nif (!hash_equals(\$token, \$_POST['token'])) { die(); }", 'call'],
            'through eval()' => ["eval('\$t = \$_SESSION[\"token\"];');\nif (!hash_equals(\$t, \$_POST['token'])) { die(); }", 'call'],
            'through a function that returns it' => ["if (!hash_equals(p202_standalone_wizard_token(), \$_POST['token'])) { die(); }", 'call'],
            'through a function declared here' => ["function my_token(): string { return \$_SESSION['token'] ?? ''; }\nif (!hash_equals(my_token(), \$_POST['token'])) { die(); }", 'call'],
            'through a callable string' => ["\$f = 'p202_standalone_wizard_token';\nif (!hash_equals(\$f(), \$_POST['token'])) { die(); }", 'call'],
            'through a static method' => ["if (!hash_equals(Tok::get(), \$_POST['token'])) { die(); }\nclass Tok { static function get(): string { return \$_SESSION['token']; } }", 'call'],
            'strcmp' => ["if (strcmp(\$_SESSION['token'], \$_POST['token']) !== 0) { die(); }", 'call'],
            'strcasecmp' => ["if (strcasecmp((string) (\$_SESSION['token'] ?? ''), (string) (\$_POST['token'] ?? ''))) { die(); }", 'call'],
            'strncmp' => ["if (strncmp(\$_SESSION['token'], \$_POST['token'], 32)) { die(); }", 'call'],
            'substr_compare' => ["if (substr_compare(\$_SESSION['token'], \$_POST['token'], 0)) { die(); }", 'call'],
            '===' => ["if ((\$_SESSION['token'] ?? '') !== (\$_POST['token'] ?? '')) { die(); }", 'operator'],
            '==' => ["if (\$_POST['token'] == \$_SESSION['token']) { echo 'ok'; }", 'operator'],
            '!=' => ["if (\$_POST['token'] != \$_SESSION['token']) { die(); }", 'operator'],
            '<>' => ["if (\$_POST['token'] <> \$_SESSION['token']) { die(); }", 'operator'],
            '<=>' => ["if ((\$_POST['token'] <=> \$_SESSION['token']) !== 0) { die(); }", 'operator'],
            '=== through a cast variable' => ["\$t = (string) (\$_SESSION['token'] ?? '');\nif (\$t !== (string) (\$_POST['token'] ?? '')) { die(); }", 'operator'],
            '=== through trim()' => ["\$t = trim((string) (\$_SESSION['token'] ?? ''));\nif (\$_POST['token'] !== \$t) { die(); }", 'operator'],
            '=== through a function that returns it' => ["if (p202_standalone_wizard_token() !== \$_POST['token']) { die(); }", 'operator'],
            '=== through \$GLOBALS' => ["if (\$GLOBALS['_SESSION']['token'] !== \$_POST['token']) { die(); }", 'operator'],
            'hash_equals as a string' => ["if (!call_user_func('hash_equals', \$expected, \$_POST['token'])) { die(); }", 'unreadable'],
            'hash_equals as a qualified string' => ["\$cmp = '\\\\hash_equals';\nif (!\$cmp(\$a, \$b)) { die(); }", 'unreadable'],
            'hash_equals as a first-class callable' => ["\$cmp = hash_equals(...);\nif (!\$cmp(\$a, \$b)) { die(); }", 'unreadable'],
        ];
    }

    /**
     * What must stay clean: comparisons that do not read the session token,
     * and the guards as they are written.
     *
     * @dataProvider cleanShapes
     */
    public function testShapesThatAreNotSessionTokenComparisonsPass(string $code): void
    {
        $source = "<?php\ndeclare(strict_types=1);\n$code\n";
        $sources = self::tokenSources([...self::treeBodies(), ...self::functionBodies($source)]);
        $refused = array_values(array_filter(
            self::findings('tracking202/ajax/clean.php', $source, $sources),
            static fn (array $f): bool => $f['allowed'] === null
        ));
        self::assertSame([], $refused, "refused:\n$code");
    }

    /** @return array<string, array{0: string}> */
    public static function cleanShapes(): array
    {
        return [
            'the guard' => ["if (!AUTH::check_csrf_token()) { die(); }"],
            'the GET guard' => ["if (!AUTH::csrf_token_matches(\$_GET['token'] ?? null)) { die(); }"],
            'a flag from the guard, compared' => ["\$ok = AUTH::check_csrf_token();\nif (\$ok === false) { die(); }"],
            'the comparison\'s answer, compared' => ["if (AUTH::csrf_token_matches(\$_GET['token'] ?? null) !== true) { die(); }"],
            'the installer helper' => ["\$e = (string) (\$_SESSION['token'] ?? '');\n\$csrf_ok = install_csrf_ok(\$e, (string) (\$_POST['token'] ?? ''));\nif (\$csrf_ok && \$x === 'y') { go(); }"],
            'rendering the token' => ["\$token = (string) (\$_SESSION['token'] ?? '');\necho p202_setup_token_field(\$token);\nif (\$mode === 'edit') { echo htmlspecialchars(\$token); }"],
            'a signature check' => ["if (!hash_equals(self::sign(\$key, \$body), \$signature)) { die(); }"],
            'another session value' => ["if (!hash_equals(AUTH::session_fingerprint(), (string) \$_SESSION['session_fingerprint'])) { die(); }"],
            'a session value by operator' => ["if (\$_SESSION['user_id'] === \$row['user_id']) { go(); }"],
            'strcmp as a sort callback' => ["usort(\$rows, 'strcmp');\nusort(\$rows, strcmp(...));"],
            'a variable that is not the token, compared' => ["\$t = \$_SESSION['token'];\n\$n = count(\$rows);\nif (\$n === 0) { die(); }"],
        ];
    }

    // ---------------------------------------------------------------------

    private static function siteKey(string $file, ?string $class, string $function): string
    {
        return $file . ' ' . ($class === null ? '' : $class . '::') . $function . '()';
    }

    /**
     * The tokens of a source without whitespace, comments and the literal
     * text of an interpolated string: PhpToken objects, re-indexed. A
     * string's text is not code, and `"($a + {$b})"` lexes its `(` and `)`
     * as text tokens that read exactly like brackets. Inline HTML and the
     * open/close tags stay, as statement boundaries, with their text
     * blanked for the same reason.
     *
     * @return list<\PhpToken>
     */
    private static function significant(string $source): array
    {
        $out = [];
        foreach (\PhpToken::tokenize($source) as $t) {
            if ($t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ENCAPSED_AND_WHITESPACE])) {
                continue;
            }
            if ($t->is(T_INLINE_HTML)) {
                $t->text = "\0html";
            }
            $out[] = $t;
        }
        return $out;
    }

    /**
     * Every bracket mapped to its partner. `{` from `{$` and `${` in a
     * string close with a plain `}`, and an attribute's `#[` with a plain
     * `]`; they are openers too.
     *
     * @param list<\PhpToken> $tokens
     * @return array<int, int>|null null when the brackets do not balance
     */
    private static function pairs(array $tokens): ?array
    {
        $pairs = [];
        $stack = [];
        foreach ($tokens as $i => $t) {
            if ($t->text === '(' || $t->text === '[' || $t->text === '{' || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE])) {
                $stack[] = $i;
            } elseif ($t->text === ')' || $t->text === ']' || $t->text === '}') {
                $open = array_pop($stack);
                if ($open === null) {
                    return null;
                }
                $pairs[$open] = $i;
                $pairs[$i] = $open;
            }
        }
        return $stack === [] ? $pairs : null;
    }

    /**
     * Named functions and methods, closures, and classes, as ranges of their
     * bodies.
     *
     * @param list<\PhpToken> $tokens
     * @param array<int, int> $pairs
     * @return array{functions: list<array{name: ?string, open: int, close: int, global: bool}>, classes: list<array{name: ?string, open: int, close: int}>}
     */
    private static function ranges(array $tokens, array $pairs): array
    {
        $functions = [];
        $classes = [];
        $count = count($tokens);
        foreach ($tokens as $i => $t) {
            // `use function Other\name;` imports a name; it declares nothing.
            if ($t->is(T_FUNCTION) && !($tokens[$i - 1] ?? null)?->is(T_USE)) {
                $j = $i + 1;
                if (($tokens[$j]->text ?? '') === '&') {
                    $j++;
                }
                $name = ($tokens[$j] ?? null)?->is(T_STRING) ? strtolower($tokens[$j]->text) : null;
                while ($j < $count && $tokens[$j]->text !== '(') {
                    $j++;
                }
                if (!isset($pairs[$j])) {
                    continue;
                }
                for ($k = $pairs[$j] + 1; $k < $count && $tokens[$k]->text !== '{' && $tokens[$k]->text !== ';'; $k++) {
                    if ($tokens[$k]->text === '(') {
                        $k = $pairs[$k] ?? $k;
                    }
                }
                if ($k < $count && $tokens[$k]->text === '{' && isset($pairs[$k])) {
                    $global = false;
                    for ($g = $k; $g < $pairs[$k]; $g++) {
                        $global = $global || $tokens[$g]->is(T_GLOBAL);
                    }
                    $functions[] = ['name' => $name, 'open' => $k, 'close' => $pairs[$k], 'global' => $global];
                }
            } elseif ($t->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && !($tokens[$i - 1] ?? null)?->is(T_DOUBLE_COLON)) {
                $name = ($tokens[$i + 1] ?? null)?->is(T_STRING) ? $tokens[$i + 1]->text : null;
                for ($k = $i + 1; $k < $count && $tokens[$k]->text !== '{'; $k++) {
                    if ($tokens[$k]->text === '(') {
                        $k = $pairs[$k] ?? $k;
                    }
                }
                if ($k < $count && isset($pairs[$k])) {
                    $classes[] = ['name' => $name, 'open' => $k, 'close' => $pairs[$k]];
                }
            }
        }
        return ['functions' => $functions, 'classes' => $classes];
    }

    /**
     * The innermost range of $ranges that contains $i, or null.
     *
     * @param list<array{open: int, close: int}> $ranges
     */
    private static function innermost(array $ranges, int $i, ?callable $filter = null): ?array
    {
        $best = null;
        foreach ($ranges as $range) {
            if ($range['open'] < $i && $i < $range['close'] && ($filter === null || $filter($range))
                && ($best === null || $range['open'] > $best['open'])) {
                $best = $range;
            }
        }
        return $best;
    }

    /**
     * What the token at $i reads of the session: 'token' for the token
     * itself by a key this can read (form a: `$_SESSION['…token…']`, either
     * quote style, or `"$_SESSION[token]"` in a string); 'unreadable' for
     * what reaches the session without a key this can read (`$_SESSION`
     * whole or by any other key expression, and form b: `$GLOBALS`, `$$x`,
     * `${…}`); null for anything else.
     *
     * @param list<\PhpToken> $tokens
     * @param array<int, int> $pairs
     */
    private static function sessionRead(array $tokens, array $pairs, int $i): ?string
    {
        $t = $tokens[$i];
        if ($t->is(T_VARIABLE) && $t->text === '$_SESSION') {
            if (($tokens[$i + 1]->text ?? '') !== '[' || !isset($pairs[$i + 1])) {
                return 'unreadable'; // the whole session
            }
            $close = $pairs[$i + 1];
            if ($close === $i + 3 && $tokens[$i + 2]->is(T_CONSTANT_ENCAPSED_STRING)) {
                return preg_match('/token/i', substr($tokens[$i + 2]->text, 1, -1)) === 1 ? 'token' : null;
            }
            // "$_SESSION[token]" in a string: the key is the bare word.
            if ($close === $i + 3 && $tokens[$i + 2]->is(T_STRING) && self::insideString($tokens, $i)) {
                return preg_match('/token/i', $tokens[$i + 2]->text) === 1 ? 'token' : null;
            }
            return 'unreadable'; // a key this cannot read
        }
        if ($t->is(T_VARIABLE) && $t->text === '$GLOBALS') {
            // $GLOBALS['_SESSION']['…token…'] is the session token by name.
            if (($tokens[$i + 1]->text ?? '') === '[' && ($pairs[$i + 1] ?? null) === $i + 3
                && $tokens[$i + 2]->is(T_CONSTANT_ENCAPSED_STRING) && substr($tokens[$i + 2]->text, 1, -1) === '_SESSION'
                && ($tokens[$i + 4]->text ?? '') === '[' && ($pairs[$i + 4] ?? null) === $i + 6
                && $tokens[$i + 5]->is(T_CONSTANT_ENCAPSED_STRING)) {
                return preg_match('/token/i', substr($tokens[$i + 5]->text, 1, -1)) === 1 ? 'token' : 'unreadable';
            }
            return 'unreadable';
        }
        // $$name and ${expr}: a variable this cannot name.
        if ($t->text === '$' || $t->is(T_DOLLAR_OPEN_CURLY_BRACES)) {
            return 'unreadable';
        }
        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private static function insideString(array $tokens, int $i): bool
    {
        $quotes = 0;
        for ($k = 0; $k < $i; $k++) {
            if ($tokens[$k]->text === '"' || $tokens[$k]->is([T_START_HEREDOC, T_END_HEREDOC])) {
                $quotes++;
            }
        }
        return $quotes % 2 === 1;
    }

    /**
     * Whether the name token at $i is called: `name(` — a function, a
     * method or a static method, by its last segment.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function calledName(array $tokens, int $i): ?string
    {
        $t = $tokens[$i];
        if (!$t->is([T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE]) || ($tokens[$i + 1]->text ?? '') !== '(') {
            return null;
        }
        if (($tokens[$i - 1] ?? null)?->is([T_FUNCTION, T_NEW]) || ($tokens[$i - 2] ?? null)?->is(T_FUNCTION)) {
            return null; // a declaration or a constructor
        }
        $segments = explode('\\', $t->text);
        return strtolower((string) end($segments));
    }

    /** The function a string literal names, as a callable string would. */
    private static function namedInString(\PhpToken $t): ?string
    {
        if (!$t->is(T_CONSTANT_ENCAPSED_STRING)) {
            return null;
        }
        $value = stripcslashes(substr($t->text, 1, -1));
        if (preg_match('/^\\\\?([A-Za-z_][A-Za-z0-9_]*\\\\)*([A-Za-z_][A-Za-z0-9_]*)$/', $value, $m) !== 1) {
            return null;
        }
        return strtolower($m[2]);
    }

    /**
     * The statements of a file: runs of token indices between `;`, a brace
     * that is not a string's `{$`/`${`, and the open and close tags.
     *
     * @param list<\PhpToken> $tokens
     * @param array<int, int> $pairs
     * @return list<list<int>>
     */
    private static function statements(array $tokens, array $pairs): array
    {
        $statements = [];
        $current = [];
        foreach ($tokens as $i => $t) {
            $stringBrace = ($t->text === '}' && isset($pairs[$i]) && $tokens[$pairs[$i]]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]))
                || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]);
            $boundary = !$stringBrace && ($t->text === ';' || $t->text === '{' || $t->text === '}'
                || $t->is([T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG, T_INLINE_HTML]));
            if ($boundary) {
                if ($current !== []) {
                    $statements[] = $current;
                }
                $current = [];
                continue;
            }
            $current[] = $i;
        }
        if ($current !== []) {
            $statements[] = $current;
        }
        return $statements;
    }

    /**
     * The tokens of one operand of the comparison operator at $op, walking
     * in $direction (-1 left, 1 right): everything that binds tighter than a
     * comparison, with bracketed groups taken whole, up to the first token
     * that binds looser or ends the expression. `&&`, `||`, `??`, `?:`,
     * `&`, `|`, `^`, an assignment, `,`, `=>`, an unmatched bracket, a
     * block brace and the statement keywords all end it, so
     * `'editing' => $form['id'] !== ''` reads `$form['id']`, and
     * `($_SESSION['token'] ?? '') !== …` reads the whole group.
     *
     * @param list<\PhpToken> $tokens
     * @param array<int, int> $pairs
     * @return list<int>
     */
    private static function operand(array $tokens, array $pairs, int $op, int $direction): array
    {
        $out = [];
        for ($k = $op + $direction; isset($tokens[$k]); $k += $direction) {
            $t = $tokens[$k];
            $entersGroup = $direction < 0
                ? ($t->text === ')' || $t->text === ']' || ($t->text === '}' && isset($pairs[$k]) && $tokens[$pairs[$k]]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])))
                : ($t->text === '(' || $t->text === '[' || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE]));
            if ($entersGroup && isset($pairs[$k])) {
                $end = $pairs[$k];
                for ($g = $k; $g !== $end + $direction; $g += $direction) {
                    $out[] = $g;
                }
                $k = $end;
                continue;
            }
            $stops = in_array($t->text, ['(', ')', '[', ']', '{', '}', ',', ';', '?', ':', '&', '|', '^', '='], true)
                || $t->is([
                    T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_COALESCE, T_DOUBLE_ARROW,
                    T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL, T_AND_EQUAL,
                    T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL,
                    T_RETURN, T_ECHO, T_PRINT, T_YIELD, T_YIELD_FROM, T_THROW, T_AS, T_CASE,
                    T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG, T_INLINE_HTML,
                ]);
            if ($stops) {
                break;
            }
            $out[] = $k;
        }
        return $out;
    }

    /** @var list<array{0: string, 1: bool, 2: list<string>, 3: bool}>|null */
    private static ?array $treeBodies = null;

    /**
     * Every named function and method in the served tree, as [lowercase
     * name, whether it returns the session token, the names it calls or
     * names in a string, whether it is a method]; read once per process.
     *
     * @return list<array{0: string, 1: bool, 2: list<string>, 3: bool}>
     */
    private static function treeBodies(): array
    {
        if (self::$treeBodies === null) {
            $bodies = [];
            foreach (SourceScan::phpFiles() as $source) {
                array_push($bodies, ...self::functionBodies($source));
            }
            self::$treeBodies = $bodies;
        }
        return self::$treeBodies;
    }

    /** @return list<array{0: string, 1: bool, 2: list<string>, 3: bool}> */
    private static function functionBodies(string $source): array
    {
        $tokens = self::significant($source);
        $pairs = self::pairs($tokens);
        if ($pairs === null) {
            return []; // refused by name in findings()
        }
        $bodies = [];
        ['functions' => $functions, 'classes' => $classes] = self::ranges($tokens, $pairs);
        $statements = self::statements($tokens, $pairs);
        foreach ($functions as $fn) {
            if ($fn['name'] === null) {
                continue;
            }
            $calls = [];
            for ($i = $fn['open'] + 1; $i < $fn['close']; $i++) {
                $called = self::calledName($tokens, $i) ?? self::namedInString($tokens[$i]);
                if ($called !== null) {
                    $calls[$called] = true;
                }
            }
            // Returns the token: a `return` whose expression reads it as in
            // (a), or names a variable that some statement of the body
            // reads it into (to a fixpoint, over the whole body).
            $body = array_values(array_filter($statements, static fn (array $m): bool => $m[0] > $fn['open'] && $m[0] < $fn['close']));
            $carrying = [];
            do {
                $grew = false;
                foreach ($body as $members) {
                    $reads = false;
                    foreach ($members as $i) {
                        $reads = $reads || self::sessionRead($tokens, $pairs, $i) === 'token'
                            || ($tokens[$i]->is(T_VARIABLE) && isset($carrying[$tokens[$i]->text]));
                    }
                    if (!$reads) {
                        continue;
                    }
                    foreach ($members as $i) {
                        if ($tokens[$i]->is(T_VARIABLE) && !in_array($tokens[$i]->text, self::SUPERGLOBALS, true) && !isset($carrying[$tokens[$i]->text])) {
                            $carrying[$tokens[$i]->text] = true;
                            $grew = true;
                        }
                    }
                }
            } while ($grew);
            $returns = false;
            foreach ($body as $members) {
                if (!$tokens[$members[0]]->is(T_RETURN)) {
                    continue;
                }
                foreach ($members as $i) {
                    $returns = $returns || self::sessionRead($tokens, $pairs, $i) === 'token'
                        || ($tokens[$i]->is(T_VARIABLE) && isset($carrying[$tokens[$i]->text]));
                }
            }
            $bodies[] = [$fn['name'], $returns, array_keys($calls), self::innermost($classes, $fn['open']) !== null];
        }
        return $bodies;
    }

    /**
     * Form d: the functions and methods that return the session token (a
     * `return` that reads it by a key this can read, directly or through a
     * variable of the body), by lowercase name. The allowed comparisons are
     * not sources: they return the answer, not the token. A body that only
     * renders the token (template_top() echoes it into the page) is not a
     * source, and sources are not followed to their callers: by name,
     * across the tree, either one reaches every page that renders the token
     * (template_top(), then everything that calls it, then
     * _mysqli_query()…) and refuses every comparison near them; nor is one
     * seeded from a read this cannot key (form b), for the same reason.
     *
     * @param list<array{0: string, 1: bool, 2: list<string>, 3: bool}> $bodies
     * @return array<string, true>
     */
    private static function tokenSources(array $bodies): array
    {
        $allowed = [];
        foreach (self::ALLOWED as [, , $function]) {
            $allowed[strtolower($function)] = true;
        }
        $sources = [];
        foreach ($bodies as [$name, $reads]) {
            if ($reads && !isset($allowed[$name])) {
                $sources[$name] = true;
            }
        }
        return $sources;
    }

    /**
     * Every comparison in a file that reads the session token, each with the
     * allowed site it sits in (or null): the subject of both tests.
     *
     * @param array<string, true> $sources
     * @return list<array{line: int, kind: string, what: string, allowed: ?string}>
     */
    private static function findings(string $path, string $source, array $sources): array
    {
        $tokens = self::significant($source);
        $pairs = self::pairs($tokens);
        if ($pairs === null) {
            return [['line' => 1, 'kind' => 'unreadable', 'what' => 'brackets that do not balance; this test cannot read the file', 'allowed' => null]];
        }
        ['functions' => $functions, 'classes' => $classes] = self::ranges($tokens, $pairs);
        $named = static fn (array $f): bool => $f['name'] !== null;

        // The variable scope of each token: the innermost named function's
        // body (closures share their parent's; a function that declares
        // `global` shares the top level's), or the top level (-1).
        $scopeOf = static function (int $i) use ($functions, $named): int {
            $fn = self::innermost($functions, $i, $named);
            return $fn === null || $fn['global'] ? -1 : $fn['open'];
        };
        $siteOf = static function (int $i, array $sites) use ($path, $functions, $classes, $named): ?string {
            $fn = self::innermost($functions, $i, $named);
            if ($fn === null) {
                return null;
            }
            $class = self::innermost($classes, $i);
            foreach ($sites as [$file, $siteClass, $function]) {
                if ($path === $file && $fn['name'] === strtolower($function)
                    && ($siteClass === null ? $class === null : ($class !== null && $class['name'] === $siteClass))) {
                    return self::siteKey($file, $siteClass, $function);
                }
            }
            return null;
        };

        // Form d at a token: a call of a token source, or a string naming one.
        $readsViaSource = static function (int $i) use ($tokens, $sources): bool {
            $name = self::calledName($tokens, $i) ?? self::namedInString($tokens[$i]);
            return $name !== null && isset($sources[$name]);
        };
        // The token itself (a, d), and anything that may reach it (a, b, d).
        $strict = static fn (int $i): bool => self::sessionRead($tokens, $pairs, $i) === 'token' || $readsViaSource($i);
        $direct = static fn (int $i): bool => self::sessionRead($tokens, $pairs, $i) !== null || $readsViaSource($i);

        $propertyAt = static function (int $i) use ($tokens): ?string {
            $t = $tokens[$i];
            if ($t->is(T_STRING) && ($tokens[$i - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
                && ($tokens[$i + 1]->text ?? '') !== '(') {
                return strtolower($t->text);
            }
            if ($t->is(T_VARIABLE) && ($tokens[$i - 1] ?? null)?->is(T_DOUBLE_COLON)) {
                return strtolower(substr($t->text, 1));
            }
            return null;
        };
        $variableAt = static function (int $i) use ($tokens): ?string {
            $t = $tokens[$i];
            if (!$t->is(T_VARIABLE) || in_array($t->text, self::SUPERGLOBALS, true) || ($tokens[$i - 1] ?? null)?->is(T_DOUBLE_COLON)) {
                return null;
            }
            return $t->text;
        };

        $statements = self::statements($tokens, $pairs);
        $statementOf = [];
        foreach ($statements as $s => $members) {
            foreach ($members as $i) {
                $statementOf[$i] = $s;
            }
        }

        // 1c: the wide taint, to a fixpoint.
        $tainted = [];      // scope => [$name => true]
        $properties = [];   // name => true
        $allTainted = [];   // scope => true
        $unnamedWriters = [];
        foreach ($tokens as $i => $t) {
            $called = self::calledName($tokens, $i);
            if ($t->text === '$' || $t->is(T_DOLLAR_OPEN_CURLY_BRACES) || ($called !== null && in_array($called, self::UNNAMED_WRITERS, true))) {
                $unnamedWriters[$scopeOf($i)] = true;
            }
            // eval() runs code this cannot read: whatever it assigns is unread.
            if ($t->is(T_EVAL)) {
                $allTainted[$scopeOf($i)] = true;
            }
        }
        $taintedAt = static function (int $i) use (&$tainted, &$properties, &$allTainted, $direct, $propertyAt, $variableAt, $scopeOf): bool {
            if ($direct($i)) {
                return true;
            }
            $variable = $variableAt($i);
            if ($variable !== null && (isset($tainted[$scopeOf($i)][$variable]) || isset($allTainted[$scopeOf($i)]))) {
                return true;
            }
            $property = $propertyAt($i);
            return $property !== null && isset($properties[$property]);
        };
        do {
            $grew = false;
            $statementTainted = [];
            foreach ($statements as $s => $members) {
                foreach ($members as $i) {
                    if ($taintedAt($i)) {
                        $statementTainted[$s] = true;
                        break;
                    }
                }
            }
            // A closure or arrow function whose body is tainted taints the
            // statement it is written in: `$f = function () { return … };`.
            foreach ($functions as $fn) {
                if ($fn['name'] !== null) {
                    continue;
                }
                for ($i = $fn['open'] + 1; $i < $fn['close']; $i++) {
                    if (isset($statementOf[$i], $statementTainted[$statementOf[$i]])) {
                        $keyword = $fn['open'];
                        while ($keyword > 0 && !$tokens[$keyword]->is(T_FUNCTION)) {
                            $keyword--;
                        }
                        if (isset($statementOf[$keyword])) {
                            $statementTainted[$statementOf[$keyword]] = true;
                        }
                        break;
                    }
                }
            }
            foreach (array_keys($statementTainted) as $s) {
                foreach ($statements[$s] as $i) {
                    $scope = $scopeOf($i);
                    if (isset($unnamedWriters[$scope]) && !isset($allTainted[$scope])) {
                        $allTainted[$scope] = true;
                        $grew = true;
                    }
                    $variable = $variableAt($i);
                    if ($variable !== null && !isset($tainted[$scope][$variable])) {
                        $tainted[$scope][$variable] = true;
                        $grew = true;
                    }
                    $property = $propertyAt($i);
                    if ($property !== null && !isset($properties[$property])) {
                        $properties[$property] = true;
                        $grew = true;
                    }
                }
            }
        } while ($grew);

        // 2: the narrow taint — a variable assigned the token by a
        // value-preserving expression, in statement order within its scope.
        $valueTainted = [];
        foreach ($statements as $members) {
            $first = $members[0];
            $variable = $variableAt($first);
            if ($variable === null || ($tokens[$members[1] ?? -1]->text ?? '') !== '=' || ($tokens[$members[2] ?? -1]->text ?? '') === '&') {
                continue;
            }
            $carries = false;
            $preserving = true;
            foreach (array_slice($members, 2) as $i) {
                $t = $tokens[$i];
                $carries = $carries || $strict($i) || ($variableAt($i) !== null && isset($valueTainted[$scopeOf($i)][$variableAt($i)]));
                $name = self::calledName($tokens, $i);
                $preserving = $preserving && (
                    $t->is([T_VARIABLE, T_CONSTANT_ENCAPSED_STRING, T_STRING_CAST, T_COALESCE, T_LNUMBER])
                    || in_array($t->text, ['(', ')', '[', ']'], true)
                    || ($t->is(T_STRING) && in_array(strtolower($t->text), ['null', 'true', 'false'], true))
                    || ($name !== null && (in_array($name, self::VALUE_PRESERVING, true) || isset($sources[$name])))
                );
            }
            if ($carries && $preserving) {
                $valueTainted[$scopeOf($first)][$variable] = true;
            }
        }

        $findings = [];
        $report = static function (int $i, string $kind, string $what, array $sites) use (&$findings, $tokens, $siteOf): void {
            $findings[] = ['line' => $tokens[$i]->line, 'kind' => $kind, 'what' => $what, 'allowed' => $siteOf($i, $sites)];
        };

        foreach ($tokens as $i => $t) {
            // 3: hash_equals where its arguments cannot be read.
            if (self::namedInString($t) === 'hash_equals') {
                $report($i, 'unreadable', 'hash_equals named in a string; its arguments are not at the call site', []);
                continue;
            }
            $called = self::calledName($tokens, $i);
            if ($called === null || !in_array($called, self::COMPARING_CALLS, true)) {
                continue;
            }
            $open = $i + 1;
            $close = $pairs[$open];
            if ($called === 'hash_equals' && $close === $open + 2 && $tokens[$open + 1]->is(T_ELLIPSIS)) {
                $report($i, 'unreadable', 'hash_equals(...) taken as a first-class callable; its arguments are not at the call site', []);
                continue;
            }
            // 1: a comparing call whose arguments read the token.
            for ($k = $open + 1; $k < $close; $k++) {
                if ($taintedAt($k)) {
                    $report($i, 'call', "$called() compares the session token (read at `{$tokens[$k]->text}`)", self::ALLOWED);
                    break;
                }
            }
        }

        // 2: a comparison operator one of whose operands reads the token.
        foreach ($tokens as $operator => $t) {
            if (!$t->is([T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_SPACESHIP])) {
                continue;
            }
            foreach ([...self::operand($tokens, $pairs, $operator, -1), ...self::operand($tokens, $pairs, $operator, 1)] as $i) {
                if ($strict($i) || ($variableAt($i) !== null && isset($valueTainted[$scopeOf($i)][$variableAt($i)]))) {
                    $report($operator, 'operator', "`{$t->text}` compares the session token (read at `{$tokens[$i]->text}`)", array_merge(self::ALLOWED, self::SEEDS));
                    break;
                }
            }
        }

        return $findings;
    }
}
