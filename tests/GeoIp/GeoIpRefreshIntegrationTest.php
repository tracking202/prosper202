<?php

declare(strict_types=1);

namespace Tests\GeoIp;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\GeoIp\DatabaseInstaller;
use Prosper202\GeoIp\GeoIpRefresh;
use Prosper202\GeoIp\GuardedCurlTransport;
use Prosper202\GeoIp\MaxMindDownload;
use Prosper202\GeoIp\RefreshResult;
use Prosper202\GeoIp\RefreshStore;

/**
 * A refresh end to end, every part real but MaxMind: the row in a real
 * database, the real curl transport (the guard's pin, TLS verified) against
 * tests/fixtures/geoip/fake_maxmind.py on 127.0.0.1, which answers as
 * MaxMind does (Basic auth, a 302 to a presigned URL that refuses a second
 * credential), and the real installer swapping a file in a temp directory.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS,
 * P202_TEST_DB_NAME), or when python3 or openssl is missing. It installs the
 * schema there and empties 202_geoip_refresh. The fake listens on
 * P202_FAKE_MAXMIND_PORT (8151).
 *
 * @group integration
 */
final class GeoIpRefreshIntegrationTest extends TestCase
{
    private const NOW = 1792800000;
    private const OLD = 1530653216;
    private const NEW = 1792500000;

    private static ?\mysqli $db = null;
    private static ?FakeMaxMind $fake = null;

    private string $dir;
    private string $target;

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
        self::$fake = FakeMaxMind::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$fake?->stop();
        self::$fake = null;
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        if (self::$fake === null) {
            self::markTestSkipped('python3 or openssl is missing, so the fake MaxMind server cannot run.');
        }
        self::assertTrue(self::$db->query('DELETE FROM 202_geoip_refresh'));
        self::$fake->reset();
        foreach (['GeoLite2-City.tar.gz', 'GeoLite2-City.tar.gz.sha256'] as $file) {
            self::$fake->answer($file, null);
        }
        $this->dir = GeoIpFixture::tempDir();
        $this->target = $this->dir . '/GeoLite2-City.mmdb';
        file_put_contents($this->target, GeoIpFixture::mmdb(self::OLD));
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            GeoIpFixture::remove($this->dir);
        }
    }

    public function testARefreshSwapsInTheNewBuildAndTheCredentialsGoOnlyToMaxMind(): void
    {
        $old = (string) file_get_contents($this->target);
        self::$fake->serve(GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW)));
        $this->store()->saveCredentials(FakeMaxMind::ACCOUNT, FakeMaxMind::LICENSE);

        $result = $this->refresh()->run();

        self::assertSame(RefreshResult::REFRESHED, $result->outcome, $result->message);
        self::assertSame(self::NEW, GeoIpFixture::buildEpoch($this->target));
        self::assertSame($old, file_get_contents($this->target . '.previous'));
        $state = $this->store()->read();
        self::assertSame('refreshed', $state->lastOutcome);
        self::assertSame(self::NOW, $state->lastRefreshedAt);
        self::assertSame(self::NOW, $state->lastCheckedAt);
        self::assertSame(self::NEW, $state->installedBuildEpoch);
        self::assertNull($state->lastError);

        $seen = array_map(
            static fn (array $r): string => $r['path'] . '?' . $r['query']
                . ' auth=' . ($r['authorization'] ? 'yes' : 'no') . ' ' . $r['status'],
            self::$fake->requests()
        );
        self::assertSame([
            '/geoip/databases/GeoLite2-City/download?suffix=tar.gz.sha256 auth=yes 302',
            '/r2/GeoLite2-City.tar.gz.sha256?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=fake auth=no 200',
            '/geoip/databases/GeoLite2-City/download?suffix=tar.gz auth=yes 302',
            '/r2/GeoLite2-City.tar.gz?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=fake auth=no 200',
        ], $seen);
    }

    public function testTheNextRunAsksOnlyForTheChecksumAndFindsItCurrent(): void
    {
        self::$fake->serve(GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW)));
        $this->store()->saveCredentials(FakeMaxMind::ACCOUNT, FakeMaxMind::LICENSE);
        self::assertSame(RefreshResult::REFRESHED, $this->refresh()->run()->outcome);
        self::$fake->reset();

        $result = $this->refresh(self::NOW + 7 * 86400)->run();

        self::assertSame(RefreshResult::CURRENT, $result->outcome, $result->message);
        self::assertCount(2, self::$fake->requests(), 'the checksum and its redirect, no archive');
        self::assertSame(self::NOW + 7 * 86400, $this->store()->read()->lastCheckedAt);
        self::assertSame(self::NOW, $this->store()->read()->lastRefreshedAt);
    }

    public function testAFileRedeployedOverTheRefreshedOneIsReplacedAgain(): void
    {
        self::$fake->serve(GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW)));
        $this->store()->saveCredentials(FakeMaxMind::ACCOUNT, FakeMaxMind::LICENSE);
        self::assertSame(RefreshResult::REFRESHED, $this->refresh()->run()->outcome);
        // A container redeploy puts the image's copy back; MaxMind's archive is the same.
        file_put_contents($this->target, GeoIpFixture::mmdb(self::OLD));

        self::assertNotNull($this->refresh(self::NOW + 3600)->runIfDue(), 'the cron job sees it at once');
        self::assertSame(self::NEW, GeoIpFixture::buildEpoch($this->target), 'and puts the refreshed build back');
    }

    public function testASecondRunWhileOneHoldsTheLockAsksNothing(): void
    {
        self::$fake->serve(GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW)));
        $this->store()->saveCredentials(FakeMaxMind::ACCOUNT, FakeMaxMind::LICENSE);
        $lock = fopen($this->dir . '/.GeoLite2-City.mmdb.lock', 'c');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $result = $this->refresh()->run();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::assertSame(RefreshResult::BUSY, $result->outcome);
        self::assertSame([], self::$fake->requests());
        self::assertSame(self::OLD, GeoIpFixture::buildEpoch($this->target));
        self::assertNull($this->store()->read()->lastAttemptAt, 'a run that did not happen is not recorded');
    }

    public function testABadChecksumKeepsTheOldFileAndRecordsWhy(): void
    {
        $old = (string) file_get_contents($this->target);
        self::$fake->serve(GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW)), str_repeat('0', 64));
        $this->store()->saveCredentials(FakeMaxMind::ACCOUNT, FakeMaxMind::LICENSE);

        $result = $this->refresh()->run();

        self::assertSame(RefreshResult::FAILED, $result->outcome);
        self::assertStringContainsString('not the ' . str_repeat('0', 16) . ' MaxMind published', $result->message);
        self::assertSame($old, file_get_contents($this->target));
        self::assertFileDoesNotExist($this->target . '.previous');
        self::assertSame([], glob($this->dir . '/.GeoLite2-City.mmdb.*.part') ?: []);
        $state = $this->store()->read();
        self::assertSame('failed', $state->lastOutcome);
        self::assertStringContainsString('SHA-256', (string) $state->lastError);
        self::assertNull($state->lastCheckedAt, 'a failure is not a check');
        $installed = \Prosper202\GeoIp\CityDatabase::status($this->target, self::NOW);
        self::assertSame(self::NOW + 23 * 3600, $state->dueAt($installed));
    }

    public function testWrongCredentialsAreSaidAsMaxMindSaysThem(): void
    {
        self::$fake->serve(GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW)));
        $this->store()->saveCredentials(FakeMaxMind::ACCOUNT, 'wrongKey_000000');

        $result = $this->refresh()->run();

        self::assertSame(RefreshResult::FAILED, $result->outcome);
        self::assertStringContainsString(
            'did not accept the account ID and licence key (401: "Your account ID or license key could not be'
                . ' authenticated.")',
            $result->message
        );
        self::assertSame(self::OLD, GeoIpFixture::buildEpoch($this->target));
    }

    public function testADownloadLimitIsSaid(): void
    {
        self::$fake->serve(GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW)));
        self::$fake->answer('GeoLite2-City.tar.gz', 429);
        $this->store()->saveCredentials(FakeMaxMind::ACCOUNT, FakeMaxMind::LICENSE);

        $result = $this->refresh()->run();

        self::assertSame(RefreshResult::FAILED, $result->outcome);
        self::assertStringContainsString('(429', $result->message);
        self::assertSame(self::OLD, GeoIpFixture::buildEpoch($this->target));
    }

    public function testAnUntrustedCertificateIsRefused(): void
    {
        self::$fake->serve(GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW)));
        $this->store()->saveCredentials(FakeMaxMind::ACCOUNT, FakeMaxMind::LICENSE);
        $origin = self::$fake->origin;
        $download = static fn (): MaxMindDownload => new MaxMindDownload(
            new GuardedCurlTransport($origin, null),
            $origin
        );
        $clock = static fn (): int => self::NOW;
        $refresh = new GeoIpRefresh(
            $this->store(),
            new DatabaseInstaller($this->target, 'GeoLite2-City', $clock),
            $download,
            $clock
        );

        $result = $refresh->run();

        self::assertSame(RefreshResult::FAILED, $result->outcome);
        self::assertStringContainsString('Could not download from 127.0.0.1', $result->message);
        self::assertSame([], self::$fake->requests(), 'nothing was sent over a connection it could not verify');
    }

    public function testNoCredentialsIsNotARunAndNothingIsAsked(): void
    {
        $result = $this->refresh()->run();

        self::assertSame(RefreshResult::NOT_CONFIGURED, $result->outcome);
        self::assertNull($this->refresh()->runIfDue());
        self::assertSame([], self::$fake->requests());
        self::assertNull($this->store()->read()->lastAttemptAt);
    }

    public function testTheCronJobRunsItOnlyWhenDue(): void
    {
        self::$fake->serve(GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW)));
        $this->store()->saveCredentials(FakeMaxMind::ACCOUNT, FakeMaxMind::LICENSE);

        self::assertSame(RefreshResult::REFRESHED, $this->refresh()->runIfDue()?->outcome);
        self::assertNull($this->refresh(self::NOW + 3600)->runIfDue(), 'refreshed an hour ago');
        self::assertNull($this->refresh(self::NOW + 6 * 86400)->runIfDue());
        self::assertSame(RefreshResult::CURRENT, $this->refresh(self::NOW + 7 * 86400)->runIfDue()?->outcome);
    }

    public function testCredentialsAreStoredAndForgotten(): void
    {
        $store = $this->store();
        $store->saveCredentials('111', null);
        self::assertTrue($store->read()->accountIdStored());
        self::assertFalse($store->read()->licenseKeyStored());
        $store->saveCredentials(null, FakeMaxMind::LICENSE);
        $both = $store->read()->credentials();
        self::assertSame(['111', FakeMaxMind::LICENSE], [$both?->accountId, $both?->licenseKey]);
        $store->saveCredentials('222', null);
        self::assertSame('222', $store->read()->credentials()?->accountId, 'a new account ID keeps the stored key');
        self::assertSame(FakeMaxMind::LICENSE, $store->read()->credentials()?->licenseKey);

        $store->forgetCredentials();
        self::assertNull($store->read()->credentials());
        self::assertFalse($store->read()->accountIdStored());
    }

    private function store(): RefreshStore
    {
        return new RefreshStore(new Connection(self::$db));
    }

    private function refresh(int $now = self::NOW): GeoIpRefresh
    {
        $fake = self::$fake;
        $download = static fn (): MaxMindDownload => new MaxMindDownload(
            new GuardedCurlTransport($fake->origin, $fake->caFile),
            $fake->origin
        );
        $clock = static fn (): int => $now;

        return new GeoIpRefresh(
            $this->store(),
            new DatabaseInstaller($this->target, 'GeoLite2-City', $clock),
            $download,
            $clock
        );
    }
}
