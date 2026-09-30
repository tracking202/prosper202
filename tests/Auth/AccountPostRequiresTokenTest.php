<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;

/**
 * The Account pages (202-account/, AJAX fragments included) check the session
 * token wherever they read a POST, and every form they render that posts
 * carries it.
 *
 * Error pattern #5 found three writes in this family that asked for no token
 * — the Stats202 app key handler in account.php and the two AJAX endpoints
 * ajax/dni.php (?updateStatus) and ajax/survey.php (deleted in U8 with the
 * classic shell's survey pop-up, its only caller) — and three more that were
 * GET links: removing a user, removing a DNI network, and account.php's
 * profile branch that rewrote the email on any POST that lacked
 * update_profile. Those are POSTs with the token now, and this test keeps the
 * family from growing a new one.
 *
 * What it proves, and what it does not:
 *
 *   - A file that reads $_POST or $_REQUEST calls AUTH::check_csrf_token() —
 *     the app's one implementation, executed in AuthClassTest and in
 *     PreLoginPostRequiresTokenTest — or calls hash_equals() with the
 *     session token and the posted token as its two arguments. The call is
 *     read as a call: `AUTH::` directly before the name (not `->`, not
 *     `MyAUTH::`), in a file that declares no namespace and imports nothing
 *     named AUTH.
 *   - Every `<form method="post">` in those files carries the token inside its
 *     own bounds: p202_account_token_field(), or a hidden input named token.
 *
 * It does NOT prove the guard runs before the write it protects, or decides
 * whether it runs; that is what PreLoginPostRequiresTokenTest does for three
 * pages at considerable length (error patterns #20-#22), and this is a floor
 * for fifteen. tests/live/account-pages.sh is the other half: it posts a bad
 * token to each form it drives and asserts the refusal sentence and an
 * unchanged database row.
 */
final class AccountPostRequiresTokenTest extends TestCase
{
    /**
     * Files that read a POST without a guard this test recognises, each with
     * the reason. The list only ever shrinks: a file that gains a guard fails
     * testTheKnownListHasNoStaleEntries until it is removed from here.
     */
    private const KNOWN_UNGUARDED = [
        // Validates a key the installer is about to save; writes nothing.
        '202-account/ajax/validate-apikey.php',
        // Snoozes the update banner for this session only; writes no row.
        '202-account/ajax/delay-alert.php',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> repo-relative paths of every PHP file under 202-account/ */
    private static function accountFiles(): array
    {
        $root = self::root();
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/202-account', \FilesystemIterator::SKIP_DOTS));
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

    /**
     * Whether the file takes a POST: it reads $_POST or $_REQUEST, or it
     * compares the request method with 'POST'. The second matters because a
     * handler can write on a POST while reading only $_GET and the body
     * (ajax/dni.php does), and with its guard deleted it would read no
     * $_POST at all — and drop out of this test's scope in silence.
     */
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

    /** `[ 'token' ]` directly after the superglobal at $i. */
    private static function indexesToken(array $tokens, int $i): bool
    {
        return ($tokens[$i + 1][1] ?? '') === '['
            && ($tokens[$i + 2][0] ?? 0) === T_CONSTANT_ENCAPSED_STRING
            && in_array($tokens[$i + 2][1], ["'token'", '"token"'], true)
            && ($tokens[$i + 3][1] ?? '') === ']';
    }

    /**
     * Whether the file calls a guard this test recognises.
     *
     * @return list<string> the guard calls found, as "line N: spelling"
     */
    private static function guardCalls(array $tokens): array
    {
        $found = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $tokens[$i];
            if ($id !== T_STRING) {
                continue;
            }
            $before = $tokens[$i - 1][1] ?? '';
            $beforeId = $tokens[$i - 1][0] ?? 0;

            // AUTH::check_csrf_token() — the class named exactly AUTH, global.
            if ($text === 'check_csrf_token' && $before === '::' && ($tokens[$i + 1][1] ?? '') === '(' && ($tokens[$i + 2][1] ?? '') === ')') {
                $class = $tokens[$i - 2] ?? [0, ''];
                $classText = ltrim($class[1], '\\');
                if (in_array($class[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && $classText === 'AUTH') {
                    $found[] = 'AUTH::check_csrf_token()';
                }
                continue;
            }

            // hash_equals(<session token>, <posted token>) — a plain function
            // call, the session token in the first argument and the posted one
            // in the second.
            if ($text === 'hash_equals' && ($tokens[$i + 1][1] ?? '') === '('
                && !in_array($before, ['->', '?->', '::', '\\'], true) && $beforeId !== T_FUNCTION && $beforeId !== T_NEW) {
                $depth = 0;
                $argument = 0;
                $sessionIn = null;
                $postIn = null;
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j][1];
                    if ($t === '(' || $t === '[') {
                        $depth++;
                    } elseif ($t === ')' || $t === ']') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    } elseif ($t === ',' && $depth === 1) {
                        $argument++;
                    } elseif ($tokens[$j][0] === T_VARIABLE && self::indexesToken($tokens, $j)) {
                        if ($t === '$_SESSION') {
                            $sessionIn = $sessionIn ?? $argument;
                        } elseif ($t === '$_POST' || $t === '$_REQUEST') {
                            $postIn = $postIn ?? $argument;
                        }
                    }
                }
                if ($sessionIn === 0 && $postIn === 1 && $argument === 1) {
                    $found[] = 'hash_equals($_SESSION[token], $_POST[token])';
                }
            }
        }
        return $found;
    }

    /** A namespace or an import could make `AUTH` or `hash_equals` another symbol. */
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
                if (preg_match('/(^|\\\\|\s)(AUTH|hash_equals)\s*(;|$|,)/i', $statement) || preg_match('/\bas\s+(AUTH|hash_equals)\b/i', $statement)) {
                    $problems[] = 'imports a name the guard is read by: use ' . trim($statement);
                }
            }
        }
        return $problems;
    }

    public function testEveryAccountFileThatReadsAPostChecksTheToken(): void
    {
        $missing = [];
        $checked = 0;
        foreach (self::accountFiles() as $relative) {
            $source = (string) file_get_contents(self::root() . '/' . $relative);
            $tokens = self::tokens($source);
            if (!self::readsPost($tokens)) {
                continue;
            }
            if (in_array($relative, self::KNOWN_UNGUARDED, true)) {
                continue;
            }
            $checked++;
            foreach (self::rebindings($tokens) as $problem) {
                $missing[] = "$relative $problem";
            }
            if (self::guardCalls($tokens) === []) {
                $missing[] = "$relative reads \$_POST or \$_REQUEST and never calls AUTH::check_csrf_token()";
            }
        }

        $this->assertGreaterThanOrEqual(10, $checked, 'far fewer Account files read a POST than expected; the scan is broken');
        $this->assertSame([], $missing, "Account files that take a POST without checking the session token:\n  "
            . implode("\n  ", $missing)
            . "\nCheck it with AUTH::check_csrf_token() before the write, and put p202_account_token_field() in the form.");
    }

    /**
     * The attribution dashboard (PR 10) takes eight kinds of POST — models
     * and exports — through one handler, so this pins the shape that handler
     * relies on, beyond the family floor above: the POST block's first
     * statement is the token check, no $_POST value is read anywhere in the
     * file before it, and a refusal says the guard's sentence and leaves
     * (p202_account_redirect() is `never`). tests/live/mta-ui.sh posts a
     * forged token to a model form and an export form and reads that nothing
     * was written.
     */
    public function testTheAttributionDashboardChecksTheTokenBeforeItReadsAPost(): void
    {
        $relative = '202-account/attribution.php';
        $tokens = self::tokens((string) file_get_contents(self::root() . '/' . $relative));
        $texts = array_map(static fn (array $t): string => $t[1], $tokens);
        $count = count($texts);

        // if ( $_SERVER [ 'REQUEST_METHOD' ] === 'POST' ) {
        $block = null;
        for ($i = 0; $i < $count - 9; $i++) {
            if (array_slice($texts, $i, 10) === ['if', '(', '$_SERVER', '[', "'REQUEST_METHOD'", ']', '===', "'POST'", ')', '{']) {
                $block = $i + 10;
                break;
            }
        }
        $this->assertNotNull($block, "$relative has no `if (\$_SERVER['REQUEST_METHOD'] === 'POST') {` block");
        $this->assertSame(['if', '(', '!', 'AUTH', '::', 'check_csrf_token', '(', ')', ')', '{'], array_slice($texts, $block, 10),
            'the first statement in the POST block is the token check');

        $firstPost = null;
        foreach ($tokens as $i => [$id, $text]) {
            if ($id === T_VARIABLE && ($text === '$_POST' || $text === '$_REQUEST')) {
                $firstPost = $i;
                break;
            }
        }
        $this->assertNotNull($firstPost, "$relative reads no POST at all; the scan is broken");
        $this->assertGreaterThan($block, $firstPost, 'no POST value is read before the token check');

        // The refusal branch: the guard's sentence, then the redirect, and nothing else.
        $depth = 0;
        $branch = [];
        for ($j = $block + 9; $j < $count; $j++) {
            $branch[] = $texts[$j];
            if ($texts[$j] === '{') {
                $depth++;
            } elseif ($texts[$j] === '}' && --$depth === 0) {
                break;
            }
        }
        $this->assertSame('p202_account_flash', $branch[1] ?? null, 'the refusal says so first');
        $this->assertContains('P202_ACCOUNT_TOKEN_REFUSED', $branch, 'in the guard\'s own sentence');
        $this->assertContains('p202_account_redirect', $branch, 'and leaves');
        $this->assertNotContains('$_POST', $branch, 'reading nothing that was posted');
    }

    public function testTheKnownListHasNoStaleEntries(): void
    {
        foreach (self::KNOWN_UNGUARDED as $relative) {
            $path = self::root() . '/' . $relative;
            $this->assertFileExists($path, "$relative is gone; remove it from KNOWN_UNGUARDED.");
            $tokens = self::tokens((string) file_get_contents($path));
            $this->assertTrue(self::readsPost($tokens), "$relative no longer reads a POST; remove it from KNOWN_UNGUARDED.");
            $this->assertSame([], self::guardCalls($tokens), "$relative checks the token now; remove it from KNOWN_UNGUARDED.");
        }
    }

    /**
     * Every form that posts carries the token inside its own bounds.
     *
     * Read from the source: from each `<form` whose tag says method="post" to
     * the next `</form>`, outside HTML comments. A form assembled in a PHP
     * string would be invisible here, so a `<form` inside a PHP string is
     * refused by name rather than passed.
     */
    public function testEveryPostFormCarriesTheToken(): void
    {
        $problems = [];
        $forms = 0;
        foreach (self::accountFiles() as $relative) {
            $source = (string) file_get_contents(self::root() . '/' . $relative);
            foreach (\PhpToken::tokenize($source) as $token) {
                if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) && stripos($token->text, '<form') !== false) {
                    $problems[] = "$relative line {$token->line}: a <form> built in a PHP string cannot be read by this test";
                }
            }
            // PHP comments describe forms too ("one <form id=...> per group"),
            // and a regex that starts in a comment swallows the real form.
            $markup = '';
            foreach (\PhpToken::tokenize($source) as $token) {
                if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                    $markup .= $token->text;
                }
            }
            $markup = (string) preg_replace('/<!--.*?-->/s', '', $markup);
            // An echo block inside the tag (action="…") ends in a question
            // mark and a bracket that do not end the tag; read past it, or a
            // method="post" after it goes unread and the form is skipped.
            if (!preg_match_all('/<form\b((?:<\?php.*?\?>|[^>])*)>(.*?)<\/form>/is', $markup, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                if (!preg_match('/\bmethod\s*=\s*["\']?post\b/i', $match[1])) {
                    continue;
                }
                $forms++;
                $body = $match[2];
                $carries = preg_match('/\bp202_account_token_field\(\s*\)/', $body)
                    || preg_match('/<input\b[^>]*type\s*=\s*["\']hidden["\'][^>]*name\s*=\s*["\']token["\']/i', $body)
                    || preg_match('/<input\b[^>]*name\s*=\s*["\']token["\'][^>]*type\s*=\s*["\']hidden["\']/i', $body);
                if (!$carries) {
                    $problems[] = "$relative: a POST form without the token: " . trim(substr($match[0], 0, 120));
                }
            }
        }

        $this->assertGreaterThanOrEqual(15, $forms, 'far fewer POST forms than the Account pages render; the scan is broken');
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * Account › ClickServers' switches post to
     * 202-config/clickserver_api_management.php, outside 202-account/ and so
     * outside the scan above. It checked login and the token, and nothing
     * else: not the access_to_clickservers permission the page requires, and
     * not whose key or domain it was handed — the posted api_key was used as
     * sent (#165, #173). The decision is a pure function, so it is executed
     * here for each refusal and for the one request it lets through.
     */
    public function testTheClickServerSwitchChecksTokenPermissionKeyAndDomain(): void
    {
        require_once self::root() . '/202-config/clickserver_api_management.php';
        $mine = static fn (string $key): array => $key === 'my-key'
            ? [['clickserver' => ['domain' => 'mine.example', 'status' => '1']]]
            : [['clickserver' => ['domain' => 'theirs.example', 'status' => '1']]];
        $cases = [
            'no token' => [[false, true, 'my-key', 'mine.example', 'deactivate'], 403],
            'no permission' => [[true, false, 'my-key', 'mine.example', 'deactivate'], 403],
            'an unknown method' => [[true, true, 'my-key', 'mine.example', 'drop'], 400],
            'no key on file' => [[true, true, '', 'mine.example', 'deactivate'], 409],
            'another account\'s domain' => [[true, true, 'my-key', 'theirs.example', 'deactivate'], 404],
            'no domain' => [[true, true, 'my-key', '', 'deactivate'], 400],
        ];
        foreach ($cases as $name => [$args, $status]) {
            $refusal = p202_clickserver_switch_refusal(...[...$args, $mine]);
            $this->assertIsArray($refusal, "$name is refused");
            $this->assertSame($status, $refusal[0], "$name is answered $status");
            $this->assertNotSame('', $refusal[1], "$name says why");
        }
        $this->assertNull(p202_clickserver_switch_refusal(true, true, 'my-key', 'mine.example', 'deactivate', $mine), 'this account\'s own domain goes ahead');
        $this->assertSame(502, p202_clickserver_switch_refusal(true, true, 'my-key', 'mine.example', 'activate', static fn (): mixed => null)[0] ?? null,
            'a licence service that cannot answer is not "your domain"');

        // And the endpoint decides with the stored key, the session token and
        // the permission, and acts with the stored key: a posted api_key is
        // never read.
        $source = (string) file_get_contents(self::root() . '/202-config/clickserver_api_management.php');
        $code = (string) preg_replace('/\s+/', '', implode('', array_map(static fn (\PhpToken $t): string => $t->is([T_COMMENT, T_DOC_COMMENT]) ? '' : $t->text, \PhpToken::tokenize($source))));
        $this->assertStringNotContainsString("\$_POST['api_key']", $code, 'the posted key is not read');
        $this->assertStringContainsString("\$storedKey=p202_clickserver_stored_key(\$db,(int)(\$_SESSION['user_id']??0));\$refusal=p202_clickserver_switch_refusal(hash_equals((string)(\$_SESSION['token']??''),(string)(\$_POST['token']??'')),isset(\$userObj)&&is_object(\$userObj)&&\$userObj->hasPermission('access_to_clickservers'),\$storedKey,", $code,
            'the endpoint decides with the session token, the permission and the stored key');
        $this->assertStringContainsString("if(\$refusal!==null){http_response_code(\$refusal[0]);echo\$refusal[1];return;}if(clickserver_api_domain_act_deact(\$storedKey,", $code,
            'a refusal ends the request before the switch, which uses the stored key');
        $this->assertSame(1, substr_count($code, 'clickserver_api_domain_act_deact(') - substr_count($code, 'functionclickserver_api_domain_act_deact('), 'one switch call');
    }
}
