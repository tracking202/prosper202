<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Every served file that names 202_users_log.login_success outside a comment
 * is a decision written down here, and none but SignInOutcome compares it
 * with a value.
 *
 * The column held 0 or 1 until a right password whose sign-in could not be
 * completed was logged as 2 (SignInOutcome::NotCompleted). Every reader
 * then read 2 as something: the page's `== 0 ? Failed : Passed` and the
 * API's `!== 0` both as passed, and the throttle's `= 0` (rightly) as no
 * failure. A writer's "none" has to be each reader's (CLAUDE.md #25), so the
 * outcomes are spelled once, in SignInOutcome, and a file that starts
 * reading the column fails here until it is listed with how it reads it.
 *
 * What this cannot see: a value copied into another variable before it is
 * compared, a reader that takes the column by position (SELECT * read with
 * fetch_row()), and anything outside the served PHP — the browser specs and
 * the live scripts, which read the database directly, are the reader's own
 * to keep right.
 */
final class SignInOutcomeReadersTest extends TestCase
{
    /** file => how it treats the column; `via` is what the file must call to read it, when it reads it */
    private const NAMERS = [
        '202-config/User/SignInOutcome.php' => ['the spelling: each outcome\'s value, status and SQL condition', null],
        '202-config/User/SignInLog.php' => ['the one writer, of a SignInOutcome', 'SignInOutcome $outcome'],
        '202-config/Database/Tables/UserTables.php' => ['the column and the throttle\'s indexes', null],
        '202-config/functions-upgrade.php' => ['the 1.9.60 step\'s throttle indexes', null],
        'api/v3/Controllers/AdministrationController.php' => [
            'GET /system/login-log: status and ?status=',
            'SignInOutcome::fromStored(',
        ],
        '202-account/administration.php' => [
            'Account › Settings\' sign-in attempts',
            'p202_account_sign_in_attempt_pill(',
        ],
    ];

    public function testEveryFileThatNamesTheColumnIsListed(): void
    {
        $naming = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (self::codeOf($source) !== '' && str_contains(self::codeOf($source), 'login_success')) {
                $naming[] = $path;
            }
        }

        self::assertSame(
            [],
            array_values(array_diff($naming, array_keys(self::NAMERS))),
            'These files read or write 202_users_log.login_success and are not listed: read it through'
                . ' SignInOutcome (fromStored(), sqlCondition()), so 2, a sign-in not completed, is not read as a'
                . ' pass or a failure, then list the file here with how it reads it'
        );
        self::assertSame(
            [],
            array_values(array_diff(array_keys(self::NAMERS), $naming)),
            'Listed files that no longer name the column; drop them from the list'
        );
    }

    public function testEachReaderReadsThroughTheSpelling(): void
    {
        $files = SourceScan::phpFiles();
        foreach (self::NAMERS as $path => [$why, $via]) {
            if ($via !== null) {
                self::assertStringContainsString($via, self::codeOf($files[$path] ?? ''), "$path ($why)");
            }
        }
    }

    public function testNoFileButTheSpellingComparesTheColumn(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if ($path === '202-config/User/SignInOutcome.php') {
                continue;
            }
            foreach (self::comparisons(self::codeOf($source)) as $match) {
                $found[] = "$path: $match";
            }
        }

        self::assertSame(
            [],
            $found,
            "login_success compared outside SignInOutcome; ask it instead:\n  " . implode("\n  ", $found)
        );
    }

    /** @return iterable<array{string, list<string>}> */
    public static function comparisonShapes(): iterable
    {
        yield 'SQL equality' => ["\$sql = 'SELECT COUNT(*) FROM t WHERE login_success = 0';", ['login_success = 0']];
        yield 'SQL inequality' => ['$q = "... WHERE login_success != 1 AND x";', ['login_success != 1']];
        yield 'SQL <>' => ['$q = "WHERE `login_success` <> 1";', ['login_success` <> 1']];
        yield 'SQL IN' => ["\$q = 'WHERE login_success IN (0, 2)';", ['login_success IN (0']];
        yield 'SQL NOT IN' => ["\$q = 'WHERE login_success NOT IN (1, 2)';", ['login_success NOT IN (1']];
        yield 'SQL IS FALSE' => ["\$q = 'WHERE login_success IS FALSE';", ['login_success IS FALSE']];
        yield 'PHP loose' => ["<?php echo \$row['login_success'] == 0 ? 'a' : 'b';", ["login_success'] == 0"]];
        yield 'PHP strict' => [
            "<?php \$ok = (int) \$row['login_success'] !== 0;",
            ["(int) \$row['login_success']", "login_success'] !== 0"],
        ];
        yield 'PHP truthiness' => ["<?php if (\$row[\"login_success\"]) {}", ['if ($row["login_success"])']];
        yield 'PHP negation' => ["<?php if (!\$row['login_success']) {}", ["!\$row['login_success']"]];
        yield 'PHP ternary' => ["<?php \$x = \$r['login_success'] ? 1 : 0;", ["login_success'] ?"]];
        yield 'PHP bool cast' => ["<?php \$x = (bool) \$r['login_success'];", ["(bool) \$r['login_success']"]];
        yield 'PHP empty' => ["<?php \$x = empty(\$r['login_success']);", ["empty(\$r['login_success']"]];
    }

    /**
     * @dataProvider comparisonShapes
     * @param list<string> $expected
     */
    public function testTheScanReadsEachShape(string $source, array $expected): void
    {
        $php = str_starts_with($source, '<?php') ? $source : "<?php $source";
        self::assertSame($expected, self::comparisons(self::codeOf($php)));
    }

    public function testTheScanLeavesReadsThroughTheSpellingAlone(): void
    {
        $reads = [
            "<?php \$o = SignInOutcome::fromStored(\$row['login_success']);",
            "<?php \$sql = 'SELECT login_id, login_success FROM 202_users_log';",
            "<?php \$sql = 'INSERT INTO 202_users_log (user_name, login_success, login_error) VALUES (?, ?, ?)';",
            "<?php \$t = 'KEY `throttle_user` (`user_name`(64), `login_success`, `login_time`)';",
            "<?php \$t = '`login_success` tinyint(1) NOT NULL,';",
            "<?php // WHERE login_success = 0\n",
        ];
        foreach ($reads as $source) {
            self::assertSame([], self::comparisons(self::codeOf($source)), $source);
        }
    }

    /** The source without its comments, which may name the column freely. */
    private static function codeOf(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** @return list<string> each comparison of the column the code makes */
    private static function comparisons(string $code): array
    {
        // The name with its closing quote, backtick or bracket, then an operator.
        $name = 'login_success[\'"`\]]*\s*';
        $element = '\$\w+\[[\'"]login_success[\'"]\]';
        $patterns = [
            "/{$name}(?:={1,3}(?!>)|!==?|<>|<=?|>=?)\s*[-\w?]+/i",
            "/{$name}\?(?![?>])/",
            "/{$name}IS\s+(?:NOT\s+)?\w+/i",
            "/{$name}(?:NOT\s+)?IN\s*\(\s*[-\w]+/i",
            // PHP: a cast, a negation or empty() of the element, or the element as a whole condition.
            "/(?:\((?:int|integer|bool|boolean)\)\s*|!\s*|\b(?:empty|intval|boolval)\(\s*){$element}/i",
            "/\b(?:if|elseif|while)\s*\(\s*{$element}\s*\)/i",
        ];
        $found = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $code, $m, PREG_OFFSET_CAPTURE) === false) {
                throw new \RuntimeException('the comparison scan failed');
            }
            foreach ($m[0] as [$text, $offset]) {
                $found[$offset] = $text;
            }
        }
        ksort($found);

        return array_values($found);
    }
}
