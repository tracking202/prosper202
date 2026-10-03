<?php

declare(strict_types=1);

namespace Tests\Update;

use PHPUnit\Framework\TestCase;

/**
 * The Update pages (tracking202/update/) check the session token wherever
 * they read a POST, every form they render that posts carries it, and they
 * post to nothing outside the family.
 *
 * Error pattern #5 found the hole this pins: Update CPC wrote through
 * tracking202/ajax/update_cpc2.php, which asked for no token at all, beside a
 * subid upload and a delete that did. U5 moved every Update write onto the
 * page that renders its form (the CPC update, the campaign reset) and retired
 * the three AJAX fragments, so the family's writes are the five files here.
 *
 * What it proves:
 *
 *   - A file that reads $_POST or $_REQUEST, or compares the request method
 *     with 'POST', calls AUTH::check_csrf_token() — read as a call: `AUTH::`
 *     directly before the name, in a file that declares no namespace and
 *     imports nothing named AUTH (error pattern #21: a name is not a call
 *     site).
 *   - Every `<form method="post">` carries the token inside its own bounds —
 *     p202_setup_token_field(), or a hidden input named token — outside
 *     comments; a form built in a PHP string is refused by name, because it
 *     cannot be read here.
 *   - No Update source names a tracking202/ajax/ endpoint: a write that
 *     moved back into a fragment would be out of this test's sight.
 *
 * It does NOT prove the guard runs before the write, or decides it; that is
 * tests/live/update-pages.sh, which posts every form without its token and
 * asserts the refusal sentence and an unchanged database, and
 * tests/live/conversion-ledger.sh for the ledger behind three of the pages.
 */
final class UpdatePostsRequireTokenTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> repo-relative paths of every PHP file under tracking202/update/ */
    private static function updateFiles(): array
    {
        $root = self::root();
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/tracking202/update', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);
        return $files;
    }

    /** @return list<array{0: int, 1: string}> significant tokens: [id, text] (id 0 for single characters) */
    private static function tokens(string $source): array
    {
        $out = [];
        foreach (\PhpToken::tokenize($source) as $token) {
            if ($token->isIgnorable()) {
                continue;
            }
            $out[] = [$token->id < 256 ? 0 : $token->id, $token->text];
        }
        return $out;
    }

    private static function readsPost(array $tokens): bool
    {
        foreach ($tokens as [$id, $text]) {
            if ($id === T_VARIABLE && ($text === '$_POST' || $text === '$_REQUEST')) {
                return true;
            }
            if ($id === T_CONSTANT_ENCAPSED_STRING && in_array($text, ["'POST'", '"POST"'], true)) {
                return true;
            }
        }
        return false;
    }

    /** How many times the file calls AUTH::check_csrf_token(), the class named exactly AUTH. */
    private static function guardCalls(array $tokens): int
    {
        $found = 0;
        foreach ($tokens as $i => [$id, $text]) {
            if ($id !== T_STRING || $text !== 'check_csrf_token') {
                continue;
            }
            if (($tokens[$i - 1][1] ?? '') !== '::' || ($tokens[$i + 1][1] ?? '') !== '(' || ($tokens[$i + 2][1] ?? '') !== ')') {
                continue;
            }
            $class = $tokens[$i - 2] ?? [0, ''];
            if (in_array($class[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && ltrim($class[1], '\\') === 'AUTH') {
                $found++;
            }
        }
        return $found;
    }

    /** @return list<string> */
    private static function rebindings(array $tokens): array
    {
        $problems = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] === T_NAMESPACE && ($tokens[$i + 1][1] ?? '') !== '\\') {
                $problems[] = 'declares a namespace';
            }
            if ($tokens[$i][0] === T_USE && ($tokens[$i - 1][1] ?? '') !== ')') {
                $statement = '';
                for ($j = $i + 1; $j < $count && $tokens[$j][1] !== ';' && $tokens[$j][1] !== '{'; $j++) {
                    $statement .= $tokens[$j][1] . ' ';
                }
                if (preg_match('/(^|\\\\|\s)AUTH\s*(;|$|,)/i', $statement) || preg_match('/\bas\s+AUTH\b/i', $statement)) {
                    $problems[] = 'imports a name the guard is read by: use ' . trim($statement);
                }
            }
        }
        return $problems;
    }

    public function testEveryUpdateFileThatTakesAPostChecksTheToken(): void
    {
        $missing = [];
        $checked = 0;
        foreach (self::updateFiles() as $relative) {
            $tokens = self::tokens((string) file_get_contents(self::root() . '/' . $relative));
            if (!self::readsPost($tokens)) {
                continue;
            }
            $checked++;
            foreach (self::rebindings($tokens) as $problem) {
                $missing[] = "$relative $problem";
            }
            if (self::guardCalls($tokens) === 0) {
                $missing[] = "$relative takes a POST and never calls AUTH::check_csrf_token()";
            }
        }
        // subids, delete-subids, clear-subids, cpc, upload.
        self::assertGreaterThanOrEqual(5, $checked, 'fewer Update files take a POST than the family has writes; the scan is broken');
        self::assertSame([], $missing, implode("\n", $missing));
    }

    public function testEveryPostFormCarriesTheToken(): void
    {
        $problems = [];
        $forms = 0;
        foreach (self::updateFiles() as $relative) {
            $source = (string) file_get_contents(self::root() . '/' . $relative);
            $markup = '';
            foreach (\PhpToken::tokenize($source) as $token) {
                if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) && stripos($token->text, '<form') !== false) {
                    $problems[] = "$relative line {$token->line}: a <form> built in a PHP string cannot be read by this test";
                }
                if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                    $markup .= $token->text;
                }
            }
            $markup = (string) preg_replace('/<!--.*?-->/s', '', $markup);
            // A tag's attributes may hold an echo block, whose closing
            // question mark and bracket are not the end of the tag: an
            // attribute after it, method="post" included, would otherwise go
            // unread (the upload form's did).
            if (!preg_match_all('/<form\b((?:<\?php.*?\?>|[^>])*)>(.*?)<\/form>/is', $markup, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                if (!preg_match('/\bmethod\s*=\s*["\']?post\b/i', $match[1])) {
                    continue;
                }
                $forms++;
                $body = $match[2];
                self::assertSame(0, preg_match('/<form\b/i', $body), "$relative: a form inside a form");
                $carries = preg_match('/\bp202_setup_token_field\(\s*\(string\)\s*\(\$_SESSION\[\'token\'\]\s*\?\?\s*\'\'\)\s*\)/', $body)
                    || preg_match('/<input\b[^>]*type\s*=\s*["\']hidden["\'][^>]*name\s*=\s*["\']token["\']/i', $body)
                    || preg_match('/<input\b[^>]*name\s*=\s*["\']token["\'][^>]*type\s*=\s*["\']hidden["\']/i', $body);
                if (!$carries) {
                    $problems[] = "$relative: a POST form without the session token inside it: " . trim(substr($match[0], 0, 120));
                }
            }
        }
        // subids, delete-subids, clear-subids, the CPC confirm, the upload and its column picker.
        self::assertGreaterThanOrEqual(6, $forms, 'fewer POST forms than the Update pages render; the scan is broken');
        self::assertSame([], $problems, implode("\n", $problems));
    }

    public function testNoUpdatePageWritesThroughAnAjaxFragment(): void
    {
        $named = [];
        foreach (self::updateFiles() as $relative) {
            $source = (string) file_get_contents(self::root() . '/' . $relative);
            // Comments may say where a thing came from; code may not post there.
            $code = '';
            foreach (\PhpToken::tokenize($source) as $token) {
                if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                    $code .= $token->text;
                }
            }
            if (preg_match_all('~tracking202/ajax/[a-z0-9_]+\.php~i', $code, $matches)) {
                foreach ($matches[0] as $endpoint) {
                    $named[] = "$relative names $endpoint";
                }
            }
        }
        self::assertSame([], $named, "An Update page posts to an AJAX fragment again; move the write onto the page, or teach this test the fragment's guard:\n" . implode("\n", $named));
        foreach (['update_cpc.php', 'update_cpc2.php', 'clear_subids.php'] as $retired) {
            self::assertFileDoesNotExist(self::root() . '/tracking202/ajax/' . $retired, "$retired was retired in U5; its write lives on the page now");
        }
    }
}
