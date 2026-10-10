<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Every served file that reads the request body ($_POST or $_REQUEST) calls
 * a token guard, or is listed in NO_TOKEN with why it needs none — and that
 * list only shrinks.
 *
 * CLAUDE.md #5 recorded the sweep as open: "74 files in the tree read $_POST
 * and 27 check a token", and anything adding a POST handler was to be held to
 * it by hand. Swept: of the files that read the body and called no guard, one
 * wrote — 202-account/ajax/delay-alert.php, which snoozes the update banner
 * in the session and was posted by fetch(), which the jQuery prefilter that
 * attaches the token never sees. It now checks the token and the chrome
 * sends it. What remains is listed below; a new file that reads the body
 * fails here until it calls a guard or says, in this list, why not.
 *
 * A guard is a call, read from tokens (not a substring):
 * AUTH::check_csrf_token() or AUTH::csrf_token_matches(), with AUTH the
 * class and `::` before the name; install_csrf_ok() or
 * p202_standalone_wizard_token_ok() as a function call (not a method, a
 * declaration or `new`); or a class that extends SetupController, whose
 * handleRequest() validates every POST before dispatching it.
 *
 * What this does not see, stated so a green run is not read as more: it is a
 * file-level question, so a file with one guarded handler and one unguarded
 * one passes (the per-area tests — SetupPostsRequireTokenTest,
 * AccountPostRequiresTokenTest, PreLoginPostRequiresTokenTest,
 * SetUserPrefsRequiresTokenTest — hold their pages handler by handler); a
 * body read through another name (php://input, filter_input()) is not a read
 * here; and a write on GET is outside it (AccountGetDoesNotWriteTest).
 */
final class PostReadersCheckTokenTest extends TestCase
{
    /**
     * Files that read the request body without a token guard, each with why.
     * Remove an entry when the file gains a guard or stops reading the body;
     * the test fails on a stale one.
     */
    private const NO_TOKEN = [
        '202-account/ajax/validate-apikey.php' => 'writes nothing: it forwards a key to the vendor API to be checked and prints the answer, which another origin cannot read',
        '202-config/class-dataengine.php' => 'a report reader: offset and order page and sort a read',
        '202-config/functions-auth.php' => 'defines the guard: check_csrf_token() reads the posted token',
        '202-config/functions-standalone-ui.php' => 'defines the pre-login guard: p202_standalone_wizard_token_ok() reads the posted token',
        '202-config/functions-upgrade.php' => 'the upgrade ladder, which runs only behind the upgrade pages\' guards (PreLoginPostRequiresTokenTest, AccountPostRequiresTokenTest)',
        'tracking202/Report/Json/ReportDispatchRequest.php' => 'a report read: it sets $_POST\'s offset and order for DataEngine, and writes nothing',
        'tracking202/ajax/click_history.php' => 'a read: the click history list, filtered and paged by the posted values',
        'tracking202/ajax/ltv_company.php' => 'a read: one company\'s LTV view',
        'tracking202/ajax/ltv_merge_search.php' => 'a read: the customer search the merge dialog offers',
        'tracking202/ajax/ltv_subscriptions.php' => 'a read: the subscriptions list, filtered and paged',
        'tracking202/ajax/sort_ltv.php' => 'a read: the LTV report, sorted and paged',
        'tracking202/analyze/AnalyzeReportController.php' => 'a report read: it sets $_POST\'s offset and order for DataEngine, and writes nothing',
        'tracking202/analyze/MobileAppsReportController.php' => 'a read: the Verify tab checks a pasted postback\'s signature and stores nothing',
        'tracking202/static/p13n.php' => 'a public endpoint authenticated by a personalization bearer token, cross-origin by design (no session to forge a request from)',
        'tracking202/static/p13n_event.php' => 'a public beacon authenticated by a personalization bearer token, cross-origin by design (no session to forge a request from)',
    ];

    private const AUTH_GUARDS = ['check_csrf_token', 'csrf_token_matches'];

    private const FUNCTION_GUARDS = ['install_csrf_ok', 'p202_standalone_wizard_token_ok'];

    public function testEveryFileThatReadsTheBodyCallsATokenGuardOrSaysWhyNot(): void
    {
        $files = SourceScan::phpFiles();
        self::assertGreaterThan(500, count($files), 'the walk read the served tree');

        $unguarded = [];
        $readers = 0;
        foreach ($files as $path => $source) {
            [$reads, $guarded] = self::scan($source);
            if (!$reads) {
                continue;
            }
            $readers++;
            if (!$guarded && !array_key_exists($path, self::NO_TOKEN)) {
                $unguarded[] = $path;
            }
        }

        self::assertGreaterThan(50, $readers, 'the scan found the body readers (a scan that sees none passes vacuously)');
        self::assertSame([], $unguarded, "These files read \$_POST or \$_REQUEST and call no token guard:\n  "
            . implode("\n  ", $unguarded)
            . "\nA handler that writes calls AUTH::check_csrf_token() (or AUTH::csrf_token_matches() for a token that"
            . ' arrives otherwise) before it writes; one that only reads goes in NO_TOKEN with the reason.');
    }

    public function testEveryListedFileStillReadsTheBodyWithoutAGuard(): void
    {
        $files = SourceScan::phpFiles();
        $stale = [];
        foreach (self::NO_TOKEN as $path => $why) {
            if (!isset($files[$path])) {
                $stale[] = "$path: no longer in the tree";
                continue;
            }
            [$reads, $guarded] = self::scan($files[$path]);
            if (!$reads) {
                $stale[] = "$path: no longer reads the body";
            } elseif ($guarded) {
                $stale[] = "$path: calls a guard now";
            }
        }
        self::assertSame([], $stale, 'NO_TOKEN only shrinks: remove these entries');
    }

    /**
     * What the guard recognition accepts and refuses, executed.
     *
     * @dataProvider shapes
     */
    public function testTheScanReadsGuardsAsCallsNotAsNames(string $code, bool $reads, bool $guarded): void
    {
        self::assertSame([$reads, $guarded], self::scan("<?php\n" . $code . "\n"), $code);
    }

    /** @return array<string, array{string, bool, bool}> */
    public static function shapes(): array
    {
        $read = "\$x = \$_POST['a'];";

        return [
            'no read' => ["echo 1;", false, false],
            'a read, no guard' => [$read, true, false],
            'a $_REQUEST read' => ["\$x = \$_REQUEST['a'];", true, false],
            'the read only in a comment' => ["// \$_POST['a']\n/** \$_POST */", false, false],
            'AUTH::check_csrf_token()' => [$read . " if (!AUTH::check_csrf_token()) { die(); }", true, true],
            'AUTH::csrf_token_matches()' => [$read . " if (!AUTH::csrf_token_matches(\$_GET['token'] ?? null)) { die(); }", true, true],
            'install_csrf_ok()' => [$read . " \$ok = install_csrf_ok(\$a, \$b);", true, true],
            '\\p202_standalone_wizard_token_ok()' => [$read . " \$ok = \\p202_standalone_wizard_token_ok();", true, true],
            'a SetupController subclass' => ["class P extends SetupController { function handlePost(): void { \$x = \$_POST['a']; } }", true, true],
            'another class\'s method of the name' => [$read . " if (!MyAUTH::check_csrf_token()) { die(); }", true, false],
            'an instance method of the name' => [$read . " if (!\$auth->check_csrf_token()) { die(); }", true, false],
            'the name without a call' => [$read . " \$f = 'AUTH::check_csrf_token';", true, false],
            'the name in a comment' => [$read . " // AUTH::check_csrf_token()", true, false],
            'declaring the function' => [$read . " function install_csrf_ok(\$a, \$b) { return true; }", true, false],
            'a method named like the function' => [$read . " \$ok = \$h->install_csrf_ok(\$a, \$b);", true, false],
        ];
    }

    /**
     * Whether a source reads the request body, and whether it calls a guard.
     *
     * @return array{bool, bool}
     */
    private static function scan(string $source): array
    {
        $t = array_values(array_filter(
            token_get_all($source),
            static fn ($x): bool => !is_array($x) || !in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $reads = false;
        $guarded = false;
        foreach ($t as $i => $x) {
            if (!is_array($x)) {
                continue;
            }
            if ($x[0] === T_VARIABLE && in_array($x[1], ['$_POST', '$_REQUEST'], true)) {
                $reads = true;
            }
            $next = $t[$i + 1] ?? null;
            $prev = $t[$i - 1] ?? null;
            if ($x[0] === T_STRING && in_array($x[1], self::AUTH_GUARDS, true) && $next === '('
                && is_array($prev) && $prev[0] === T_DOUBLE_COLON
                && is_array($t[$i - 2] ?? null) && in_array($t[$i - 2][1], ['AUTH', '\\AUTH'], true)) {
                $guarded = true;
            }
            if (in_array($x[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && in_array(ltrim($x[1], '\\'), self::FUNCTION_GUARDS, true)
                && $next === '('
                && !(is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true))) {
                $guarded = true;
            }
            if ($x[0] === T_EXTENDS && is_array($next) && in_array(ltrim($next[1], '\\'), ['SetupController', 'Tracking202\\Setup\\SetupController'], true)) {
                $guarded = true;
            }
        }

        return [$reads, $guarded];
    }
}
