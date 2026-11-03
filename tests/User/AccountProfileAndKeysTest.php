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
 * tests/live/account-pages.sh and the account browser pass drive the page.
 * The failure cases are driven here, through the fake mysqli, and nowhere
 * live: no live pass renames or drops the column (an earlier version of
 * this docblock said one did, and #165's review found no such step).
 */
final class AccountProfileAndKeysTest extends TestCase
{
    private const PREFS = ['user_keyword_searched_or_bidded' => 'searched', 'user_pref_dynamic_bid' => 1, 'user_pref_privacy' => 'eu'];

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
        self::assertDoesNotMatchRegularExpression('/`?(user_timezone|user_pref_privacy)`?\s*=\s*\'/i', $page, 'no second, autocommitted profile write beside it');
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
        self::assertSame(['searched', 1, 'eu', 7], $prefs[0]->boundValues);
        self::assertStringContainsString('`user_keyword_searched_or_bidded` = ?, `user_pref_dynamic_bid` = ?, `user_pref_privacy` = ? WHERE `user_id` = ?', $prefs[0]->sql);
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
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'label'", [['Field' => 'label']]);
        $row = ['api_key' => 'k1', 'created_at' => 5, 'scope' => 'reports:read', 'label' => 'AI: Claude via mcp.tracking202.com'];
        $db->whenQueryContainsReturnRows('FROM 202_api_keys WHERE user_id', [$row]);

        $keys = p202_account_api_keys($db, 7);
        self::assertSame([[
            'ref' => hash('sha256', 'k1'),
            'prefix' => 'k1',
            'created_at' => 5,
            'scope' => 'reports:read',
            'label' => 'AI: Claude via mcp.tracking202.com',
            'watcher_token' => \Api\V3\Support\AiConnect::watcherToken('k1'),
        ]], $keys, 'a key not hashed yet is named by the hash it will get, and never returned');
        $select = $db->statementsContaining('FROM 202_api_keys WHERE user_id')[0]->sql;
        self::assertStringContainsString('SELECT api_key, created_at, scope, label FROM 202_api_keys', $select);
    }

    /**
     * From 1.9.80 a key is stored hashed (ApiKeyStore): the list reads its
     * hash, prefix and the watcher token stored for an AI connection's key.
     */
    public function testHashedKeysAreListedByTheirHashAndPrefix(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'key_hash'", [['Field' => 'key_hash']]);
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'scope'", [['Field' => 'scope']]);
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'label'", [['Field' => 'label']]);
        $hash = str_repeat('ab', 32);
        $token = str_repeat('cd', 32);
        $db->whenQueryContainsReturnRows('FROM 202_api_keys WHERE user_id', [
            ['api_key' => '', 'key_hash' => $hash, 'key_prefix' => '1a2b3c4d', 'watcher_token' => $token,
                'created_at' => 5, 'scope' => 'reports:read', 'label' => 'AI: Claude via mcp.tracking202.com'],
            ['api_key' => '', 'key_hash' => $hash, 'key_prefix' => '9f8e7d6c', 'watcher_token' => null,
                'created_at' => 4, 'scope' => null, 'label' => null],
        ]);

        $keys = p202_account_api_keys($db, 7);
        self::assertSame(['1a2b3c4d', $token], [$keys[0]['prefix'], $keys[0]['watcher_token']]);
        self::assertSame([$hash, '9f8e7d6c', null], [$keys[1]['ref'], $keys[1]['prefix'], $keys[1]['watcher_token']]);
        self::assertArrayNotHasKey('api_key', $keys[0]);
        $select = $db->statementsContaining('FROM 202_api_keys WHERE user_id')[0]->sql;
        self::assertStringContainsString('SELECT api_key, key_hash, key_prefix, watcher_token, created_at, scope, label', $select);
    }

    /**
     * 1.9.78's files can be served before its upgrade has added the label
     * column (the API is not sent to the upgrade page): the list still reads.
     */
    public function testKeysAreReadWithoutALabelColumnBeforeTheUpgradeRuns(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'scope'", [['Field' => 'scope']]);
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'label'", []);
        $db->whenQueryContainsReturnRows('FROM 202_api_keys WHERE user_id', [['api_key' => 'k1', 'created_at' => 5, 'scope' => 'reports:read']]);

        $keys = p202_account_api_keys($db, 7);
        $select = $db->statementsContaining('FROM 202_api_keys WHERE user_id')[0]->sql;
        self::assertStringContainsString('SELECT api_key, created_at, scope FROM 202_api_keys', $select);
        self::assertStringNotContainsString('label', $select, 'no column the install does not have');
        self::assertSame([['ref' => hash('sha256', 'k1'), 'prefix' => 'k1', 'created_at' => 5,
            'scope' => 'reports:read', 'label' => null, 'watcher_token' => null]], $keys);
    }

    public function testALabelProbeThatCannotTellIsAnErrorNotAnAnswer(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'scope'", [['Field' => 'scope']]);
        $db->whenQueryContainsResultFails("SHOW COLUMNS FROM 202_api_keys LIKE 'label'");
        $this->expectException(\Throwable::class);
        p202_account_api_keys($db, 7);
    }

    public function testKeysAreReadWithoutAScopeColumnAsFullAccess(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'scope'", []);
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'label'", []);
        $db->whenQueryContainsReturnRows('FROM 202_api_keys WHERE user_id', [['api_key' => 'k1', 'created_at' => 5]]);

        $keys = p202_account_api_keys($db, 7);
        $select = $db->statementsContaining('FROM 202_api_keys WHERE user_id')[0]->sql;
        self::assertStringNotContainsString('scope', $select, 'the list does not name a column the install does not have');
        self::assertSame([['ref' => hash('sha256', 'k1'), 'prefix' => 'k1', 'created_at' => 5,
            'scope' => null, 'label' => null, 'watcher_token' => null]], $keys);
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
