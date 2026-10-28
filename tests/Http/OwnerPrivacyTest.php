<?php

declare(strict_types=1);

namespace Tests\Http;

use Prosper202\Database\Connection;
use Tests\Apps\Apple\CapturingMysqli;
use Tests\TestCase;

/**
 * Which privacy setting the click path applies to a visitor: the stricter of
 * the install's (connect2.php's bootstrap, $_SESSION['privacy']) and the
 * setting of the account whose link was clicked (p202ApplyOwnerPrivacy()),
 * and — until an endpoint has said whose click it is — none at all: the
 * visitor is held back.
 *
 * The click path applied the install's alone. Measured live, an account set
 * to 'all' under an install set to 'disabled' had its visitor's address
 * stored as it arrived and was set every click cookie. This runs connect2.php's
 * own functions, extracted from its source (which a test cannot include),
 * against a database double holding the two accounts' rows.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class OwnerPrivacyTest extends TestCase
{
    use CapturingMysqli;

    private const FUNCTIONS = [
        'p202StoredVisitorIp', 'p202ApplyOwnerPrivacy', 'p202PrivacyInForce', 'trackingEnabled',
        'p202ClickIdentity', 'p202ClickCookieJs', 'p202VisitorMayBeInEu',
    ];

    private const VISITOR = '203.0.113.77';

    private string $log = '';

    protected function setUp(): void
    {
        parent::setUp();
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/connect2.php');
        foreach (self::FUNCTIONS as $name) {
            self::assertSame(1, preg_match('/^function ' . $name . '\(.*?^\}\n/ms', $source, $m), $name);
            eval($m[0]);
        }
        $_SERVER = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => self::VISITOR, 'SCRIPT_NAME' => '/x.php'];
        $_COOKIE = ['p202vid' => str_repeat('a', 32)];
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-owner-privacy-');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    /** @param array<int, string|null> $settings user id => user_pref_privacy */
    private function conn(array $settings): Connection
    {
        $rows = [];
        foreach ($settings as $user => $setting) {
            $rows[] = ['user_id' => $user, 'user_pref_privacy' => $setting];
        }

        return new Connection($this->capturingDb(['FROM 202_users_pref' => $rows]));
    }

    /** @return array{bool, string, string, ?string} in full, stored address, cookie script, p202vid */
    private static function visitor(): array
    {
        return [
            trackingEnabled(),
            p202StoredVisitorIp(),
            p202ClickCookieJs(['tracking202subid' => '41']),
            p202ClickIdentity([], true)->cookieValue,
        ];
    }

    public function testBeforeAnEndpointNamesTheOwnerTheVisitorIsHeldBack(): void
    {
        $_SESSION = ['privacy' => 'disabled'];
        self::assertSame([false, '203.0.113.0', '', null], self::visitor());
        self::assertStringContainsString(
            'asked before the request named whose click this is (/x.php); holding back',
            (string) file_get_contents($this->log)
        );
    }

    /** @return iterable<string, array{string, array<int, string|null>, int, bool}> */
    public static function settings(): iterable
    {
        yield 'the install tracks, the owner holds back' => ['disabled', [1 => 'disabled', 2 => 'all'], 2, false];
        yield 'the install holds back, the owner tracks' => ['all', [1 => 'all', 2 => 'disabled'], 2, false];
        yield 'both track' => ['disabled', [1 => 'disabled', 2 => 'disabled'], 2, true];
        yield 'the first account is the install' => ['disabled', [1 => 'disabled'], 1, true];
        // The bootstrap's read is cached for three minutes; the owner's is
        // not. A stricter cached install setting still holds.
        yield 'a cached stricter install setting' => ['all', [1 => 'disabled', 2 => 'disabled'], 2, false];
        // PrivacySetting holds back for a stored value that is not a setting.
        yield 'the owner\'s value is not a setting' => ['disabled', [1 => 'disabled', 2 => 'EU'], 2, false];
        // No preferences row for the owner: the column's default, 'disabled'.
        yield 'the owner has no preferences row' => ['disabled', [1 => 'disabled'], 2, true];
    }

    /**
     * @dataProvider settings
     * @param array<int, string|null> $rows
     */
    public function testTheStricterOfTheInstallsAndTheOwnersGoverns(
        string $bootstrap,
        array $rows,
        int $owner,
        bool $inFull
    ): void {
        $_SESSION = ['privacy' => $bootstrap];
        p202ApplyOwnerPrivacy((string) $owner, $this->conn($rows));
        if ($inFull) {
            $script = "createCookie(\"tracking202subid\",\"41\",0);\n";
            self::assertSame([true, self::VISITOR, $script, str_repeat('a', 32)], self::visitor());
        } else {
            self::assertSame([false, '203.0.113.0', '', null], self::visitor(), 'held back: masked, no cookie');
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function noOwner(): iterable
    {
        yield 'NULL (no row)' => [null];
        yield 'zero' => ['0'];
        yield 'not a number' => ['2abc'];
        yield 'negative' => [-2];
    }

    /** @dataProvider noOwner */
    public function testARowThatNamesNoAccountHoldsBack(mixed $owner): void
    {
        $_SESSION = ['privacy' => 'disabled'];
        p202ApplyOwnerPrivacy($owner, $this->conn([1 => 'disabled']));
        self::assertSame([false, '203.0.113.0', '', null], self::visitor());
        self::assertStringContainsString('the click names no account', (string) file_get_contents($this->log));
    }

    public function testASettingThatCannotBeReadHoldsBack(): void
    {
        $_SESSION = ['privacy' => 'disabled'];
        $db = $this->getMockBuilder(\mysqli::class)->disableOriginalConstructor()->getMock();
        $db->method('prepare')->willThrowException(new \mysqli_sql_exception('gone away'));
        p202ApplyOwnerPrivacy('2', new Connection($db));
        self::assertSame([false, '203.0.113.0', '', null], self::visitor());
    }

    public function testALaterOwnerReplacesAnEarlierOne(): void
    {
        // off.php: a lookup among the first account's clicks, then the
        // cookie for the click it found, another account's.
        $_SESSION = ['privacy' => 'disabled'];
        p202ApplyOwnerPrivacy(2, $this->conn([1 => 'disabled', 2 => 'all']));
        self::assertFalse(trackingEnabled());
        p202ApplyOwnerPrivacy(1, $this->conn([1 => 'disabled']));
        self::assertTrue(trackingEnabled());
    }
}
