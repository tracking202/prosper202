<?php

declare(strict_types=1);

namespace Tests\User;

use Api\V3\Support\AiConnect;
use PHPUnit\Framework\TestCase;
use Prosper202\User\ApiKeyStore;
use Tests\Support\FakeMysqliConnection;

/**
 * REST API keys are stored as a hash and matched byte for byte (CA14).
 */
final class ApiKeyStoreTest extends TestCase
{
    private const KEY = '0123456789abcdefABCDEF0123456789abcdef0123456789abcdef0123456789';

    private static function db(bool $hashed, bool $scope = true, bool $label = true): FakeMysqliConnection
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'key_hash'", $hashed ? [['Field' => 'key_hash']] : []);
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'scope'", $scope ? [['Field' => 'scope']] : []);
        $db->whenQueryContainsReturnRows("SHOW COLUMNS FROM 202_api_keys LIKE 'label'", $label ? [['Field' => 'label']] : []);
        return $db;
    }

    public function testAKeyIsStoredAsItsHashAndPrefixNeverWhole(): void
    {
        $db = self::db(true);
        ApiKeyStore::insert($db, 7, self::KEY, 'reports:read', null, 100);

        $insert = $db->statementsContaining('INSERT INTO 202_api_keys')[0];
        self::assertStringContainsString('(user_id, api_key, key_hash, key_prefix, watcher_token, scope, created_at)', $insert->sql);
        $values = $insert->boundValues;
        self::assertMatchesRegularExpression('/^![0-9a-f]{64}$/D', $values[1], 'api_key holds a placeholder, never the key or empty');
        $values[1] = 'PLACEHOLDER';
        self::assertSame([7, 'PLACEHOLDER', hash('sha256', self::KEY), '01234567', null, 'reports:read', 100], $values);
        self::assertNotContains(self::KEY, $insert->boundValues);
        self::assertNotSame(ApiKeyStore::placeholder(), ApiKeyStore::placeholder());
    }

    public function testAnAiConnectionsKeyStoresTheTokenItsWatchersAreKnownBy(): void
    {
        $db = self::db(true);
        ApiKeyStore::insert($db, 7, self::KEY, 'reports:read', AiConnect::LABEL_PREFIX . 'Claude', 100);

        $values = $db->statementsContaining('INSERT INTO 202_api_keys')[0]->boundValues;
        self::assertContains(AiConnect::watcherToken(self::KEY), $values);
        self::assertContains(AiConnect::LABEL_PREFIX . 'Claude', $values);
    }

    public function testBeforeTheUpgradeAKeyIsStoredAsBefore(): void
    {
        $db = self::db(false, true, false);
        ApiKeyStore::insert($db, 7, self::KEY, null, 'ignored without the column', 100);

        $insert = $db->statementsContaining('INSERT INTO 202_api_keys')[0];
        self::assertStringContainsString('(user_id, api_key, scope, created_at)', $insert->sql);
        self::assertSame([7, self::KEY, null, 100], $insert->boundValues);
    }

    public function testAScopeIsWrittenEvenWhereTheColumnIsMissingSoTheInsertFailsRatherThanGrantAll(): void
    {
        $db = self::db(false, false, false);
        ApiKeyStore::insert($db, 7, self::KEY, 'reports:read', null, 100);

        self::assertStringContainsString('scope', $db->statementsContaining('INSERT INTO 202_api_keys')[0]->sql);
    }

    public function testAKeyIsMatchedByItsHashOrExactlyWhereNotYetHashed(): void
    {
        [$sql, $types, $values] = ApiKeyStore::matchKey(self::db(true), self::KEY, 'k');
        self::assertSame('(k.key_hash = ? OR (k.key_hash IS NULL AND CONVERT(k.api_key USING utf8mb4) COLLATE utf8mb4_bin = ?))', $sql);
        self::assertSame(['ss', [hash('sha256', self::KEY), self::KEY]], [$types, $values]);

        [$sql, $types, $values] = ApiKeyStore::matchKey(self::db(false), self::KEY);
        self::assertSame(['CONVERT(api_key USING utf8mb4) COLLATE utf8mb4_bin = ?', 's', [self::KEY]], [$sql, $types, $values]);
    }

    public function testAnEmptyKeyIsNeverLookedUpSinceEveryHashedRowsApiKeyIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApiKeyStore::matchKey(self::db(true), '');
    }

    public function testARefIsSixtyFourLowerCaseHexDigits(): void
    {
        self::assertTrue(ApiKeyStore::isRef(hash('sha256', 'x')));
        self::assertFalse(ApiKeyStore::isRef(strtoupper(hash('sha256', 'x'))));
        self::assertFalse(ApiKeyStore::isRef('abc'));
        self::assertFalse(ApiKeyStore::isRef(hash('sha256', 'x') . "\n"));

        [$sql, , $values] = ApiKeyStore::matchRef(self::db(true), hash('sha256', 'x'));
        self::assertStringContainsString("key_hash IS NULL AND api_key <> '' AND SHA2(api_key, 256) = ?", $sql);
        self::assertSame([hash('sha256', 'x'), hash('sha256', 'x')], $values);

        $this->expectException(\InvalidArgumentException::class);
        ApiKeyStore::matchRef(self::db(true), 'not-a-ref');
    }

    public function testARowIsNamedAndShownTheSameHashedOrNot(): void
    {
        $whole = ['api_key' => self::KEY];
        $hashed = ['api_key' => '', 'key_hash' => hash('sha256', self::KEY), 'key_prefix' => '01234567', 'watcher_token' => 'tok'];

        self::assertSame(ApiKeyStore::ref($whole), ApiKeyStore::ref($hashed));
        self::assertSame(ApiKeyStore::prefixOf($whole), ApiKeyStore::prefixOf($hashed));
        self::assertSame(AiConnect::watcherToken(self::KEY), ApiKeyStore::watcherTokenOf($whole));
        self::assertSame('tok', ApiKeyStore::watcherTokenOf($hashed));
        self::assertNull(ApiKeyStore::watcherTokenOf(['api_key' => '', 'key_hash' => 'h', 'watcher_token' => null]));
        $placeholder = ['api_key' => ApiKeyStore::placeholder(), 'key_hash' => 'h', 'key_prefix' => 'abcd1234', 'watcher_token' => null];
        self::assertNull(ApiKeyStore::watcherTokenOf($placeholder), 'a placeholder is not a key');
        self::assertSame(['h', 'abcd1234'], [ApiKeyStore::ref($placeholder), ApiKeyStore::prefixOf($placeholder)]);
        self::assertSame('01234567' . str_repeat('*', 24), ApiKeyStore::masked('01234567'));
    }

    public function testTheLegacyApisLiteralTakesNoQuestionMarkInAValueForAPlaceholder(): void
    {
        [$sql, , $values] = ApiKeyStore::matchKey(self::db(true), "a?b'c", 'k');
        $literal = ApiKeyStore::fillPlaceholders($sql, $values, 'addslashes');

        self::assertSame(
            "(k.key_hash = '" . hash('sha256', "a?b'c") . "' OR (k.key_hash IS NULL AND CONVERT(k.api_key USING utf8mb4) COLLATE utf8mb4_bin = 'a?b\\'c'))",
            $literal
        );
    }

    public function testAKeyEndsAtItsEndingAndAnUnreadableEndingHasEnded(): void
    {
        self::assertNull(ApiKeyStore::endedAt(['user_id' => 7], 1000), 'no column: the key never ends');
        self::assertNull(ApiKeyStore::endedAt(['ends_at' => null], 1000));
        self::assertNull(ApiKeyStore::endedAt(['ends_at' => '1001'], 1000));
        self::assertSame(1000, ApiKeyStore::endedAt(['ends_at' => '1000'], 1000));
        self::assertSame(999, ApiKeyStore::endedAt(['ends_at' => 999], 1000));
        foreach (['', 'soon', '12abc', '-5', 1.5, []] as $unreadable) {
            self::assertSame(0, ApiKeyStore::endedAt(['ends_at' => $unreadable], 1000), var_export($unreadable, true));
        }
        self::assertStringContainsString('on 1970-01-01 00:16 UTC', ApiKeyStore::endedMessage(999));
        self::assertStringNotContainsString(' on ', ApiKeyStore::endedMessage(0));
    }

    public function testAClientNameIsOneLineOfValidTextCutToItsColumn(): void
    {
        self::assertSame('store-sync/2.1 (nightly)', ApiKeyStore::clientName(" store-sync/2.1\r\n(nightly) "));
        self::assertSame('first', ApiKeyStore::clientName(['first', 'second']));
        self::assertNull(ApiKeyStore::clientName(null));
        self::assertNull(ApiKeyStore::clientName("  \t "));
        self::assertNull(ApiKeyStore::clientName(42));
        $long = ApiKeyStore::clientName(str_repeat("\u{1F6D2}", 400));
        self::assertSame(ApiKeyStore::CLIENT_LENGTH, mb_strlen((string) $long));
        self::assertTrue(mb_check_encoding((string) ApiKeyStore::clientName("bad\xC3(bytes"), 'UTF-8'));
    }

    public function testAUseIsNotWrittenWithoutTheColumnsOrInsideTheInterval(): void
    {
        $db = self::db(true);
        ApiKeyStore::stampUse($db, ['user_id' => 7, 'scope' => null], self::KEY, 'v3', 'store-sync/2.1', 1000);
        $row = ['user_id' => 7, 'last_used_api' => 'v3', 'last_used_client' => null];
        $recent = ['last_used_at' => 1000 - ApiKeyStore::USE_STAMP_INTERVAL + 1] + $row;
        ApiKeyStore::stampUse($db, $recent, self::KEY, 'v3', 'store-sync/2.1', 1000);
        self::assertSame([], $db->statementsContaining('UPDATE 202_api_keys'));

        $stale = ['last_used_at' => 1000 - ApiKeyStore::USE_STAMP_INTERVAL] + $row;
        ApiKeyStore::stampUse($db, $stale, self::KEY, 'v1', 'store-sync/2.1', 1000);
        $update = $db->statementsContaining('UPDATE 202_api_keys')[0];
        self::assertStringContainsString('WHERE user_id = ? AND (key_hash = ?', $update->sql);
        self::assertStringContainsString('AND (last_used_at IS NULL OR last_used_at < ?)', $update->sql);
        self::assertSame(
            [1000, 'v1', 'store-sync/2.1', 7, hash('sha256', self::KEY), self::KEY, 1000],
            $update->boundValues
        );
    }

    public function testAKeysRowSaysItsLastUseInTheClientsWordsAndItsEnding(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-account-ui.php';
        $key = ['use_recorded' => true, 'label' => null, 'last_used_at' => null, 'last_used_api' => null,
            'last_used_client' => null, 'ends_at' => null];
        self::assertSame(
            ['used' => 'No use recorded', 'ending' => '', 'ended' => false, 'replaceable' => true],
            p202_account_key_use($key, 5000)
        );

        $used = p202_account_key_use(
            ['last_used_at' => 4000, 'last_used_api' => 'v3', 'last_used_client' => 'store-sync/2.1'] + $key,
            5000
        );
        self::assertSame(
            'Last used ' . date('M j, Y H:i', 4000) . ' on API v3, by a client calling itself store-sync/2.1',
            $used['used']
        );

        $ending = p202_account_key_use(['ends_at' => 5001] + $key, 5000);
        self::assertSame(
            ['Replaced; works until ' . date('M j, Y H:i', 5001), false, false],
            [$ending['ending'], $ending['ended'], $ending['replaceable']]
        );
        $ended = p202_account_key_use(['ends_at' => 5000] + $key, 5000);
        self::assertSame(
            ['Replaced; ended ' . date('M j, Y H:i', 5000), true, false],
            [$ended['ending'], $ended['ended'], $ended['replaceable']]
        );

        $ai = p202_account_key_use(['label' => AiConnect::LABEL_PREFIX . 'Claude'] + $key, 5000);
        self::assertFalse($ai['replaceable']);
        self::assertSame(
            ['used' => '', 'ending' => '', 'ended' => false, 'replaceable' => false],
            p202_account_key_use(['use_recorded' => false] + $key, 5000)
        );
    }
}
