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
        self::assertSame([7, '', hash('sha256', self::KEY), '01234567', null, 'reports:read', 100], $insert->boundValues);
        self::assertNotContains(self::KEY, $insert->boundValues);
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
        self::assertSame('(k.key_hash = ? OR (k.key_hash IS NULL AND k.api_key COLLATE utf8mb4_bin = ?))', $sql);
        self::assertSame(['ss', [hash('sha256', self::KEY), self::KEY]], [$types, $values]);

        [$sql, $types, $values] = ApiKeyStore::matchKey(self::db(false), self::KEY);
        self::assertSame(['api_key COLLATE utf8mb4_bin = ?', 's', [self::KEY]], [$sql, $types, $values]);
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
        self::assertSame('01234567' . str_repeat('*', 24), ApiKeyStore::masked('01234567'));
    }

    public function testTheLegacyApisLiteralTakesNoQuestionMarkInAValueForAPlaceholder(): void
    {
        [$sql, , $values] = ApiKeyStore::matchKey(self::db(true), "a?b'c", 'k');
        $literal = ApiKeyStore::fillPlaceholders($sql, $values, 'addslashes');

        self::assertSame(
            "(k.key_hash = '" . hash('sha256', "a?b'c") . "' OR (k.key_hash IS NULL AND k.api_key COLLATE utf8mb4_bin = 'a?b\\'c'))",
            $literal
        );
    }
}
