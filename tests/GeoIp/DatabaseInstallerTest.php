<?php

declare(strict_types=1);

namespace Tests\GeoIp;

use PHPUnit\Framework\TestCase;
use Prosper202\GeoIp\DatabaseInstaller;
use Prosper202\GeoIp\RefreshFailed;

/**
 * Verify and swap: a download goes in place of the database only when its
 * SHA-256 is the published one, its .mmdb opens as the edition and was
 * built after the installed file; every refusal leaves the installed file's
 * bytes as they were and nothing of the attempt beside it.
 */
final class DatabaseInstallerTest extends TestCase
{
    private const NOW = 1792800000;
    private const OLD = 1530653216;
    private const NEW = 1792500000;

    private string $dir;
    private string $target;

    protected function setUp(): void
    {
        $this->dir = GeoIpFixture::tempDir();
        $this->target = $this->dir . '/GeoLite2-City.mmdb';
        file_put_contents($this->target, GeoIpFixture::mmdb(self::OLD));
        chmod($this->target, 0640);
    }

    protected function tearDown(): void
    {
        GeoIpFixture::remove($this->dir);
    }

    public function testANewerBuildReplacesTheFileAndTheOldOneIsKept(): void
    {
        $before = (string) file_get_contents($this->target);
        $inode = fileinode($this->target);

        $outcome = $this->install(GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW)));

        self::assertTrue($outcome->replaced);
        self::assertSame(self::NEW, GeoIpFixture::buildEpoch($this->target));
        self::assertSame(self::NEW, $outcome->now->builtAt);
        self::assertSame(self::OLD, $outcome->before->builtAt);
        self::assertNotSame($inode, fileinode($this->target), 'a new file renamed into place, not the old one written');
        self::assertSame($before, file_get_contents($this->target . '.previous'));
        self::assertSame(0644, fileperms($this->target) & 0777, 'the old mode, readable by every user');
        self::assertNothingLeftBeside();
    }

    public function testAReaderOpenBeforeTheSwapKeepsItsFileAndTheNextOneReadsTheNew(): void
    {
        $open = new \GeoIp2\Database\Reader($this->target);
        $this->install(GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW)));

        self::assertSame('GB', $open->city('81.2.69.160')->country->isoCode, 'a lookup in flight still reads');
        $open->close();
        $next = new \MaxMind\Db\Reader($this->target);
        self::assertSame(self::NEW, $next->metadata()->buildEpoch);
        $next->close();
    }

    public function testTheNewFileIsReadableUnderARestrictiveUmask(): void
    {
        unlink($this->target);
        $umask = umask(0077);
        try {
            $this->install(GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW)));
        } finally {
            umask($umask);
        }

        self::assertSame(0444, fileperms($this->target) & 0444, 'the web server and the cron job are often two users');
        self::assertFileDoesNotExist($this->target . '.previous', 'nothing was installed before, so nothing is kept');
    }

    public function testABadChecksumKeepsTheInstalledFile(): void
    {
        $archive = GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW));
        $this->assertRefused(
            'SHA-256 begins ' . substr(hash('sha256', $archive), 0, 16) . ', not the abababababababab MaxMind',
            $archive,
            str_repeat('ab', 32)
        );
    }

    public function testAChecksumThatIsNotASha256IsRefused(): void
    {
        $this->assertRefused('not a SHA-256', GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW)), 'abc');
    }

    public function testATruncatedArchiveKeepsTheInstalledFile(): void
    {
        $archive = GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW));
        $tar = (string) gzdecode($archive);
        $truncated = (string) gzencode(substr($tar, 0, strlen($tar) - 1024 - 8000));
        // The checksum is the truncated file's own, so the extraction is what refuses it.
        $this->assertRefused('the download is truncated', $truncated, hash('sha256', $truncated));
    }

    public function testAnArchiveWithoutTheDatabaseIsRefused(): void
    {
        $archive = GeoIpFixture::tarGz(['GeoLite2-City_20261022/README.txt' => 'nothing here']);
        $this->assertRefused('holds no GeoLite2-City.mmdb', $archive, hash('sha256', $archive));
    }

    public function testAnMmdbThatWillNotOpenKeepsTheInstalledFile(): void
    {
        $archive = GeoIpFixture::archive(str_repeat('not a database ', 400));
        $this->assertRefused('does not open as a MaxMind database', $archive, hash('sha256', $archive));
    }

    public function testAnMmdbWithACorruptSearchTreeKeepsTheInstalledFile(): void
    {
        $bytes = GeoIpFixture::mmdb(self::NEW);
        // The first records of the search tree: the metadata still opens, a lookup does not.
        $broken = str_repeat("\xFF", 600) . substr($bytes, 600);
        $archive = GeoIpFixture::archive($broken);
        $this->assertRefused('does not open as a MaxMind database', $archive, hash('sha256', $archive));
    }

    public function testAnOlderBuildKeepsTheInstalledFile(): void
    {
        file_put_contents($this->target, GeoIpFixture::mmdb(self::NEW));
        $archive = GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW - 86400));
        $this->assertRefused('before the installed database', $archive, hash('sha256', $archive));
    }

    public function testTheSameBuildIsCurrentAndReplacesNothing(): void
    {
        file_put_contents($this->target, GeoIpFixture::mmdb(self::NEW));
        $inode = fileinode($this->target);

        $outcome = $this->install(GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW)));

        self::assertFalse($outcome->replaced);
        self::assertSame(self::NEW, $outcome->now->builtAt);
        self::assertSame($inode, fileinode($this->target));
        self::assertFileDoesNotExist($this->target . '.previous');
        self::assertNothingLeftBeside();
    }

    public function testAnotherEditionIsRefused(): void
    {
        $installer = new DatabaseInstaller($this->target, 'GeoIP2-City', static fn (): int => self::NOW);
        $archive = GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW), 'GeoIP2-City');
        $before = (string) file_get_contents($this->target);
        try {
            $installer->install($this->file($archive), hash('sha256', $archive));
            self::fail('a GeoLite2-City file offered as GeoIP2-City was installed');
        } catch (RefreshFailed $e) {
            self::assertStringContainsString('is a GeoLite2-City database, not GeoIP2-City', $e->getMessage());
        }
        self::assertSame($before, file_get_contents($this->target));
    }

    public function testABuildDateAfterTomorrowIsRefused(): void
    {
        $archive = GeoIpFixture::archive(GeoIpFixture::mmdb(self::NOW + 3 * 86400));
        $this->assertRefused('after today', $archive, hash('sha256', $archive));
    }

    public function testNothingInstallsInADirectoryItCannotWriteTo(): void
    {
        $installer = new DatabaseInstaller(
            $this->dir . '/gone/GeoLite2-City.mmdb',
            'GeoLite2-City',
            static fn (): int => self::NOW
        );
        $archive = GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW));

        $this->expectException(RefreshFailed::class);
        $this->expectExceptionMessage('needs write access to ' . $this->dir . '/gone');
        $installer->install($this->file($archive), hash('sha256', $archive));
    }

    public function testLeftoversOfAStoppedRunAreRemovedAndNothingElse(): void
    {
        $leftover = $this->dir . '/.GeoLite2-City.mmdb.0123456789ab.mmdb.part';
        file_put_contents($leftover, 'half a database');
        file_put_contents($this->dir . '/keep.part', 'not ours');
        file_put_contents($this->target . '.previous', 'the one before');

        (new DatabaseInstaller($this->target))->cleanLeftovers();

        self::assertFileDoesNotExist($leftover);
        self::assertFileExists($this->dir . '/keep.part');
        self::assertFileExists($this->target . '.previous');
        self::assertFileExists($this->target);
    }

    public function testTheNewFileIsWritableByItsOwnerAloneWhateverTheOldOneAllowed(): void
    {
        chmod($this->target, 0666);

        $this->install(GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW)));

        clearstatcache();
        self::assertSame(self::NEW, GeoIpFixture::buildEpoch($this->target));
        self::assertSame(0644, fileperms($this->target) & 0777, 'no group or other write on the new database');
    }

    public function testADatabaseThatIsASymbolicLinkIsLeftAsALink(): void
    {
        $kept = $this->dir . '/kept-elsewhere.mmdb';
        rename($this->target, $kept);
        symlink($kept, $this->target);
        $before = (string) file_get_contents($kept);

        try {
            $this->install(GeoIpFixture::archive(GeoIpFixture::mmdb(self::NEW)));
            self::fail('a symbolic link was replaced by a file');
        } catch (RefreshFailed $e) {
            self::assertStringContainsString('is a symbolic link to ' . $kept, $e->getMessage());
        }
        clearstatcache();
        self::assertTrue(is_link($this->target), 'the link is still a link');
        self::assertSame($kept, readlink($this->target));
        self::assertSame($before, file_get_contents($kept), 'the file it points to is unchanged');
        self::assertFileDoesNotExist($this->target . '.previous');
        self::assertNothingLeftBeside();
    }

    private function install(string $archive): \Prosper202\GeoIp\InstallOutcome
    {
        return (new DatabaseInstaller($this->target, 'GeoLite2-City', static fn (): int => self::NOW))
            ->install($this->file($archive), hash('sha256', $archive));
    }

    private function assertRefused(string $fragment, string $archive, string $sha256): void
    {
        $before = (string) file_get_contents($this->target);
        $inode = fileinode($this->target);
        try {
            (new DatabaseInstaller($this->target, 'GeoLite2-City', static fn (): int => self::NOW))
                ->install($this->file($archive), $sha256);
            self::fail('installed although it should have been refused for: ' . $fragment);
        } catch (RefreshFailed $e) {
            self::assertStringContainsString($fragment, $e->getMessage());
        }
        clearstatcache();
        self::assertSame($before, file_get_contents($this->target), 'the installed file is unchanged');
        self::assertSame($inode, fileinode($this->target));
        self::assertFileDoesNotExist($this->target . '.previous');
        self::assertNothingLeftBeside();
    }

    private function file(string $archive): string
    {
        $path = $this->dir . '/download.tar.gz';
        file_put_contents($path, $archive);

        return $path;
    }

    private function assertNothingLeftBeside(): void
    {
        self::assertSame([], glob($this->dir . '/.GeoLite2-City.mmdb.*') ?: [], 'no temporary file is left beside it');
    }
}
