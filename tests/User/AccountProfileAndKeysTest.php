<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Tests\Support\FakeMysqliConnection;

/**
 * Two reads and writes of 202-account/account.php
 * (202-config/functions-account-ui.php), through Connection over a fake
 * mysqli so the transaction and the statements are Connection's real ones.
 *
 *  - The profile save writes 202_users and 202_users_pref as one
 *    transaction: when the second UPDATE fails, the first is rolled back and
 *    the caller is told, so "nothing has changed" is true.
 *  - The API-key list reads without the scope column on an install that has
 *    none (such a key is full access, as the API grants it), and a probe
 *    that cannot tell throws rather than guessing (CLAUDE.md #11).
 *
 * tests/live/account-pages.sh and the account browser pass drive the page;
 * the failure cases were also driven live by renaming the column each one
 * depends on.
 */
final class AccountProfileAndKeysTest extends TestCase
{
    private const PREFS = ['user_keyword_searched_or_bidded' => 'searched', 'user_pref_dynamic_bid' => 1, 'user_daily_email' => '07'];

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/202-config/functions-account-ui.php';
    }

    public function testTheAccountPageUsesBoth(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/202-account/account.php');
        self::assertSame(1, preg_match_all('/\bp202_account_save_profile\(/', $page), 'the profile form saves through the one transaction');
        self::assertSame(1, preg_match_all('/\bp202_account_api_keys\(/', $page), 'the key panel reads through the column-aware list');
        self::assertDoesNotMatchRegularExpression('/`?(user_timezone|user_pref_privacy|user_daily_email)`?\s*=\s*\'/i', $page, 'no second, autocommitted profile write beside it');
        self::assertDoesNotMatchRegularExpression('/SELECT[^;]*FROM\s+202_api_keys/i', $page, 'no second read of the keys that names a column the install may not have');
    }

    public function testAProfileSaveIsOneTransactionOverBothRows(): void
    {
        $db = new FakeMysqliConnection();
        p202_account_save_profile(new Connection($db), 7, 'a@example.com', 'Europe/Berlin', self::PREFS);

        self::assertTrue($db->beginTransactionCalled);
        self::assertTrue($db->commitCalled);
        self::assertFalse($db->rollbackCalled);
        $users = $db->statementsContaining('UPDATE `202_users` SET');
        self::assertCount(1, $users);
        self::assertSame(['a@example.com', 'Europe/Berlin', 7], $users[0]->boundValues);
        $prefs = $db->statementsContaining('UPDATE `202_users_pref` SET');
        self::assertCount(1, $prefs);
        self::assertSame('sisi', $prefs[0]->boundTypes, 'each value bound as its column\'s type, the user id last');
        self::assertSame(['searched', 1, '07', 7], $prefs[0]->boundValues);
        self::assertStringContainsString('`user_keyword_searched_or_bidded` = ?, `user_pref_dynamic_bid` = ?, `user_daily_email` = ? WHERE `user_id` = ?', $prefs[0]->sql);
    }

    public function testAFailedPreferencesUpdateRollsTheAccountRowBack(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsExecuteReturns('UPDATE `202_users_pref`', false);
        try {
            p202_account_save_profile(new Connection($db), 7, 'a@example.com', 'Europe/Berlin', self::PREFS);
            self::fail('a failed preferences write was reported as saved');
        } catch (\Throwable $expected) {
            self::assertNotSame('', $expected->getMessage());
        }
        self::assertSame(1, $db->statementsContaining('UPDATE `202_users` SET')[0]->executeCount, 'the account row was written first');
        self::assertTrue($db->rollbackCalled, 'and rolled back with the rest');
        self::assertFalse($db->commitCalled, 'nothing was committed');
    }

    public function testAProfileSaveRefusesAColumnNameItDidNotWrite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        p202_account_save_profile(new Connection(new FakeMysqliConnection()), 7, 'a@example.com', 'UTC', ['user_pref_privacy` = 1, `x' => 'y']);
    }

    public function testKeysAreReadWithTheirScopeWhereTheColumnExists(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'scope'", [['Field' => 'scope']]);
        $db->whenQueryContainsReturnRows('FROM 202_api_keys WHERE user_id', [['api_key' => 'k1', 'created_at' => 5, 'scope' => 'reports:read']]);

        $keys = p202_account_api_keys($db, 7);
        self::assertSame([['api_key' => 'k1', 'created_at' => 5, 'scope' => 'reports:read']], $keys);
        self::assertStringContainsString('SELECT api_key, created_at, scope FROM 202_api_keys', $db->statementsContaining('FROM 202_api_keys WHERE user_id')[0]->sql);
    }

    public function testKeysAreReadWithoutAScopeColumnAsFullAccess(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'scope'", []);
        $db->whenQueryContainsReturnRows('FROM 202_api_keys WHERE user_id', [['api_key' => 'k1', 'created_at' => 5]]);

        $keys = p202_account_api_keys($db, 7);
        $select = $db->statementsContaining('FROM 202_api_keys WHERE user_id')[0]->sql;
        self::assertStringNotContainsString('scope', $select, 'the list does not name a column the install does not have');
        self::assertSame([['api_key' => 'k1', 'created_at' => 5, 'scope' => null]], $keys);
        self::assertSame(['*'], \Api\V3\Auth::parseScopes((string) ($keys[0]['scope'] ?? '')), 'which the page reads as full access, as the API grants it');
    }

    public function testAProbeThatCannotTellIsAnErrorNotAnAnswer(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsResultFails("SHOW COLUMNS FROM 202_api_keys LIKE 'scope'");
        $this->expectException(\Throwable::class);
        p202_account_api_keys($db, 7);
    }

    public function testAFailedListReadIsAnErrorNotNoKeys(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'scope'", [['Field' => 'scope']]);
        $db->whenQueryContainsResultFails('FROM 202_api_keys WHERE user_id');
        $this->expectException(\Throwable::class);
        p202_account_api_keys($db, 7);
    }
}
