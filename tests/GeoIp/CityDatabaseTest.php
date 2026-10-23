<?php

declare(strict_types=1);

namespace Tests\GeoIp;

use PHPUnit\Framework\TestCase;
use Prosper202\GeoIp\CityDatabase;
use Prosper202\GeoIp\DatabaseStatus;

/**
 * The GeoIP database's age, read from the build date in its metadata, and
 * the threshold it is stale after. A file that is missing or cannot be read
 * has no age, and nothing about it reads as fresh.
 */
final class CityDatabaseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = GeoIpFixture::tempDir();
    }

    protected function tearDown(): void
    {
        GeoIpFixture::remove($this->dir);
    }

    public function testTheShippedDatabaseIsGeoLite2OfJuly2018AndStale(): void
    {
        $now = gmmktime(12, 0, 0, 10, 23, 2026);
        $status = CityDatabase::status(CityDatabase::PATH, $now);

        self::assertSame(DatabaseStatus::STALE, $status->state);
        self::assertSame('GeoLite2-City', $status->databaseType);
        self::assertSame(1530653216, $status->builtAt);
        self::assertSame('2018-07-03', $status->builtOn());
        self::assertSame(intdiv($now - 1530653216, 86400), $status->ageDays);
        self::assertStringNotContainsString('/../', $status->path, 'the path is shown resolved');
        self::assertStringEndsWith('/202-config/geo/GeoLite2-City.mmdb', $status->path);
    }

    public function testAFileBuiltFiveDaysAgoIsCurrent(): void
    {
        $now = 1792800000;
        $path = $this->write(GeoIpFixture::mmdb($now - 5 * 86400));

        $status = CityDatabase::status($path, $now);

        self::assertSame(DatabaseStatus::CURRENT, $status->state);
        self::assertSame(5, $status->ageDays);
        self::assertSame($now - 5 * 86400, $status->builtAt);
        self::assertNull($status->warning());
        self::assertNull($status->reason);
    }

    public function testStaleStartsAfterThirtyDaysToTheSecond(): void
    {
        $now = 1792800000;
        $edge = CityDatabase::status($this->write(GeoIpFixture::mmdb($now - 30 * 86400)), $now);
        self::assertSame(DatabaseStatus::CURRENT, $edge->state, 'exactly 30 days old is not yet stale');

        $past = CityDatabase::status($this->write(GeoIpFixture::mmdb($now - 30 * 86400 - 1)), $now);
        self::assertSame(DatabaseStatus::STALE, $past->state);
        self::assertSame(30, $past->ageDays);
        self::assertSame(30, $past->staleAfterDays);
        self::assertStringContainsString('older than 30 days', (string) $past->reason);
        self::assertStringContainsString('days ago (stale after 30)', (string) $past->warning());
    }

    public function testTheThresholdIsThirtyDays(): void
    {
        self::assertSame(30, CityDatabase::STALE_AFTER_DAYS);
    }

    public function testAMissingFileHasNoAgeAndSaysSo(): void
    {
        $status = CityDatabase::status($this->dir . '/GeoLite2-City.mmdb', 1792800000);

        self::assertSame(DatabaseStatus::MISSING, $status->state);
        self::assertNull($status->builtAt);
        self::assertNull($status->ageDays);
        self::assertFalse($status->readable());
        self::assertStringContainsString('There is no GeoIP database at', (string) $status->reason);
        self::assertNotNull($status->warning());
    }

    /** @return iterable<string, array{0: string}> */
    public static function unreadableFiles(): iterable
    {
        yield 'text' => ['this is not a database'];
        yield 'empty' => [''];
        yield 'truncated: the metadata at the end is gone' => [substr(GeoIpFixture::mmdb(1792000000), 0, 9000)];
    }

    /** @dataProvider unreadableFiles */
    public function testAFileThatDoesNotOpenIsUnreadableNotFresh(string $bytes): void
    {
        $status = CityDatabase::status($this->write($bytes), 1792800000);

        self::assertSame(DatabaseStatus::UNREADABLE, $status->state);
        self::assertNull($status->builtAt);
        self::assertNull($status->ageDays);
        self::assertStringContainsString('could not be opened', (string) $status->reason);
        self::assertNotNull($status->warning());
    }

    public function testABuildDateAfterTomorrowIsNotBelieved(): void
    {
        $now = 1792800000;
        $tomorrow = CityDatabase::status($this->write(GeoIpFixture::mmdb($now + 86400)), $now);
        self::assertSame(DatabaseStatus::CURRENT, $tomorrow->state);

        $status = CityDatabase::status($this->write(GeoIpFixture::mmdb($now + 86401)), $now);
        self::assertSame(DatabaseStatus::UNREADABLE, $status->state);
        self::assertStringContainsString('after today', (string) $status->reason);
    }

    public function testNoBuildDateOrANonCityTypeIsUnreadable(): void
    {
        foreach ([0, -5, null, '1792800000'] as $epoch) {
            $status = CityDatabase::fromMetadata('/x.mmdb', 'GeoLite2-City', $epoch, 1792800000);
            self::assertSame(DatabaseStatus::UNREADABLE, $status->state, var_export($epoch, true));
            self::assertStringContainsString('no build date', (string) $status->reason);
        }
        foreach (['GeoIP2-ISP', 'GeoLite2-Country', null] as $type) {
            $status = CityDatabase::fromMetadata('/x.mmdb', $type, 1792000000, 1792800000);
            self::assertSame(DatabaseStatus::UNREADABLE, $status->state, var_export($type, true));
            self::assertStringContainsString('not a City one', (string) $status->reason);
        }
    }

    public function testTheHealthFieldsCarryNoPathOrReaderError(): void
    {
        $status = CityDatabase::status($this->write('junk'), 1792800000);

        self::assertSame(
            ['state', 'built_at', 'built_on', 'age_days', 'stale_after_days'],
            array_keys($status->healthFields())
        );
        self::assertStringNotContainsString($this->dir, (string) json_encode($status->healthFields()));
        self::assertStringNotContainsString($this->dir, (string) $status->warning());
        self::assertSame(
            ['state', 'path', 'database_type', 'built_at', 'built_on', 'age_days', 'stale_after_days', 'reason'],
            array_keys($status->toArray())
        );
    }

    private function write(string $bytes): string
    {
        $path = $this->dir . '/' . bin2hex(random_bytes(4)) . '.mmdb';
        file_put_contents($path, $bytes);

        return $path;
    }
}
