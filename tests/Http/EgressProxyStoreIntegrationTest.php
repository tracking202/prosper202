<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Http\EgressProxy;
use Prosper202\Http\EgressProxyStore;
use Prosper202\Http\StoredEgressProxy;

/**
 * The saved egress proxy in a real database: the installer makes its table,
 * an empty box keeps the stored credentials, removing them or the proxy
 * leaves none, the row reads back into the proxy the senders use, and a
 * database without the table (an upgrade not yet run) holds none.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_*). It
 * installs the schema there and empties 202_egress_proxy.
 *
 * @group integration
 */
final class EgressProxyStoreIntegrationTest extends TestCase
{
    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) {'
                . ' return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::assertTrue(self::$db->query('DELETE FROM 202_egress_proxy'));
    }

    protected function tearDown(): void
    {
        EgressProxy::readStoredFrom(null);
    }

    private static function store(): EgressProxyStore
    {
        assert(self::$db !== null);

        return new EgressProxyStore(new Connection(self::$db));
    }

    public function testASavedProxyKeepsItsCredentialsUntilTheyAreReplacedOrRemoved(): void
    {
        $store = self::store();
        self::assertNull($store->read(), 'nothing saved');

        $store->save('http://proxy.corp.example:3128', 'svc', 'pä:ss', false, 1, 1000);
        $saved = $store->read();
        self::assertNotNull($saved);
        self::assertSame('http://proxy.corp.example:3128', $saved->url);
        self::assertSame(['svc', 'pä:ss'], $saved->credentials());
        self::assertSame(1000, $saved->updatedAt);
        self::assertStringNotContainsString('pä:ss', print_r($saved, true), 'a dump never shows them');

        $store->save('https://proxy2.corp.example:8443', null, null, false, 1, 1001);
        self::assertSame(['svc', 'pä:ss'], $store->read()?->credentials(), 'an empty box keeps them');
        self::assertSame('https://proxy2.corp.example:8443', $store->read()?->url);

        EgressProxy::readStoredFrom(static fn (): ?StoredEgressProxy => self::store()->read());
        $proxy = EgressProxy::configured();
        self::assertNotNull($proxy);
        self::assertSame(EgressProxy::FROM_SETTINGS, $proxy->source);
        self::assertTrue($proxy->hasCredentials());
        self::assertSame('https://proxy2.corp.example:8443', $proxy->label());

        $store->save('https://proxy2.corp.example:8443', null, null, true, 1, 1002);
        self::assertFalse($store->read()?->credentialsStored(), 'removed');

        $store->save('https://proxy2.corp.example:8443', 'svc', 'again', false, 1, 1003);
        $store->forget(1, 1004);
        self::assertNull($store->read(), 'no proxy');
        assert(self::$db !== null);
        $row = self::$db->query('SELECT proxy_url, proxy_username, proxy_password FROM 202_egress_proxy')->fetch_row();
        self::assertSame(['', '', ''], $row, 'nothing of the proxy is left in the row');
        self::assertNull(EgressProxy::configured());
    }

    public function testRemovingAProxyNoneWasSavedLeavesNone(): void
    {
        assert(self::$db !== null);
        self::store()->forget(1, 1005);
        self::assertNull(self::store()->read(), 'no proxy');
        $row = self::$db->query('SELECT proxy_url, proxy_username, proxy_password, updated_by FROM 202_egress_proxy')->fetch_row();
        self::assertSame(['', '', '', '1'], array_map('strval', $row), 'the one row holds no proxy, and who removed it');
    }

    public function testASavedValueThatDoesNotReadRefusesToSend(): void
    {
        assert(self::$db !== null);
        self::assertTrue(self::$db->query("INSERT INTO 202_egress_proxy (proxy_id, proxy_url) VALUES (1, 'proxy.corp.example')"));
        EgressProxy::readStoredFrom(static fn (): ?StoredEgressProxy => self::store()->read());
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The egress proxy saved on Account › Settings is "proxy.corp.example"');
        EgressProxy::configured();
    }

    public function testADatabaseWithoutTheTableHoldsNoSavedProxy(): void
    {
        assert(self::$db !== null);
        self::assertTrue(self::$db->query('DROP TABLE 202_egress_proxy'));
        try {
            self::assertNull(self::store()->read());
        } finally {
            (new SchemaInstaller(self::$db))->install();
        }
        self::assertTrue((bool) self::$db->query('SELECT 1 FROM 202_egress_proxy LIMIT 1'), 'the installer makes it again');
    }
}
