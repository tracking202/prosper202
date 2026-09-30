<?php

declare(strict_types=1);

namespace Tests\Standalone;

use PHPUnit\Framework\TestCase;

/**
 * Every session cookie of the install decides its Secure flag from one
 * answer, p202_request_is_https() (202-config/request-https.php), which
 * reads a TLS-terminating proxy's headers.
 *
 * The setup wizard runs before 202-config.php exists, so it cannot load
 * connect.php and started its own session — with a Secure flag read from
 * $_SERVER['HTTPS'] alone. Behind a proxy that terminates TLS that is empty,
 * so the wizard's cookie went out without Secure while every other page's
 * had it (Codex on #169; error pattern #5). This pins the helper's answers,
 * runs the wizard's session start in a fresh process behind a proxy, and
 * walks the tree so a new session cookie cannot decide the flag another way.
 */
final class SessionCookieSecureTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function setUpBeforeClass(): void
    {
        require_once self::root() . '/202-config/request-https.php';
    }

    /** @return array<string, array{array<string, mixed>, bool}> */
    public static function requests(): array
    {
        return [
            'plain http' => [['SERVER_PORT' => '80'], false],
            'nothing at all' => [[], false],
            'direct TLS' => [['HTTPS' => 'on'], true],
            'direct TLS, upper case off' => [['HTTPS' => 'OFF'], false],
            'HTTPS empty' => [['HTTPS' => ''], false],
            'proxy: X-Forwarded-Proto' => [['HTTP_X_FORWARDED_PROTO' => 'https', 'SERVER_PORT' => '80'], true],
            'proxy: X-Forwarded-Proto upper case' => [['HTTP_X_FORWARDED_PROTO' => 'HTTPS'], true],
            'proxy: X-Forwarded-Proto http' => [['HTTP_X_FORWARDED_PROTO' => 'http'], false],
            'proxy: X-Forwarded-SSL' => [['HTTP_X_FORWARDED_SSL' => 'on'], true],
            'proxy: X-Forwarded-Port' => [['HTTP_X_FORWARDED_PORT' => '443'], true],
            'port 443' => [['SERVER_PORT' => '443'], true],
            'request scheme' => [['REQUEST_SCHEME' => 'https'], true],
            'a header sent as an array' => [['HTTP_X_FORWARDED_PROTO' => ['https']], false],
        ];
    }

    /**
     * @dataProvider requests
     * @param array<string, mixed> $server
     */
    public function testTheAnswer(array $server, bool $https): void
    {
        self::assertSame($https, p202_request_is_https($server));
    }

    /**
     * The wizard's own session start, executed: a fresh PHP process (no
     * output yet, so the session can start) behind a proxy that terminated
     * TLS. The cookie it would send carries Secure.
     */
    public function testTheWizardsCookieIsSecureBehindAProxy(): void
    {
        $saved = sys_get_temp_dir() . '/p202-wizard-session-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($saved), 'a session directory for the probe');
        try {
            foreach (['https' => '1', 'http' => '0'] as $proto => $secure) {
                $script = '$_SERVER["HTTP_X_FORWARDED_PROTO"] = ' . var_export($proto, true) . ';'
                    . '$_SERVER["SERVER_PORT"] = "80";'
                    . 'require ' . var_export(self::root() . '/202-config/functions-standalone-ui.php', true) . ';'
                    . '$t = p202_standalone_wizard_token();'
                    . 'echo json_encode(["token" => $t !== "", "secure" => session_get_cookie_params()["secure"]]);';
                $out = [];
                $rc = 0;
                exec(escapeshellarg(PHP_BINARY) . ' -d session.save_path=' . escapeshellarg($saved)
                    . ' -d display_errors=stderr -r ' . escapeshellarg($script) . ' 2>&1', $out, $rc);
                self::assertSame(0, $rc, implode("\n", $out));
                $result = json_decode((string) end($out), true);
                self::assertIsArray($result, 'the probe answered: ' . implode("\n", $out));
                self::assertTrue($result['token'], 'the session started and minted a token (' . $proto . ')');
                self::assertSame($secure === '1', $result['secure'], 'X-Forwarded-Proto: ' . $proto);
            }
        } finally {
            array_map('unlink', glob($saved . '/*') ?: []);
            rmdir($saved);
        }
    }

    /**
     * Every place the tree sets session.cookie_secure hands it
     * p202_request_is_https($_SERVER), directly or through one variable
     * assigned exactly that in the same file; and nothing sets the session
     * cookie's parameters another way.
     */
    public function testEverySessionCookieAsksTheSameQuestion(): void
    {
        $sites = [];
        $problems = [];
        foreach (self::sourceFiles() as $relative => $source) {
            $tokens = self::tokens($source);
            foreach ($tokens as $i => $t) {
                $where = $relative . ':' . $t->line;
                if ($t->is(T_STRING) && strtolower($t->text) === 'session_set_cookie_params') {
                    $problems[] = $where . ' sets the session cookie with session_set_cookie_params();'
                        . " use ini_set('session.cookie_secure', p202_request_is_https(\$_SERVER) ? '1' : '0')";
                }
                if (!$t->is(T_CONSTANT_ENCAPSED_STRING) || strtolower(trim($t->text, '\'"')) !== 'session.cookie_secure') {
                    continue;
                }
                $call = $tokens[$i - 2] ?? null;
                $isIniSet = $call !== null && $call->is(T_STRING) && strtolower($call->text) === 'ini_set'
                    && ($tokens[$i - 1]->text ?? '') === '(' && ($tokens[$i + 1]->text ?? '') === ',';
                if (!$isIniSet) {
                    $problems[] = $where . ' names session.cookie_secure outside ini_set(name, value)';
                    continue;
                }
                $sites[] = $relative;
                $value = self::argumentAt($tokens, $i + 2);
                if (!self::asksTheHelper($value, $source)) {
                    $problems[] = $where . " sets session.cookie_secure from `$value`, not p202_request_is_https(\$_SERVER)";
                }
            }
        }
        self::assertSame([], $problems, implode("\n", $problems));
        sort($sites);
        self::assertSame(
            ['202-config/connect.php', '202-config/functions-standalone-ui.php'],
            $sites,
            'the two session starts that set the flag were both read'
        );
    }

    /** @return iterable<string, string> relative path => source, for the PHP files outside vendor and tests */
    private static function sourceFiles(): iterable
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::root(), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen(self::root()) + 1);
            if (!str_ends_with($relative, '.php') || preg_match('#^(vendor|tests|node_modules|\.git|\.claude)/#', $relative)) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'cookie_secure') || str_contains($source, 'session_set_cookie_params')) {
                yield $relative => $source;
            }
        }
    }

    /** @return list<\PhpToken> */
    private static function tokens(string $source): array
    {
        $tokens = \PhpToken::tokenize($source);
        return array_values(array_filter($tokens, static fn (\PhpToken $t): bool => !$t->isIgnorable()));
    }

    /**
     * The text of a call's last argument, from $start to the call's closing
     * parenthesis, without whitespace or comments.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function argumentAt(array $tokens, int $start): string
    {
        $depth = 0;
        $value = '';
        for ($j = $start; $j < count($tokens); $j++) {
            $text = $tokens[$j]->text;
            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            }
            $value .= $text;
        }
        return $value;
    }

    /**
     * The value is p202_request_is_https($_SERVER) ? '1' : '0', or a variable
     * in its place that the file assigns exactly once, from exactly that call,
     * and never binds by reference.
     */
    private static function asksTheHelper(string $value, string $source): bool
    {
        if ($value === "p202_request_is_https(\$_SERVER)?'1':'0'") {
            return true;
        }
        if (preg_match('/^(\$\w+)\?\'1\':\'0\'$/', $value, $m) !== 1) {
            return false;
        }
        $name = preg_quote($m[1], '/');
        $writes = preg_match_all('/' . $name . '\s*(?:[.+\-*\/%&|^?]|\?\?|<<|>>)?=(?!=)/', $source);
        $exact = preg_match_all('/' . $name . '\s*=\s*p202_request_is_https\(\$_SERVER\);/', $source);
        return $writes === 1 && $exact === 1 && !str_contains($source, '&' . $m[1]) && !str_contains($source, '=&');
    }
}
