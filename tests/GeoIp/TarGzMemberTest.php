<?php

declare(strict_types=1);

namespace Tests\GeoIp;

use PHPUnit\Framework\TestCase;
use Prosper202\GeoIp\RefreshFailed;
use Prosper202\GeoIp\TarGzMember;

/** Taking the .mmdb out of a GeoLite2 archive, and refusing an archive that is not one. */
final class TarGzMemberTest extends TestCase
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

    public function testItReadsAnArchiveGnuTarMade(): void
    {
        [$out, $size] = $this->extract((string) file_get_contents(GeoIpFixture::GNU_TAR));

        self::assertSame(filesize(GeoIpFixture::MMDB), $size);
        self::assertSame(hash_file('sha256', GeoIpFixture::MMDB), hash('sha256', $out));
    }

    public function testItReadsMaxMindsLayoutAndSkipsTheOtherFiles(): void
    {
        $mmdb = GeoIpFixture::mmdb(1792000000);
        [$out] = $this->extract(GeoIpFixture::archive($mmdb));

        self::assertSame($mmdb, $out);
    }

    public function testAGnuLongNameNamesTheEntryAfterIt(): void
    {
        $long = str_repeat('d', 120) . '/GeoLite2-City.mmdb';
        $archive = GeoIpFixture::tarGz([
            '././@LongLink' => ['L', $long . "\0"],
            substr($long, 0, 100) => 'the database',
        ]);

        self::assertSame('the database', $this->extract($archive)[0]);
    }

    public function testANameThatOnlyEndsInTheMemberIsNotIt(): void
    {
        $this->expectRefusal('holds no GeoLite2-City.mmdb');
        $this->extract(GeoIpFixture::tarGz(['dir/NotGeoLite2-City.mmdb' => 'x', 'dir/GeoLite2-City.mmdb.txt' => 'y']));
    }

    public function testADirectoryOrLinkOfThatNameIsNotIt(): void
    {
        $this->expectRefusal('holds no GeoLite2-City.mmdb');
        $this->extract(GeoIpFixture::tarGz(['a/GeoLite2-City.mmdb' => ['5', ''], 'b/GeoLite2-City.mmdb' => ['2', '']]));
    }

    public function testNotGzipIsRefused(): void
    {
        $this->expectRefusal('not a gzip archive');
        $this->extract(GeoIpFixture::tar(['GeoLite2-City.mmdb' => 'x']));
    }

    public function testGzipThatIsNotTarIsRefused(): void
    {
        $this->expectRefusal('ended before it held');
        $this->extract((string) gzencode('<html>an error page</html>'));
    }

    public function testAHeaderWhoseChecksumDoesNotAddUpIsRefused(): void
    {
        $tar = GeoIpFixture::tar(['dir/GeoLite2-City.mmdb' => 'payload']);
        $tar[10] = 'X';
        $this->expectRefusal('checksum does not add up');
        $this->extract((string) gzencode($tar));
    }

    public function testAnArchiveTruncatedInsideTheMemberIsRefused(): void
    {
        $tar = GeoIpFixture::tar(['dir/GeoLite2-City.mmdb' => str_repeat('m', 5000)]);
        $this->expectRefusal('ended inside GeoLite2-City.mmdb (4000 of 5000 bytes)');
        $this->extract((string) gzencode(substr($tar, 0, 512 + 4000)));
    }

    public function testAnArchiveTruncatedBeforeTheMemberIsRefused(): void
    {
        $tar = GeoIpFixture::tar(['dir/COPYRIGHT.txt' => str_repeat('c', 3000), 'dir/GeoLite2-City.mmdb' => 'payload']);
        $this->expectRefusal('ended before it held GeoLite2-City.mmdb');
        $this->extract((string) gzencode(substr($tar, 0, 2048)));
    }

    public function testACorruptGzipStreamIsRefused(): void
    {
        $gz = GeoIpFixture::tarGz(['dir/GeoLite2-City.mmdb' => random_bytes(20000)]);
        for ($i = 40; $i < strlen($gz) - 40; $i += 7) {
            $gz[$i] = chr(ord($gz[$i]) ^ 0x5A);
        }
        $this->expectException(RefreshFailed::class);
        $this->extract($gz);
    }

    public function testAMemberOverTheLimitIsNotUnpacked(): void
    {
        $this->expectRefusal('more than the 1000 allowed');
        $this->extract(GeoIpFixture::tarGz(['dir/GeoLite2-City.mmdb' => str_repeat('m', 1001)]), 1000);
    }

    /** @return array{0: string, 1: int} */
    private function extract(string $archive, int $max = 1 << 30): array
    {
        $path = $this->dir . '/a.tar.gz';
        file_put_contents($path, $archive);
        $sink = fopen($this->dir . '/out', 'wb');
        self::assertIsResource($sink);
        try {
            $size = TarGzMember::extract($path, 'GeoLite2-City.mmdb', $sink, $max);
        } finally {
            fclose($sink);
        }

        return [(string) file_get_contents($this->dir . '/out'), $size];
    }

    private function expectRefusal(string $fragment): void
    {
        $this->expectException(RefreshFailed::class);
        $this->expectExceptionMessage($fragment);
    }
}
