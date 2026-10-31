<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Every way a page request becomes signed in asks whether the session's user
 * may still act (UserStanding), and no page reads a session that has not been
 * asked.
 *
 * The sign-in, the remember-me cookie and the API keys asked for an active
 * user that was not deleted; a session already signed in was never asked
 * again, so a user switched off or removed kept every page for as long as they
 * kept using the browser (CLAUDE.md #5, "Turning a user off is the same
 * sweep": the session was the path that sweep did not list). Three ways in,
 * each held here:
 *
 *  - The predicate. AUTH::logged_in() — which logged_in_with_remember(),
 *    require_user(), the chat and analytics gate, the report dispatcher and
 *    health.php all end in — reads the row itself when connect.php read none
 *    for this user; SessionEndsWithUserIntegrationTest executes it, and
 *    AuthClassTest the fold that reads nothing twice.
 *  - A page that reads $_SESSION['user_id'] as proof, or shows the chrome
 *    for it, without asking the predicate. connect.php ends a session whose
 *    user may not act right after it reads the user for the role, before any
 *    page code runs, so no page reads that session's identity; and a session
 *    is opened only there, after it, or by the setup wizard, which runs
 *    before any account exists. Both are held here, by token order. The
 *    remember-me cookie signs a browser in in connect.php too, once, before
 *    that read, in the same block, so the User the check asks and every page
 *    uses is the signed-in user's on the cookie's first request as well:
 *    tried by the page, after the read, it ran that request with no roles.
 *  - A new sign-in. The session's identity is written only by
 *    AUTH::begin_user_session(), called only from the password sign-in and
 *    the remember-me cookie, whose reads ask the same rule;
 *    SessionEndsWithUserIntegrationTest proves the password, the cookie and
 *    the session agree on every value of the two columns.
 *
 * What this does not see, stated so a green run is not read as more: an
 * identity key written by passing it by reference to a function
 * (`settype($_SESSION['user_id'], …)` reads, to a token scan, as any call
 * that reads it); and a page that opens no session and reads one through
 * another name (a custom save handler's own store). Each scan is run on
 * planted sources below, and every planted shape is refused.
 */
final class SessionSignInPathsTest extends TestCase
{
    private const CONNECT = '202-config/connect.php';

    /** The keys that say who the session is signed in as. */
    private const IDENTITY = ['user_id', 'user_own_id', 'user_name', 'session_fingerprint'];

    /**
     * The generation a session began at (SessionGeneration): written by the
     * sign-in, and by AUTH::keepSessionAfterPasswordChange() for the browser
     * that changed its own password. Written anywhere else, a stale session
     * could be given the current one and outlive the change.
     */
    private const GENERATION = 'user_session_generation';

    /**
     * Files that open a session other than connect.php or after it, each with why
     * no session's identity is read there unchecked.
     */
    private const OTHER_OPENERS = [
        '202-config/functions-standalone-ui.php' => 'the setup wizard\'s own session, opened by'
            . ' p202_standalone_wizard_token() where connect.php cannot load (no 202-config.php yet, so no account'
            . ' to be signed in as): it holds the wizard\'s token and nothing else',
    ];

    /** Where a sign-in begins a session, each after a read that asks UserStanding's rule. */
    private const SIGN_INS = [
        '202-login.php' => 'the password sign-in, after AUTH::authenticate() (LOGIN_SELECT)',
        '202-config/functions-auth.php' => 'the remember-me cookie, after its read of 202_auth_keys and 202_users',
    ];

    public function testConnectEndsADeadSessionBeforeAnyPageReadsIt(): void
    {
        $files = SourceScan::phpFiles();
        self::assertArrayHasKey(self::CONNECT, $files);
        self::assertSame([], self::scanConnect($files[self::CONNECT]));
    }

    /**
     * The connect.php scan, on connect.php with each way of losing the check planted.
     *
     * @dataProvider connectPlants
     */
    public function testTheConnectScanRefusesEveryPlantedBypass(string $find, string $replace, string $why): void
    {
        $source = SourceScan::phpFiles()[self::CONNECT];
        self::assertSame(1, substr_count($source, $find), "the plant's anchor is in connect.php once: $find");
        $planted = str_replace($find, $replace, $source);
        self::assertNotSame($source, $planted, 'the plant landed');
        self::assertNotSame([], self::scanConnect($planted), "a planted bypass passed: $why");
    }

    /** @return array<string, array{string, string, string}> */
    public static function connectPlants(): array
    {
        $call = 'AUTH::endSessionIfItsUserCannotAct($userObj);';
        $cookie = "    AUTH::remember_me_on_logged_out();\n";
        $read = "    \$userObj = new User(\$_SESSION['user_own_id'] ?? null);\n";

        return [
            'the call removed' => [$call, '', 'nothing ends the session'],
            'the call commented out' => [$call, '// ' . $call, 'a comment is not a call'],
            'the call behind a condition' => [
                $call,
                'if ($navigation[1] != "tracking202") { ' . $call . ' }',
                'skipped on some pages',
            ],
            'the call in an expression' => [$call, '$checked = $_is_ajax || ' . $call, 'short-circuited for AJAX'],
            'another argument' => [
                $call,
                'AUTH::endSessionIfItsUserCannotAct(null);',
                'the User read is not the one asked',
            ],
            'another class' => [
                $call,
                'MyAUTH::endSessionIfItsUserCannotAct($userObj);',
                'a name is not a call site',
            ],
            'an exit before it' => [$call, 'if ($_is_ajax) { exit; } ' . $call, 'an AJAX request leaves first'],
            'the user read after it' => [
                "    \$userObj = new User(\$_SESSION['user_own_id'] ?? null);\n",
                "    \$userObj = null;\n",
                'no row was read before the call',
            ],
            'the identity read before the call' => [
                "// Skip user initialization during installation\n",
                "\$signedInAs = \$_SESSION['user_id'] ?? 0;\n// Skip user initialization during installation\n",
                'connect.php acts on the session before it is asked',
            ],
            'the whole session read before the call' => [
                "// Skip user initialization during installation\n",
                "\$copy = \$_SESSION;\n// Skip user initialization during installation\n",
                'a copy of the session taken before it is asked',
            ],
            'the user replaced after it' => [
                $call,
                $call . ' $userObj = new User(1);',
                'the object asked is not the one the pages use',
            ],
            'a goto in the file' => [$call, $call . ' goto done; done:', 'a goto can jump over the call'],
            'the cookie tried after the User read' => [
                $cookie . $read,
                $read . $cookie,
                'the first request after the cookie uses a User read for nobody',
            ],
            'the cookie not tried' => [$cookie, '', 'the page tries it, after the User read'],
            'the cookie tried again after the check' => [
                $call,
                $call . ' AUTH::remember_me_on_logged_out();',
                'a second sign-in after the User was read',
            ],
            'the cookie behind a condition' => [
                $cookie,
                '    if ($navigation[1] == "tracking202") { AUTH::remember_me_on_logged_out(); }' . "\n",
                'skipped on some pages',
            ],
            'the cookie short-circuited' => [
                $cookie,
                '    $_is_ajax || AUTH::remember_me_on_logged_out();' . "\n",
                'skipped for AJAX',
            ],
            'the cookie by another class' => [
                $cookie,
                '    MyAUTH::remember_me_on_logged_out();' . "\n",
                'a name is not a call site',
            ],
            'an exit between the cookie and the User read' => [
                $cookie,
                $cookie . '    if ($_is_ajax) { exit; }' . "\n",
                'an AJAX request leaves before the User is read',
            ],
        ];
    }

    public function testEverySessionIsOpenedByTheBootstrapOrAfterIt(): void
    {
        $files = SourceScan::phpFiles();
        self::assertGreaterThan(500, count($files), 'the walk read the served tree');
        $unchecked = [];
        $openers = [];
        foreach ($files as $path => $source) {
            $calls = self::sessionStarts($source);
            if ($calls === []) {
                continue;
            }
            $openers[] = $path;
            if ($path === self::CONNECT || array_key_exists($path, self::OTHER_OPENERS)) {
                continue;
            }
            foreach (self::openedBeforeConnect($source) as $line) {
                $unchecked[] = "$path:$line";
            }
        }
        self::assertContains(self::CONNECT, $openers, 'the scan found the bootstrap\'s session_start()');
        self::assertGreaterThanOrEqual(4, count($openers), 'the scan found the openers there are');
        self::assertSame([], $unchecked, "These open a session that connect.php has not checked first:\n  "
            . implode("\n  ", $unchecked)
            . "\nInclude 202-config/connect.php at the top of the file, as a statement of its own, before"
            . ' session_start(); a session opened anywhere else is read without asking whether its user may still'
            . ' act.');
        foreach (self::OTHER_OPENERS as $path => $why) {
            self::assertContains($path, $openers, "$path no longer opens a session: remove it from OTHER_OPENERS");
        }
    }

    /**
     * What the opener scan accepts and refuses, executed.
     *
     * @dataProvider openerShapes
     */
    public function testTheOpenerScanReadsIncludesAndCallsAsTheyRun(string $code, bool $refused): void
    {
        $source = "<?php\n" . $code . "\n";
        self::assertSame($refused, self::openedBeforeConnect($source) !== [], $code);
    }

    /** @return array<string, array{string, bool}> */
    public static function openerShapes(): array
    {
        $connect = "include_once(str_repeat('../', 2) . '202-config/connect.php');";

        return [
            'connect.php first' => [$connect . ' AUTH::require_user(); session_start();', false],
            'require_once __DIR__' => ["require_once __DIR__ . '/../202-config/connect.php'; session_start();", false],
            'no connect.php' => ['session_start();', true],
            'connect.php after the call' => ['session_start(); ' . $connect, true],
            'connect.php behind a condition' => ['if ($x) { ' . $connect . ' } session_start();', true],
            'connect.php in a function' => [
                "function boot() { include_once '202-config/connect.php'; } boot(); session_start();",
                true,
            ],
            'another connect.php' => ["include_once 'tracking202/connect.php'; session_start();", true],
            'a fully qualified call' => ['\\session_start();', true],
            'a call in another case' => ['Session_Start();', true],
            'a method of the name' => ['$s->session_start();', false],
            'the name in a comment' => ['// session_start();', false],
        ];
    }

    public function testTheSessionsIdentityIsWrittenOnlyBySignIn(): void
    {
        $files = SourceScan::phpFiles();
        $writes = [];
        $callers = [];
        foreach ($files as $path => $source) {
            foreach (self::identityWrites($source) as $line) {
                $writes[] = "$path:$line";
            }
            if (self::callsBeginUserSession($source)) {
                $callers[] = $path;
            }
        }
        self::assertSame([], $writes, "These write who the session is signed in as outside"
            . " AUTH::begin_user_session():\n  "
            . implode("\n  ", $writes)
            . "\nA sign-in goes through AUTH::begin_user_session(), after a read that asks UserStanding's rule.");
        sort($callers);
        $known = array_keys(self::SIGN_INS);
        sort($known);
        self::assertSame($known, $callers, 'The places that begin a session changed. A new one reads the user with'
            . ' `user_active = 1 AND user_deleted = 0` first (UserStanding), and goes in SIGN_INS.');
    }

    /** The two sign-in reads ask the rule; SessionEndsWithUserIntegrationTest executes the agreement. */
    public function testBothSignInReadsAskTheRule(): void
    {
        $auth = SourceScan::phpFiles()['202-config/functions-auth.php'];
        self::assertMatchesRegularExpression(
            '/LOGIN_SELECT = \'[^\']*\bu\.user_deleted = 0 AND u\.user_active = 1\b[^\']*\';/',
            $auth
        );
        self::assertMatchesRegularExpression('/2u\.user_deleted = 0\s+AND\s+2u\.user_active = 1/', $auth);
    }

    /**
     * What the identity-write scan accepts and refuses, executed.
     *
     * @dataProvider writeShapes
     */
    public function testTheWriteScanRefusesEveryWayToWriteTheIdentity(string $code, bool $refused): void
    {
        self::assertSame($refused, self::identityWrites("<?php\n" . $code . "\n") !== [], $code);
    }

    /** @return array<string, array{string, bool}> */
    public static function writeShapes(): array
    {
        return [
            'a read' => ["\$id = (int) \$_SESSION['user_id'];", false],
            'a comparison' => ["if (\$_SESSION['user_id'] == 1) {}", false],
            'a non-identity write' => ["\$_SESSION['toolbar'] = 'true';", false],
            'unset' => ["unset(\$_SESSION['user_id']);", false],
            'clearing the session' => ['$_SESSION = [];', false],
            'begin_user_session itself' => [
                "class AUTH { public static function begin_user_session(array \$r): void {"
                . " \$_SESSION['user_id'] = 1; } }",
                false,
            ],
            'an assignment' => ["\$_SESSION['user_id'] = 5;", true],
            'a double-quoted key' => ["\$_SESSION[\"user_own_id\"] = 5;", true],
            'a coalescing assignment' => ["\$_SESSION['user_name'] ??= 'x';", true],
            'a compound assignment' => ["\$_SESSION['user_id'] += 1;", true],
            'an increment' => ["\$_SESSION['user_id']++;", true],
            'a pre-decrement' => ["--\$_SESSION['user_id'];", true],
            'a nested element' => ["\$_SESSION['session_fingerprint'][0] = 'x';", true],
            'a foreach target' => ["foreach (\$rows as \$_SESSION['user_id']) {}", true],
            'a foreach target after a key' => ["foreach (\$rows as \$k => \$_SESSION['user_own_id']) {}", true],
            'a destructuring target' => ["[\$_SESSION['user_id']] = [5];", true],
            'a keyed destructuring target' => ["['id' => \$_SESSION['user_id']] = \$row;", true],
            'a nested destructuring target' => ["[\$a, [\$_SESSION['user_name']]] = \$rows;", true],
            'a list() target' => ["list(\$a, \$_SESSION['user_id']) = \$row;", true],
            'in an array that is read' => ["\$copy = [\$_SESSION['user_id'], 1];", false],
            'a call argument' => ["\$id = intval(\$_SESSION['user_id']);", false],
            'AUTH\'s writer, handed another key' => [
                self::writer("self::writeSessionValue('toolbar', 'true');"),
                false,
            ],
            'AUTH\'s writer, handed an identity key' => [self::writer("self::writeSessionValue('user_id', 5);"), true],
            'AUTH\'s writer, handed a key that cannot be read' => [
                self::writer('self::writeSessionValue($k, 5);'),
                true,
            ],
            'AUTH\'s writer, called past writeSessionValue()' => [
                self::writer("self::updateSession(['toolbar' => 1]);"),
                true,
            ],
            'a dynamic key' => ["\$_SESSION[\$key] = 5;", true],
            'the whole session' => ["\$_SESSION = ['user_id' => 5];", true],
            'an alias' => ['$s = &$_SESSION;', true],
            'an alias the other way' => ['$_SESSION =& $s;', true],
            'by $GLOBALS' => ["\$GLOBALS['_SESSION']['user_id'] = 5;", true],
            'another method of the name' => [
                "class X { function begin_user_session() { \$_SESSION['user_id'] = 1; } }",
                true,
            ],
            'the generation, by a page' => ["\$_SESSION['user_session_generation'] = 7;", true],
            'the generation, counted up by a page' => ["\$_SESSION['user_session_generation']++;", true],
            'the generation, by begin_user_session' => [
                "class AUTH { public static function begin_user_session(array \$r): void {"
                . " \$_SESSION['user_session_generation'] = 1; } }",
                false,
            ],
            'the generation, by keepSessionAfterPasswordChange' => [
                "class AUTH { public static function keepSessionAfterPasswordChange(int \$g): void {"
                . " \$w = static function () use (\$g): void { \$_SESSION['user_session_generation'] = \$g; }; } }",
                false,
            ],
            'the generation, by another method of AUTH' => [
                "class AUTH { public static function other(int \$g): void {"
                . " \$_SESSION['user_session_generation'] = \$g; } }",
                true,
            ],
            'the generation, handed to AUTH\'s writer' => [
                self::writer("self::writeSessionValue('user_session_generation', 5);"),
                true,
            ],
        ];
    }

    /** AUTH's session writer as functions-auth.php has it, and one more method calling $call. */
    private static function writer(string $call): string
    {
        return 'class AUTH {'
            . ' private static function updateSession(array $values): void {'
            . ' foreach ($values as $key => $value) { $_SESSION[$key] = $value; } }'
            . ' private static function writeSessionValue(string $key, $value): void {'
            . ' self::updateSession([$key => $value]); }'
            . ' public static function caller(): void { ' . $call . ' } }';
    }

    /**
     * Problems with connect.php's check: it runs once, as a statement of its
     * own at the top level, on the User read just before, with nothing
     * between that leaves, and nothing before it reads who the session is.
     *
     * @return list<string>
     */
    private static function scanConnect(string $source): array
    {
        $t = self::tokens($source);
        $depth = self::depths($t);
        $problems = [];
        foreach ($t as $x) {
            if (is_array($x) && $x[0] === T_GOTO) {
                $problems[] = 'a goto in connect.php: it can jump over the check';
            }
        }

        $calls = [];
        foreach ($t as $i => $x) {
            if (
                is_array($x) && $x[0] === T_STRING && strcasecmp($x[1], 'endSessionIfItsUserCannotAct') === 0
                && self::text($t[$i - 1] ?? '') === '::'
                && in_array(self::text($t[$i - 2] ?? ''), ['AUTH', '\\AUTH'], true)
                && self::text($t[$i + 1] ?? '') === '('
            ) {
                $calls[] = $i - 2;
            }
        }
        if (count($calls) !== 1) {
            $problems[] = 'AUTH::endSessionIfItsUserCannotAct() is called ' . count($calls) . ' times, not once';

            return $problems;
        }
        $at = $calls[0];
        if ($depth[$at] !== 0) {
            $problems[] = 'the check is inside a block: it may not run';
        }
        if (!self::isBoundary($t[$at - 1] ?? null)) {
            $problems[] = 'the check is not a statement of its own';
        }
        $args = array_map([self::class, 'text'], array_slice($t, $at + 3, 3));
        if ($args !== ['(', '$userObj', ')'] || self::text($t[$at + 6] ?? '') !== ';') {
            $problems[] = 'the check is not handed $userObj, the User connect.php just read';
        }

        $read = null;
        foreach ($t as $i => $x) {
            $class = ltrim(self::text($t[$i + 1] ?? ''), '\\');
            if (is_array($x) && $x[0] === T_NEW && strcasecmp($class, 'User') === 0) {
                $read ??= $i;
            }
        }
        if ($read === null || $read > $at) {
            $problems[] = 'no `new User(...)` reads the session\'s user before the check';
            $read = $at;
        }
        $problems = [...$problems, ...self::cookieSignInBefore($t, $depth, $read)];
        $readArgs = [$read, self::closing($t, $read + 2)];
        for ($i = $read; $i < $at; $i++) {
            if (is_array($t[$i]) && in_array($t[$i][0], [T_EXIT, T_RETURN, T_THROW], true)) {
                $problems[] = 'something between the User read and the check leaves: ' . $t[$i][1];
            }
        }
        foreach ($t as $i => $x) {
            $assigned = self::text($t[$i + 1] ?? '') === '=';
            if ($i > $at && is_array($x) && $x[0] === T_VARIABLE && $x[1] === '$userObj' && $assigned) {
                $problems[] = '$userObj is replaced after the check';
            }
            if (
                $i < $at && is_array($x) && $x[0] === T_VARIABLE && $x[1] === '$_SESSION'
                && !($i > $readArgs[0] && $i < $readArgs[1])
            ) {
                $key = self::literalKey($t, $i);
                if ($key === null && self::text($t[$i + 1] ?? '') !== '[') {
                    $problems[] = 'the whole session is read before the check';
                } elseif ($key === null || in_array($key, self::IDENTITY, true)) {
                    $problems[] = 'who the session is signed in as is read before the check: $_SESSION['
                        . ($key ?? '?') . ']';
                }
            }
        }

        return $problems;
    }

    /**
     * Problems with the remember-me cookie's sign-in in connect.php: it is
     * tried once, as a statement of its own, before the User is read ($read,
     * the `new` of `new User`), in the same block, with nothing between that
     * leaves. Tried after the read, or not at all there (the page tried it),
     * the User every page uses was read for nobody on the cookie's first
     * request, and it ran with no roles.
     *
     * @param list<string|array{int, string, int}> $t
     * @param list<int> $depth
     * @return list<string>
     */
    private static function cookieSignInBefore(array $t, array $depth, int $read): array
    {
        $calls = [];
        foreach ($t as $i => $x) {
            if (
                is_array($x) && $x[0] === T_STRING && strcasecmp($x[1], 'remember_me_on_logged_out') === 0
                && self::text($t[$i - 1] ?? '') === '::'
                && in_array(self::text($t[$i - 2] ?? ''), ['AUTH', '\\AUTH'], true)
                && self::text($t[$i + 1] ?? '') === '('
            ) {
                $calls[] = $i - 2;
            }
        }
        if (count($calls) !== 1) {
            return ['the remember-me cookie is tried ' . count($calls) . ' times in connect.php, not once,'
                . ' before the User is read'];
        }
        $at = $calls[0];
        if ($at > $read) {
            return ['the remember-me cookie is tried after the User is read: on its first request every page'
                . ' would use a User read for nobody, with no roles'];
        }
        $problems = [];
        if (!self::isBoundary($t[$at - 1] ?? null) || self::text($t[self::closing($t, $at + 3) + 1] ?? '') !== ';') {
            $problems[] = 'the remember-me cookie is not tried as a statement of its own';
        }
        for ($i = $at; $i < $read; $i++) {
            $s = self::text($t[$i]);
            if ($s === '{' || $s === '}' || $depth[$i] !== $depth[$read]) {
                $problems[] = 'the remember-me cookie is not tried in the block that reads the User';
                break;
            }
            if (is_array($t[$i]) && in_array($t[$i][0], [T_EXIT, T_RETURN, T_THROW], true)) {
                $problems[] = 'something between the remember-me cookie and the User read leaves: ' . $t[$i][1];
            }
        }

        return $problems;
    }

    /** @return list<int> the token indexes of session_start() calls */
    private static function sessionStarts(string $source): array
    {
        $t = self::tokens($source);
        $calls = [];
        foreach ($t as $i => $x) {
            if (
                is_array($x) && SourceScan::callableName($x[0], $x[1]) === 'session_start'
                && self::text($t[$i + 1] ?? '') === '('
                && !in_array(self::text($t[$i - 1] ?? ''), ['->', '?->', '::', 'function', 'new'], true)
            ) {
                $calls[] = $i;
            }
        }

        return $calls;
    }

    /**
     * Lines of session_start() calls not preceded by an include of
     * 202-config/connect.php that runs whenever the file runs: a top-level
     * statement of its own.
     *
     * @return list<int>
     */
    private static function openedBeforeConnect(string $source): array
    {
        $t = self::tokens($source);
        $depth = self::depths($t);
        $connect = null;
        foreach ($t as $i => $x) {
            if (
                !is_array($x) || !in_array($x[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)
                || $depth[$i] !== 0 || !self::isBoundary($t[$i - 1] ?? null)
            ) {
                continue;
            }
            for ($j = $i + 1; $j < count($t) && self::text($t[$j]) !== ';'; $j++) {
                if (
                    is_array($t[$j]) && $t[$j][0] === T_CONSTANT_ENCAPSED_STRING
                    && preg_match('#(^|/)202-config/connect\.php$#', substr($t[$j][1], 1, -1)) === 1
                ) {
                    $connect ??= $i;
                }
            }
        }
        $lines = [];
        foreach (self::sessionStarts($source) as $i) {
            if ($connect === null || $connect > $i) {
                $lines[] = $t[$i][2];
            }
        }

        return $lines;
    }

    /**
     * Lines where who the session is signed in as is written, outside
     * AUTH::begin_user_session(): an identity key assigned, incremented or
     * made a foreach target; any key that cannot be read; the session
     * replaced by anything but []; and the session aliased, or reached
     * through $GLOBALS, anywhere.
     *
     * @return list<int>
     */
    private static function identityWrites(string $source): array
    {
        $t = self::tokens($source);
        $allowed = self::functionBody($t, 'AUTH', 'begin_user_session');
        // AUTH's writer writes the key its one caller, writeSessionValue(),
        // is handed: held below to a literal that is not an identity key.
        $writer = self::functionBody($t, 'AUTH', 'updateSession');
        $writeValue = self::functionBody($t, 'AUTH', 'writeSessionValue');
        $keep = self::functionBody($t, 'AUTH', 'keepSessionAfterPasswordChange');
        $inside = static fn (int $i, ?array $range): bool => $range !== null && $i > $range[0] && $i < $range[1];
        $foreachTargets = [];
        foreach ($t as $i => $x) {
            if (is_array($x) && $x[0] === T_FOREACH && self::text($t[$i + 1] ?? '') === '(') {
                $close = self::closing($t, $i + 1);
                for ($j = $i + 2; $j < $close; $j++) {
                    if (is_array($t[$j]) && $t[$j][0] === T_AS) {
                        $foreachTargets[] = [$j, $close];
                        break;
                    }
                }
            }
        }
        $writes = [];
        foreach ($t as $i => $x) {
            if (!is_array($x)) {
                continue;
            }
            if ($x[0] === T_STRING && self::text($t[$i + 1] ?? '') === '(' && self::text($t[$i - 1] ?? '') === '::') {
                if (strcasecmp($x[1], 'updateSession') === 0 && !$inside($i, $writeValue)) {
                    $writes[] = $x[2];
                }
                if (strcasecmp($x[1], 'writeSessionValue') === 0) {
                    $key = $t[$i + 2] ?? null;
                    if (
                        !is_array($key) || $key[0] !== T_CONSTANT_ENCAPSED_STRING
                        || self::text($t[$i + 3] ?? '') !== ','
                        || in_array(substr($key[1], 1, -1), [...self::IDENTITY, self::GENERATION], true)
                    ) {
                        $writes[] = $x[2];
                    }
                }
            }
            if (
                $x[0] === T_VARIABLE && $x[1] === '$GLOBALS' && self::text($t[$i + 1] ?? '') === '['
                && trim(self::text($t[$i + 2] ?? ''), '\'"') === '_SESSION'
            ) {
                $writes[] = $x[2];
                continue;
            }
            if ($x[0] !== T_VARIABLE || $x[1] !== '$_SESSION') {
                continue;
            }
            $next = self::text($t[$i + 1] ?? '');
            if (self::text($t[$i - 1] ?? '') === '&' || ($next === '=' && self::text($t[$i + 2] ?? '') === '&')) {
                $writes[] = $x[2];
                continue;
            }
            if ($next === '=') {
                $cleared = array_map([self::class, 'text'], array_slice($t, $i + 2, 3)) === ['[', ']', ';'];
                if (!$cleared) {
                    $writes[] = $x[2];
                }
                continue;
            }
            if (self::text($t[$i + 1] ?? '') !== '[') {
                continue;
            }
            $key = self::literalKey($t, $i);
            $end = self::closing($t, $i + 1);
            while (self::text($t[$end + 1] ?? '') === '[') {
                $end = self::closing($t, $end + 1);
            }
            $after = $t[$end + 1] ?? null;
            $written = self::isAssignment($after) || in_array(self::text($after ?? ''), ['++', '--'], true)
                || in_array(self::text($t[$i - 1] ?? ''), ['++', '--'], true);
            foreach ($foreachTargets as $range) {
                $written = $written || $inside($i, $range);
            }
            $written = $written || self::isDestructuringTarget($t, $i, $end);
            if (!$written || $inside($i, $allowed) || ($key === null && $inside($i, $writer))) {
                continue;
            }
            if ($key === null || in_array($key, self::IDENTITY, true)) {
                $writes[] = $x[2];
            } elseif ($key === self::GENERATION && !$inside($i, $keep)) {
                $writes[] = $x[2];
            }
        }

        return $writes;
    }

    /**
     * Whether the element from $start to $end is a target of `[...] = …` or
     * `list(...) = …`, at any depth of nesting.
     *
     * @param list<string|array{int, string, int}> $t
     */
    private static function isDestructuringTarget(array $t, int $start, int $end): bool
    {
        while (in_array(self::text($t[$end + 1] ?? ''), [',', ']', ')'], true)) {
            $opener = null;
            $level = 0;
            for ($j = $start - 1; $j >= 0; $j--) {
                $s = self::text($t[$j]);
                if (in_array($s, [')', ']'], true)) {
                    $level++;
                } elseif (in_array($s, ['(', '['], true)) {
                    if ($level === 0) {
                        $opener = $j;
                        break;
                    }
                    $level--;
                }
            }
            if ($opener === null) {
                return false;
            }
            $isList = self::text($t[$opener]) === '['
                || (is_array($t[$opener - 1] ?? null) && $t[$opener - 1][0] === T_LIST);
            if (!$isList) {
                return false;
            }
            $closer = self::closing($t, $opener);
            if (self::text($t[$closer + 1] ?? '') === '=') {
                return true;
            }
            $start = is_array($t[$opener - 1] ?? null) && $t[$opener - 1][0] === T_LIST ? $opener - 1 : $opener;
            $end = $closer;
        }

        return false;
    }

    private static function callsBeginUserSession(string $source): bool
    {
        $t = self::tokens($source);
        foreach ($t as $i => $x) {
            if (
                is_array($x) && $x[0] === T_STRING && strcasecmp($x[1], 'begin_user_session') === 0
                && self::text($t[$i + 1] ?? '') === '(' && self::text($t[$i - 1] ?? '') === '::'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The token range of a method's body in a class of the name, or null.
     *
     * @param list<string|array{int, string, int}> $t
     * @return array{int, int}|null
     */
    private static function functionBody(array $t, string $class, string $method): ?array
    {
        $inClass = null;
        foreach ($t as $i => $x) {
            $named = is_array($t[$i + 1] ?? null) && strcasecmp($t[$i + 1][1], $class) === 0;
            if (is_array($x) && $x[0] === T_CLASS && $named) {
                $open = $i;
                while (self::text($t[$open] ?? '{') !== '{') {
                    $open++;
                }
                $inClass = [$open, self::closing($t, $open)];
            }
            if (
                $inClass !== null && $i > $inClass[0] && $i < $inClass[1] && is_array($x) && $x[0] === T_FUNCTION
                && is_array($t[$i + 1] ?? null) && strcasecmp($t[$i + 1][1], $method) === 0
            ) {
                $open = $i;
                while (self::text($t[$open] ?? '{') !== '{') {
                    $open++;
                }

                return [$open, self::closing($t, $open)];
            }
        }

        return null;
    }

    /**
     * The literal key of `$_SESSION[...]` at $i, or null when the index is
     * not one string literal.
     *
     * @param list<string|array{int, string, int}> $t
     */
    private static function literalKey(array $t, int $i): ?string
    {
        if (
            self::text($t[$i + 1] ?? '') !== '[' || self::text($t[$i + 3] ?? '') !== ']'
            || !is_array($t[$i + 2] ?? null) || $t[$i + 2][0] !== T_CONSTANT_ENCAPSED_STRING
        ) {
            return null;
        }

        return substr($t[$i + 2][1], 1, -1);
    }

    /**
     * @param list<string|array{int, string, int}> $t
     * @return int the index of the bracket that closes the one at $open
     */
    private static function closing(array $t, int $open): int
    {
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        $opener = self::text($t[$open] ?? '');
        if (!isset($pairs[$opener])) {
            return $open;
        }
        $level = 0;
        for ($i = $open; $i < count($t); $i++) {
            $s = self::text($t[$i]);
            if ($s === $opener || ($opener === '{' && self::opensCurly($t[$i]))) {
                $level++;
            } elseif ($s === $pairs[$opener]) {
                $level--;
                if ($level === 0) {
                    return $i;
                }
            }
        }

        return count($t);
    }

    /**
     * Each token's curly-brace depth.
     *
     * @param list<string|array{int, string, int}> $t
     * @return list<int>
     */
    private static function depths(array $t): array
    {
        $depth = 0;
        $out = [];
        foreach ($t as $x) {
            if (self::text($x) === '}') {
                $depth--;
            }
            $out[] = $depth;
            if (self::text($x) === '{' || self::opensCurly($x)) {
                $depth++;
            }
        }

        return $out;
    }

    /** @param string|array{int, string, int}|null $x */
    private static function isBoundary(string|array|null $x): bool
    {
        return $x === null || in_array(self::text($x), [';', '{', '}'], true)
            || (is_array($x) && in_array($x[0], [T_OPEN_TAG, T_CLOSE_TAG], true));
    }

    /**
     * A `{` of an interpolation (`{$x}`, `${x}`), which its `}` closes.
     *
     * @param string|array{int, string, int} $x
     */
    private static function opensCurly(string|array $x): bool
    {
        return is_array($x) && in_array($x[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
    }

    /** @param string|array{int, string, int}|null $x */
    private static function isAssignment(string|array|null $x): bool
    {
        if ($x === '=') {
            return true;
        }

        return is_array($x) && in_array($x[0], [
            T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL, T_AND_EQUAL,
            T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL,
        ], true);
    }

    /** @param string|array{int, string, int} $x */
    private static function text(string|array $x): string
    {
        return is_array($x) ? $x[1] : $x;
    }

    /** @return list<string|array{int, string, int}> */
    private static function tokens(string $source): array
    {
        return array_values(array_filter(
            token_get_all($source),
            static fn ($x): bool => !is_array($x) || !in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
    }
}
