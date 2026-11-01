<?php

declare(strict_types=1);

namespace Tests\GeoIp;

/**
 * Databases and archives for the GeoIP refresh's tests, made from
 * tests/fixtures/geoip/GeoLite2-City-Test.mmdb (MaxMind's test database,
 * from github.com/maxmind/MaxMind-DB, MIT or Apache-2.0; see the README
 * beside it).
 *
 * A build date is set by rewriting the four bytes of the metadata's
 * build_epoch in place (a uint32-sized uint64 in the MaxMind DB format), so
 * the file stays a valid database: the reader is the judge of that, not
 * this helper.
 */
final class GeoIpFixture
{
    public const MMDB = __DIR__ . '/../fixtures/geoip/GeoLite2-City-Test.mmdb';
    public const GNU_TAR = __DIR__ . '/../fixtures/geoip/GeoLite2-City-Test.tar.gz';

    /** The test database's bytes, built at $buildEpoch (or as shipped). */
    public static function mmdb(?int $buildEpoch = null): string
    {
        $bytes = (string) file_get_contents(self::MMDB);
        if ($buildEpoch === null) {
            return $bytes;
        }
        if ($buildEpoch < 0 || $buildEpoch > 0xFFFFFFFF) {
            throw new \InvalidArgumentException('a build epoch the four-byte field holds');
        }
        $marker = "\x4bbuild_epoch\x04\x02";
        $at = strrpos($bytes, $marker);
        if ($at === false) {
            throw new \RuntimeException('the fixture has no four-byte build_epoch to rewrite');
        }

        return substr_replace($bytes, pack('N', $buildEpoch), $at + strlen($marker), 4);
    }

    /**
     * A ustar archive of $entries (name => contents, or name => [type, contents]), gzipped.
     *
     * @param array<string, string|array{0: string, 1: string}> $entries
     */
    public static function tarGz(array $entries): string
    {
        return (string) gzencode(self::tar($entries));
    }

    /** @param array<string, string|array{0: string, 1: string}> $entries */
    public static function tar(array $entries): string
    {
        $out = '';
        foreach ($entries as $name => $entry) {
            [$type, $data] = is_array($entry) ? $entry : ['0', $entry];
            $padding = str_repeat("\0", (512 - strlen($data) % 512) % 512);
            $out .= self::header((string) $name, $type, strlen($data)) . $data . $padding;
        }

        return $out . str_repeat("\0", 1024);
    }

    /** One tar header block, its checksum computed as tar does. */
    public static function header(string $name, string $type, int $size): string
    {
        $header = str_pad(substr($name, 0, 100), 100, "\0")
            . "0000644\0" . "0000000\0" . "0000000\0"
            . str_pad(decoct($size), 11, '0', STR_PAD_LEFT) . "\0"
            . str_pad(decoct(1761091200), 11, '0', STR_PAD_LEFT) . "\0"
            . '        '
            . $type
            . str_repeat("\0", 100)
            . "ustar\0" . '00'
            . str_pad('root', 32, "\0") . str_pad('root', 32, "\0")
            . str_repeat("\0", 16)
            . str_repeat("\0", 155)
            . str_repeat("\0", 12);
        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($header[$i]);
        }

        return substr_replace($header, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT) . "\0 ", 148, 8);
    }

    /** An archive laid out as MaxMind's: a dated directory holding the database and its licence files. */
    public static function archive(string $mmdb, string $edition = 'GeoLite2-City'): string
    {
        return self::tarGz([
            $edition . '_20261022/' => ['5', ''],
            $edition . '_20261022/COPYRIGHT.txt' => 'Database and Contents Copyright (c) 2026 MaxMind, Inc.',
            $edition . '_20261022/LICENSE.txt' => 'Use of this MaxMind product is governed by the GeoLite2 EULA.',
            $edition . '_20261022/' . $edition . '.mmdb' => $mmdb,
        ]);
    }

    public static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/p202-geoip-test-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700, true)) {
            throw new \RuntimeException('could not make ' . $dir);
        }

        return $dir;
    }

    public static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($walk as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /** The build epoch the file at $path carries, read with the reader the lookups use. */
    public static function buildEpoch(string $path): int
    {
        $reader = new \MaxMind\Db\Reader($path);
        try {
            return (int) $reader->metadata()->buildEpoch;
        } finally {
            $reader->close();
        }
    }
}
