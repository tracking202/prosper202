<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * A failed upgrade says on the page what failed and what to do. The upgrade
 * page runs before sign-in, so what it shows holds no value a statement or
 * the server's message quoted, and not the database's name.
 */
final class UpgradeFailureReportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../202-config/functions-upgrade.php';
        require_once __DIR__ . '/../../202-config/functions-ui.php';
    }

    protected function setUp(): void
    {
        \UPGRADE::forgetFailures();
    }

    /** A connection whose every statement fails with $errno and $error. */
    private static function failing(int $errno, string $error): object
    {
        return new class ($errno, $error) {
            public function __construct(public int $errno, public string $error)
            {
            }

            public function query(string $sql): bool
            {
                return false;
            }
        };
    }

    private static function query(object $conn, string $sql): mixed
    {
        $log = (string) ini_get('error_log');
        ini_set('error_log', '/dev/null');
        try {
            return _upgrade_query_run($conn, $sql, static fn () => null, static fn () => false);
        } finally {
            ini_set('error_log', $log);
        }
    }

    public function testAFailedStatementIsKeptForThePageWithoutItsValues(): void
    {
        $sql = "UPDATE 202_users SET user_pass = 'hunter2-secret' WHERE user_name = 'owner@store.example'";
        self::assertFalse(self::query(
            self::failing(1142, "UPDATE command denied to user 'p202'@'10.1.2.3' for table 'shopdb.202_users'"),
            $sql
        ));

        $failures = \UPGRADE::failures();
        self::assertCount(1, $failures);
        self::assertStringContainsString('[errno 1142]', $failures[0]);
        self::assertStringContainsString('UPDATE 202_users SET user_pass = ?', $failures[0]);
        foreach (['hunter2', 'owner@store.example', 'p202', '10.1.2.3', 'shopdb'] as $secret) {
            self::assertStringNotContainsString($secret, $failures[0], "$secret is not shown");
        }
    }

    public function testAMessageThatNamesATableIsShownWithoutTheDatabasesName(): void
    {
        self::query(
            self::failing(1146, "Table 'shopdb.202_missing' doesn't exist"),
            'ALTER TABLE 202_missing ADD x INT'
        );
        self::query(
            self::failing(
                1452,
                'Cannot add or update a child row: a foreign key constraint fails (`shopdb`.`202_a`, CONSTRAINT `fk`)'
            ),
            'INSERT INTO 202_a SET b = 1'
        );
        self::query(self::failing(1049, "Unknown database 'shopdb'"), 'SELECT 1');

        $shown = implode("\n", \UPGRADE::failures());
        self::assertStringContainsString("Table '202_missing' doesn't exist", $shown);
        self::assertStringContainsString('fails (`202_a`, CONSTRAINT `fk`)', $shown);
        self::assertStringNotContainsString('shopdb', $shown);
    }

    public function testAnAlreadyAppliedStepIsNotAFailure(): void
    {
        foreach (_UPGRADE_ALREADY_APPLIED as $errno) {
            self::query(self::failing($errno, 'already there'), 'ALTER TABLE 202_x ADD y INT');
        }
        self::assertSame([], \UPGRADE::failures());
    }

    public function testANoteIsKeptAndWhatIsForTheLogAloneIsNot(): void
    {
        $log = (string) ini_get('error_log');
        $file = (string) tempnam(sys_get_temp_dir(), 'p202-upgrade-note-');
        ini_set('error_log', $file);
        try {
            _upgrade_note('the API keys could not be hashed', ': SQLSTATE at /var/www/site/202-config/x.php');
        } finally {
            ini_set('error_log', $log);
        }
        $logged = (string) file_get_contents($file);
        unlink($file);

        self::assertSame(['the API keys could not be hashed'], \UPGRADE::failures());
        self::assertStringContainsString(
            'Prosper202 upgrade: the API keys could not be hashed: SQLSTATE at /var/www/site/202-config/x.php',
            $logged
        );
    }

    public function testTheListIsBoundedAndKeepsTheCauseAndWhereItStopped(): void
    {
        for ($i = 0; $i < 45; $i++) {
            \UPGRADE::noteFailure('step ' . $i);
        }
        $failures = \UPGRADE::failures();
        self::assertCount(27, $failures);
        self::assertSame('step 0', $failures[0]);
        self::assertSame('step 19', $failures[19]);
        self::assertSame("(19 more here, in the server's PHP error log)", $failures[20]);
        self::assertSame('step 39', $failures[21]);
        self::assertSame('step 44', $failures[26], 'the last line says where the upgrade stopped');

        \UPGRADE::forgetFailures();
        self::assertSame([], \UPGRADE::failures());
        for ($i = 0; $i < 26; $i++) {
            \UPGRADE::noteFailure('step ' . $i);
        }
        self::assertCount(26, \UPGRADE::failures(), 'nothing is dropped until both ends are full');
    }

    public function testNoLadderFailureGoesToTheLogAlone(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../202-config/functions-upgrade.php');
        preg_match_all('/error_log\(\s*[\'"]Prosper202 upgrade: ([a-z ]+)/', $source, $m);
        sort($m[1]);
        self::assertSame(
            ['persistent transient ', 'statement failed ', 'transient '],
            $m[1],
            'a failure of the upgrade is written with _upgrade_note(), so the page can show it; these three are'
            . ' a retry, the statement failure (kept redacted beside its log line) and the pause, which has a page'
        );
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function causes(): iterable
    {
        yield 'no permission' => [['a statement failed [errno 1142]: x | ALTER TABLE 202_x'], 'grant it ALTER'];
        yield 'disk full' => [['a statement failed [errno 1114]: x | ALTER TABLE 202_x'], 'out of disk space'];
        yield 'lost connection' => [
            ['a statement failed [errno 2006]: x | ALTER TABLE 202_x'],
            'connection to the database server was lost',
        ];
        yield 'anything else' => [
            ['a statement failed [errno 1064]: x | ALTER TABLE 202_x'],
            'send the report below to support',
        ];
        yield 'nothing named' => [[], 'send the report below to support'];
    }

    /**
     * @dataProvider causes
     * @param list<string> $failures
     */
    public function testTheAdviceFollowsTheCause(array $failures, string $expected): void
    {
        self::assertStringContainsString($expected, implode(' ', p202_upgrade_failure_advice($failures)));
    }

    public function testThePageReportSaysWhereItStandsThatARerunIsSafeAndIsCopyable(): void
    {
        $html = p202_upgrade_failure_report(
            ['a statement failed [errno 1142]: denied <b> | ALTER TABLE 202_x', 'leaving version at 1.9.79'],
            '1.9.79',
            '1.9.80'
        );

        self::assertStringContainsString('The database is at version 1.9.79 and did not reach 1.9.80.', $html);
        self::assertStringContainsString('Running the upgrade again is safe', $html);
        self::assertStringContainsString('grant it ALTER', $html);
        self::assertStringContainsString(
            "- a statement failed [errno 1142]: denied &lt;b&gt; | ALTER TABLE 202_x\n"
            . '- leaving version at 1.9.79</textarea>',
            $html
        );
        self::assertStringContainsString(
            'class="btn btn-secondary btn-sm p202-copy" data-p202-copy="Prosper202 database upgrade stopped at',
            $html
        );
        self::assertStringContainsString('href="https://support.tracking202.com/"', $html);
        self::assertStringContainsString(
            'The server&#039;s PHP error log may say more.',
            p202_upgrade_failure_report([], '1.9.79', '1.9.80')
        );
    }

    public function testEveryPageThatRunsTheUpgradeShowsItsFailures(): void
    {
        $pages = ['202-config/upgrade.php', '202-account/auto-upgrade.php', '202-account/auto-upgrade-premium.php'];
        foreach ($pages as $page) {
            $source = (string) file_get_contents(__DIR__ . '/../../' . $page);
            self::assertStringContainsString('UPGRADE::failures()', $source, $page);
            self::assertStringNotContainsString('http://support.tracking202.com', $source, $page);
        }
    }

    /**
     * The connection may be the thing that failed, so what a page draws
     * after the upgrade answered false asks the database for nothing it
     * cannot do without (LostConnectionUpgradeReportIntegrationTest runs it).
     */
    public function testAFailedUpgradesPageReadsTheStoredVersionWithoutFailingOnIt(): void
    {
        $root = __DIR__ . '/../../';
        foreach (['202-account/auto-upgrade.php', '202-account/auto-upgrade-premium.php'] as $page) {
            $source = (string) file_get_contents($root . $page);
            self::assertSame(1, preg_match(
                '/p202_upgrade_failure_log\(\s*UPGRADE::failures\(\),\s*_upgrade_stored_version_or_null\(\),'
                . '\s*\(string\) \$latest_version\s*\)/',
                $source
            ), "$page: the stored version read may fail, and the files are the version just unpacked");
        }

        // After its one read at the top, a 1-click page asks the database for the
        // stored version only through the reader that may fail, and ends on the
        // standalone page before the signed-in shell when it does.
        foreach (['202-account/auto-upgrade.php', '202-account/auto-upgrade-premium.php'] as $page) {
            $source = (string) file_get_contents($root . $page);
            self::assertSame(1, substr_count($source, 'PROSPER202::prosper202_version()'), $page);
            $read = (int) strpos($source, 'PROSPER202::prosper202_version()');
            $post = (int) strpos($source, "\$_POST['start_upgrade']");
            $ending = (int) strpos($source, 'p202_one_click_after_failed_database_step(');
            $shell = (int) strpos($source, 'template_top(');
            self::assertTrue(0 < $read && $read < $post && $post < $ending && $ending < $shell, $page);
        }

        $source = (string) file_get_contents($root . '202-config/upgrade.php');
        $drawn = substr($source, (int) strpos($source, "info_top(['title' => 'Upgrade - Prosper202 ClickServer']);"));
        $prompt = (int) strpos($drawn, "echo p202_standalone_card(\$task_202 . ' to Prosper202 '");
        self::assertGreaterThan(0, $prompt);
        self::assertStringNotContainsString(
            'PROSPER202::prosper202_version()',
            substr($drawn, 0, $prompt),
            'above the prompt (the branches a POST draws) the stored version is the one read before the upgrade,'
            . ' or _upgrade_stored_version_or_null()'
        );
        self::assertStringContainsString('_upgrade_stored_version_or_null(),', substr($drawn, 0, $prompt));
    }

    public function testWithNoVersionToNameTheReportSaysSo(): void
    {
        $html = p202_upgrade_failure_report(['a statement failed [errno 2013]: x | SELECT 1'], null, '1.9.80');

        self::assertStringContainsString(
            'The database did not reach 1.9.80, and could not be read to say which version it is at.',
            $html
        );
        self::assertStringContainsString('it carries on from the version the database is at', $html);
        self::assertStringContainsString('connection to the database server was lost', $html);
        self::assertStringNotContainsString('version  ', $html);
    }
}
