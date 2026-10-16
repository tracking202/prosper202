<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\User\SignInOutcome;

/**
 * The login log's three outcomes as stored, as the API names them, and as
 * Account › Settings shows them: a sign-in not completed (2) is neither a
 * pass nor a failure anywhere, and a value no version writes is none of
 * the three.
 */
final class SignInOutcomeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-account-ui.php';
    }

    public function testTheStoredValuesAreTheOnesEveryVersionWrote(): void
    {
        self::assertSame(0, SignInOutcome::Failed->value);
        self::assertSame(1, SignInOutcome::Passed->value);
        self::assertSame(2, SignInOutcome::NotCompleted->value);
    }

    public function testAStoredValueIsReadExactly(): void
    {
        // mysqli answers the column as a string; a native int read answers an int.
        $cases = ['0' => SignInOutcome::Failed, '1' => SignInOutcome::Passed, '2' => SignInOutcome::NotCompleted];
        foreach ($cases as $stored => $case) {
            self::assertSame($case, SignInOutcome::fromStored((string) $stored));
            self::assertSame($case, SignInOutcome::fromStored((int) $stored));
        }
        foreach (['3', '-1', '127', '', ' 1', '1 ', '01x', 'passed', null, true, 1.0, ['1']] as $unknown) {
            self::assertNull(SignInOutcome::fromStored($unknown), var_export($unknown, true) . ' is no outcome');
        }
    }

    public function testTheStatusesAreTheApisWords(): void
    {
        self::assertSame(['failed', 'passed', 'not_completed'], SignInOutcome::statuses());
        foreach (SignInOutcome::cases() as $case) {
            self::assertSame($case, SignInOutcome::fromStatus($case->status()));
        }
        $bads = ['not-completed', 'NOT_COMPLETED', 'Passed', 'success', 'unknown', '2', '', null, ['passed']];
        foreach ($bads as $bad) {
            self::assertNull(SignInOutcome::fromStatus($bad), var_export($bad, true) . ' names no status');
        }
        self::assertNotContains(SignInOutcome::UNKNOWN_STATUS, SignInOutcome::statuses(), 'unknown is no filter');
    }

    public function testEachOutcomeSelectsItsOwnValueAlone(): void
    {
        self::assertSame('login_success = 0', SignInOutcome::Failed->sqlCondition(), 'an index can range over it');
        self::assertSame('login_success = 1', SignInOutcome::Passed->sqlCondition());
        self::assertSame('login_success = 2', SignInOutcome::NotCompleted->sqlCondition());
    }

    public function testTheSettingsPageShowsANotCompletedSignInApart(): void
    {
        $pill = p202_account_sign_in_attempt_pill(...);
        self::assertSame('<span class="p202-pill p202-pill--bad">Failed</span>', $pill('0'));
        self::assertSame('<span class="p202-pill p202-pill--good">Passed</span>', $pill('1'));
        $notCompleted = p202_account_sign_in_attempt_pill('2');
        self::assertStringContainsString('p202-pill--warn', $notCompleted);
        self::assertStringContainsString('>Not completed</span>', $notCompleted);
        self::assertStringNotContainsString('Failed', $notCompleted);
        self::assertStringNotContainsString('Passed', $notCompleted);
        self::assertSame('<span class="p202-pill">Unknown (7)</span>', p202_account_sign_in_attempt_pill('7'));
        self::assertSame('<span class="p202-pill">Unknown (&lt;b&gt;)</span>', $pill('<b>'));
    }
}
