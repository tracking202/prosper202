<?php

declare(strict_types=1);

namespace Tests\Release;

use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * A merge of #327 resolved by an agent committed its conflict markers into
 * 202-config/changelogs.json, and the squash took them into 1.9.79: the file
 * the in-app release notes read stopped being JSON, and nothing CI runs
 * parsed it (PHP lint reads PHP, and no test decoded the file). A marker is a
 * text no tracked file means to hold, so every tracked file is held to having
 * none, and every tracked JSON file to parsing.
 */
final class NoMergeConflictMarkersTest extends TestCase
{
    private const MARKER = '/^(?:<{7}|>{7}|\|{7})(?: |$)|^={7}$/m';

    public function testNoTrackedFileHoldsAConflictMarker(): void
    {
        $found = [];
        foreach (self::trackedFiles() as $path) {
            $source = self::read($path);
            if ($source === null) {
                continue;
            }
            if (SourceScan::countMatches(self::MARKER, $source, $path, $matches) > 0) {
                $found[] = $path . ': ' . implode(' | ', array_unique($matches[0]));
            }
        }
        self::assertSame([], $found, 'A tracked file holds a merge conflict marker: a merge was committed unresolved.');
    }

    public function testEveryTrackedJsonFileParses(): void
    {
        $broken = [];
        $read = 0;
        foreach (self::trackedFiles() as $path) {
            if (!str_ends_with($path, '.json')) {
                continue;
            }
            $read++;
            try {
                json_decode((string) self::read($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $broken[] = $path . ': ' . $e->getMessage();
            }
        }
        self::assertGreaterThan(0, $read, 'no tracked JSON file was read');
        self::assertSame([], $broken, 'A tracked JSON file does not parse.');
    }

    /** @return list<string> */
    private static function trackedFiles(): array
    {
        $root = SourceScan::repoRoot();
        if (!is_dir($root . '/.git') && !is_file($root . '/.git')) {
            self::markTestSkipped('not a git checkout');
        }
        $out = shell_exec('git -C ' . escapeshellarg($root) . ' ls-files -z');
        self::assertIsString($out, 'git ls-files did not run');
        $files = array_values(array_filter(explode("\0", $out), static fn(string $f): bool => $f !== ''));
        self::assertGreaterThan(100, count($files), 'git ls-files listed almost nothing');

        return $files;
    }

    /** A tracked text file's content, or null for a binary, a deleted file or a symbolic link. */
    private static function read(string $path): ?string
    {
        $full = SourceScan::repoRoot() . '/' . $path;
        if (is_link($full) || !is_file($full)) {
            return null;
        }
        $source = file_get_contents($full);
        self::assertIsString($source, 'could not read ' . $path);

        return str_contains(substr($source, 0, 8000), "\0") ? null : $source;
    }
}
