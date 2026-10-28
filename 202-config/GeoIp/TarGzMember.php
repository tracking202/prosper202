<?php

declare(strict_types=1);

namespace Prosper202\GeoIp;

/**
 * One file out of a .tar.gz, by its base name, streamed to a handle the
 * caller opened. Nothing is written by an entry's own name, so a name in
 * the archive cannot point outside the directory; PharData is not used,
 * because it unpacks the whole tar beside the archive first.
 *
 * Reads ustar and GNU tar (a GNU long name, type L, names the entry after
 * it); every other entry is skipped by its size. An archive that ends early,
 * a header whose checksum does not add up, a member larger than the caller
 * allows, or more entries or other bytes before the member than a GeoLite
 * archive holds (a few text files) is refused with the reason, so what is
 * unpacked is bounded by the caller's limit and not by the archive.
 */
final class TarGzMember
{
    private const BLOCK = 512;
    private const CHUNK = 1048576;
    public const MAX_ENTRIES = 64;
    public const MAX_OTHER_BYTES = 16777216;

    private function __construct()
    {
    }

    /**
     * @param resource $sink
     * @return int the member's size in bytes, all of it written to $sink
     * @throws RefreshFailed
     */
    public static function extract(string $archive, string $member, $sink, int $maxBytes): int
    {
        $magic = @file_get_contents($archive, false, null, 0, 2);
        if ($magic !== "\x1f\x8b") {
            throw new RefreshFailed('The download is not a gzip archive, so ' . $member
                . ' could not be taken out of it.');
        }
        if (!function_exists('gzopen')) {
            throw new RefreshFailed('PHP\'s zlib extension is not loaded, so the archive could not be unpacked.');
        }
        $gz = @gzopen($archive, 'rb');
        if ($gz === false) {
            throw new RefreshFailed('The downloaded archive could not be opened.');
        }
        try {
            $longName = null;
            $entries = 0;
            $otherBytes = 0;
            while (true) {
                if (++$entries > self::MAX_ENTRIES) {
                    throw new RefreshFailed('The archive has more than ' . self::MAX_ENTRIES . ' entries before '
                        . $member . ', so it is not one MaxMind builds.');
                }
                $header = self::read($gz, self::BLOCK);
                if (strlen($header) < self::BLOCK) {
                    throw new RefreshFailed('The archive ended before it held ' . $member
                        . ': the download is truncated.');
                }
                if ($header === str_repeat("\0", self::BLOCK)) {
                    throw new RefreshFailed('The archive holds no ' . $member . '.');
                }
                self::checkHeader($header);
                $size = self::size($header);
                $type = $header[156];
                $name = $longName ?? self::name($header);
                $longName = null;
                $padding = (self::BLOCK - $size % self::BLOCK) % self::BLOCK;

                if ($type === 'L') {
                    if ($size > 4096) {
                        throw new RefreshFailed('The archive has a file name longer than 4 KB, so it is not one'
                            . ' MaxMind builds.');
                    }
                    $longName = rtrim(self::exactly($gz, $size, $member), "\0");
                    self::skip($gz, $padding, $member);
                    continue;
                }
                if (($type === '0' || $type === "\0") && basename($name) === $member) {
                    if ($size > $maxBytes) {
                        throw new RefreshFailed($member . ' in the archive is ' . $size . ' bytes, more than the '
                            . $maxBytes . ' allowed, so it was not unpacked.');
                    }
                    self::copy($gz, $sink, $size, $member);

                    return $size;
                }
                $otherBytes += $size;
                if ($otherBytes > self::MAX_OTHER_BYTES) {
                    throw new RefreshFailed('The archive holds more than ' . self::MAX_OTHER_BYTES . ' bytes of other'
                        . ' files before ' . $member . ', so it is not one MaxMind builds.');
                }
                self::skip($gz, $size + $padding, $member);
            }
        } finally {
            gzclose($gz);
        }
    }

    /** @param resource $gz */
    private static function read($gz, int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = gzread($gz, min(self::CHUNK, $length - strlen($buffer)));
            if ($chunk === false) {
                throw new RefreshFailed('The archive is not a valid gzip stream: it could not be read to the end.');
            }
            if ($chunk === '') {
                break;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /** @param resource $gz */
    private static function exactly($gz, int $length, string $member): string
    {
        $data = self::read($gz, $length);
        if (strlen($data) < $length) {
            throw new RefreshFailed('The archive ended before it held ' . $member . ': the download is truncated.');
        }

        return $data;
    }

    /** @param resource $gz */
    private static function skip($gz, int $length, string $member): void
    {
        while ($length > 0) {
            $step = min(self::CHUNK, $length);
            self::exactly($gz, $step, $member);
            $length -= $step;
        }
    }

    /**
     * @param resource $gz
     * @param resource $sink
     */
    private static function copy($gz, $sink, int $size, string $member): void
    {
        $left = $size;
        while ($left > 0) {
            $chunk = self::read($gz, min(self::CHUNK, $left));
            if ($chunk === '') {
                throw new RefreshFailed('The archive ended inside ' . $member . ' (' . ($size - $left) . ' of ' . $size
                    . ' bytes): the download is truncated.');
            }
            $written = fwrite($sink, $chunk);
            if ($written !== strlen($chunk)) {
                throw new RefreshFailed($member . ' could not be written out of the archive (is the disk full?).');
            }
            $left -= strlen($chunk);
        }
    }

    /** The header's checksum: its bytes summed with the checksum field read as spaces. */
    private static function checkHeader(string $header): void
    {
        $stored = trim(substr($header, 148, 8), " \0");
        if ($stored === '' || preg_match('/^[0-7]+$/D', $stored) !== 1) {
            throw new RefreshFailed('The download is not a tar archive (a header has no checksum).');
        }
        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ($i >= 148 && $i < 156) ? 32 : ord($header[$i]);
        }
        if ($sum !== octdec($stored)) {
            throw new RefreshFailed('The download is not a tar archive (a header\'s checksum does not add up).');
        }
    }

    private static function size(string $header): int
    {
        if ((ord($header[124]) & 0x80) !== 0) {
            throw new RefreshFailed('The archive holds a file of 8 GB or more, so it is not one MaxMind builds.');
        }
        $field = trim(substr($header, 124, 12), " \0");
        if ($field === '' || preg_match('/^[0-7]{1,11}$/D', $field) !== 1) {
            throw new RefreshFailed('The download is not a tar archive (a header\'s size is not a number).');
        }

        return (int) octdec($field);
    }

    private static function name(string $header): string
    {
        $name = rtrim(substr($header, 0, 100), "\0");
        // POSIX ustar only: GNU tar's "ustar  " header keeps times where ustar keeps the prefix.
        if (substr($header, 257, 6) === "ustar\0") {
            $prefix = rtrim(substr($header, 345, 155), "\0");
            if ($prefix !== '') {
                $name = $prefix . '/' . $name;
            }
        }

        return $name;
    }
}
