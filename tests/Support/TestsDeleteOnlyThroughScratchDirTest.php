<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A test deletes what a glob or a directory walk found only through
 * ScratchDir, which acts inside the system temp directory alone.
 *
 * Fifty-odd tests cleaned up by hand, each in its own way. When setUp stopped
 * before it made its directory the name was '', `glob('' . '/*')` listed the
 * root, and run as root in a container the cleanup unlinked /bin, /lib,
 * /sbin and /.dockerenv (twice in one session, from two different tests).
 *
 * The check reads each function (a closure is read as part of the function
 * it sits in, and file-scope code as one more body): one that both lists a
 * directory (glob, scandir, opendir, a directory iterator) and deletes
 * (unlink, rmdir, or either named as a callback) is refused unless listed
 * below with why. It does not see a delete in another function handed what
 * this one listed, a shell `rm`, or a path built some other way: those are
 * still the author's to send through ScratchDir.
 */
final class TestsDeleteOnlyThroughScratchDirTest extends TestCase
{
    /** file => why its list-and-delete is not the hazard */
    private const ALLOWED = [
        'tests/Api/V3/ServerStateStoreQuotaTest.php' => 'globs for one bucket file to assert on, and unlinks only the'
            . ' tempnam() file it made: nothing it lists is deleted',
    ];

    private const LISTS = ['glob', 'scandir', 'opendir', 'readdir'];
    private const ITERATORS = ['DirectoryIterator', 'RecursiveDirectoryIterator', 'FilesystemIterator', 'GlobIterator'];
    private const DELETES = ['unlink', 'rmdir'];

    public function testNoTestDeletesWhatItListsByHand(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $files = 0;
        foreach (new \RecursiveIteratorIterator(SourceScan::tree($root . '/tests')) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $rel = substr((string) $file, strlen($root) + 1);
            if (str_starts_with($rel, 'tests/fixtures/') || $rel === 'tests/Support/TestsDeleteOnlyThroughScratchDirTest.php') {
                continue;
            }
            $files++;
            foreach (self::bodies((string) file_get_contents((string) $file)) as [$line, $tokens]) {
                if (self::lists($tokens) && self::deletes($tokens)) {
                    $found[] = $rel . ':' . $line;
                }
            }
        }
        self::assertGreaterThan(900, $files, 'the tests were not found where this looks');
        $unlisted = array_values(array_filter(
            $found,
            static fn (string $site): bool => !array_key_exists(explode(':', $site)[0], self::ALLOWED)
        ));
        self::assertSame([], $unlisted, 'delete what a glob or directory walk found through Tests\Support\ScratchDir');
        foreach (array_keys(self::ALLOWED) as $allowed) {
            self::assertNotEmpty(
                array_filter($found, static fn (string $site): bool => str_starts_with($site, $allowed . ':')),
                $allowed . ' is listed and no longer lists and deletes; take it off the list'
            );
        }
    }

    /** The planted shapes the scan must see, and one it must not. */
    public function testTheScanSeesEachShape(): void
    {
        $refused = [
            'array_map' => '<?php function f($d) { array_map(\'unlink\', glob($d . \'/*\') ?: []); }',
            'foreach glob' => '<?php function f($d) { foreach (glob($d . \'/*\') as $f) { @unlink($f); } }',
            'scandir and recursion' => '<?php function f($p) { foreach (array_diff(scandir($p), [\'.\', \'..\']) as $c) { f("$p/$c"); } rmdir($p); }',
            // Spelled in two pieces so SourceScanTest's count of direct walks does not read it as one.
            'iterator' => '<?php function f($d) { foreach (new \RecursiveIteratorIterator(new \Recursive' . 'DirectoryIterator($d)) as $f) { unlink($f); } }',
            'qualified' => '<?php function f($d) { foreach (\glob($d . \'/*\') as $f) { \unlink($f); } }',
            'in a closure' => '<?php function f($d) { $g = function () use ($d) { array_map(\'rmdir\', glob($d . \'/*\')); }; }',
            'file scope' => '<?php foreach (glob($dir . \'/*\') as $f) { unlink($f); }',
        ];
        foreach ($refused as $shape => $code) {
            $hit = false;
            foreach (self::bodies($code) as [, $tokens]) {
                $hit = $hit || (self::lists($tokens) && self::deletes($tokens));
            }
            self::assertTrue($hit, $shape);
        }
        $methods = '<?php function g($o) { $o->glob(); $o->unlink(); Fs::scandir(); Fs::rmdir(); }';
        [[, $tokens]] = self::bodies($methods);
        self::assertFalse(self::lists($tokens) || self::deletes($tokens), 'methods named glob and unlink are not the calls');
    }

    /**
     * Each function's body and the file's top-level code, as token lists.
     *
     * @return list<array{int, list<\PhpToken>}>
     */
    private static function bodies(string $src): array
    {
        $tokens = \PhpToken::tokenize($src);
        $bodies = [];
        $top = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            if ($tokens[$i]->id !== T_FUNCTION) {
                $top[] = $tokens[$i];
                continue;
            }
            $j = $i + 1;
            while ($j < $n && $tokens[$j]->text !== '{' && $tokens[$j]->text !== ';') {
                $j++;
            }
            if ($j >= $n || $tokens[$j]->text === ';') {
                continue; // an abstract or interface method
            }
            $depth = 0;
            $body = [];
            for ($k = $j; $k < $n; $k++) {
                $t = $tokens[$k];
                if ($t->text === '{' || $t->id === T_CURLY_OPEN || $t->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                } elseif ($t->text === '}') {
                    $depth--;
                }
                $body[] = $t;
                if ($depth === 0) {
                    break;
                }
            }
            $bodies[] = [$tokens[$i]->line, $body];
            $i = $k;
        }
        $bodies[] = [1, $top];

        return $bodies;
    }

    /** @param list<\PhpToken> $tokens */
    private static function lists(array $tokens): bool
    {
        foreach ($tokens as $i => $t) {
            $name = self::globalName($tokens, $i);
            if ($name !== null && in_array($name, self::LISTS, true) && self::calls($tokens, $i)) {
                return true;
            }
            if ($t->id === T_NEW) {
                $next = self::next($tokens, $i);
                if ($next !== null && in_array(ltrim($next->text, '\\'), self::ITERATORS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<\PhpToken> $tokens */
    private static function deletes(array $tokens): bool
    {
        foreach ($tokens as $i => $t) {
            $name = self::globalName($tokens, $i);
            if ($name !== null && in_array($name, self::DELETES, true) && self::calls($tokens, $i)) {
                return true;
            }
            if ($t->id === T_CONSTANT_ENCAPSED_STRING && in_array(strtolower(trim($t->text, '\'"')), self::DELETES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The lower-case name of a global function call at $i, or null for
     * anything else (a method, a static call, a declaration).
     *
     * @param list<\PhpToken> $tokens
     */
    private static function globalName(array $tokens, int $i): ?string
    {
        $t = $tokens[$i];
        if ($t->id === T_NAME_FULLY_QUALIFIED) {
            $name = substr($t->text, 1);
            return str_contains($name, '\\') ? null : strtolower($name);
        }
        if ($t->id !== T_STRING) {
            return null;
        }
        $prev = self::prev($tokens, $i);
        if ($prev !== null && in_array($prev->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
            return null;
        }

        return strtolower($t->text);
    }

    /** @param list<\PhpToken> $tokens */
    private static function calls(array $tokens, int $i): bool
    {
        $next = self::next($tokens, $i);

        return $next !== null && $next->text === '(';
    }

    /** @param list<\PhpToken> $tokens */
    private static function next(array $tokens, int $i): ?\PhpToken
    {
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            if (!$tokens[$j]->isIgnorable()) {
                return $tokens[$j];
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private static function prev(array $tokens, int $i): ?\PhpToken
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!$tokens[$j]->isIgnorable()) {
                return $tokens[$j];
            }
        }

        return null;
    }
}
