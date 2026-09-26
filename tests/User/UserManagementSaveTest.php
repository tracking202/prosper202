<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Tests\Support\FakeMysqliConnection;

/**
 * Settings › Users saves a user, its role and (when new) its preferences as
 * one transaction (p202_account_save_user(), #165, #173).
 *
 * The page wrote them as three autocommitted statements: a failed role or
 * preferences insert left a live account with a password and no role, which
 * a retry then collided with on its username. These run the real Connection
 * over a fake mysqli, so the transaction and the statements are Connection's
 * own, and a failure is planted at each write in turn.
 */
final class UserManagementSaveTest extends TestCase
{
    private const USER = ['user_fname' => 'Ada', 'user_lname' => 'L', 'user_email' => 'ada@example.com', 'user_name' => 'ada',
        'user_time_register' => 5, 'user_timezone' => 'UTC', 'user_pass' => 'hash', 'user_active' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/202-config/functions-account-ui.php';
    }

    public function testACreateWritesTheUserItsPreferencesAndItsRoleAndCommitsOnce(): void
    {
        $db = new FakeMysqliConnection();
        // The fake's statements cannot carry an insert id (a read-only native
        // property), so the id comes from the server's LAST_INSERT_ID().
        $db->whenQueryContainsReturnRows('LAST_INSERT_ID()', [['id' => 41]]);
        $id = p202_account_save_user(new Connection($db), null, self::USER, 3);

        self::assertSame(41, $id);
        self::assertTrue($db->beginTransactionCalled);
        self::assertTrue($db->commitCalled);
        self::assertFalse($db->rollbackCalled);
        $insert = $db->statementsContaining('INSERT INTO `202_users` SET');
        self::assertCount(1, $insert);
        self::assertSame('ssssissi', $insert[0]->boundTypes, 'every value bound as its column\'s type');
        self::assertSame([41], $db->statementsContaining('INSERT INTO `202_users_pref`')[0]->boundValues);
        self::assertSame([41, 3], $db->statementsContaining('INSERT INTO `202_user_role`')[0]->boundValues);
    }

    /** @return array<string, array{0: string}> */
    public static function createWrites(): array
    {
        return [
            'the user row' => ['INSERT INTO `202_users` SET'],
            'the preferences row' => ['INSERT INTO `202_users_pref`'],
            'the role' => ['INSERT INTO `202_user_role`'],
        ];
    }

    /** @dataProvider createWrites */
    public function testACreateThatFailsAnywhereLeavesNothing(string $failing): void
    {
        $db = new FakeMysqliConnection();
        // The fake's statements cannot carry an insert id (a read-only native
        // property), so the id comes from the server's LAST_INSERT_ID().
        $db->whenQueryContainsReturnRows('LAST_INSERT_ID()', [['id' => 41]]);
        $db->whenQueryContainsExecuteReturns($failing, false);
        try {
            p202_account_save_user(new Connection($db), null, self::USER, 3);
            self::fail("a failed write to $failing was reported as saved");
        } catch (\Throwable $expected) {
            self::assertNotInstanceOf(\DomainException::class, $expected);
        }
        self::assertTrue($db->rollbackCalled, 'everything written before it was rolled back');
        self::assertFalse($db->commitCalled, 'nothing was committed');
    }

    public function testAnEditReplacesTheRoleOfAUserWhoIsStillThere(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('FOR UPDATE', [['user_id' => 9]]);
        $id = p202_account_save_user(new Connection($db), 9, self::USER, 4);

        self::assertSame(9, $id);
        $order = array_map(static fn ($s): string => strtok($s->sql, ' ') . ' ' . (preg_match('/`(202_\w+)`/', $s->sql, $m) ? $m[1] : ''), $db->statements);
        self::assertSame(['SELECT 202_users', 'UPDATE 202_users', 'DELETE 202_user_role', 'INSERT 202_user_role'], $order,
            'lock the user, write it, and replace its role, so a user with no role row gets one');
        $update = $db->statementsContaining('UPDATE `202_users` SET')[0];
        self::assertStringEndsWith('WHERE `user_id` = ? AND `user_deleted` != 1', $update->sql, 'a removed user is not written');
        self::assertSame(9, $update->boundValues[count($update->boundValues) - 1]);
        self::assertSame([9, 4], $db->statementsContaining('INSERT INTO `202_user_role`')[0]->boundValues);
        self::assertTrue($db->commitCalled);
    }

    public function testAnEditOfAUserWhoIsGoneWritesNothingAndSaysSo(): void
    {
        $db = new FakeMysqliConnection();
        try {
            p202_account_save_user(new Connection($db), 9, self::USER, 4);
            self::fail('an edit of a removed user was saved');
        } catch (\DomainException $gone) {
            self::assertSame('That user is not there any more.', $gone->getMessage());
        }
        self::assertSame([], $db->statementsContaining('UPDATE `202_users`'), 'no row was written');
        self::assertSame([], $db->statementsContaining('202_user_role'));
        self::assertTrue($db->rollbackCalled);
    }

    public function testAFailedRoleWriteOnAnEditRollsTheUserRowBack(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('FOR UPDATE', [['user_id' => 9]]);
        $db->whenQueryContainsExecuteReturns('INSERT INTO `202_user_role`', false);
        try {
            p202_account_save_user(new Connection($db), 9, self::USER, 4);
            self::fail('a failed role write was reported as saved');
        } catch (\Throwable $expected) {
        }
        self::assertSame(1, $db->statementsContaining('UPDATE `202_users` SET')[0]->executeCount, 'the user row was written');
        self::assertTrue($db->rollbackCalled, 'and rolled back');
        self::assertFalse($db->commitCalled);
    }

    public function testThePageSavesThroughItAndNothingElse(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/202-account/user-management.php');
        self::assertSame(1, preg_match_all('/\bp202_account_save_user\(/', $page), 'the add/edit form saves through the one transaction');
        // The remove flow above it writes user_deleted through its own path
        // (UserDataPurge); the add/edit block is what this is about.
        $at = strpos($page, '// ─── Add or edit');
        self::assertNotFalse($at, 'the add/edit block was found');
        $page = substr($page, (int) $at);
        self::assertDoesNotMatchRegularExpression('/(INSERT\s+INTO|UPDATE)\s+`?202_(users|user_role|users_pref)`?\b/i', $page,
            'no second, autocommitted write of a user, its role or its preferences beside it');
        self::assertStringNotContainsString('begin_transaction', $page, 'and no transaction the helper would be nested in (#13)');
    }

    public function testAColumnNameItDidNotWriteIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        p202_account_save_user(new Connection(new FakeMysqliConnection()), null, ['user_name` = 1, `x' => 'y'], 3);
    }
}
