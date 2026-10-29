<?php

declare(strict_types=1);

namespace Tests\Release;

use Tests\TestCase;

/**
 * Every 1.9.79 PR added its release-note lines at the end of the version's
 * list, so every merge of two of them conflicted there, and resolving by
 * keeping both sides' lines kept a line both sides already had (28 entries
 * were listed twice or more) and the older wording of a line one side had
 * reworded (8 more). Release notes are read once, by the person upgrading,
 * and an entry that says the same thing three times, two of them out of date,
 * is read as three changes.
 *
 * A repeated entry is refused in every version of both files. A reworded
 * copy is caught by wording, so it is asked only of the version being
 * written: two of its entries that share most of their five-word runs. An
 * older version's similar entries were released as they are (1.9.56 lists
 * one change as both an Update and a Fix).
 */
final class ChangelogEntriesAreUniqueTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** Above every pair of distinct 1.9.79 entries (0.18) and below every reworded copy (0.76). */
    private const SAME_CHANGE = 0.6;

    public function testNoVersionOfTheReleaseNotesListsAnEntryTwice(): void
    {
        $repeated = [];
        foreach (self::textVersions() as $version => $entries) {
            foreach (array_count_values($entries) as $entry => $count) {
                if ($count > 1) {
                    $repeated[] = $version . ' (' . $count . 'x): ' . substr((string) $entry, 0, 100);
                }
            }
        }
        self::assertSame([], $repeated, 'changelogs.txt lists an entry more than once in one version.');
    }

    public function testNoVersionOfTheInAppNotesListsAnEntryTwice(): void
    {
        $repeated = [];
        foreach (self::jsonVersions() as $version => $entries) {
            foreach (array_count_values($entries) as $entry => $count) {
                if ($count > 1) {
                    $repeated[] = $version . ' (' . $count . 'x): ' . substr((string) $entry, 0, 100);
                }
            }
        }
        self::assertSame([], $repeated, '202-config/changelogs.json lists an entry more than once in one version.');
    }

    public function testTheVersionBeingWrittenHasNoRewordedCopyOfAnEntry(): void
    {
        $version = self::currentVersion();
        $lists = [
            'changelogs.txt' => self::textVersions()[$version] ?? [],
            '202-config/changelogs.json' => self::jsonVersions()[$version] ?? [],
        ];
        self::assertNotSame([], $lists['changelogs.txt'], 'changelogs.txt has no entries under Version ' . $version);

        $copies = [];
        foreach ($lists as $file => $entries) {
            $entries = array_values(array_unique($entries));
            $runs = array_map([self::class, 'fiveWordRuns'], $entries);
            foreach ($entries as $i => $a) {
                foreach (array_slice($entries, $i + 1, null, true) as $j => $b) {
                    $shared = count(array_intersect_key($runs[$i], $runs[$j]));
                    $all = count($runs[$i] + $runs[$j]);
                    if ($all > 0 && $shared / $all >= self::SAME_CHANGE) {
                        $copies[] = sprintf(
                            "%s %.2f\n  %s\n  %s",
                            $file,
                            $shared / $all,
                            substr($a, 0, 100),
                            substr($b, 0, 100)
                        );
                    }
                }
            }
        }
        self::assertSame(
            [],
            $copies,
            'Two ' . $version . ' entries describe one change, most likely an older wording a merge kept:'
            . ' keep the one the change\'s PR ended with.'
        );
    }

    /** @return array<string, true> */
    private static function fiveWordRuns(string $entry): array
    {
        preg_match_all('/\w+/u', mb_strtolower($entry), $m);
        $words = $m[0];
        $runs = [];
        for ($i = 0, $n = max(1, count($words) - 4); $i < $n; $i++) {
            $runs[implode(' ', array_slice($words, $i, 5))] = true;
        }

        return $runs;
    }

    /** @return array<string, list<string>> */
    private static function textVersions(): array
    {
        $text = file_get_contents(self::ROOT . '/changelogs.txt');
        self::assertIsString($text);
        $versions = [];
        $current = null;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^Version (\S+)/', $line, $m) === 1) {
                $current = $m[1];
                $versions[$current] = [];
                continue;
            }
            if ($current !== null && trim($line) !== '') {
                $versions[$current][] = $line;
            }
        }
        self::assertGreaterThan(1, count($versions), 'no "Version x.y.z" headings were read from changelogs.txt');

        return $versions;
    }

    /** @return array<string, list<string>> */
    private static function jsonVersions(): array
    {
        $json = (string) file_get_contents(self::ROOT . '/202-config/changelogs.json');
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $versions = [];
        foreach ($decoded as $version => $release) {
            self::assertIsArray($release['logs'] ?? null, 'changelogs.json ' . $version . ' has no logs list');
            $versions[(string) $version] = array_map('strval', $release['logs']);
        }

        return $versions;
    }

    private static function currentVersion(): string
    {
        $source = (string) file_get_contents(self::ROOT . '/202-config/version.php');
        $found = preg_match("/\\\$version_string = '([^']+)';/", $source, $m);
        self::assertSame(1, $found, 'no $version_string in version.php');

        return $m[1];
    }
}
