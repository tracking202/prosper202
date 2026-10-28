<?php

declare(strict_types=1);

namespace Tests\GeoIp;

use PHPUnit\Framework\TestCase;
use Prosper202\GeoIp\GeoIpRefresh;
use Prosper202\GeoIp\RefreshFailed;
use Tests\Support\SourceScan;

/**
 * One refresh at a time, and the cron job's time around it: a lock another
 * run holds is "busy", a lock that cannot be taken at all is a failure (read
 * as busy, every run would be skipped with nothing recorded), and the cron
 * job records its hour before the refresh, whose download may outlast the
 * ten minutes after which the next run reads the cron lock as stale.
 */
final class RefreshLockTest extends TestCase
{
    public function testALockAnotherRunHoldsIsBusy(): void
    {
        $dir = GeoIpFixture::tempDir();
        try {
            $first = fopen($dir . '/.lock', 'c');
            $second = fopen($dir . '/.lock', 'r');
            self::assertIsResource($first);
            self::assertIsResource($second);
            self::assertTrue(GeoIpRefresh::tryLock($first));
            self::assertFalse(GeoIpRefresh::tryLock($second));
            fclose($first);
            self::assertTrue(GeoIpRefresh::tryLock($second), 'free once the first run is done');
            fclose($second);
        } finally {
            GeoIpFixture::remove($dir);
        }
    }

    public function testALockThatCannotBeTakenIsAFailureNotBusy(): void
    {
        $unlockable = fopen('php://memory', 'r+');
        self::assertIsResource($unlockable);
        $this->expectException(RefreshFailed::class);
        $this->expectExceptionMessage('could not lock its lock file');
        GeoIpRefresh::tryLock($unlockable);
    }

    public function testTheCronJobRecordsItsHourBeforeTheRefresh(): void
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/202-cronjobs/index.php');
        self::assertSame(1, preg_match('/^function RunHourlyCronJob\(\).*?^\}\n/ms', $source, $m));
        $recorded = strpos($m[0], "cronQuery(\$db, \$insert_sql, 'the hourly tier\\'s record of this hour\\'s run');");
        $refresh = strpos($m[0], 'RefreshGeoIpDatabase();');
        self::assertNotFalse($recorded, 'the hourly tier records its hour');
        self::assertNotFalse($refresh, 'the hourly tier refreshes the GeoIP database');
        self::assertSame(1, substr_count($m[0], 'RefreshGeoIpDatabase();'));
        self::assertGreaterThan($recorded, $refresh);
    }
}
